"""Citation verifier: paraphrased who-is answers stay grounded."""

import uuid

from ai_service.generation.verifier import AnswerVerifier
from ai_service.schemas.evidence import EvidenceChunk, MediaLocator
from ai_service.schemas.retrieval import AuthorityTier


def _chunk(eid: str, content: str) -> EvidenceChunk:
    return EvidenceChunk(
        evidence_id=eid,
        chunk_id=uuid.uuid4(),
        source_id=uuid.uuid4(),
        version_id=uuid.uuid4(),
        source_name="wa",
        source_uri="whatsapp://x",
        source_type="whatsapp",
        content=content,
        breadcrumbs=["chat"],
        authority_tier=AuthorityTier.COMMUNITY_DISCUSSION,
        retrieval_score=0.91,
        media_type="text",
        locator=MediaLocator(media_url="whatsapp://x"),
    )


def test_who_is_joy_paraphrase_keeps_answer_when_speaker_in_chunk():
    chunk = _chunk(
        "E1",
        "[16/09/2026 17:27:47] Joy: Who is interested , let's form a team?\n"
        "[16/09/2026 17:28:06] Abdulsamad: I'm in\n"
        "[16/09/2026 17:28:26] Joy: Send a dm",
    )
    answer = (
        "Joy is a UniPods cohort member who posts in the group and invited "
        "people to form a hackathon team."
    )
    cleaned, cites, ok = AnswerVerifier.verify_citations(
        answer, ["E1"], {"E1": chunk}
    )
    assert ok is True
    assert cleaned == answer
    assert len(cites) >= 1


def test_unknown_evidence_id_still_wipes():
    chunk = _chunk("E1", "[16/09/2026 17:27:47] Joy: hello")
    cleaned, cites, ok = AnswerVerifier.verify_citations(
        "Joy is in the group.", ["E99"], {"E1": chunk}
    )
    assert ok is False
    assert cleaned == ""
    assert cites == []
