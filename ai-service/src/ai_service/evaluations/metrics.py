"""RAG Triad metrics evaluation engine: Faithfulness, Context Recall, and Answer Relevance."""

from collections.abc import Sequence
from dataclasses import dataclass, field
import math
import re
from typing import Any

from ai_service.citations.validator import CitationValidator
from ai_service.providers.base import EmbeddingModel


@dataclass
class RagTriadReport:
    """Quantitative performance breakdown across core RAG Triad dimensions."""

    faithfulness: float = 1.0
    context_recall: float = 1.0
    answer_relevance: float = 1.0
    triad_harmonic_mean: float = 1.0
    total_claims: int = 0
    verified_claims: int = 0
    verified_claims_count: int = 0
    hallucinated_citations: int = 0
    unanchored_claims: int = 0
    citation_precision: float = 1.0
    citation_recall: float = 1.0
    is_acceptable: bool = True
    score: float = 1.0
    passed: bool = True
    details: dict[str, Any] = field(default_factory=dict)

    def __init__(
        self,
        faithfulness: float = 1.0,
        context_recall: float = 1.0,
        answer_relevance: float = 1.0,
        triad_harmonic_mean: float = 1.0,
        total_claims: int = 0,
        verified_claims: int = 0,
        verified_claims_count: int = 0,
        hallucinated_citations: int = 0,
        unanchored_claims: int = 0,
        citation_precision: float = 1.0,
        citation_recall: float = 1.0,
        is_acceptable: bool = True,
        score: float = 1.0,
        passed: bool = True,
        details: dict[str, Any] | None = None,
        **extra_kwargs: Any,
    ) -> None:
        self.faithfulness = faithfulness
        self.citation_precision = (
            citation_precision
            if (citation_precision != 1.0 or faithfulness == 1.0)
            else faithfulness
        )
        if self.citation_precision != 1.0 and self.faithfulness == 1.0:
            self.faithfulness = self.citation_precision

        self.context_recall = context_recall
        self.citation_recall = (
            citation_recall
            if (citation_recall != 1.0 or context_recall == 1.0)
            else context_recall
        )
        if self.citation_recall != 1.0 and self.context_recall == 1.0:
            self.context_recall = self.citation_recall

        self.answer_relevance = answer_relevance
        self.total_claims = total_claims

        self.verified_claims = verified_claims or verified_claims_count
        self.verified_claims_count = self.verified_claims

        self.hallucinated_citations = hallucinated_citations
        self.unanchored_claims = unanchored_claims

        self.score = (
            score
            if (score != 1.0 or triad_harmonic_mean == 1.0)
            else triad_harmonic_mean
        )
        self.triad_harmonic_mean = self.score

        self.is_acceptable = is_acceptable and passed
        self.passed = self.is_acceptable
        self.details = details or {}
        if extra_kwargs:
            self.details.update(extra_kwargs)


# Backward-compatible alias for benchmark modules
EvaluationScore = RagTriadReport


class RagTriadEvaluator:
    """Evaluates grounded generation using the RAG Triad without external LLM judges."""

    @classmethod
    def _cosine_similarity(cls, vec1: Sequence[float], vec2: Sequence[float]) -> float:
        if not vec1 or not vec2 or len(vec1) != len(vec2):
            return 0.0
        dot = sum(a * b for a, b in zip(vec1, vec2))
        norm1 = math.sqrt(sum(a * a for a in vec1))
        norm2 = math.sqrt(sum(b * b for b in vec2))
        if norm1 == 0.0 or norm2 == 0.0:
            return 0.0
        return max(0.0, min(1.0, dot / (norm1 * norm2)))

    @classmethod
    def calculate_faithfulness(
        cls,
        answer: str,
        evidence_chunks: Sequence[Any],
    ) -> tuple[float, int, int, int, int]:
        """Compute sentence-level grounding and citation validity."""
        evidence_map: dict[str, Any] = {}
        for idx, chunk in enumerate(evidence_chunks, start=1):
            eid = getattr(chunk, "evidence_id", None) or f"E{idx}"
            evidence_map[eid] = chunk

        validation = CitationValidator.validate_answer(
            answer=answer,
            evidence_map=evidence_map,
            strip_invalid_tags=False,
        )

        verified = len(validation.verified_citations)
        hallucinated = len(validation.hallucinated_ids)
        unanchored = len(validation.unanchored_claims)
        total = verified + hallucinated + unanchored

        score = round(verified / total, 3) if total > 0 else 1.0
        return score, total, verified, hallucinated, unanchored

    @classmethod
    def calculate_context_recall(
        cls,
        evidence_chunks: Sequence[Any],
        ground_truth_key_facts: Sequence[str],
    ) -> float:
        """Measure what proportion of required facts are retrieved into context."""
        if not ground_truth_key_facts:
            return 1.0

        combined_text = " ".join(
            getattr(c, "content", str(c)).lower() for c in evidence_chunks
        )
        found_count = 0
        for fact in ground_truth_key_facts:
            fact_lower = fact.lower().strip()
            if fact_lower in combined_text:
                found_count += 1
                continue
            tokens = [w for w in re.findall(r"[\$\w]+", fact_lower) if w]
            if tokens and all(t in combined_text for t in tokens):
                found_count += 1

        return round(found_count / len(ground_truth_key_facts), 3)

    @classmethod
    def evaluate_answer(
        cls,
        answer: str = "",
        validation_result: Any = None,
        evidence_chunks: Sequence[Any] | None = None,
        ground_truth_key_facts: Sequence[str] | None = None,
        **kwargs: Any,
    ) -> RagTriadReport:
        """Synchronously evaluate an answer from validation results or evidence chunks."""
        chunks = evidence_chunks or []

        if validation_result is not None:
            verified = len(getattr(validation_result, "verified_citations", []))
            hallucinated = len(getattr(validation_result, "hallucinated_ids", []))
            unanchored = len(getattr(validation_result, "unanchored_claims", []))
            total = getattr(validation_result, "total_claims_count", None)
            if total is None:
                total = verified + hallucinated + unanchored
            faithfulness = round(verified / total, 3) if total > 0 else 1.0
        elif chunks:
            faithfulness, total, verified, hallucinated, unanchored = (
                cls.calculate_faithfulness(answer, chunks)
            )
        else:
            faithfulness, total, verified, hallucinated, unanchored = 1.0, 0, 0, 0, 0

        context_recall = (
            cls.calculate_context_recall(chunks, ground_truth_key_facts)
            if (chunks and ground_truth_key_facts)
            else 1.0
        )
        answer_relevance = 1.0

        f, r, a = faithfulness, context_recall, answer_relevance
        if f > 0.0 and r > 0.0 and a > 0.0:
            harmonic_mean = round(3.0 / ((1.0 / f) + (1.0 / r) + (1.0 / a)), 3)
        else:
            harmonic_mean = 0.0

        is_acceptable = faithfulness >= 0.80 and hallucinated == 0

        return RagTriadReport(
            faithfulness=faithfulness,
            context_recall=context_recall,
            answer_relevance=answer_relevance,
            triad_harmonic_mean=harmonic_mean,
            total_claims=total,
            verified_claims=verified,
            verified_claims_count=verified,
            hallucinated_citations=hallucinated,
            unanchored_claims=unanchored,
            citation_precision=faithfulness,
            citation_recall=context_recall,
            is_acceptable=is_acceptable,
            score=harmonic_mean,
            passed=is_acceptable,
        )

    @classmethod
    async def evaluate(
        cls,
        query: str = "",
        answer: str = "",
        evidence_chunks: Sequence[Any] | None = None,
        ground_truth_key_facts: Sequence[str] | None = None,
        embedder: EmbeddingModel | None = None,
        candidates: Sequence[Any] | None = None,
        **kwargs: Any,
    ) -> RagTriadReport:
        """Async evaluation pass computing faithfulness, recall, and embedding relevance."""
        chunks = evidence_chunks if evidence_chunks is not None else (candidates or [])

        score, total, verified, hallucinated, unanchored = (
            cls.calculate_faithfulness(answer, chunks)
        )
        context_recall = cls.calculate_context_recall(
            chunks, ground_truth_key_facts or []
        )

        if not answer.strip():
            answer_relevance = 1.0 if not query.strip() else 0.0
        elif embedder is not None:
            vectors = await embedder.embed([query, answer])
            sim = cls._cosine_similarity(vectors[0], vectors[1])
            answer_relevance = round(sim, 3)
        else:
            q_tokens = set(re.findall(r"\w+", query.lower()))
            a_tokens = set(re.findall(r"\w+", answer.lower()))
            overlap = len(q_tokens & a_tokens) / len(q_tokens) if q_tokens else 1.0
            answer_relevance = round(overlap, 3)

        f, r, a = score, context_recall, answer_relevance
        if f > 0.0 and r > 0.0 and a > 0.0:
            harmonic_mean = round(3.0 / ((1.0 / f) + (1.0 / r) + (1.0 / a)), 3)
        else:
            harmonic_mean = 0.0

        is_acceptable = score >= 0.85 and context_recall >= 0.80

        return RagTriadReport(
            faithfulness=score,
            context_recall=context_recall,
            answer_relevance=answer_relevance,
            triad_harmonic_mean=harmonic_mean,
            total_claims=total,
            verified_claims=verified,
            verified_claims_count=verified,
            hallucinated_citations=hallucinated,
            unanchored_claims=unanchored,
            citation_precision=score,
            citation_recall=context_recall,
            is_acceptable=is_acceptable,
            score=harmonic_mean,
            passed=is_acceptable,
        )


class RagMetricsCalculator(RagTriadEvaluator):
    """Backwards-compatible calculator alias for benchmark suites."""

    def __init__(self, *args: Any, **kwargs: Any) -> None:
        pass

    async def __call__(self, *args: Any, **kwargs: Any) -> RagTriadReport:
        return await self.evaluate(*args, **kwargs)

    @classmethod
    async def calculate(cls, *args: Any, **kwargs: Any) -> RagTriadReport:
        return await cls.evaluate(*args, **kwargs)

    @classmethod
    async def calculate_score(cls, *args: Any, **kwargs: Any) -> RagTriadReport:
        return await cls.evaluate(*args, **kwargs)

    @classmethod
    async def calculate_metrics(cls, *args: Any, **kwargs: Any) -> RagTriadReport:
        return await cls.evaluate(*args, **kwargs)


__all__ = [
    "EvaluationScore",
    "RagMetricsCalculator",
    "RagTriadEvaluator",
    "RagTriadReport",
]
