"""Pydantic v2 schemas for document ingestion contracts and status reporting."""

from enum import StrEnum
from typing import Any
import uuid
from pydantic import BaseModel, ConfigDict, Field, field_validator

from ai_service.schemas.retrieval import AuthorityTier


class IngestionStatus(StrEnum):
    COMPLETED = "completed"
    SKIPPED_DUPLICATE = "skipped_duplicate"
    PROCESSING = "processing"
    FAILED = "failed"


class IngestionRequest(BaseModel):
    """Payload sent by upstream services (e.g., Laravel) to ingest content."""

    model_config = ConfigDict(frozen=True, str_strip_whitespace=True)

    tenant_id: uuid.UUID
    community_id: uuid.UUID
    uri: str = Field(..., min_length=1, max_length=1024)
    name: str = Field(..., min_length=1, max_length=255)
    source_type: str = Field(..., min_length=1, max_length=50)  # 'pdf', 'docx', 'markdown', 'transcript', 'whatsapp'
    content: str = Field(..., min_length=1, description="Raw text or parsed body of the document.")
    authority_tier: AuthorityTier = Field(
        default=AuthorityTier.COMMUNITY_DISCUSSION,
        description="Authority tier for ranking weighting.",
    )
    metadata: dict[str, Any] = Field(default_factory=dict)

    @field_validator("content")
    @classmethod
    def validate_non_empty_content(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("Document content cannot be empty or whitespace only.")
        return v


class IngestionResponse(BaseModel):
    """Response returned upon document ingestion processing."""

    model_config = ConfigDict(frozen=True)

    source_id: uuid.UUID
    version_id: uuid.UUID | None = None
    status: IngestionStatus
    content_sha256: str
    chunks_created: int
    message: str
    execution_time_ms: float