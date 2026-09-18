from collections.abc import Sequence
import uuid
import pytest

from ai_service.providers.base import RerankResult, RerankingModel
from ai_service.providers.mock import MockReranker
from ai_service.reranking.service import RerankingService
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


class FailingReranker(RerankingModel):
    """Simulates a remote reranker timeout or model crash."""

    async def rerank(
        self,
        query: str,
        documents: Sequence[str],
        top_n: int | None = None,
    ) -> list[RerankResult]:
        raise TimeoutError("Connection to reranker endpoint timed out after 5000ms.")


def make_chunk(content: str, rrf_score: float) -> CandidateChunk:
    shared_id = uuid.uuid4()
    return CandidateChunk(
        chunk_id=uuid.uuid4(),
        source_id=shared_id,
        version_id=shared_id,
        content=content,
        token_count=15,
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        source_type="pdf",
        community_id=shared_id,
        rrf_score=rrf_score,
        final_score=rrf_score,
    )


@pytest.mark.asyncio
async def test_reranking_service_success():
    mock_provider = MockReranker()
    service = RerankingService(mock_provider)

    chunks = [
        make_chunk("unrelated topic about astronomy", rrf_score=0.030),
        make_chunk("official water rationing guidelines", rrf_score=0.020),
    ]

    reranked = await service.rerank_candidates(
        query="water guidelines",
        candidates=chunks,
        top_n=2,
    )

    assert len(reranked) == 2
    # The second chunk contains exact match tokens, so MockReranker must score it higher
    assert reranked[0].content == "official water rationing guidelines"
    assert reranked[0].rerank_score is not None
    assert reranked[0].rerank_score > reranked[1].rerank_score


@pytest.mark.asyncio
async def test_reranking_service_graceful_degradation_on_failure():
    failing_provider = FailingReranker()
    service = RerankingService(failing_provider)

    chunks = [
        make_chunk("higher rrf doc", rrf_score=0.030),
        make_chunk("lower rrf doc", rrf_score=0.015),
    ]

    # Service should NOT raise TimeoutError; it must fallback gracefully
    results = await service.rerank_candidates(
        query="test query",
        candidates=chunks,
        top_n=2,
    )

    assert len(results) == 2
    assert results[0].content == "higher rrf doc"
    assert results[0].rrf_score == 0.030
    assert results[0].rerank_score is None  # Rerank score remains None on fallback