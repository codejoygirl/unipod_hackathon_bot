import uuid
from ai_service.citations.validator import CitationValidationResult
from ai_service.retrieval.conflict import ConflictDetector
from ai_service.retrieval.state_resolver import AnswerStateResolver
from ai_service.schemas.evidence import AnswerState, EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier


def make_chunk(
    eid: str,
    content: str,
    tier: AuthorityTier = AuthorityTier.OFFICIAL_ANNOUNCEMENT,
    score: float = 0.90,
    source_id: uuid.UUID | None = None,
) -> EvidenceChunk:
    sid = source_id or uuid.uuid4()
    return EvidenceChunk(
        evidence_id=eid,
        chunk_id=uuid.uuid4(),
        source_id=sid,
        source_name="Document.pdf",
        source_uri="https://civic.org/doc.pdf",
        source_type="pdf",
        content=content,
        authority_tier=tier,
        retrieval_score=score,
    )


def test_conflict_detector_identifies_deadline_mismatch():
    c1 = make_chunk("E1", "Application deadline closes at 5:00 PM on October 1st.")
    c2 = make_chunk("E2", "Application deadline closes at 11:59 PM on October 15th.")

    conflicts = ConflictDetector.detect_conflicts([c1, c2])

    assert len(conflicts) == 1
    assert "Deadline" in conflicts[0].topic
    assert conflicts[0].evidence_ids == ["E1", "E2"]


def test_conflict_detector_identifies_status_contradiction():
    c1 = make_chunk("E1", "The community center is open for emergency shelter.")
    c2 = make_chunk("E2", "The community center is closed due to flooding.")

    conflicts = ConflictDetector.detect_conflicts([c1, c2])

    assert len(conflicts) == 1
    assert "Status" in conflicts[0].topic


def test_state_resolver_insufficient_evidence():
    chunks = [make_chunk("E1", "Irrelevant info", score=0.60)]
    validation = CitationValidationResult(
        cleaned_answer="",
        verified_citations=[],
        hallucinated_ids=[],
        unanchored_claims=[],
        all_citations_valid=True,
    )

    payload = AnswerStateResolver.resolve_state(
        raw_answer="INSUFFICIENT_EVIDENCE",
        evidence_chunks=chunks,
        validation_result=validation,
        detected_conflicts=[],
    )

    assert payload.state == AnswerState.INSUFFICIENT_EVIDENCE
    assert payload.answer == ""
    assert payload.needs_escalation is True


def test_state_resolver_verified_state():
    chunks = [make_chunk("E1", "Water restoration at 8:00 AM.", score=0.92)]
    validation = CitationValidationResult(
        cleaned_answer="Water returns at 8:00 AM [E1].",
        verified_citations=[],
        hallucinated_ids=[],
        unanchored_claims=[],
        all_citations_valid=True,
    )

    payload = AnswerStateResolver.resolve_state(
        raw_answer="Water returns at 8:00 AM [E1].",
        evidence_chunks=chunks,
        validation_result=validation,
        detected_conflicts=[],
    )

    assert payload.state == AnswerState.VERIFIED
    assert payload.confidence_score == 0.92
    assert payload.needs_escalation is False


def test_state_resolver_possible_state():
    # Lower authority tier forces POSSIBLE state even if score is high
    chunks = [
        make_chunk(
            "E1",
            "Someone said water is back.",
            tier=AuthorityTier.COMMUNITY_DISCUSSION,
            score=0.88,
        )
    ]
    validation = CitationValidationResult(
        cleaned_answer="Water might be back [E1].",
        verified_citations=[],
        hallucinated_ids=[],
        unanchored_claims=[],
        all_citations_valid=True,
    )

    payload = AnswerStateResolver.resolve_state(
        raw_answer="Water might be back [E1].",
        evidence_chunks=chunks,
        validation_result=validation,
        detected_conflicts=[],
    )

    assert payload.state == AnswerState.POSSIBLE
    assert payload.needs_escalation is False