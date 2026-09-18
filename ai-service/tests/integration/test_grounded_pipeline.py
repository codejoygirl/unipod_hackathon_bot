import uuid
import pytest
from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.mock import MockChatModel
from ai_service.schemas.evidence import AnswerState
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


def create_candidate(
    content: str,
    score: float,
    tier: AuthorityTier = AuthorityTier.OFFICIAL_ANNOUNCEMENT,
    source_id: uuid.UUID | None = None,
) -> CandidateChunk:
    sid = source_id or uuid.uuid4()
    return CandidateChunk(
        chunk_id=uuid.uuid4(),
        source_id=sid,
        version_id=uuid.uuid4(),
        content=content,
        token_count=20,
        authority_tier=tier,
        source_type="pdf",
        community_id=uuid.uuid4(),
        final_score=score,
        rrf_score=score,
    )


@pytest.mark.asyncio
async def test_pipeline_fast_path_insufficient_evidence():
    """Verify that retrieval scores below 0.75 immediately trigger INSUFFICIENT_EVIDENCE."""
    synthesizer = AnswerSynthesizer(MockChatModel())

    low_score_candidates = [
        create_candidate("Some loosely related community chat.", score=0.62)
    ]

    payload = await synthesizer.synthesize_grounded_answer(
        query="When is the municipal food drive?",
        candidates=low_score_candidates,
    )

    assert payload.state == AnswerState.INSUFFICIENT_EVIDENCE
    assert payload.answer == ""
    assert payload.needs_escalation is True
    assert "minimum confidence threshold" in (payload.escalation_reason or "")


@pytest.mark.asyncio
async def test_pipeline_successful_verified_answer():
    """High confidence and official authority should yield a VERIFIED state with valid citations."""
    synthesizer = AnswerSynthesizer(MockChatModel())

    high_confidence_candidates = [
        create_candidate(
            "According to community guidelines, the municipal center opens at 8:00 AM on weekdays.",
            score=0.91,
            tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        )
    ]

    payload = await synthesizer.synthesize_grounded_answer(
        query="What time does the municipal center open?",
        candidates=high_confidence_candidates,
    )

    assert payload.state == AnswerState.VERIFIED
    assert "[E1]" in payload.answer
    assert len(payload.citations) == 1
    assert payload.citations[0].evidence_id == "E1"
    assert payload.citations[0].is_verified is True
    assert payload.needs_escalation is False


@pytest.mark.asyncio
async def test_pipeline_conflict_triggers_conflict_state():
    """Conflicting high-authority sources must trigger CONFLICT state and flag for escalation."""
    synthesizer = AnswerSynthesizer(MockChatModel())

    conflicting_candidates = [
        create_candidate(
            "The grant application deadline closes at 5:00 PM on Friday.",
            score=0.93,
            tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        ),
        create_candidate(
            "The grant application deadline closes at 11:59 PM on Sunday.",
            score=0.90,
            tier=AuthorityTier.POLICY_DOCUMENT,
        ),
    ]

    payload = await synthesizer.synthesize_grounded_answer(
        query="When is the grant deadline?",
        candidates=conflicting_candidates,
        enable_conflict_detection=True,
    )

    assert payload.state == AnswerState.CONFLICT
    assert len(payload.conflicts) == 1
    assert payload.needs_escalation is True
    assert "Conflicting official guidance" in (payload.escalation_reason or "")