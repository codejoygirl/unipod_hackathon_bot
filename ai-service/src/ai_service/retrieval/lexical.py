"""PostgreSQL full-text lexical search implementation using tsvector and ts_rank_cd."""

from collections.abc import Sequence
import uuid
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.models.chunk import KnowledgeChunk
from ai_service.models.source import KnowledgeSource
from ai_service.retrieval.predicates import build_retrieval_predicates
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


async def execute_lexical_search(
    session: AsyncSession,
    query: str,
    tenant_id: uuid.UUID,
    community_ids: Sequence[uuid.UUID],
    limit: int = 25,
) -> list[CandidateChunk]:
    """Execute keyword search via PostgreSQL tsvector matching and ts_rank_cd scoring.

    Args:
        session: Active asynchronous database session.
        query: Raw search query string.
        tenant_id: Tenant identifier for data isolation.
        community_ids: Authorized community identifiers.
        limit: Maximum number of ranked chunks to return.

    Returns:
        List of CandidateChunk instances ranked by lexical relevance.
    """
    cleaned_query = query.strip()
    if not cleaned_query:
        return []

    # 'simple' configuration ensures language-agnostic token matching
    ts_query = func.plainto_tsquery("simple", cleaned_query)
    rank_score = func.ts_rank_cd(KnowledgeChunk.tsv, ts_query).label("lexical_score")

    predicates = build_retrieval_predicates(
        tenant_id=tenant_id,
        community_ids=community_ids,
        active_sources_only=True,
    )

    stmt = (
        select(KnowledgeChunk, KnowledgeSource, rank_score)
        .join(KnowledgeSource, KnowledgeChunk.source_id == KnowledgeSource.id)
        .where(predicates)
        .where(KnowledgeChunk.tsv.op("@@")(ts_query))
        .order_by(rank_score.desc())
        .limit(limit)
    )

    result = await session.execute(stmt)
    rows = result.all()

    candidates: list[CandidateChunk] = []
    for rank, (chunk, source, score) in enumerate(rows, start=1):
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
                lexical_rank=rank,
                lexical_score=float(score),
                vector_rank=None,
                vector_distance=None,
                rrf_score=0.0,
                rerank_score=None,
                final_score=0.0,
            )
        )

    return candidates