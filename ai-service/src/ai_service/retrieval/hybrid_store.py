from collections.abc import Sequence
import re
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk

async def execute_hybrid_search(
    session: AsyncSession,
    tenant_id: str,
    community_ids: Sequence[str],
    ts_query_string: str,
    query_vector: list[float],
    limit: int = 25,
    k: int = 60,
    allowed_roles: list[str] | None = None,
) -> list[CandidateChunk]:
    """Execute hybrid search using a single raw SQL query with CTEs for RRF."""

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
          AND kc.community_id = ANY(CAST(:community_ids AS text[]))
          AND ks.community_id = ANY(CAST(:community_ids AS text[]))
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
          AND kc.community_id = ANY(CAST(:community_ids AS text[]))
          AND ks.community_id = ANY(CAST(:community_ids AS text[]))
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
        kc.community_id,
        ks.source_type,
        ks.uri AS source_uri,
        ks.name AS source_name
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
            "tenant_id": str(tenant_id),
            "community_ids": community_ids_str,
            "ts_query": ts_query_string,
            "query_vector": str(query_vector),
            "limit": limit,
            "k": k,
        },
    )

    candidates: list[CandidateChunk] = []
    for row in result.all():
        meta = row.metadata_ or {}
        authority_tier_raw = meta.get("authority_tier", AuthorityTier.COMMUNITY_DISCUSSION.value)
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
                source_uri=str(row.source_uri or ""),
                source_name=str(row.source_name or ""),
                community_id=str(row.community_id),
                lexical_rank=row.lexical_rank,
                lexical_score=float(row.lexical_score) if row.lexical_score is not None else None,
                vector_rank=row.vector_rank,
                vector_distance=float(row.vector_distance) if row.vector_distance is not None else None,
                rrf_score=float(row.rrf_score),
                rerank_score=None,
                final_score=float(row.rrf_score),
                media_type=meta.get("media_type"),
                media_url=locator.get("media_url"),
                timestamp_seconds=locator.get("timestamp_seconds"),
                message_at=locator.get("message_at") or meta.get("content_occurred_at"),
                message_end=locator.get("message_end") or meta.get("content_occurred_end"),
                bounding_box=locator.get("bounding_box"),
            )
        )

    return candidates


async def fetch_url_bearing_chunks(
    session: AsyncSession,
    tenant_id: str,
    community_ids: Sequence[str],
    *,
    recordings_only: bool = False,
    assets_only: bool = False,
    query: str | None = None,
    limit: int = 40,
) -> list[CandidateChunk]:
    """Permission-scoped recall of chunks that already contain openable https URLs.

    Used for link/recording asks so completeness does not depend on the model
    or on semantic retrieval alone. Same tenant/community ACL as hybrid search.
    """
    if recordings_only:
        url_predicate = """
          (
            kc.content ILIKE '%youtu.be/%'
            OR kc.content ILIKE '%youtube.com/%'
            OR kc.content ILIKE '%meetingrecap%'
            OR kc.content ILIKE '%drive.google.com/file/%'
            OR kc.content ILIKE '%stream.microsoft.com%'
            OR kc.content ILIKE '%vimeo.com/%'
          )
        """
    elif assets_only:
        # Programme files / forms / decks — not chat dumps of every YouTube link.
        url_predicate = """
          (
            kc.content ILIKE '%drive.google.com/%'
            OR kc.content ILIKE '%docs.google.com/%'
            OR kc.content ILIKE '%forms.gle/%'
            OR kc.content ILIKE '%forms.office.com/%'
            OR kc.content ILIKE '%.pdf%'
          )
        """
    else:
        url_predicate = "kc.content ~* 'https?://'"

    # Soft lexical prefer: rank chunks that also mention ask tokens higher.
    # Shape-only stopwords (not a language meaning catalog).
    order_sql = "kc.created_at DESC NULLS LAST, kc.id DESC"
    params: dict[str, object] = {
        "tenant_id": str(tenant_id),
        "community_ids": [str(cid) for cid in community_ids],
        "limit": limit,
    }
    ask_tokens = [
        t
        for t in re.findall(r"[a-zA-Z0-9']{4,}", (query or "").lower())
        if t
        not in {
            "send",
            "give",
            "please",
            "link",
            "links",
            "file",
            "files",
            "document",
            "documents",
            "with",
            "from",
            "this",
            "that",
            "have",
            "need",
            "want",
            "just",
            "only",
        }
    ][:6]
    if ask_tokens:
        # Sum of ILIKE hits as a cheap relevance bump for URL recall.
        hit_exprs = []
        for i, tok in enumerate(ask_tokens):
            key = f"tok{i}"
            params[key] = f"%{tok}%"
            hit_exprs.append(f"(CASE WHEN kc.content ILIKE :{key} THEN 1 ELSE 0 END)")
        order_sql = f"({'+'.join(hit_exprs)}) DESC, kc.created_at DESC NULLS LAST, kc.id DESC"

    sql = f"""
    SELECT
        kc.id AS chunk_id,
        kc.source_id,
        kc.version_id,
        kc.content,
        kc.token_count,
        kc.breadcrumbs,
        kc.metadata AS metadata_,
        kc.community_id,
        ks.source_type,
        ks.uri AS source_uri,
        ks.name AS source_name
    FROM knowledge_chunks kc
    JOIN knowledge_sources ks ON kc.source_id = ks.id
    WHERE kc.tenant_id = :tenant_id
      AND ks.tenant_id = :tenant_id
      AND ks.status = 'active'
      AND kc.community_id = ANY(CAST(:community_ids AS text[]))
      AND ks.community_id = ANY(CAST(:community_ids AS text[]))
      AND {url_predicate}
    ORDER BY {order_sql}
    LIMIT :limit
    """

    result = await session.execute(text(sql), params)

    candidates: list[CandidateChunk] = []
    for row in result.all():
        meta = row.metadata_ or {}
        authority_tier_raw = meta.get("authority_tier", AuthorityTier.COMMUNITY_DISCUSSION.value)
        locator = meta.get("locator", {})
        # Stable score at/above POSSIBLE floor so URL recall survives soft
        # cross-language hybrid ranks (Arabic/French asks vs English chunks).
        score = 0.72
        content_l = (row.content or "").lower()
        if ask_tokens:
            hits = sum(1 for tok in ask_tokens if tok in content_l)
            score = min(0.92, 0.68 + 0.05 * hits)

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
                source_uri=str(row.source_uri or ""),
                source_name=str(row.source_name or ""),
                community_id=str(row.community_id),
                lexical_rank=None,
                lexical_score=None,
                vector_rank=None,
                vector_distance=None,
                rrf_score=score,
                rerank_score=score,
                final_score=score,
                media_type=meta.get("media_type"),
                media_url=locator.get("media_url"),
                timestamp_seconds=locator.get("timestamp_seconds"),
                message_at=locator.get("message_at") or meta.get("content_occurred_at"),
                message_end=locator.get("message_end") or meta.get("content_occurred_end"),
                bounding_box=locator.get("bounding_box"),
            )
        )

    return candidates
