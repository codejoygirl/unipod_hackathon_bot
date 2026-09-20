import pytest
from ai_service.core.token_budget import TokenBudgeter, SemanticCompressor
from ai_service.schemas.evidence import EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier
from ai_service.core.config import settings
import uuid

@pytest.fixture
def mock_chunks():
    chunks = []
    # Create chunks that are roughly 25 tokens each (100 chars)
    for i in range(10):
        chunks.append(
            EvidenceChunk(
                evidence_id=f"E{i}",
                chunk_id=uuid.uuid4(),
                source_id=uuid.uuid4(),
                source_name=f"Doc {i}",
                source_uri=f"file://{i}",
                source_type="text",
                content="A" * 100,
                breadcrumbs=[],
                authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
                retrieval_score=0.9 - (i * 0.01)
            )
        )
    return chunks

def test_token_estimation():
    text = "This is a simple text with approximately 65 characters to measure."
    tokens = TokenBudgeter.estimate_tokens(text)
    assert tokens == 16  # 66 // 4 = 16

def test_semantic_compressor_prunes_correctly(mock_chunks, monkeypatch):
    # Force max context to be exactly large enough for 2 chunks + padding + prompts
    # 2 chunks * (25 + 20) = 90 tokens
    
    monkeypatch.setattr(settings, "MAX_CONTEXT_TOKENS", 650)
    monkeypatch.setattr(settings, "MAX_COMPLETION_TOKENS", 10)
    monkeypatch.setattr(settings, "TOKEN_PADDING", 0)
    
    # Available = 650 - 10 - 0 - 500 (system) - 50 (user) = 90
    
    pruned = SemanticCompressor.prune_context(mock_chunks)
    assert len(pruned) == 2
    assert pruned[0].evidence_id == "E0"
    assert pruned[1].evidence_id == "E1"

def test_semantic_compressor_returns_empty_when_budget_negative(mock_chunks, monkeypatch):
    monkeypatch.setattr(settings, "MAX_CONTEXT_TOKENS", 500)
    monkeypatch.setattr(settings, "MAX_COMPLETION_TOKENS", 100)
    
    pruned = SemanticCompressor.prune_context(mock_chunks)
    assert len(pruned) == 0
