import uuid
from typing import Optional
from sqlalchemy import Boolean, Index, String, Text, Uuid
from sqlalchemy.orm import Mapped, mapped_column

from ai_service.db.base import Base, TenantScopedMixin, TimestampMixin


class GlossaryEntry(Base, TenantScopedMixin, TimestampMixin):
    __tablename__ = "glossary_entries"

    id: Mapped[uuid.UUID] = mapped_column(
        Uuid(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    term: Mapped[str] = mapped_column(String(255), nullable=False)
    language_code: Mapped[str] = mapped_column(
        String(10), nullable=False
    )  # ISO 639-1/2 (e.g., 'es', 'en', 'am', 'sw')
    target_term: Mapped[str] = mapped_column(String(255), nullable=False)
    definition: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)

    __table_args__ = (
        Index(
            "ix_glossary_tenant_term_lang",
            "tenant_id",
            "term",
            "language_code",
            unique=True,
        ),
    )