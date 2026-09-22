import uuid
import pytest
from sqlalchemy.dialects import postgresql

from ai_service.retrieval.predicates import build_retrieval_predicates


def test_build_retrieval_predicates_structure():
    tenant_id = uuid.uuid4()
    community_id = uuid.uuid4()

    predicate = build_retrieval_predicates(
        tenant_id=tenant_id,
        community_ids=[community_id],
        active_sources_only=True,
    )

    # Compile the expression to a PostgreSQL SQL string
    compiled_sql = str(
        predicate.compile(
            dialect=postgresql.dialect(),
            compile_kwargs={"literal_binds": True},
        )
    )

    assert str(tenant_id) in compiled_sql
    assert str(community_id) in compiled_sql
    assert "knowledge_sources.status = 'active'" in compiled_sql


def test_build_retrieval_predicates_empty_communities_raises():
    tenant_id = uuid.uuid4()
    with pytest.raises(ValueError, match="At least one authorized community_id"):
        build_retrieval_predicates(
            tenant_id=tenant_id,
            community_ids=[],
        )