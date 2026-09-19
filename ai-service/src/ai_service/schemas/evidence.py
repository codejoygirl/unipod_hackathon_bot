"""Pydantic v2 schemas defining evidence structures, citation metadata, authority tiers, and the 4-state answer contract."""

from enum import StrEnum
from typing import Any
import uuid
from pydantic import BaseModel, ConfigDict, Field, field_validator


class AuthorityTier(StrEnum):
    """Source authority weighting tiers for evidence scoring."""

    OFFICIAL_ANNOUNCEMENT = "official_announcement"  # 1.00
    POLICY_DOCUMENT = "policy_document"  # 0.85
    VERIFIED_RESOURCE = "verified_resource"  # 0.70
    COMMUNITY_DISCUSSION = "community_discussion"  # 0.40


AUTHORITY_WEIGHTS: dict[AuthorityTier, float] = {
    AuthorityTier.OFFICIAL_ANNOUNCEMENT: 1.00,
    AuthorityTier.POLICY_DOCUMENT: 0.85,
    AuthorityTier.VERIFIED_RESOURCE: 0.70,
    AuthorityTier.COMMUNITY_DISCUSSION: 0.40,
}


class AnswerState(StrEnum):
    """The four deterministic operational states of an answer."""

    VERIFIED = "VERIFIED"
    POSSIBLE = "POSSIBLE"
    CONFLICT = "CONFLICT"
    INSUFFICIENT_EVIDENCE = "INSUFFICIENT_EVIDENCE"


class MediaLocator(BaseModel):
    """Encapsulates spatial and temporal media metadata for deep linking."""
    model_config = ConfigDict(frozen=True)

    timestamp_seconds: float | None = None
    timecode: str | None = None
    media_url: str | None = None
    bounding_box: list[float] | None = None
    page_number: int | None = None


class EvidenceChunk(BaseModel):
    """A normalized knowledge chunk formatted for XML prompt fencing and citation tracking."""

    model_config = ConfigDict(frozen=True)

    evidence_id: str = Field(
        ...,
        pattern=r"^E\d+$",
        description="Deterministic identifier injected into prompts (e.g., 'E1', 'E2').",
    )
    chunk_id: uuid.UUID = Field(..., description="Unique database UUID of the chunk.")
    source_id: uuid.UUID = Field(..., description="Source document UUID.")
    source_name: str = Field(..., description="Human-readable title or filename.")
    source_uri: str = Field(..., description="Canonical URI or storage URL of the document.")
    source_type: str = Field(..., description="MIME or format type (e.g., 'pdf', 'whatsapp').")
    content: str = Field(..., description="Raw text content of the chunk.")
    breadcrumbs: list[str] = Field(default_factory=list, description="Document section hierarchy.")
    authority_tier: AuthorityTier = Field(..., description="Authority level of the source.")
    retrieval_score: float = Field(..., ge=0.0, le=1.0, description="Final hybrid/reranked score.")
    
    media_type: str | None = Field(default=None, description="'text', 'image', 'audio', or 'video'")
    locator: MediaLocator = Field(default_factory=MediaLocator)


class EnrichedCitation(BaseModel):
    """Rich citation metadata supporting Web PWA Evidence Drawers and messaging platforms."""

    model_config = ConfigDict(frozen=True)

    evidence_id: str = Field(..., pattern=r"^E\d+$")
    source_name: str
    source_uri: str
    media_type: str | None = Field(default=None, description="'text', 'image', 'audio', or 'video'")
    evidence_snippet: str = Field(
        ...,
        min_length=1,
        description="The exact text/transcription chunk used to ground the claim.",
    )
    locator: dict[str, Any] = Field(default_factory=dict)
    relevance_score: float | None = None


class ConflictDetail(BaseModel):
    """Structured information regarding contradictory facts detected across sources."""

    model_config = ConfigDict(frozen=True)

    topic: str = Field(..., description="Subject of the contradiction (e.g., 'Application Deadline').")
    conflicting_claims: list[str] = Field(
        ...,
        min_length=2,
        description="The competing assertions extracted from different documents.",
    )
    source_ids: list[uuid.UUID] = Field(..., description="Sources contributing to the conflict.")
    evidence_ids: list[str] = Field(..., description="Evidence labels (e.g., ['E1', 'E3']).")
    recommended_action: str = Field(
        default="Manual administrative verification required.",
        description="Guidance for community coordinators.",
    )


class ValidatedAnswerPayload(BaseModel):
    """The verified answer package returned to clients and upstream channels."""

    model_config = ConfigDict(frozen=True)

    state: AnswerState = Field(
        ...,
        description="Deterministic state of the answer (VERIFIED, POSSIBLE, CONFLICT, INSUFFICIENT_EVIDENCE).",
    )
    answer: str = Field(
        ...,
        description="Synthesized grounded answer text containing inline citations (e.g., [E1]). "
        "Empty when state is INSUFFICIENT_EVIDENCE.",
    )
    confidence_score: float = Field(
        ...,
        ge=0.0,
        le=1.0,
        description="Aggregated confidence based on retrieval scores and source authority.",
    )
    citations: list[EnrichedCitation] = Field(
        default_factory=list,
        description="Verified citation references linked to specific sentences.",
    )
    conflicts: list[ConflictDetail] = Field(
        default_factory=list,
        description="Contradictions identified across sources, if state is CONFLICT.",
    )
    needs_escalation: bool = Field(
        default=False,
        description="Flag indicating that human administrative intervention is required.",
    )
    escalation_reason: str | None = Field(
        default=None,
        description="Explanation when needs_escalation is True.",
    )

    @field_validator("answer")
    @classmethod
    def validate_answer_for_state(cls, v: str, info) -> str:
        state = info.data.get("state")
        if state == AnswerState.INSUFFICIENT_EVIDENCE and v.strip():
            raise ValueError("Answer must be empty when state is INSUFFICIENT_EVIDENCE.")
        return v