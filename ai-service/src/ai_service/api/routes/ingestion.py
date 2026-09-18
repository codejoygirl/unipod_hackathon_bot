"""HTTP ingestion endpoints for synchronizing knowledge documents and chat transcripts."""

import logging
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.api.dependencies import get_db_session, verify_hmac
from ai_service.ingestion.pipeline import IngestionPipeline
from ai_service.providers.mock import MockEmbedder
from ai_service.schemas.ingestion import IngestionRequest, IngestionResponse

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/ingestion", tags=["ingestion"])

# Global singleton pipeline (swap MockEmbedder with OpenAIProvider/GeminiProvider in production)
_pipeline = IngestionPipeline(embedder=MockEmbedder(dimension=1536))


def get_ingestion_pipeline() -> IngestionPipeline:
    return _pipeline


@router.post(
    "/sync",
    response_model=IngestionResponse,
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="Synchronously ingest, chunk, embed, and index a document",
)
async def sync_ingest_document(
    request: IngestionRequest,
    session: AsyncSession = Depends(get_db_session),
    pipeline: IngestionPipeline = Depends(get_ingestion_pipeline),
) -> IngestionResponse:
    """Ingest document content into the tenant knowledge base.

    1. Applies Unicode NFC normalization.
    2. Performs SHA-256 deduplication check (short-circuits if version exists).
    3. Chunks text while preserving section breadcrumbs.
    4. Batch generates dense embeddings.
    5. Persists Source, Version, and Chunks in an atomic transaction.
    """
    try:
        return await pipeline.ingest_document(session=session, request=request)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc
    except Exception as exc:
        logger.error("Failed to ingest document '%s': %s", request.name, str(exc), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="An error occurred while processing document ingestion.",
        ) from exc