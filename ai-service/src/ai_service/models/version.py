import uuid
from typing import TYPE_CHECKING, Any
from sqlalchemy import ForeignKey, Index, Integer, String, Uuid
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from ai_service.db.base import Base, TenantScopedMixin, TimestampMixin

if TYPE_CHECKING:
    from ai_service.models.chunk import KnowledgeChunk
    from ai_service.models.source import KnowledgeSource


class KnowledgeSourceVersion(Base, TenantScopedMixin, TimestampMixin):
    __tablename__ = "knowledge_source_versions"

    id: Mapped[uuid.UUID] = mapped_column(
        Uuid(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    source_id: Mapped[uuid.UUID] = mapped_column(
        Uuid(as_uuid=True),
        ForeignKey("knowledge_sources.id", ondelete="CASCADE"),
        nullable=False,
    )
    version_number: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    content_sha256: Mapped[str] = mapped_column(String(64), nullable=False)
    storage_path: Mapped[str] = mapped_column(String(1024), nullable=False)
    metadata_: Mapped[dict[str, Any]] = mapped_column(
        "metadata", JSONB, default=dict, nullable=False
    )

    # Relationships
    source: Mapped["KnowledgeSource"] = relationship(
        "KnowledgeSource", back_populates="versions", lazy="selectin",
    )
    chunks: Mapped[list["KnowledgeChunk"]] = relationship(
        "KnowledgeChunk",
        back_populates="version",
        cascade="all, delete-orphan",lazy="selectin",
    )

    __table_args__ = (
        Index(
            "ix_source_versions_unique_num",
            "tenant_id",
            "source_id",
            "version_number",
            unique=True,
        ),
        Index("ix_source_versions_hash", "tenant_id", "content_sha256"),
    )