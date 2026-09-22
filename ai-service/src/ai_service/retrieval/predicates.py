"""Hard SQL predicate builders enforcing tenant isolation and community access boundaries."""

from collections.abc import Sequence
from sqlalchemy import BinaryExpression, and_

from ai_service.models.chunk import KnowledgeChunk
from ai_service.models.source import KnowledgeSource


def build_retrieval_predicates(
    tenant_id: str,
    community_ids: Sequence[str],
    active_sources_only: bool = True,
) -> BinaryExpression:
    """Build composite SQL WHERE conditions for strict data isolation."""
    if not community_ids:
        raise ValueError("At least one authorized community_id must be provided.")

    predicates = [
        KnowledgeChunk.tenant_id == tenant_id,
        KnowledgeSource.tenant_id == tenant_id,
        KnowledgeChunk.community_id.in_(list(community_ids)),
        KnowledgeSource.community_id.in_(list(community_ids)),
    ]

    if active_sources_only:
        predicates.append(KnowledgeSource.status == "active")

    return and_(*predicates)
