import uuid
from ai_service.citations.extractor import (
    extract_all_evidence_ids,
    parse_claims_with_citations,
)
from ai_service.citations.validator import CitationValidator
from ai_service.schemas.evidence import EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier


def make_chunk(eid: str, content: str, name: str = "Test.pdf") -> EvidenceChunk:
    uid = uuid.uuid4()
    return EvidenceChunk(
        evidence_id=eid,
        chunk_id=uid,
        source_id=uid,
        source_name=name,
        source_uri=f"https://civic.org/{name}",
        source_type="pdf",
        content=content,
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        retrieval_score=0.90,
        page_number=1,
    )


def test_extract_all_evidence_ids():
    text = "Water is closed [E1]. Electricity is off [E2, E3] and [E1]."
    ids = extract_all_evidence_ids(text)
    assert ids == ["E1", "E2", "E3"]


def test_parse_claims_with_citations():
    text = "First claim about water [E1]. Second claim about food [E2, E3]! Third statement without cite."
    claims = parse_claims_with_citations(text)

    assert len(claims) == 3
    assert claims[0].claim_text == "First claim about water"
    assert claims[0].evidence_ids == ["E1"]
    assert claims[1].claim_text == "Second claim about food"
    assert claims[1].evidence_ids == ["E2", "E3"]
    assert claims[2].claim_text == "Third statement without cite"
    assert claims[2].evidence_ids == []


def test_citation_validator_verified_match():
    evidence = {
        "E1": make_chunk("E1", "Water distribution points will open at 8:00 AM on Friday across all zones."),
    }

    answer = "Water points will open at 8:00 AM on Friday [E1]."
    result = CitationValidator.validate_answer(
        answer=answer,
        evidence_ids_used=["E1"],
        evidence_map=evidence
    )

    assert result.all_citations_valid is True
    assert len(result.verified_citations) == 1
    assert result.verified_citations[0].evidence_id == "E1"
    assert "8:00 AM on Friday" in result.verified_citations[0].evidence_snippet


def test_citation_validator_detects_hallucinated_id():
    evidence = {
        "E1": make_chunk("E1", "Water points open at 8:00 AM."),
    }

    # Model hallucinates [E99]
    answer = "Water points open at 8:00 AM [E1]. School starts Monday [E99]."
    result = CitationValidator.validate_answer(
        answer=answer,
        evidence_ids_used=["E1", "E99"],
        evidence_map=evidence
    )

    assert result.all_citations_valid is False
    assert result.hallucinated_ids == ["E99"]
    # With hard failure, the cleaned_answer should be empty
    assert result.cleaned_answer == ""


def test_citation_validator_detects_unanchored_claim():
    evidence = {
        "E1": make_chunk("E1", "Road repairs will continue through next Tuesday on Main Street."),
    }

    # Answer asserts dental clinics are open, but cites the road repair document
    answer = "Free dental cleanings are available tomorrow at the clinic [E1]."
    result = CitationValidator.validate_answer(
        answer=answer,
        evidence_ids_used=["E1"],
        evidence_map=evidence
    )

    assert result.all_citations_valid is False
    assert len(result.unanchored_claims) == 1
    assert len(result.verified_citations) == 0