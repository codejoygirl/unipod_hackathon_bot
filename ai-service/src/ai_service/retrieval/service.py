"""High-level retrieval service orchestrating query processing and search."""

import uuid
from sqlalchemy.ext.asyncio import AsyncSession
from ai_service.retrieval.query_processor import QueryProcessor
from ai_service.retrieval.hybrid_store import execute_hybrid_search
from ai_service.retrieval.reranker import ReRankerPipeline
from ai_service.schemas.retrieval import QueryRequest, RetrievalResponse, CandidateChunk
import time
import re

class HybridRetrievalService:
    """Wrapper that orchestrates query, search, and reranking."""
    
    def __init__(self, embedder, expander=None, reranker_service=None):
        self.embedder = embedder
        
    async def search(self, session: AsyncSession, request: QueryRequest) -> RetrievalResponse:
        start_time = time.perf_counter()
        
        # Sanitizer could be called here or earlier
        
        query_bundle = await QueryProcessor.process_query(request.query)
        
        # Format TS vector query using proper pg syntax (no | in simple terms usually but we'll extract words)
        words = re.findall(r'\w+', query_bundle.original_query)
        ts_query_string = " & ".join(words) if words else query_bundle.original_query
        
        # Get true embedding
        query_vector = await self.embedder.embed_query(query_bundle.original_query)
        
        candidates = await execute_hybrid_search(
            session=session,
            tenant_id=request.tenant_id,
            community_ids=request.community_ids,
            ts_query_string=ts_query_string,
            query_vector=query_vector,
            limit=request.top_k,
            k=60
        )
        
        reranked = await ReRankerPipeline.rerank(request.query, candidates, top_n=request.rerank_top_n)
        elapsed_ms = (time.perf_counter() - start_time) * 1000.0
        
        return RetrievalResponse(
            original_query=query_bundle.original_query,
            expanded_queries=query_bundle.expanded_queries,
            detected_language=query_bundle.detected_language,
            candidates=reranked,
            execution_time_ms=elapsed_ms,
            total_candidates_scanned=len(candidates)
        )
