"""High-level retrieval service orchestrating query processing and search."""

import uuid
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func
from datetime import datetime
from ai_service.retrieval.query_processor import QueryProcessor
from ai_service.retrieval.hybrid_store import execute_hybrid_search
from ai_service.retrieval.reranker import ReRankerPipeline
from ai_service.schemas.retrieval import QueryRequest, RetrievalResponse, CandidateChunk, StageTimings
from ai_service.models.source import KnowledgeSource
import time
import re

class HybridRetrievalService:
    """Wrapper that orchestrates query, search, and reranking."""
    
    def __init__(self, embedder, expander=None, reranker_service=None):
        self.embedder = embedder
        self.expander = expander
        self.reranker_service = reranker_service
        
    async def search(self, session: AsyncSession, request: QueryRequest) -> RetrievalResponse:
        start_time = time.perf_counter()
        timings = {}
        
        # 1. Context Resolution
        t0 = time.perf_counter()
        resolved_query = await QueryProcessor.rewrite_contextual_query(request.query, request.chat_history)
        timings['context_resolution'] = (time.perf_counter() - t0) * 1000.0
        
        # 4. Temporal Classification
        t0 = time.perf_counter()
        query_type, resolved_window = await QueryProcessor.classify_query(resolved_query)
        
        if query_type == "summary_aggregation" and resolved_window is None:
            raise ValueError("summary_aggregation query must always return a resolved_window dictionary.")
            
        timings['classification'] = (time.perf_counter() - t0) * 1000.0
        
        # 3. Expansion
        t0 = time.perf_counter()
        expansion_validation_failures = 0
        expanded_queries = []
        detected_language = "unknown"
        if self.expander:
            expansion = await self.expander.expand_query(resolved_query, getattr(request, 'target_language', None))
            detected_language = expansion.detected_language
            
            for eq in expansion.queries:
                if re.search(r"\[[A-Z_]+\]", eq):
                    expansion_validation_failures += 1
                else:
                    expanded_queries.append(eq)
                    
            if expansion.queries and expansion_validation_failures == len(expansion.queries):
                import logging
                logging.getLogger(__name__).error("Expansion generator failed 100% validation. Check prompt or model health.")
                
            if not expanded_queries:
                expanded_queries = [resolved_query]
        else:
            expanded_queries = [resolved_query]
        timings['expansion'] = (time.perf_counter() - t0) * 1000.0
        
        # 4. Format TS vector query using proper pg syntax
        words = re.findall(r'\w+', resolved_query)
        ts_query_string = " & ".join(words) if words else resolved_query
        
        # 5. Embedding
        t0 = time.perf_counter()
        query_vector = await self.embedder.embed_query(resolved_query)
        timings['embedding'] = (time.perf_counter() - t0) * 1000.0
        
        # 6. Hybrid Search
        t0 = time.perf_counter()
        time_window_gte = resolved_window.get("gte") if resolved_window else None
        
        candidates = await execute_hybrid_search(
            session=session,
            tenant_id=request.tenant_id,
            community_ids=request.community_ids,
            ts_query_string=ts_query_string,
            query_vector=query_vector,
            limit=request.top_k,
            k=60,
            time_window_gte=time_window_gte
        )
        # Vector and Lexical run concurrently in the CTEs.
        # ADR: We collapse vector and lexical search execution times into a single `hybrid_search` metric.
        # We explicitly choose NOT to split the RRF fusion into separate Python round-trips to measure them independently, 
        # as doing so would duplicate the permission filter WHERE clause, double the connection pool pressure, 
        # and introduce a read-consistency drift risk (snapshot drift between statements).
        timings['hybrid_search'] = (time.perf_counter() - t0) * 1000.0
        
        # 7. Rerank
        t0 = time.perf_counter()
        reranked = await ReRankerPipeline.rerank(resolved_query, candidates, top_n=request.rerank_top_n)
        timings['fusion_rerank'] = (time.perf_counter() - t0) * 1000.0
        
        total_execution_time_ms = (time.perf_counter() - start_time) * 1000.0
        
        # 8. Invariants & Status Check
        knowledge_freshness_gap = False
        retrieval_status = "ok"
        
        if len(candidates) == 0:
            if query_type == "summary_aggregation" and resolved_window:
                max_ts_stmt = select(func.max(KnowledgeSource.updated_at)).where(
                    KnowledgeSource.tenant_id == request.tenant_id,
                    KnowledgeSource.status == 'active'
                )
                max_ts_res = await session.execute(max_ts_stmt)
                max_ts = max_ts_res.scalar()
                
                if max_ts:
                    bound = datetime.fromisoformat(time_window_gte) if time_window_gte else None
                    if bound and max_ts < bound:
                        knowledge_freshness_gap = True
                        resolved_window["actual_latest_timestamp"] = max_ts.isoformat()
                        retrieval_status = "stale_knowledge"
                    else:
                        retrieval_status = "pipeline_failure"
                else:
                    retrieval_status = "no_data"
            else:
                retrieval_status = "no_data"
                
        if retrieval_status == "ok" and len(candidates) == 0:
            raise ValueError("Zero candidates returned as 'ok'.")
            
        stage_timings = StageTimings(
            context_resolution=timings.get('context_resolution', 0.0),
            classification=timings.get('classification', 0.0),
            expansion=timings.get('expansion', 0.0),
            embedding=timings.get('embedding', 0.0),
            hybrid_search=timings.get('hybrid_search', 0.0),
            fusion_rerank=timings.get('fusion_rerank', 0.0),
            generation=0.0,
            external_fallback=None
        )
        
        sum_timings = sum([v for v in stage_timings.model_dump().values() if v])
        if total_execution_time_ms > 2000.0 and sum_timings < (total_execution_time_ms * 0.5):
            raise RuntimeError(f"Anomalous execution: total time {total_execution_time_ms}ms but only {sum_timings}ms accounted for in stages.")
        
        return RetrievalResponse(
            original_query=request.query,
            resolved_query=resolved_query,
            query_type=query_type,
            expansion_queries=expanded_queries,
            expansion_validation_failures=expansion_validation_failures,
            detected_language=detected_language,
            candidates=reranked,
            retrieval_status=retrieval_status,
            total_candidates_scanned=len(candidates),
            knowledge_freshness_gap=knowledge_freshness_gap,
            resolved_window=resolved_window,
            stage_timings_ms=stage_timings,
            total_execution_time_ms=total_execution_time_ms
        )
