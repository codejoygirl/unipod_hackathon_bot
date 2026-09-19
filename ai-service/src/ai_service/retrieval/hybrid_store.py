from collections.abc import Sequence
import uuid
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk

async def execute_hybrid_search(
    session: AsyncSession,
    tenant_id: uuid.UUID,
    community_ids: Sequence[uuid.UUID],
    ts_query_string: str,
    query_vector: list[float],
    limit: int = 25,
    k: int = 60,
    allowed_roles: list[str] = None
) -> list[CandidateChunk]:
    """Execute hybrid search using a single raw SQL query with CTEs for RRF."""
    
    # We must format the community_ids as an array literal for PostgreSQL ANY()
    # or pass it as a parameter that asyncpg can adapt to uuid[]
    
    sql = """
    WITH lexical_search AS (
        SELECT 
            kc.id,
            ts_rank_cd(kc.tsv, plainto_tsquery('simple', :ts_query)) AS lexical_score,
            ROW_NUMBER() OVER (ORDER BY ts_rank_cd(kc.tsv, plainto_tsquery('simple', :ts_query)) DESC) AS lexical_rank
        FROM knowledge_chunks kc
        JOIN knowledge_sources ks ON kc.source_id = ks.id
        WHERE kc.tenant_id = :tenant_id
          AND ks.tenant_id = :tenant_id
          AND ks.status = 'active'
          AND kc.metadata->>'community_id' = ANY(:community_ids)
          AND kc.tsv @@ plainto_tsquery('simple', :ts_query)
        ORDER BY lexical_score DESC
        LIMIT :limit
    ),
    vector_search AS (
        SELECT 
            kc.id,
            kc.embedding <=> CAST(:query_vector AS vector) AS vector_distance,
            ROW_NUMBER() OVER (ORDER BY kc.embedding <=> CAST(:query_vector AS vector) ASC) AS vector_rank
        FROM knowledge_chunks kc
        JOIN knowledge_sources ks ON kc.source_id = ks.id
        WHERE kc.tenant_id = :tenant_id
          AND ks.tenant_id = :tenant_id
          AND ks.status = 'active'
          AND kc.metadata->>'community_id' = ANY(:community_ids)
        ORDER BY vector_distance ASC
        LIMIT :limit
    ),
    fused_results AS (
        SELECT 
            COALESCE(l.id, v.id) as chunk_id,
            l.lexical_score,
            l.lexical_rank,
            v.vector_distance,
            v.vector_rank,
            (COALESCE(1.0 / (:k + l.lexical_rank), 0.0) + COALESCE(1.0 / (:k + v.vector_rank), 0.0)) AS rrf_score
        FROM lexical_search l
        FULL OUTER JOIN vector_search v ON l.id = v.id
    )
    SELECT 
        f.chunk_id,
        f.lexical_score,
        f.lexical_rank,
        f.vector_distance,
        f.vector_rank,
        f.rrf_score,
        kc.source_id,
        kc.version_id,
        kc.content,
        kc.token_count,
        kc.breadcrumbs,
        kc.metadata AS metadata_,
        ks.source_type
    FROM fused_results f
    JOIN knowledge_chunks kc ON kc.id = f.chunk_id
    JOIN knowledge_sources ks ON kc.source_id = ks.id
    ORDER BY f.rrf_score DESC
    LIMIT :limit;
    """
    
    community_ids_str = [str(cid) for cid in community_ids]
    
    result = await session.execute(
        text(sql),
        {
            "tenant_id": tenant_id,
            "community_ids": community_ids_str,
            "ts_query": ts_query_string,
            "query_vector": str(query_vector), 
            "limit": limit,
            "k": k,
        }
    )
    
    candidates: list[CandidateChunk] = []
    for row in result.all():
        meta = row.metadata_ or {}
        raw_community_id = meta.get("community_id")
        cid = uuid.UUID(str(raw_community_id)) if raw_community_id else community_ids[0]
        authority_tier_raw = meta.get("authority_tier", AuthorityTier.COMMUNITY_DISCUSSION.value)
        
        # Extract locator metadata
        locator = meta.get("locator", {})
        
        candidates.append(
            CandidateChunk(
                chunk_id=row.chunk_id,
                source_id=row.source_id,
                version_id=row.version_id,
                content=row.content,
                token_count=row.token_count,
                breadcrumbs=row.breadcrumbs,
                authority_tier=AuthorityTier(authority_tier_raw),
                source_type=row.source_type,
                community_id=cid,
                lexical_rank=row.lexical_rank,
                lexical_score=float(row.lexical_score) if row.lexical_score is not None else None,
                vector_rank=row.vector_rank,
                vector_distance=float(row.vector_distance) if row.vector_distance is not None else None,
                rrf_score=float(row.rrf_score),
                rerank_score=None,
                final_score=0.0,
                media_type=meta.get("media_type"),
                media_url=locator.get("media_url"),
                timestamp_seconds=locator.get("timestamp_seconds"),
                bounding_box=locator.get("bounding_box")
            )
        )
        
    return candidates
