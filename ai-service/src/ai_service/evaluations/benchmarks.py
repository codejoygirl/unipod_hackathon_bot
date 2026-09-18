"""Automated benchmark harness for evaluating grounding performance against golden datasets."""

from collections.abc import Sequence
from dataclasses import dataclass
from ai_service.citations.validator import CitationValidator
from ai_service.evaluations.metrics import EvaluationScore, RagMetricsCalculator
from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.schemas.evidence import AnswerState
from ai_service.schemas.retrieval import CandidateChunk


@dataclass(frozen=True)
class GoldenTestCase:
    """A test case with known input context and expected operational state."""

    name: str
    query: str
    candidates: list[CandidateChunk]
    expected_state: AnswerState
    min_faithfulness: float = 0.85


@dataclass(frozen=True)
class BenchmarkSummary:
    """Summary metrics of an automated evaluation suite run."""

    total_cases: int
    passed_cases: int
    average_faithfulness: float
    average_citation_precision: float
    all_passed: bool


class BenchmarkRunner:
    """Executes test cases against AnswerSynthesizer and aggregates verification metrics."""

    def __init__(self, synthesizer: AnswerSynthesizer) -> None:
        self._synthesizer = synthesizer

    async def run_suite(self, cases: Sequence[GoldenTestCase]) -> BenchmarkSummary:
        """Execute all golden test cases and calculate aggregate evaluation scores."""
        if not cases:
            return BenchmarkSummary(0, 0, 1.0, 1.0, True)

        passed = 0
        total_faithfulness = 0.0
        total_precision = 0.0

        for case in cases:
            payload = await self._synthesizer.synthesize_grounded_answer(
                query=case.query,
                candidates=case.candidates,
            )

            # Assert operational state matches expected contract
            state_matches = payload.state == case.expected_state

            if payload.state == AnswerState.INSUFFICIENT_EVIDENCE:
                # Insufficient evidence has no claims to verify; perfect score by definition
                score = EvaluationScore(
                    faithfulness=1.0,
                    citation_precision=1.0,
                    citation_recall=1.0,
                    total_claims=0,
                    verified_claims_count=0,
                    is_acceptable=True,
                )
            else:
                evidence_map = {
                    f"E{i}": c for i, c in enumerate(
                        self._synthesizer._normalize_candidates_to_evidence(case.candidates),
                        start=1,
                    )
                }
                validation = CitationValidator.validate_answer(payload.answer, evidence_map)
                score = RagMetricsCalculator.evaluate_answer(
                    answer=payload.answer,
                    validation_result=validation,
                    evidence_chunks=list(evidence_map.values()),
                )

            total_faithfulness += score.faithfulness
            total_precision += score.citation_precision

            if state_matches and score.faithfulness >= case.min_faithfulness:
                passed += 1

        total_count = len(cases)
        return BenchmarkSummary(
            total_cases=total_count,
            passed_cases=passed,
            average_faithfulness=round(total_faithfulness / total_count, 3),
            average_citation_precision=round(total_precision / total_count, 3),
            all_passed=(passed == total_count),
        )