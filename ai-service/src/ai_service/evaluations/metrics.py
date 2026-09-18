"""Quantitative RAG Triad evaluation metrics: Faithfulness, Context Recall, and Answer Relevance."""

from collections.abc import Sequence
from dataclasses import dataclass
import math
import re
from ai_service.citations.extractor import parse_claims_with_citations
from ai_service.citations.validator import CitationValidator
from ai_service.providers.base import EmbeddingModel
from ai_service.schemas.evidence import EvidenceChunk


@dataclass(frozen=True)
class RagTriadReport:
    """Consolidated evaluation score package for a single query execution."""

    faithfulness: float
    context_recall: float
    answer_relevance: float
    triad_harmonic_mean: float
    total_claims: int
    verified_claims: int
    hallucinated_citations: int
    unanchored_claims: int
    is_acceptable: bool


class RagTriadEvaluator:
    """Calculates production-grade deterministic RAG Triad metrics."""

    # Production Quality Gates
    MIN_FAITHFULNESS_THRESHOLD = 0.85
    MIN_CONTEXT_RECALL_THRESHOLD = 0.80
    MIN_ANSWER_RELEVANCE_THRESHOLD = 0.75

    @staticmethod
    def _cosine_similarity(vec_a: list[float], vec_b: list[float]) -> float:
        """Compute cosine similarity between two dense vectors."""
        if not vec_a or not vec_b or len(vec_a) != len(vec_b):
            return 0.0

        dot_product = sum(a * b for a, b in zip(vec_a, vec_b, strict=True))
        norm_a = math.sqrt(sum(a * a for a in vec_a))
        norm_b = math.sqrt(sum(b * b for b in vec_b))

        if norm_a == 0.0 or norm_b == 0.0:
            return 0.0

        return max(0.0, min(1.0, dot_product / (norm_a * norm_b)))

    @classmethod
    def calculate_faithfulness(
        cls,
        answer: str,
        evidence_chunks: Sequence[EvidenceChunk],
    ) -> tuple[float, int, int, int, int]:
        """Compute Faithfulness score via claim extraction and citation grounding.

        Returns:
            Tuple of (score, total_claims, verified_claims, hallucinated_ids_count, unanchored_claims_count).
        """
        if not answer.strip():
            return 1.0, 0, 0, 0, 0

        evidence_map = {chunk.evidence_id: chunk for chunk in evidence_chunks}
        validation_result = CitationValidator.validate_answer(
            answer=answer,
            evidence_map=evidence_map,
            strip_invalid_tags=False,
        )

        claims = parse_claims_with_citations(answer)
        total_claims = len(claims)

        if total_claims == 0:
            return 1.0, 0, 0, 0, 0

        verified_chunk_ids = {c.evidence_id for c in validation_result.verified_citations}
        verified_claims_count = 0

        for claim in claims:
            if claim.evidence_ids:
                # If any cited ID is structurally verified and aligned
                if any(eid in verified_chunk_ids for eid in claim.evidence_ids):
                    verified_claims_count += 1

        faithfulness = verified_claims_count / total_claims
        hallucinated_count = len(validation_result.hallucinated_ids)
        unanchored_count = len(validation_result.unanchored_claims)

        return (
            round(faithfulness, 3),
            total_claims,
            verified_claims_count,
            hallucinated_count,
            unanchored_count,
        )

    @classmethod
    def calculate_context_recall(
        cls,
        evidence_chunks: Sequence[EvidenceChunk],
        ground_truth_key_facts: Sequence[str],
    ) -> float:
        """Compute Context Recall as the ratio of reference facts retrievable from context.

        Args:
            evidence_chunks: Chunks returned by retrieval engine.
            ground_truth_key_facts: Mandatory keywords or factual strings expected.
        """
        if not ground_truth_key_facts:
            return 1.0

        if not evidence_chunks:
            return 0.0

        aggregated_context = " ".join(c.content.lower() for c in evidence_chunks)
        hits = 0

        for fact in ground_truth_key_facts:
            fact_tokens = set(re.findall(r"\w+", fact.lower()))
            if not fact_tokens:
                continue

            # Check if all key tokens of this fact exist within the aggregated context
            context_tokens = set(re.findall(r"\w+", aggregated_context))
            overlap_ratio = len(fact_tokens.intersection(context_tokens)) / len(fact_tokens)

            if overlap_ratio >= 0.75:
                hits += 1

        return round(hits / len(ground_truth_key_facts), 3)

    @classmethod
    async def calculate_answer_relevance(
        cls,
        query: str,
        answer: str,
        embedder: EmbeddingModel | None = None,
    ) -> float:
        """Compute Answer Relevance via semantic query-response similarity.

        Falls back to normalized token Jaccard similarity if an embedder is unavailable.
        """
        clean_query = query.strip()
        clean_answer = answer.strip()

        if not clean_answer:
            return 0.0

        if embedder is not None:
            try:
                embeddings = await embedder.embed([clean_query, clean_answer])
                return round(cls._cosine_similarity(embeddings[0], embeddings[1]), 3)
            except Exception:
                pass  # Fall through to token-based similarity on provider error

        # Token-based Jaccard similarity fallback
        q_tokens = set(re.findall(r"\w+", clean_query.lower()))
        a_tokens = set(re.findall(r"\w+", clean_answer.lower()))

        if not q_tokens or not a_tokens:
            return 0.0

        intersection = len(q_tokens.intersection(a_tokens))
        union = len(q_tokens.union(a_tokens))
        return round(intersection / union, 3)

    @classmethod
    async def evaluate(
        cls,
        query: str,
        answer: str,
        evidence_chunks: Sequence[EvidenceChunk],
        ground_truth_key_facts: Sequence[str],
        embedder: EmbeddingModel | None = None,
    ) -> RagTriadReport:
        """Run complete RAG Triad assessment and return consolidated quality report."""
        faithfulness, total_c, verified_c, hallucinated_n, unanchored_n = (
            cls.calculate_faithfulness(answer, evidence_chunks)
        )

        context_recall = cls.calculate_context_recall(
            evidence_chunks=evidence_chunks,
            ground_truth_key_facts=ground_truth_key_facts,
        )

        answer_relevance = await cls.calculate_answer_relevance(
            query=query,
            answer=answer,
            embedder=embedder,
        )

        # Compute Triad Harmonic Mean: 3 / (1/F + 1/R + 1/A)
        scores = [faithfulness, context_recall, answer_relevance]
        if any(s == 0.0 for s in scores):
            harmonic_mean = 0.0
        else:
            harmonic_mean = 3.0 / sum(1.0 / s for s in scores)

        is_acceptable = (
            faithfulness >= cls.MIN_FAITHFULNESS_THRESHOLD
            and context_recall >= cls.MIN_CONTEXT_RECALL_THRESHOLD
            and answer_relevance >= cls.MIN_ANSWER_RELEVANCE_THRESHOLD
        )

        return RagTriadReport(
            faithfulness=faithfulness,
            context_recall=context_recall,
            answer_relevance=answer_relevance,
            triad_harmonic_mean=round(harmonic_mean, 3),
            total_claims=total_c,
            verified_claims=verified_c,
            hallucinated_citations=hallucinated_n,
            unanchored_claims=unanchored_n,
            is_acceptable=is_acceptable,
        )