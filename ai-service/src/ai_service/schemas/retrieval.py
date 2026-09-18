"""Pydantic v2 schemas defining retrieval contracts, filtering predicates, and candidate payloads."""

from datetime import datetime
import uuid
from pydantic import BaseModel, ConfigDict, Field, field_validator

from ai_service.schemas.evidence import (
    AuthorityTier,
    AUTHORITY_WEIGHTS,
    ValidatedAnswerPayload,
)

__all__ = [
    "AuthorityTier",
    "AUTHORITY_WEIGHTS",
    "QueryRequest",
    "CandidateChunk",
    "RetrievalResponse",
    "GroundedAnswerRequest",
    "GroundedAnswerResponse",
]


class QueryRequest(BaseModel):
    """Incoming search request with strict tenant and permission scoping."""

    model_config = ConfigDict(frozen=True, str_strip_whitespace=True)

    query: str = Field(
        ...,
        min_length=1,
        max_length=2000,
        description="Raw search query input by the user.",
    )
    tenant_id: uuid.UUID = Field(
        ...,
        description="Tenant identifier for strict data isolation.",
    )
    community_ids: list[uuid.UUID] = Field(
        ...,
        min_length=1,
        description="Authorized community IDs for row-level permission filtering.",
    )
    top_k: int = Field(
        default=25,
        ge=1,
        le=100,
        description="Number of preliminary candidates to retrieve per search modality.",
    )
    rerank_top_n: int = Field(
        default=5,
        ge=1,
        le=20,
        description="Final number of top candidates retained post-reranking and authority weighting.",
    )
    target_language: str | None = Field(
        default=None,
        max_length=10,
        description="Target ISO language code for cross-lingual expansion (e.g., 'en', 'es', 'am').",
    )
    min_authority_threshold: float = Field(
        default=0.0,
        ge=0.0,
        le=1.0,
        description="Hard cutoff below which low-authority chunks are dropped.",
    )

    @field_validator("query")
    @classmethod
    def validate_non_empty_query(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("Query cannot be blank or whitespace only.")
        return v


class CandidateChunk(BaseModel):
    """A retrieved knowledge chunk enriched with hybrid ranking scores and lineage."""

    model_config = ConfigDict(frozen=True)

    chunk_id: uuid.UUID
    source_id: uuid.UUID
    version_id: uuid.UUID
    content: str
    token_count: int
    breadcrumbs: list[str] = Field(default_factory=list)
    authority_tier: AuthorityTier
    source_type: str
    community_id: uuid.UUID

    # Intermediate scores for telemetry and auditability
    lexical_rank: int | None = None
    lexical_score: float | None = None
    vector_rank: int | None = None
    vector_distance: float | None = None
    rrf_score: float = 0.0
    rerank_score: float | None = None
    final_score: float = 0.0


class RetrievalResponse(BaseModel):
    """Structured response returned by the hybrid retrieval service."""

    model_config = ConfigDict(frozen=True)

    original_query: str
    expanded_queries: list[str]
    detected_language: str
    candidates: list[CandidateChunk]
    execution_time_ms: float
    total_candidates_scanned: int


class GroundedAnswerRequest(BaseModel):
    """Client request for complete retrieval, synthesis, and verification pipeline."""

    model_config = ConfigDict(frozen=True, str_strip_whitespace=True)

    query: str = Field(..., min_length=1, max_length=2000)
    tenant_id: uuid.UUID
    community_ids: list[uuid.UUID] = Field(..., min_length=1)
    target_language: str | None = Field(default=None, max_length=10)
    enable_conflict_detection: bool = Field(
        default=True,
        description="Whether to run cross-document contradiction checks.",
    )
    temperature: float = Field(
        default=0.0,
        ge=0.0,
        le=0.2,
        description="Generation temperature. Defaults strictly to 0.0 for deterministic grounding.",
    )


class GroundedAnswerResponse(BaseModel):
    """Top-level API response package containing the validated answer and pipeline metrics."""

    model_config = ConfigDict(frozen=True)

    query: str
    detected_language: str
    validated_payload: ValidatedAnswerPayload
    execution_time_ms: float
    total_chunks_retrieved: int