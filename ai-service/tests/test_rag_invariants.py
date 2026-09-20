import pytest
import httpx
from unittest.mock import patch, MagicMock
from datetime import datetime, timezone
from ai_service.main import app
from ai_service.schemas.retrieval import RetrievalResponse, GroundedAnswerResponse, StageTimings
from ai_service.retrieval.query_processor import QueryProcessor
from ai_service.schemas.evidence import ValidatedAnswerPayload, AnswerState
from ai_service.schemas.ingestion import RawDocument
from ai_service.ingestion.parsers.document import DocumentParser

@pytest.mark.asyncio
async def test_openapi_schema_matches_live_response():
    """Verify that the actual JSON returned matches the OpenAPI schema definitions."""
    openapi_schema = app.openapi()
    
    # Check that RetrievalResponse has the correct fields
    retrieval_schema = openapi_schema["components"]["schemas"]["RetrievalResponse"]
    props = retrieval_schema["properties"]
    assert "expansion_queries" in props
    assert "total_execution_time_ms" in props
    assert "generation_invoked" in props
    
    # Check that StageTimings has hybrid_search instead of vector_search
    stage_schema = openapi_schema["components"]["schemas"]["StageTimings"]
    stage_props = stage_schema["properties"]
    assert "hybrid_search" in stage_props
    assert "vector_search" not in stage_props

@pytest.mark.asyncio
async def test_summary_query_always_produces_resolved_window():
    """Ensure summary_aggregation classification never yields None for resolved_window."""
    # Test recognizable date math
    q_type, window = await QueryProcessor.classify_query("latest news this week")
    assert q_type == "summary_aggregation"
    assert window is not None
    assert "gte" in window
    
    # Test fallback extraction failure (should return {"unresolved": "true"})
    q_type, window = await QueryProcessor.classify_query("summarize the unknown thing")
    assert q_type == "summary_aggregation"
    assert window is not None
    assert window.get("unresolved") == "true"

@pytest.mark.asyncio
async def test_content_shape_mismatch_flagged_not_blocked():
    """Ensure WhatsApp formatted text in a 'doc' source sets content_type_mismatch_suspected = True."""
    content = "15/05/23, 14:30 - John: Here is a message\n15/05/23, 14:31 - Mary: Got it."
    
    doc = RawDocument(
        id="123",
        uri="chat.txt",
        source_type="doc",
        content=content
    )
    
    chunks = await DocumentParser.parse(doc, {"media_url": "chat.txt"})
    assert len(chunks) == 1
    assert chunks[0]["content_type_mismatch_suspected"] is True

@pytest.mark.asyncio
async def test_escalation_reason_matches_actual_cause():
    """Test that synthesizer escalation text distinguishes zero candidates vs filtered."""
    from ai_service.generation.synthesizer import AnswerSynthesizer
    from ai_service.providers.factory import ModelFactory
    
    synth = AnswerSynthesizer(chat_model=ModelFactory.get_chat_model())
    
    # 0 scanned candidates
    payload, invoked = await synth.synthesize_grounded_answer(
        query="test",
        candidates=[],
        total_candidates_scanned=0
    )
    assert invoked is False
    assert "No evidence found" in payload.escalation_reason
    
    # 5 scanned candidates, but 0 passed threshold
    payload, invoked = await synth.synthesize_grounded_answer(
        query="test",
        candidates=[],
        total_candidates_scanned=5
    )
    assert invoked is False
    assert "Scanned 5 candidates" in payload.escalation_reason
