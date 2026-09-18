"""Fault-tolerant cross-encoder reranking service with graceful fallback to RRF scores."""

from collections.abc import Sequence
import logging
from ai_service.providers.base import RerankingModel
from ai_service.schemas.retrieval import CandidateChunk

logger = logging.getLogger(__name__)


class RerankingService:
    """Orchestrates cross-encoder reranking over retrieved candidate chunks."""

    def __init__(self, provider: RerankingModel) -> None:
        self._provider = provider

    async def rerank_candidates(
        self,
        query: str,
        candidates: Sequence[CandidateChunk],
        top_n: int,
    ) -> list[CandidateChunk]:
        """Score and reorder candidate chunks using a cross-encoder model.

        If the underlying reranking provider fails or times out, this method
        catches the exception, logs structured telemetry, and gracefully falls
        back to the candidates' existing RRF score order.
        """
        if not candidates:
            return []

        doc_texts = [cand.content for cand in candidates]

        try:
            rerank_results = await self._provider.rerank(
                query=query,
                documents=doc_texts,
                top_n=top_n,
            )

            # Map scores back to candidate chunks using lineage index
            scored_candidates: list[CandidateChunk] = []
            for result in rerank_results:
                if 0 <= result.index < len(candidates):
                    base_cand = candidates[result.index]
                    updated = base_cand.model_copy(
                        update={
                            "rerank_score": float(result.score),
                            "final_score": float(result.score),
                        }
                    )
                    scored_candidates.append(updated)

            return scored_candidates

        except Exception as exc:
            logger.warning(
                "Cross-encoder reranking failed; degrading gracefully to RRF rankings. Error: %s",
                str(exc),
                exc_info=True,
            )
            # Fallback: maintain RRF score ordering, setting rerank_score to None
            fallback_sorted = sorted(
                candidates,
                key=lambda c: (c.rrf_score, c.chunk_id),
                reverse=True,
            )
            return list(fallback_sorted[:top_n])