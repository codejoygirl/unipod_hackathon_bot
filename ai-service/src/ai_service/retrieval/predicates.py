"""Hard SQL predicate builders enforcing tenant isolation and community access boundaries."""

from collections.abc import Sequence
import uuid
from sqlalchemy import BinaryExpression, and_

from ai_service.models.chunk import KnowledgeChunk
from ai_service.models.source import KnowledgeSource


def build_retrieval_predicates(
    tenant_id: uuid.UUID,
    community_ids: Sequence[uuid.UUID],
    active_sources_only: bool = True,
) -> BinaryExpression:
    """Build composite SQL WHERE conditions for strict data isolation.

    Guarantees:
    1. Cross-tenant leakage is impossible at the query level.
    2. Chunks are restricted to the caller's authorized community IDs.
    3. Archived or failed source documents are excluded from search results.
    """
    if not community_ids:
        raise ValueError("At least one authorized community_id must be provided.")

    predicates = [
        KnowledgeChunk.tenant_id == tenant_id,
        KnowledgeSource.tenant_id == tenant_id,
        # Check source-level community assignment stored in source metadata
        KnowledgeSource.metadata_["community_id"].as_string().in_(
            [str(cid) for cid in community_ids]
        ),
    ]

    if active_sources_only:
        predicates.append(KnowledgeSource.status == "active")

    return and_(*predicates)