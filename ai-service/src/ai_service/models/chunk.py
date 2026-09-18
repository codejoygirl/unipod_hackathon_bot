import uuid
from typing import TYPE_CHECKING, Any
from pgvector.sqlalchemy import Vector
from sqlalchemy import Computed, ForeignKey, Index, Integer, String, Text, Uuid
from sqlalchemy.dialects.postgresql import JSONB, TSVECTOR
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ai_service.db.base import Base, TenantScopedMixin, TimestampMixin

if TYPE_CHECKING:
    from ai_service.models.source import KnowledgeSource
    from ai_service.models.version import KnowledgeSourceVersion

# Configured for OpenAI text-embedding-3-small or similar 1536-dim embeddings.
# Swap to 768 if targeting Gemini text-embedding-004.
VECTOR_DIMENSION = 1536


class KnowledgeChunk(Base, TenantScopedMixin, TimestampMixin):
    __tablename__ = "knowledge_chunks"

    id: Mapped[uuid.UUID] = mapped_column(
        Uuid(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    source_id: Mapped[uuid.UUID] = mapped_column(
        Uuid(as_uuid=True),
        ForeignKey("knowledge_sources.id", ondelete="CASCADE"),
        nullable=False,
    )
    version_id: Mapped[uuid.UUID] = mapped_column(
        Uuid(as_uuid=True),
        ForeignKey("knowledge_source_versions.id", ondelete="CASCADE"),
        nullable=False,
    )

    chunk_index: Mapped[int] = mapped_column(Integer, nullable=False)
    content: Mapped[str] = mapped_column(Text, nullable=False)
    content_sha256: Mapped[str] = mapped_column(String(64), nullable=False)
    token_count: Mapped[int] = mapped_column(Integer, nullable=False)

    # Hierarchical breadcrumbs: e.g., ["Chapter 2", "Section 1.3", "Permissions"]
    breadcrumbs: Mapped[list[str]] = mapped_column(
        JSONB, default=list, nullable=False
    )
    metadata_: Mapped[dict[str, Any]] = mapped_column(
        "metadata", JSONB, default=dict, nullable=False
    )

    # Dense Vector Representation
    embedding: Mapped[list[float]] = mapped_column(
        Vector(VECTOR_DIMENSION), nullable=False
    )

    # Lexical Search Representation: Generated TSVECTOR with 'simple' analyzer for language-neutral lexemes
    tsv: Mapped[str] = mapped_column(
        TSVECTOR,
        Computed("to_tsvector('simple', content)", persisted=True),
        nullable=False,
    )

    # Relationships
    source: Mapped["KnowledgeSource"] = relationship(
        "KnowledgeSource", back_populates="chunks"
    )
    version: Mapped["KnowledgeSourceVersion"] = relationship(
        "KnowledgeSourceVersion", back_populates="chunks"
    )

    __table_args__ = (
        # Dedup Index
        Index("ix_chunks_tenant_hash", "tenant_id", "content_sha256"),
        # Tenant isolation scan index
        Index("ix_chunks_tenant_version", "tenant_id", "version_id"),
        # Full-text Lexical Search GIN Index
        Index("ix_chunks_tsv", "tsv", postgresql_using="gin"),
        # Dense Vector HNSW Cosine Distance Index
        Index(
            "ix_chunks_embedding_hnsw",
            "embedding",
            postgresql_using="hnsw",
            postgresql_with={"m": 16, "ef_construction": 64},
            postgresql_ops={"embedding": "vector_cosine_ops"},
        ),
    )