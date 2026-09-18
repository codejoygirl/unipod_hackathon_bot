"""End-to-end hybrid retrieval orchestrator combining dual-query expansion, parallel search, RRF, reranking, and authority scaling."""

import asyncio
from collections.abc import Sequence
import time
import uuid
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.models.glossary import GlossaryEntry
from ai_service.providers.base import EmbeddingModel
from ai_service.reranking.service import RerankingService
from ai_service.retrieval.authority import apply_authority_weighting
from ai_service.retrieval.lexical import execute_lexical_search
from ai_service.retrieval.predicates import build_retrieval_predicates
from ai_service.retrieval.rrf import merge_candidate_rankings
from ai_service.retrieval.vector import execute_vector_search
from ai_service.schemas.retrieval import CandidateChunk, QueryRequest, RetrievalResponse
from ai_service.translation.expander import QueryExpander


class HybridRetrievalService:
    """Coordinates the multi-stage hybrid search and reranking lifecycle."""

    def __init__(
        self,
        embedder: EmbeddingModel,
        expander: QueryExpander,
        reranker_service: RerankingService,
    ) -> None:
        self._embedder = embedder
        self._expander = expander
        self._reranker = reranker_service

    async def _fetch_tenant_glossary_terms(
        self,
        session: AsyncSession,
        tenant_id: uuid.UUID,
    ) -> list[str]:
        """Retrieve all active glossary terms for the tenant to protect during expansion."""
        stmt = (
            select(GlossaryEntry.term)
            .where(
                GlossaryEntry.tenant_id == tenant_id,
                GlossaryEntry.is_active.is_(True),
            )
        )
        result = await session.execute(stmt)
        return [row[0] for row in result.all()]

    async def search(
        self,
        session: AsyncSession,
        request: QueryRequest,
    ) -> RetrievalResponse:
        """Execute end-to-end permission-filtered hybrid search.

        1. Fetches tenant glossary terms for translation preservation.
        2. Expands query into multilingual variants (original + translated).
        3. Fans out concurrent lexical and dense vector database queries.
        4. Merges multi-modal results via Reciprocal Rank Fusion (k=60).
        5. Performs cross-encoder reranking on top preliminary candidates.
        6. Scales final scores using Source Authority Tiers and prunes thresholds.
        """
        start_time = time.perf_counter()

        # 1. Fetch protected terms for the tenant
        glossary_terms = await self._fetch_tenant_glossary_terms(
            session=session,
            tenant_id=request.tenant_id,
        )

        # 2. Query expansion
        expansion = await self._expander.expand_query(
            query=request.query,
            target_language=request.target_language,
            protected_terms=glossary_terms,
        )

        # 3. Dense query embeddings for all expanded query variants
        embed_tasks = [self._embedder.embed_query(q) for q in expansion.queries]
        query_vectors: list[list[float]] = await asyncio.gather(*embed_tasks)

        # 4. Concurrent Search Execution across all query variations
        search_tasks = []

        # Lexical search tasks
        for q in expansion.queries:
            search_tasks.append(
                execute_lexical_search(
                    session=session,
                    query=q,
                    tenant_id=request.tenant_id,
                    community_ids=request.community_ids,
                    limit=request.top_k,
                )
            )

        # Vector search tasks
        for vec in query_vectors:
            search_tasks.append(
                execute_vector_search(
                    session=session,
                    query_vector=vec,
                    tenant_id=request.tenant_id,
                    community_ids=request.community_ids,
                    limit=request.top_k,
                )
            )

        # Execute all retrieval queries concurrently
        search_results = await asyncio.gather(*search_tasks)

        # Separate results back into lexical and vector candidate pools
        num_queries = len(expansion.queries)
        lexical_candidate_lists = search_results[:num_queries]
        vector_candidate_lists = search_results[num_queries:]

        # Flatten while preserving unique chunks per modality
        all_lexical: list[CandidateChunk] = []
        for cand_list in lexical_candidate_lists:
            all_lexical.extend(cand_list)

        all_vector: list[CandidateChunk] = []
        for cand_list in vector_candidate_lists:
            all_vector.extend(cand_list)

        total_scanned = len(all_lexical) + len(all_vector)

        # 5. Reciprocal Rank Fusion
        fused_candidates = merge_candidate_rankings(
            lexical_candidates=all_lexical,
            vector_candidates=all_vector,
            k=60,
        )

        # Take preliminary pool for cross-encoder scoring
        preliminary_pool = fused_candidates[: request.top_k]

        # 6. Cross-Encoder Reranking (with automatic fallback to RRF)
        reranked_candidates = await self._reranker.rerank_candidates(
            query=request.query,
            candidates=preliminary_pool,
            top_n=request.top_k,
        )

        # 7. Source Authority Weighting & Cutoff Filtering
        # If reranking was successful, scale rerank_score; otherwise scale rrf_score
        has_rerank_scores = any(c.rerank_score is not None for c in reranked_candidates)
        final_candidates = apply_authority_weighting(
            candidates=reranked_candidates,
            use_rerank_score=has_rerank_scores,
            min_authority_threshold=request.min_authority_threshold,
        )

        # 8. Slicing to requested rerank_top_n
        top_candidates = final_candidates[: request.rerank_top_n]
        elapsed_ms = (time.perf_counter() - start_time) * 1000.0

        return RetrievalResponse(
            original_query=request.query,
            expanded_queries=expansion.queries,
            detected_language=expansion.detected_language,
            candidates=top_candidates,
            execution_time_ms=round(elapsed_ms, 2),
            total_candidates_scanned=total_scanned,
        )