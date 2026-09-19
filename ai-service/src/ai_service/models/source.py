import uuid
from typing import TYPE_CHECKING, Any, Optional
from sqlalchemy import Enum, Index, String, Uuid
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ai_service.db.base import Base, CommunityScopedMixin, TenantScopedMixin, TimestampMixin

if TYPE_CHECKING:
    from ai_service.models.chunk import KnowledgeChunk
    from ai_service.models.version import KnowledgeSourceVersion


class KnowledgeSource(Base, TenantScopedMixin, CommunityScopedMixin, TimestampMixin):
    __tablename__ = "knowledge_sources"

    id: Mapped[uuid.UUID] = mapped_column(
        Uuid(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    uri: Mapped[str] = mapped_column(String(1024), nullable=False)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    source_type: Mapped[str] = mapped_column(
        String(50), nullable=False
    )  # 'pdf', 'docx', 'markdown', 'whatsapp', 'transcript'
    status: Mapped[str] = mapped_column(
        String(50), default="active", nullable=False
    )  # 'active', 'archived', 'processing', 'error'
    metadata_: Mapped[dict[str, Any]] = mapped_column(
        "metadata", JSONB, default=dict, nullable=False
    )

    # Relationships
    versions: Mapped[list["KnowledgeSourceVersion"]] = relationship(
        "KnowledgeSourceVersion",
        back_populates="source",
        cascade="all, delete-orphan",lazy="selectin",
        order_by="desc(KnowledgeSourceVersion.version_number)",
    )
    chunks: Mapped[list["KnowledgeChunk"]] = relationship(
        "KnowledgeChunk",
        back_populates="source",
        cascade="all, delete-orphan",lazy="selectin",
    )

    __table_args__ = (
        Index("ix_sources_tenant_uri", "tenant_id", "uri", unique=True),
        Index("ix_sources_tenant_status", "tenant_id", "status"),
        Index("ix_sources_tenant_community", "tenant_id", "community_id"),
    )