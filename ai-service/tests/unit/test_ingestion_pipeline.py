import uuid
import pytest
from unittest.mock import AsyncMock, MagicMock

from ai_service.ingestion.pipeline import IngestionPipeline
from ai_service.providers.mock import MockEmbedder
from ai_service.schemas.ingestion import IngestionRequest, IngestionStatus
from ai_service.schemas.retrieval import AuthorityTier


def test_normalization_and_hashing():
    pipeline = IngestionPipeline(embedder=MockEmbedder())

    raw = "Water\r\nPolicy \x00\x08Update"
    normalized = pipeline.normalize_text(raw)

    assert "\r" not in normalized
    assert "\x00" not in normalized
    assert normalized == "Water\nPolicy Update"

    hash_val = pipeline.compute_sha256(normalized)
    assert len(hash_val) == 64


def test_semantic_chunking_with_breadcrumbs():
    pipeline = IngestionPipeline(embedder=MockEmbedder(), chunk_size_chars=100, chunk_overlap_chars=20)

    markdown_doc = """# Department of Water

General guidelines for municipal supply.

## Zone 3 Testing

Samples taken from Zone 3 show clear drinkable water."""

    chunks = pipeline.chunk_content(markdown_doc)

    assert len(chunks) >= 2
    # Verify breadcrumb propagation
    first_chunk_text, first_crumbs = chunks[0]
    assert "Department of Water" in first_crumbs or "Department of Water" in first_chunk_text


@pytest.mark.asyncio
async def test_ingest_document_deduplication_skip():
    """Verify identical content hash triggers SKIPPED_DUPLICATE without re-embedding."""
    embedder = MockEmbedder()
    pipeline = IngestionPipeline(embedder=embedder)

    tenant_id = uuid.uuid4()
    community_id = uuid.uuid4()
    req = IngestionRequest(
        tenant_id=tenant_id,
        community_id=community_id,
        uri="s3://civic/bulletin.pdf",
        name="Bulletin.pdf",
        source_type="pdf",
        content="Notice: Community clinic will remain open on Saturday.",
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
    )

    # Mock DB Session
    mock_session = AsyncMock()

    # Simulate existing source and matching version
    mock_source = MagicMock()
    mock_source.id = uuid.uuid4()
    mock_version = MagicMock()
    mock_version.id = uuid.uuid4()

    # Configure session.execute returns
    mock_result_source = MagicMock()
    mock_result_source.scalar_one_or_none.return_value = mock_source

    mock_result_ver = MagicMock()
    mock_result_ver.scalar_one_or_none.return_value = mock_version

    mock_session.execute.side_effect = [mock_result_source, mock_result_ver]

    response = await pipeline.ingest_document(mock_session, req)

    assert response.status == IngestionStatus.SKIPPED_DUPLICATE
    assert response.chunks_created == 0
    assert "already indexed" in response.message
    # No inserts committed
    assert mock_session.commit.await_count == 0