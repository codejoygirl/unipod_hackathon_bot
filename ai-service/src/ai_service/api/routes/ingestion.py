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
    response_model=IngestionResponse,
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="Ingest multimodal media or text (file and/or content)",
)
async def ingest_multimodal_file(
    tenant_id: str = Form(...),
    community_id: str = Form(...),
    source_type: str = Form(...),
    name: str = Form(...),
    uri: str = Form(...),
    authority_tier: str = Form("community_discussion"),
    content: str | None = Form(None),
    file: UploadFile | None = File(None),
    session: AsyncSession = Depends(get_db_session),
    pipeline: IngestionPipeline = Depends(get_ingestion_pipeline),
) -> IngestionResponse:
    """Ingest image/audio/video/text via multipart form.

    Provide either ``file`` and/or ``content`` (plain text). Text-like
    ``source_type`` values (text, markdown, whatsapp, transcript) decode
    uploaded files as UTF-8 instead of base64.
    """
    try:
        text_types = {"text", "markdown", "whatsapp", "transcript", "txt", "md"}
        normalized_type = source_type.strip().lower()
        metadata: dict = {}

        if file is not None:
            raw = await file.read()
            metadata["filename"] = file.filename
            metadata["mime_type"] = file.content_type
            if normalized_type in text_types:
                body = raw.decode("utf-8", errors="replace")
            else:
                import base64

                body = base64.b64encode(raw).decode("utf-8")
        elif content is not None and content.strip():
            body = content
            if normalized_type not in text_types:
                normalized_type = "text"
        else:
            raise ValueError("Provide either a file or non-empty content.")

        request = IngestionRequest(
            tenant_id=tenant_id,
            community_id=community_id,
            name=name,
            uri=uri,
            source_type=normalized_type,
            content=body,
            authority_tier=AuthorityTier(authority_tier),
            metadata=metadata,
        )

        return await pipeline.ingest_document(session=session, request=request)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc
    except Exception as exc:
        logger.error("Failed to ingest multimodal document '%s': %s", name, str(exc), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="An error occurred while processing multimodal ingestion.",
        ) from exc