import uuid
import pytest
from pydantic import ValidationError

from ai_service.schemas.evidence import (
    AnswerState,
    CitationDetail,
    EvidenceChunk,
    ValidatedAnswerPayload,
)
from ai_service.schemas.retrieval import AuthorityTier


def test_evidence_chunk_validation():
    valid_uuid = uuid.uuid4()
    chunk = EvidenceChunk(
        evidence_id="E1",
        chunk_id=valid_uuid,
        source_id=valid_uuid,
        source_name="Community Guidelines.pdf",
        source_uri="s3://civic-bucket/guidelines.pdf",
        source_type="pdf",
        content="Applications close on Friday at 5:00 PM.",
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        retrieval_score=0.91,
        page_number=4,
    )
    assert chunk.evidence_id == "E1"
    assert chunk.authority_tier == AuthorityTier.OFFICIAL_ANNOUNCEMENT

    # Direct initialization with invalid evidence_id pattern must raise ValidationError
    with pytest.raises(ValidationError):
        EvidenceChunk(
            evidence_id="INVALID_ID",  # Does not match ^E\d+$
            chunk_id=valid_uuid,
            source_id=valid_uuid,
            source_name="Community Guidelines.pdf",
            source_uri="s3://civic-bucket/guidelines.pdf",
            source_type="pdf",
            content="Applications close on Friday at 5:00 PM.",
            authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
            retrieval_score=0.91,
        )


def test_validated_answer_payload_state_rules():
    valid_uuid = uuid.uuid4()

    # 1. Valid verified payload
    payload = ValidatedAnswerPayload(
        state=AnswerState.VERIFIED,
        answer="The deadline is Friday at 5:00 PM [E1].",
        confidence_score=0.92,
        citations=[
            CitationDetail(
                evidence_id="E1",
                chunk_id=valid_uuid,
                source_name="Policy.pdf",
                source_uri="https://city.gov/policy.pdf",
                authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
                exact_quote="Friday at 5:00 PM",
            )
        ],
        needs_escalation=False,
    )
    assert payload.state == AnswerState.VERIFIED
    assert len(payload.citations) == 1

    # 2. INSUFFICIENT_EVIDENCE with non-empty answer must fail validation
    with pytest.raises(ValidationError, match="Answer must be empty"):
        ValidatedAnswerPayload(
            state=AnswerState.INSUFFICIENT_EVIDENCE,
            answer="I think the deadline might be next week.",
            confidence_score=0.40,
            needs_escalation=True,
        )

    # 3. Valid empty-answer INSUFFICIENT_EVIDENCE payload
    insufficient = ValidatedAnswerPayload(
        state=AnswerState.INSUFFICIENT_EVIDENCE,
        answer="",
        confidence_score=0.40,
        needs_escalation=True,
        escalation_reason="No documents found above confidence threshold 0.75.",
    )
    assert insufficient.needs_escalation is True
    assert insufficient.answer == ""