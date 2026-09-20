"""HTTP ingestion endpoints for synchronizing knowledge documents and chat transcripts."""

import logging
from fastapi import APIRouter, Depends, HTTPException, status, UploadFile, File, Form
from sqlalchemy.ext.asyncio import AsyncSession
import uuid
import json

from ai_service.api.dependencies import get_db_session, verify_hmac
from ai_service.ingestion.pipeline import IngestionPipeline
from ai_service.providers.factory import ModelFactory
from ai_service.schemas.ingestion import IngestionRequest, IngestionResponse
from ai_service.schemas.retrieval import AuthorityTier

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/ingestion", tags=["ingestion"])

# Global singleton pipeline
_pipeline = IngestionPipeline(embedder=ModelFactory.get_embedding_model())


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
    """Ingest document content into the tenant knowledge base."""
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


@router.post(
    "/multimodal",
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(verify_hmac)],
    summary="Ingest a multimodal file (image, audio, video) asynchronously",
)
async def ingest_multimodal_file(
    file: UploadFile = File(...),
    tenant_id: str = Form(...),
    community_id: str = Form(...),
    source_type: str = Form(...),
    name: str = Form(...),
    uri: str = Form(...),
    authority_tier: str = Form("community_discussion"),
    pipeline: IngestionPipeline = Depends(get_ingestion_pipeline),
):
    """Ingest a multimodal file asynchronously via a background task."""
    import tempfile
    import os
    import asyncio
    from ai_service.db.base import async_session_factory
    
    # Create a temporary file to hold the stream so we don't load gigabytes into RAM
    fd, temp_path = tempfile.mkstemp(suffix=f"_{file.filename}")
    try:
        with os.fdopen(fd, 'wb') as f:
            while chunk := await file.read(4 * 1024 * 1024):  # 4MB chunks
                f.write(chunk)
    except Exception as exc:
        os.unlink(temp_path)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to stream file to disk buffer."
        ) from exc
        
    job_id = str(uuid.uuid4())
    
    async def process_background_job():
        try:
            # We must use a fresh DB session for the background task
            async with async_session_factory() as bg_session:
                request = IngestionRequest(
                    tenant_id=uuid.UUID(tenant_id),
                    community_id=uuid.UUID(community_id),
                    name=name,
                    uri=temp_path, # Provide temp path instead of original URI for local parsing
                    source_type=source_type,
                    content="binary", # Parser will read from URI
                    authority_tier=AuthorityTier(authority_tier),
                    metadata={"filename": file.filename, "mime_type": file.content_type, "original_uri": uri}
                )
                
                await pipeline.ingest_document(session=bg_session, request=request)
        except Exception as e:
            logger.error("Background ingestion job %s failed: %s", job_id, str(e), exc_info=True)
            # In production, this error state should be logged to a jobs/DLQ table
        finally:
            if os.path.exists(temp_path):
                os.unlink(temp_path)

    # Dispatch to asyncio event loop
    asyncio.create_task(process_background_job())
    
    return {"status": "accepted", "job_id": job_id, "message": "File is processing in the background."}