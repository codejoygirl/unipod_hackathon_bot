"""CLI test harness and evaluation orchestrator for regression testing and CI quality gates."""

import argparse
import asyncio
from collections.abc import Sequence
from dataclasses import asdict, dataclass
import json
import sys
import time
from typing import Any

from ai_service.evaluations.datasets import BenchmarkCase, load_golden_benchmark_cases
from ai_service.evaluations.metrics import RagTriadEvaluator, RagTriadReport
from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.base import EmbeddingModel
from ai_service.providers.mock import MockChatModel, MockEmbedder
from ai_service.schemas.evidence import AnswerState, EvidenceChunk


@dataclass(frozen=True)
class CaseResult:
    case_id: str
    expected_state: str
    actual_state: str
    state_passed: bool
    triad_report: RagTriadReport
    passed: bool
    error_message: str | None = None


@dataclass(frozen=True)
class SuiteRunSummary:
    total_cases: int
    passed_cases: int
    failed_cases: int
    avg_faithfulness: float
    avg_context_recall: float
    avg_answer_relevance: float
    avg_harmonic_mean: float
    all_passed: bool
    execution_time_seconds: float
    results: list[CaseResult]


class EvaluationRunner:
    """Runs benchmark suites against the AnswerSynthesizer and verifies RAG Triad thresholds."""

    def __init__(
        self,
        synthesizer: AnswerSynthesizer,
        embedder: EmbeddingModel | None = None,
    ) -> None:
        self.synthesizer = synthesizer
        self.embedder = embedder or MockEmbedder(dimension=1536)

    async def evaluate_case(self, case: BenchmarkCase) -> CaseResult:
        """Execute a single benchmark case and calculate Triad metrics."""
        try:
            payload = await self.synthesizer.synthesize_grounded_answer(
                query=case.query,
                candidates=case.candidates,
                target_language=case.target_language,
            )

            state_matches = payload.state == case.expected_state

            if payload.state == AnswerState.INSUFFICIENT_EVIDENCE:
                # Insufficient evidence has no factual claims to evaluate
                triad = RagTriadReport(
                    faithfulness=1.0,
                    context_recall=1.0,
                    answer_relevance=1.0,
                    triad_harmonic_mean=1.0,
                    total_claims=0,
                    verified_claims=0,
                    hallucinated_citations=0,
                    unanchored_claims=0,
                    is_acceptable=True,
                )
            else:
                evidence_chunks = [
                    EvidenceChunk(
                        evidence_id=f"E{idx}",
                        chunk_id=c.chunk_id,
                        source_id=c.source_id,
                        source_name=f"Source_{c.source_type}",
                        source_uri=c.source_uri or f"community://sources/{c.source_id}",
                        source_type=c.source_type,
                        content=c.content,
                        authority_tier=c.authority_tier,
                        retrieval_score=c.final_score,
                    )
                    for idx, c in enumerate(case.candidates, start=1)
                ]

                triad = await RagTriadEvaluator.evaluate(
                    query=case.query,
                    answer=payload.answer,
                    evidence_chunks=evidence_chunks,
                    ground_truth_key_facts=case.expected_key_facts,
                    embedder=self.embedder,
                )

            passed = (
                state_matches
                and triad.faithfulness >= case.min_faithfulness
                and triad.context_recall >= case.min_context_recall
            )

            return CaseResult(
                case_id=case.case_id,
                expected_state=case.expected_state.value,
                actual_state=payload.state.value,
                state_passed=state_matches,
                triad_report=triad,
                passed=passed,
            )

        except Exception as exc:
            dummy_triad = RagTriadReport(
                faithfulness=0.0,
                context_recall=0.0,
                answer_relevance=0.0,
                triad_harmonic_mean=0.0,
                total_claims=0,
                verified_claims=0,
                hallucinated_citations=0,
                unanchored_claims=0,
                is_acceptable=False,
            )
            return CaseResult(
                case_id=case.case_id,
                expected_state=case.expected_state.value,
                actual_state="EXCEPTION",
                state_passed=False,
                triad_report=dummy_triad,
                passed=False,
                error_message=str(exc),
            )

    async def run_suite(self, cases: Sequence[BenchmarkCase]) -> SuiteRunSummary:
        """Run all test cases concurrently and compute aggregate performance."""
        start_time = time.perf_counter()

        tasks = [self.evaluate_case(case) for case in cases]
        results = await asyncio.gather(*tasks)

        total = len(results)
        passed = sum(1 for r in results if r.passed)
        failed = total - passed

        avg_f = sum(r.triad_report.faithfulness for r in results) / total if total else 0.0
        avg_r = sum(r.triad_report.context_recall for r in results) / total if total else 0.0
        avg_a = sum(r.triad_report.answer_relevance for r in results) / total if total else 0.0
        avg_h = sum(r.triad_report.triad_harmonic_mean for r in results) / total if total else 0.0

        elapsed = time.perf_counter() - start_time

        return SuiteRunSummary(
            total_cases=total,
            passed_cases=passed,
            failed_cases=failed,
            avg_faithfulness=round(avg_f, 3),
            avg_context_recall=round(avg_r, 3),
            avg_answer_relevance=round(avg_a, 3),
            avg_harmonic_mean=round(avg_h, 3),
            all_passed=(failed == 0),
            execution_time_seconds=round(elapsed, 2),
            results=list(results),
        )


async def main() -> int:
    """CLI entrypoint for running evaluation benchmarks."""
    parser = argparse.ArgumentParser(description="Run RAG Triad benchmark suite.")
    parser.add_argument("--json", action="store_true", help="Output machine-readable JSON.")
    args = parser.parse_args()

    synthesizer = AnswerSynthesizer(MockChatModel())
    runner = EvaluationRunner(synthesizer=synthesizer)
    cases = load_golden_benchmark_cases()

    summary = await runner.run_suite(cases)

    if args.json:
        output_dict = {
            "total": summary.total_cases,
            "passed": summary.passed_cases,
            "failed": summary.failed_cases,
            "avg_faithfulness": summary.avg_faithfulness,
            "avg_context_recall": summary.avg_context_recall,
            "avg_answer_relevance": summary.avg_answer_relevance,
            "all_passed": summary.all_passed,
            "duration_s": summary.execution_time_seconds,
        }
        print(json.dumps(output_dict, indent=2))
    else:
        print("\n=======================================================")
        print("          RAG TRIAD EVALUATION BENCHMARK               ")
        print("=======================================================")
        for r in summary.results:
            status = "PASS" if r.passed else "FAIL"
            print(
                f"[{status}] {r.case_id} | State: {r.actual_state:<21} | "
                f"Faith: {r.triad_report.faithfulness:.2f} | "
                f"Recall: {r.triad_report.context_recall:.2f} | "
                f"Rel: {r.triad_report.answer_relevance:.2f}"
            )
            if r.error_message:
                print(f"       Error: {r.error_message}")

        print("-------------------------------------------------------")
        print(
            f"Summary: {summary.passed_cases}/{summary.total_cases} passed in "
            f"{summary.execution_time_seconds}s"
        )
        print(
            f"Averages: Faithfulness={summary.avg_faithfulness:.3f}, "
            f"ContextRecall={summary.avg_context_recall:.3f}, "
            f"AnswerRelevance={summary.avg_answer_relevance:.3f}"
        )
        print("=======================================================\n")

    return 0 if summary.all_passed else 1


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))