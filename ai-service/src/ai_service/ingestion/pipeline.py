"""Asynchronous ingestion pipeline coordinating normalization, deduplication, chunking, and indexing."""

import hashlib
import re
import time
import unicodedata
import uuid
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.core.logging import get_logger
from ai_service.models.chunk import KnowledgeChunk
from ai_service.models.source import KnowledgeSource
from ai_service.models.version import KnowledgeSourceVersion
from ai_service.providers.base import EmbeddingModel
from ai_service.schemas.ingestion import IngestionRequest, IngestionResponse, IngestionStatus

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

    @staticmethod
    def normalize_text(text: str) -> str:
        """Apply Unicode NFC normalization and clean non-printable characters."""
        if not text:
            return ""
        # 1. Unicode NFC canonical decomposition followed by canonical composition
        normalized = unicodedata.normalize("NFC", text)
        # 2. Strip C0/C1 control characters (preserve tabs, newlines, carriage returns)
        cleaned = re.sub(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]", "", normalized)
        # 3. Standardize CRLF to LF
        return cleaned.replace("\r\n", "\n").replace("\r", "\n").strip()

    @staticmethod
    def compute_sha256(text: str) -> str:
        """Compute deterministic SHA-256 hexadecimal hash of content."""
        return hashlib.sha256(text.encode("utf-8")).hexdigest()

    def chunk_content(self, text: str) -> list[tuple[str, list[str]]]:
        """Split text into overlapping semantic windows while tracking header breadcrumbs.

        Returns:
            List of tuples: (chunk_content, breadcrumbs)
        """
        paragraphs = [p.strip() for p in text.split("\n\n") if p.strip()]
        chunks: list[tuple[str, list[str]]] = []
        current_breadcrumbs: list[str] = []

        current_chunk_text = ""

        for para in paragraphs:
            # Simple Markdown header detection for breadcrumbs
            if para.startswith("#"):
                header_title = para.lstrip("#").strip().split("\n")[0]
                level = len(para) - len(para.lstrip("#"))
                # Update breadcrumbs based on header level
                if level <= len(current_breadcrumbs):
                    current_breadcrumbs = current_breadcrumbs[: level - 1]
                current_breadcrumbs.append(header_title)

            if len(current_chunk_text) + len(para) + 2 <= self.chunk_size:
                current_chunk_text = f"{current_chunk_text}\n\n{para}".strip()
            else:
                if current_chunk_text:
                    chunks.append((current_chunk_text, list(current_breadcrumbs)))
                    # Carry over overlap
                    overlap_seed = current_chunk_text[-self.chunk_overlap :]
                    current_chunk_text = f"{overlap_seed}\n\n{para}".strip()
                else:
                    # Paragraph itself exceeds chunk_size, force slice
                    for i in range(0, len(para), self.chunk_size - self.chunk_overlap):
                        slice_text = para[i : i + self.chunk_size]
                        chunks.append((slice_text, list(current_breadcrumbs)))

        if current_chunk_text:
            chunks.append((current_chunk_text, list(current_breadcrumbs)))

        return chunks

    async def ingest_document(
        self,
        session: AsyncSession,
        request: IngestionRequest,
    ) -> IngestionResponse:
        """Execute atomic document ingestion.

        Guarantees:
        1. Exact duplicate versions within tenant are skipped.
        2. Chunks and embeddings are created atomically in a single transaction.
        3. Full-text search tsvector is populated automatically via persisted column.
        """
        start_time = time.perf_counter()

        # Step 1: Normalize & Compute Hash
        normalized_content = self.normalize_text(request.content)
        content_hash = self.compute_sha256(normalized_content)

        # Step 2: Query for existing KnowledgeSource
        source_stmt = select(KnowledgeSource).where(
            KnowledgeSource.tenant_id == request.tenant_id,
            KnowledgeSource.uri == request.uri,
        )
        source_result = await session.execute(source_stmt)
        source = source_result.scalar_one_or_none()

        # Step 3: Check for duplicate content version
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
                    message="Identical content hash already indexed for this source.",
                    execution_time_ms=round(elapsed_ms, 2),
                )
        else:
            # Create new KnowledgeSource
            meta = dict(request.metadata)
            meta["community_id"] = str(request.community_id)
            meta["authority_tier"] = request.authority_tier.value

            source = KnowledgeSource(
                id=uuid.uuid4(),
                tenant_id=request.tenant_id,
                uri=request.uri,
                name=request.name,
                source_type=request.source_type,
                status="processing",
                metadata_=meta,
            )
            session.add(source)
            await session.flush()

        # Step 4: Create new Version record
        version_num = len(source.versions) + 1 if source.versions else 1
        new_version = KnowledgeSourceVersion(
            id=uuid.uuid4(),
            tenant_id=request.tenant_id,
            source_id=source.id,
            version_number=version_num,
            content_sha256=content_hash,
            storage_path=request.uri,
            metadata_=request.metadata,
        )
        session.add(new_version)
        await session.flush()

        # Step 5: Chunk normalized content
        chunk_tuples = self.chunk_content(normalized_content)
        chunk_texts = [ct[0] for ct in chunk_tuples]

        # Step 6: Generate dense vector embeddings in batch
        embeddings = await self.embedder.embed(chunk_texts)

        # Step 7: Construct and persist KnowledgeChunk records
        created_chunks: list[KnowledgeChunk] = []
        for idx, ((c_text, crumbs), emb) in enumerate(zip(chunk_tuples, embeddings, strict=True)):
            c_hash = self.compute_sha256(c_text)
            # Token estimate: ~4 chars per token
            token_count = max(1, len(c_text) // 4)

            k_chunk = KnowledgeChunk(
                id=uuid.uuid4(),
                tenant_id=request.tenant_id,
                source_id=source.id,
                version_id=new_version.id,
                chunk_index=idx,
                content=c_text,
                content_sha256=c_hash,
                token_count=token_count,
                breadcrumbs=crumbs,
                metadata_={
                    "community_id": str(request.community_id),
                    "authority_tier": request.authority_tier.value,
                },
                embedding=emb,
            )
            created_chunks.append(k_chunk)

        session.add_all(created_chunks)

        # Step 8: Mark Source status active & commit
        source.status = "active"
        await session.commit()

        elapsed_ms = (time.perf_counter() - start_time) * 1000.0
        return IngestionResponse(
            source_id=source.id,
            version_id=new_version.id,
            status=IngestionStatus.COMPLETED,
            content_sha256=content_hash,
            chunks_created=len(created_chunks),
            message=f"Successfully indexed {len(created_chunks)} chunks.",
            execution_time_ms=round(elapsed_ms, 2),
        )