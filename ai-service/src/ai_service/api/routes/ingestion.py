"""HTTP ingestion endpoints for synchronizing knowledge documents and chat transcripts."""

import hashlib
import logging
from fastapi import APIRouter, Depends, HTTPException, Request, status, UploadFile, File, Form
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
import uuid

from ai_service.api.dependencies import assert_multipart_hmac, get_db_session, verify_hmac
from ai_service.core.security import multipart_canonical_payload
from ai_service.ingestion.pipeline import IngestionPipeline
from ai_service.models.source import KnowledgeSource
from ai_service.providers.factory import ModelFactory
from ai_service.ingestion.purge import purge_knowledge
from ai_service.schemas.ingestion import (
    IngestionRequest,
    IngestionResponse,
    PurgeKnowledgeRequest,
    PurgeKnowledgeResponse,
)
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
    "/purge",
    response_model=PurgeKnowledgeResponse,
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="Purge knowledge sources, chunks, and embeddings for a scope",
)
async def purge_knowledge_endpoint(
    request: PurgeKnowledgeRequest,
    session: AsyncSession = Depends(get_db_session),
) -> PurgeKnowledgeResponse:
    """Destructive ops wipe of the AI index (called by ``php artisan zak:purge-knowledge``)."""
    try:
        return await purge_knowledge(session=session, request=request)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc
    except Exception as exc:
        logger.error("Failed to purge knowledge: %s", str(exc), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="An error occurred while purging knowledge.",
        ) from exc


@router.post(
    "/activate/{source_id}",
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="Mark a previously indexed source searchable (pending → active)",
)
async def activate_source(
    source_id: uuid.UUID,
    session: AsyncSession = Depends(get_db_session),
) -> dict:
    """Flip index status to active after Laravel publish approval."""
    result = await session.execute(
        select(KnowledgeSource).where(KnowledgeSource.id == source_id)
    )
    source = result.scalar_one_or_none()
    if source is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Source not found.")

    source.status = "active"
    await session.commit()
    return {"source_id": str(source.id), "status": source.status}


@router.post(
    "/multimodal",
    response_model=IngestionResponse,
    status_code=status.HTTP_200_OK,
    summary="Ingest multimodal media or text (file and/or content)",
)
async def ingest_multimodal_file(
    request: Request,
    tenant_id: str = Form(...),
    community_id: str = Form(...),
    source_type: str = Form(...),
    name: str = Form(...),
    uri: str = Form(...),
    authority_tier: str = Form("community_discussion"),
    index_status: str = Form("pending"),
    content: str | None = Form(None),
    file: UploadFile | None = File(None),
    session: AsyncSession = Depends(get_db_session),
    pipeline: IngestionPipeline = Depends(get_ingestion_pipeline),
) -> IngestionResponse:
    """Ingest image/audio/video/text via multipart form.

    Provide either ``file`` and/or ``content`` (plain text). Text-like
    ``source_type`` values (text, markdown, whatsapp, transcript) decode
    uploaded files as UTF-8 instead of base64.

    HMAC signs a canonical form+content digest (not an empty body).
    """
    try:
        text_types = {"text", "markdown", "whatsapp", "transcript", "txt", "md"}
        normalized_type = source_type.strip().lower()
        metadata: dict = {}
        raw_for_hash = b""

        if file is not None:
            raw = await file.read()
            raw_for_hash = raw
            metadata["filename"] = file.filename
            metadata["mime_type"] = file.content_type
            if normalized_type in text_types:
                body = raw.decode("utf-8", errors="replace")
            else:
                import base64

                body = base64.b64encode(raw).decode("utf-8")
        elif content is not None and content.strip():
            body = content
            raw_for_hash = content.encode("utf-8")
            if normalized_type not in text_types:
                normalized_type = "text"
        else:
            raise ValueError("Provide either a file or non-empty content.")

        content_sha256 = hashlib.sha256(raw_for_hash).hexdigest()
        canonical = multipart_canonical_payload(
            tenant_id=tenant_id,
            community_id=community_id,
            uri=uri,
            name=name,
            source_type=source_type,
            authority_tier=authority_tier,
            content_sha256=content_sha256,
            index_status=index_status,
        )
        assert_multipart_hmac(
            signature=request.headers.get("X-Signature"),
            timestamp=request.headers.get("X-Timestamp"),
            canonical_payload=canonical,
        )

        ingest_request = IngestionRequest(
            tenant_id=tenant_id,
            community_id=community_id,
            name=name,
            uri=uri,
            source_type=normalized_type,
            content=body,
            authority_tier=AuthorityTier(authority_tier),
            metadata=metadata,
            index_status=index_status,
        )

        return await pipeline.ingest_document(session=session, request=ingest_request)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc
    except HTTPException:
        raise
    except Exception as exc:
        logger.error("Failed to ingest multimodal document '%s': %s", name, str(exc), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="An error occurred while processing multimodal ingestion.",
        ) from exc
