import uuid
import pytest
from ai_service.retrieval.rrf import compute_rrf_score, merge_candidate_rankings
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


def create_candidate(chunk_id: uuid.UUID, content: str) -> CandidateChunk:
    shared_id = uuid.uuid4()
    return CandidateChunk(
        chunk_id=chunk_id,
        source_id=shared_id,
        version_id=shared_id,
        content=content,
        token_count=10,
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        source_type="markdown",
        community_id=shared_id,
    )


def test_compute_rrf_score():
    assert compute_rrf_score(1, k=60) == pytest.approx(1 / 61)
    assert compute_rrf_score(2, k=60) == pytest.approx(1 / 62)
    with pytest.raises(ValueError):
        compute_rrf_score(0)


def test_merge_candidate_rankings_dual_modality_boost():
    id_shared = uuid.uuid4()
    id_lex_only = uuid.uuid4()
    id_vec_only = uuid.uuid4()

    lexical = [
        create_candidate(id_shared, "Shared doc").model_copy(
            update={"lexical_rank": 1, "lexical_score": 0.8}
        ),
        create_candidate(id_lex_only, "Lexical only").model_copy(
            update={"lexical_rank": 2, "lexical_score": 0.5}
        ),
    ]

    vector = [
        create_candidate(id_vec_only, "Vector only").model_copy(
            update={"vector_rank": 1, "vector_distance": 0.1}
        ),
        create_candidate(id_shared, "Shared doc").model_copy(
            update={"vector_rank": 2, "vector_distance": 0.2}
        ),
    ]

    fused = merge_candidate_rankings(lexical, vector, k=60)

    # id_shared must rank first due to appearance in both result sets
    assert len(fused) == 3
    assert fused[0].chunk_id == id_shared

    expected_shared_score = (1 / 61) + (1 / 62)
    assert fused[0].rrf_score == pytest.approx(expected_shared_score)
    assert fused[0].lexical_rank == 1
    assert fused[0].vector_rank == 2