import uuid
import pytest
from ai_service.retrieval.authority import apply_authority_weighting
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


def test_apply_authority_weighting():
    shared_id = uuid.uuid4()

    official_cand = CandidateChunk(
        chunk_id=uuid.uuid4(),
        source_id=shared_id,
        version_id=shared_id,
        content="Official policy",
        token_count=10,
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,  # 1.00
        source_type="pdf",
        community_id=shared_id,
        rrf_score=0.030,
    )

    discussion_cand = CandidateChunk(
        chunk_id=uuid.uuid4(),
        source_id=shared_id,
        version_id=shared_id,
        content="Community forum chat",
        token_count=10,
        authority_tier=AuthorityTier.COMMUNITY_DISCUSSION,  # 0.40
        source_type="whatsapp",
        community_id=shared_id,
        rrf_score=0.050,  # Higher initial RRF score
    )

    # Unweighted: discussion (0.050) > official (0.030)
    # Weighted: official (0.030 * 1.0 = 0.030) > discussion (0.050 * 0.40 = 0.020)
    weighted = apply_authority_weighting([discussion_cand, official_cand])

    assert weighted[0].chunk_id == official_cand.chunk_id
    assert weighted[0].final_score == pytest.approx(0.030)
    assert weighted[1].chunk_id == discussion_cand.chunk_id
    assert weighted[1].final_score == pytest.approx(0.020)


def test_apply_authority_pruning_threshold():
    shared_id = uuid.uuid4()

    low_tier_cand = CandidateChunk(
        chunk_id=uuid.uuid4(),
        source_id=shared_id,
        version_id=shared_id,
        content="Chat",
        token_count=10,
        authority_tier=AuthorityTier.COMMUNITY_DISCUSSION,  # 0.40
        source_type="whatsapp",
        community_id=shared_id,
        rrf_score=0.010,  # 0.010 * 0.40 = 0.004
    )

    weighted = apply_authority_weighting([low_tier_cand], min_authority_threshold=0.005)
    assert len(weighted) == 0