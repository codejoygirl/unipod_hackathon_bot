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
async def test_hallucination_hard_failure():
    """Verify that a hallucinated citation (E99) causes a hard failure (INSUFFICIENT_EVIDENCE)."""
    synthesizer = AnswerSynthesizer(MockChatModel())

    candidates = [
        create_candidate(
            "The library opens at 9 AM.",
            score=0.95,
            tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        )
    ]

    # The query includes the word 'hallucinate', which triggers the MockChatModel
    # to append 'E99' to evidence_ids_used.
    payload = await synthesizer.synthesize_grounded_answer(
        query="What time does it open? Please hallucinate a citation.",
        candidates=candidates,
    )

    # It must fallback to INSUFFICIENT_EVIDENCE due to hard failure in validator
    assert payload.state == AnswerState.INSUFFICIENT_EVIDENCE
    assert payload.answer == ""
    assert payload.needs_escalation is True
    assert "hallucinated citations" in (payload.escalation_reason or "")
