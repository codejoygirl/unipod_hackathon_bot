import uuid
import pytest
from ai_service.evaluations.metrics import RagTriadEvaluator
from ai_service.providers.mock import MockEmbedder
from ai_service.schemas.evidence import EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier


def make_chunk(eid: str, text: str) -> EvidenceChunk:
    uid = uuid.uuid4()
    return EvidenceChunk(
        evidence_id=eid,
        chunk_id=uid,
        source_id=uid,
        source_name="Notice.pdf",
        source_uri="https://civic.org/notice.pdf",
        source_type="pdf",
        content=text,
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        retrieval_score=0.92,
    )


def test_faithfulness_calculation_clean():
    chunks = [
        make_chunk("E1", "The community center clinic opens at 8:00 AM on weekdays."),
    ]
    answer = "The clinic opens at 8:00 AM on weekdays [E1]."

    score, total, verified, hallucinated, unanchored = (
        RagTriadEvaluator.calculate_faithfulness(answer, chunks)
    )

    assert score == 1.0
    assert total == 1
    assert verified == 1
    assert hallucinated == 0
    assert unanchored == 0


def test_faithfulness_calculation_penalizes_hallucinated_citation():
    chunks = [
        make_chunk("E1", "The community center clinic opens at 8:00 AM on weekdays."),
    ]
    # Emits [E99] which does not exist in context
    answer = "The clinic opens at 8:00 AM [E1]. Emergency rooms close at midnight [E99]."

    score, total, verified, hallucinated, unanchored = (
        RagTriadEvaluator.calculate_faithfulness(answer, chunks)
    )

    assert score == 0.5  # 1 out of 2 claims verified
    assert total == 2
    assert verified == 1
    assert hallucinated == 1


def test_context_recall_calculation():
    chunks = [
        make_chunk("E1", "Application deadline is October 15th at 5:00 PM."),
        make_chunk("E2", "Late fee is $25 for submissions after deadline."),
    ]

    gold_facts = [
        "October 15th",
        "$25 late fee",
        "Mandatory photo ID required",  # Missing from chunks
    ]

    recall = RagTriadEvaluator.calculate_context_recall(chunks, gold_facts)
    # 2 out of 3 facts retrieved
    assert recall == 0.667


@pytest.mark.asyncio
async def test_complete_rag_triad_evaluation_pass():
    embedder = MockEmbedder(dimension=1536)
    chunks = [
        make_chunk("E1", "The public library provides free Wi-Fi and laptop loans daily until 7 PM."),
    ]
    query = "What services does the library provide?"
    answer = "The public library provides free Wi-Fi and laptop loans daily until 7 PM [E1]."
    gold_facts = ["free Wi-Fi", "laptop loans"]

    report = await RagTriadEvaluator.evaluate(
        query=query,
        answer=answer,
        evidence_chunks=chunks,
        ground_truth_key_facts=gold_facts,
        embedder=embedder,
    )

    assert report.faithfulness == 1.0
    assert report.context_recall == 1.0
    assert report.answer_relevance >= 0.75
    assert report.is_acceptable is True