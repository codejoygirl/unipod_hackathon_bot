import uuid
import pytest
from ai_service.evaluations.benchmarks import BenchmarkRunner, GoldenTestCase
from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.mock import MockChatModel
from ai_service.schemas.evidence import AnswerState
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


def make_candidate(content: str, score: float, tier: AuthorityTier) -> CandidateChunk:
    shared_id = uuid.uuid4()
    return CandidateChunk(
        chunk_id=uuid.uuid4(),
        source_id=shared_id,
        version_id=shared_id,
        content=content,
        token_count=15,
        authority_tier=tier,
        source_type="pdf",
        community_id=shared_id,
        final_score=score,
        rrf_score=score,
    )


@pytest.mark.asyncio
async def test_hallucination_cutoff_benchmark_suite():
    """Assert that the benchmark runner enforces state contracts across distinct query types."""
    synthesizer = AnswerSynthesizer(MockChatModel())
    runner = BenchmarkRunner(synthesizer)

    suite = [
        # Case 1: Out-of-domain query with below-threshold score (must yield INSUFFICIENT_EVIDENCE)
        GoldenTestCase(
            name="Out of domain query",
            query="What is the stock price of Apple?",
            candidates=[
                make_candidate(
                    "Local community park schedule is updated.",
                    score=0.45,
                    tier=AuthorityTier.COMMUNITY_DISCUSSION,
                )
            ],
            expected_state=AnswerState.INSUFFICIENT_EVIDENCE,
        ),
        # Case 2: Zero retrieved candidates (must yield INSUFFICIENT_EVIDENCE)
        GoldenTestCase(
            name="Zero candidates found",
            query="When is the zoning hearing?",
            candidates=[],
            expected_state=AnswerState.INSUFFICIENT_EVIDENCE,
        ),
        # Case 3: High authority, high score (must yield VERIFIED)
        GoldenTestCase(
            name="Official verified clinic schedule",
            query="When is the clinic open?",
            candidates=[
                make_candidate(
                    "According to community guidelines, the municipal center opens at 8:00 AM.",
                    score=0.92,
                    tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
                )
            ],
            expected_state=AnswerState.VERIFIED,
        ),
    ]

    summary = await runner.run_suite(suite)

    assert summary.all_passed is True
    assert summary.passed_cases == 3
    assert summary.average_faithfulness >= 0.90
    assert summary.average_citation_precision >= 0.90