import uuid
from unittest.mock import AsyncMock, MagicMock
import pytest
from sqlalchemy import text
from ai_service.retrieval.hybrid_store import execute_hybrid_search

@pytest.mark.asyncio
async def test_cross_tenant_leakage_sql_enforcement():
    """Verify that execute_hybrid_search explicitly filters by tenant_id and community_ids."""
    mock_session = AsyncMock()
    mock_result = MagicMock()
    mock_result.all.return_value = []
    mock_session.execute.return_value = mock_result
    
    tenant_a = uuid.uuid4()
    community_1 = uuid.uuid4()
    
    await execute_hybrid_search(
        session=mock_session,
        tenant_id=tenant_a,
        community_ids=[community_1],
        ts_query_string="test",
        query_vector=[0.1, 0.2, 0.3],
        limit=10,
    )
    
    # Extract the sql statement passed to session.execute
    call_args = mock_session.execute.call_args
    assert call_args is not None, "session.execute was not called"
    
    sql_text_obj = call_args[0][0]
    sql_params = call_args[0][1]
    
    sql_string = sql_text_obj.text
    
    # The raw SQL MUST explicitly bound the queries by tenant_id for both chunks and sources
    assert "WHERE kc.tenant_id = :tenant_id" in sql_string
    assert "AND ks.tenant_id = :tenant_id" in sql_string
    assert "AND kc.metadata->>'community_id' = ANY(:community_ids)" in sql_string
    
    # Parameters must be correctly bound
    assert sql_params["tenant_id"] == tenant_a
    assert str(community_1) in sql_params["community_ids"]
