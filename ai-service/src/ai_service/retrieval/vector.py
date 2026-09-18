"""Dense vector retrieval engine using pgvector cosine distance (<=>)."""

from collections.abc import Sequence
import uuid
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.models.chunk import KnowledgeChunk
from ai_service.models.source import KnowledgeSource
from ai_service.retrieval.predicates import build_retrieval_predicates
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


async def execute_vector_search(
    session: AsyncSession,
    query_vector: list[float],
    tenant_id: uuid.UUID,
    community_ids: Sequence[uuid.UUID],
    limit: int = 25,
) -> list[CandidateChunk]:
    """Execute dense vector similarity search via pgvector cosine distance.

    Args:
        session: Active asynchronous database session.
        query_vector: Normalized floating-point query embedding.
        tenant_id: Tenant identifier for data isolation.
        community_ids: Authorized community identifiers.
        limit: Maximum number of candidate chunks to return.

    Returns:
        List of CandidateChunk instances ranked by cosine proximity.
    """
    if not query_vector:
        return []

    # pgvector cosine distance operator: <=>
    distance_expr = KnowledgeChunk.embedding.cosine_distance(query_vector).label("distance")

    predicates = build_retrieval_predicates(
        tenant_id=tenant_id,
        community_ids=community_ids,
        active_sources_only=True,
    )

    stmt = (
        select(KnowledgeChunk, KnowledgeSource, distance_expr)
        .join(KnowledgeSource, KnowledgeChunk.source_id == KnowledgeSource.id)
        .where(predicates)
        .order_by(distance_expr.asc())
        .limit(limit)
    )

    result = await session.execute(stmt)
    rows = result.all()

    candidates: list[CandidateChunk] = []
    for rank, (chunk, source, dist) in enumerate(rows, start=1):
        raw_community_id = source.metadata_.get("community_id")
        community_id = uuid.UUID(str(raw_community_id)) if raw_community_id else community_ids[0]
        authority_tier_raw = source.metadata_.get(
            "authority_tier", AuthorityTier.COMMUNITY_DISCUSSION.value
        )

        candidates.append(
            CandidateChunk(
                chunk_id=chunk.id,
                source_id=chunk.source_id,
                version_id=chunk.version_id,
                content=chunk.content,
                token_count=chunk.token_count,
                breadcrumbs=chunk.breadcrumbs,
                authority_tier=AuthorityTier(authority_tier_raw),
                source_type=source.source_type,
                community_id=community_id,
                lexical_rank=None,
                lexical_score=None,
                vector_rank=rank,
                vector_distance=float(dist),
                rrf_score=0.0,
                rerank_score=None,
                final_score=0.0,
            )
        )

    return candidates