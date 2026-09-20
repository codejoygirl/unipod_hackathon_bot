"""High-level retrieval service orchestrating query processing and search."""

import re
import time

from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.retrieval.hybrid_store import execute_hybrid_search
from ai_service.retrieval.reranker import ReRankerPipeline
from ai_service.schemas.retrieval import QueryRequest, RetrievalResponse
from ai_service.translation.expander import QueryExpander


class HybridRetrievalService:
    """Orchestrates multilingual expansion, hybrid search, and reranking."""

    def __init__(self, embedder, expander: QueryExpander | None = None, reranker_service=None):
        self.embedder = embedder
        self.expander = expander
        self.reranker_service = reranker_service

    async def search(self, session: AsyncSession, request: QueryRequest) -> RetrievalResponse:
        start_time = time.perf_counter()

        if self.expander is not None:
            expansion = await self.expander.expand_query(
                query=request.query,
                target_language=request.target_language,
            )
            detected_language = expansion.detected_language
            expanded_queries = expansion.queries
        else:
            detected_language = "en"
            expanded_queries = [request.query]

        # Search with each expanded query and merge by chunk id (best RRF wins).
        merged: dict[str, object] = {}
        for q in expanded_queries:
            words = re.findall(r"\w+", q, flags=re.UNICODE)
            ts_query_string = " & ".join(words) if words else q
            query_vector = await self.embedder.embed_query(q)

            candidates = await execute_hybrid_search(
                session=session,
                tenant_id=request.tenant_id,
                community_ids=request.community_ids,
                ts_query_string=ts_query_string,
                query_vector=query_vector,
                limit=request.top_k,
                k=60,
            )
            for candidate in candidates:
                key = str(candidate.chunk_id)
                existing = merged.get(key)
                if existing is None or candidate.rrf_score > existing.rrf_score:  # type: ignore[attr-defined]
                    merged[key] = candidate

        fused = sorted(merged.values(), key=lambda c: c.rrf_score, reverse=True)  # type: ignore[attr-defined]

        # Prefer injected reranker (sets final_score on a 0–1 scale). RRF alone is ~0.03
        # and fails the synthesizer's 0.75 confidence floor.
        if self.reranker_service is not None:
            reranked = await self.reranker_service.rerank_candidates(
                request.query,
                fused,  # type: ignore[arg-type]
                top_n=request.rerank_top_n,
            )
        else:
            reranked = await ReRankerPipeline.rerank(
                request.query,
                fused,  # type: ignore[arg-type]
                top_n=request.rerank_top_n,
            )

        elapsed_ms = (time.perf_counter() - start_time) * 1000.0

        return RetrievalResponse(
            original_query=request.query,
            expanded_queries=expanded_queries,
            detected_language=detected_language,
            candidates=reranked,
            execution_time_ms=elapsed_ms,
            total_candidates_scanned=len(fused),
        )
