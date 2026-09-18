import uuid
import pytest
from sqlalchemy.dialects import postgresql
from ai_service.retrieval.predicates import build_retrieval_predicates


def test_hard_tenant_isolation_in_predicates():
    """Verify that tenant and community boundaries are hardcoded into SQL expressions."""
    tenant_a = uuid.uuid4()
    community_1 = uuid.uuid4()

    predicate = build_retrieval_predicates(
        tenant_id=tenant_a,
        community_ids=[community_1],
        active_sources_only=True,
    )

    compiled = str(
        predicate.compile(
            dialect=postgresql.dialect(),
            compile_kwargs={"literal_binds": True},
        )
    )

    # Predicate MUST assert chunk tenant and source tenant matches tenant_a
    assert f"knowledge_chunks.tenant_id = '{tenant_a}'" in compiled
    assert f"knowledge_sources.tenant_id = '{tenant_a}'" in compiled
    # Predicate MUST filter by community_1
    assert str(community_1) in compiled