"""Asynchronous ingestion pipeline coordinating normalization, deduplication, chunking, and indexing."""

import hashlib
import re
import time
import unicodedata
import uuid
import base64
from sqlalchemy import select,func
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.core.logging import get_logger
from ai_service.models.chunk import KnowledgeChunk
from ai_service.models.source import KnowledgeSource
from ai_service.models.version import KnowledgeSourceVersion
from ai_service.providers.base import EmbeddingModel
from ai_service.schemas.ingestion import IngestionRequest, IngestionResponse, IngestionStatus, RawDocument

from ai_service.ingestion.multimodal import MultimodalProcessor
from ai_service.ingestion.chunking import ContextualChunker
from ai_service.ingestion.lifecycle import DocumentLifecycleManager

logger = get_logger(__name__)


class IngestionPipeline:
    """Orchestrates content normalization, deduplication, chunking, and dual-indexing."""

    def __init__(
        self,
        embedder: EmbeddingModel,
        chunk_size_chars: int = 800,
        chunk_overlap_chars: int = 150,
    ) -> None:
        self.embedder = embedder
        self.chunk_size = chunk_size_chars
        self.chunk_overlap = chunk_overlap_chars
        self.processor = MultimodalProcessor()

    @staticmethod
    def normalize_text(text: str) -> str:
        """Apply Unicode NFC normalization and clean non-printable characters."""
        if not text:
            return ""
        normalized = unicodedata.normalize("NFC", text)
        cleaned = re.sub(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]", "", normalized)
        return cleaned.replace("\r\n", "\n").replace("\r", "\n").strip()

    async def ingest_document(
        self,
        session: AsyncSession,
        request: IngestionRequest,
    ) -> IngestionResponse:
        """Execute atomic document ingestion using the MultimodalProcessor."""
        start_time = time.perf_counter()

        # Step 1: Normalize & Decode
        normalized_content = self.normalize_text(request.content)
        content_hash = DocumentLifecycleManager.generate_content_hash(normalized_content)
        
        if request.source_type in ("image", "audio", "video"):
            try:
                parse_content = base64.b64decode(request.content)
            except Exception:
                parse_content = request.content
        else:
            parse_content = normalized_content
            
        doc = RawDocument(
            id=str(uuid.uuid4()),
            uri=request.uri,
            source_type=request.source_type,
            content=parse_content if isinstance(parse_content, str) else "binary"
        )
        
        # Step 2: Process using Multimodal Processor
        processed_chunks = await self.processor.process_document(doc)

        # Step 3: Query for existing KnowledgeSource
        source_stmt = select(KnowledgeSource).where(
            KnowledgeSource.tenant_id == request.tenant_id,
            KnowledgeSource.uri == request.uri,
        )
        source_result = await session.execute(source_stmt)
        source = source_result.scalar_one_or_none()

        # Step 4: Duplicate check
        if source:
            ver_stmt = select(KnowledgeSourceVersion).where(
                KnowledgeSourceVersion.tenant_id == request.tenant_id,
                KnowledgeSourceVersion.source_id == source.id,
                KnowledgeSourceVersion.content_sha256 == content_hash,
            )
            ver_result = await session.execute(ver_stmt)
            existing_version = ver_result.scalar_one_or_none()

            if existing_version:
                elapsed_ms = (time.perf_counter() - start_time) * 1000.0
                return IngestionResponse(
                    source_id=source.id,
                    version_id=existing_version.id,
                    status=IngestionStatus.SKIPPED_DUPLICATE,
                    content_sha256=content_hash,
                    chunks_created=0,
                    message="Identical content hash already indexed.",
                    execution_time_ms=round(elapsed_ms, 2),
                )

            ver_count_stmt = select(func.count(KnowledgeSourceVersion.id)).where(
                KnowledgeSourceVersion.source_id == source.id
            )
            version_count = (await session.execute(ver_count_stmt)).scalar() or 0
            version_num = version_count + 1
        else:
            version_num = 1
            meta = dict(request.metadata or {})
            meta["community_id"] = str(request.community_id)
            meta["authority_tier"] = request.authority_tier.value

            source = KnowledgeSource(
                id=uuid.uuid4(),
                tenant_id=request.tenant_id,
                community_id=request.community_id,
                uri=request.uri,
                name=request.name,
                source_type=request.source_type,
                status="processing",
                metadata_=meta,
            )
            session.add(source)
            await session.flush()

        # Step 5: Create Version
        new_version = KnowledgeSourceVersion(
            id=uuid.uuid4(),
            tenant_id=request.tenant_id,
            source_id=source.id,
            version_number=version_num,
            content_sha256=content_hash,
            storage_path=request.uri,
            metadata_=request.metadata or {},
        )
        session.add(new_version)
        await session.flush()

        # Step 6: Chunk Document (if text).
        # Chat-export windows are already sized for retrieval — keep them intact.
        final_chunk_tuples = []
        for p_chunk in processed_chunks:
            media_type = p_chunk.get("media_type", "text")
            locator = p_chunk.get("locator", {})
            if media_type == "chat_window":
                crumbs = list(p_chunk.get("breadcrumbs") or ["chat"])
                if p_chunk.get("export_type"):
                    locator = {**locator, "export_type": p_chunk["export_type"]}
                final_chunk_tuples.append(
                    (p_chunk["content"], crumbs, locator, media_type)
                )
                continue

            sub_chunks = ContextualChunker.chunk(
                p_chunk["content"], max_tokens=self.chunk_size
            )
            for sc in sub_chunks:
                final_chunk_tuples.append(
                    (
                        sc["content"],
                        sc["breadcrumbs"],
                        locator,
                        media_type,
                    )
                )

        chunk_texts = [ct[0] for ct in final_chunk_tuples]
        if not chunk_texts:
            source.status = request.index_status
            await session.commit()
            elapsed_ms = (time.perf_counter() - start_time) * 1000.0
            return IngestionResponse(
                source_id=source.id,
                version_id=new_version.id,
                status=IngestionStatus.COMPLETED,
                content_sha256=content_hash,
                chunks_created=0,
                message="No indexable content remained after normalization.",
                execution_time_ms=round(elapsed_ms, 2),
            )

        # Step 7: Embeddings
        embeddings = await self.embedder.embed(chunk_texts)

        # Step 8: Persist Chunks
        created_chunks: list[KnowledgeChunk] = []
        for idx, ((c_text, crumbs, locator, media_type), emb) in enumerate(zip(final_chunk_tuples, embeddings, strict=True)):
            c_hash = DocumentLifecycleManager.generate_content_hash(c_text)
            token_count = max(1, len(c_text) // 4)
            
            chunk_meta = {
                "community_id": str(request.community_id),
                "authority_tier": request.authority_tier.value,
                "locator": locator,
                "media_type": media_type
            }

            k_chunk = KnowledgeChunk(
                id=uuid.uuid4(),
                tenant_id=request.tenant_id,
                community_id=request.community_id,
                source_id=source.id,
                version_id=new_version.id,
                chunk_index=idx,
                content=c_text,
                content_sha256=c_hash,
                token_count=token_count,
                breadcrumbs=crumbs,
                metadata_=chunk_meta,
                embedding=emb,
            )
            created_chunks.append(k_chunk)

        session.add_all(created_chunks)

        source_id = source.id
        version_id = new_version.id

        source.status = request.index_status
        await session.commit()

        elapsed_ms = (time.perf_counter() - start_time) * 1000.0
        return IngestionResponse(
            source_id=source_id,
            version_id=version_id,
            status=IngestionStatus.COMPLETED,
            content_sha256=content_hash,
            chunks_created=len(created_chunks),
            message=f"Successfully indexed {len(created_chunks)} chunks.",
            execution_time_ms=round(elapsed_ms, 2),
        )