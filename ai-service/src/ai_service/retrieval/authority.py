"""Authority tier weight scaling and minimum score pruning."""

from collections.abc import Sequence
from ai_service.schemas.retrieval import AUTHORITY_WEIGHTS, CandidateChunk


def apply_authority_weighting(
    candidates: Sequence[CandidateChunk],
    use_rerank_score: bool = False,
    min_authority_threshold: float = 0.0,
) -> list[CandidateChunk]:
    """Scale candidate relevance scores by source authority tiers.

    Args:
        candidates: Candidate chunks with populated rrf_score or rerank_score.
        use_rerank_score: If True, scale rerank_score; otherwise scale rrf_score.
        min_authority_threshold: Hard cutoff for final_score.

    Returns:
        List of CandidateChunk instances with updated final_score, sorted descending.
    """
    weighted_candidates: list[CandidateChunk] = []

    for cand in candidates:
        base_score = (
            cand.rerank_score
            if (use_rerank_score and cand.rerank_score is not None)
            else cand.rrf_score
        )
        authority_factor = AUTHORITY_WEIGHTS.get(cand.authority_tier, 0.40)
        final_score = base_score * authority_factor

        if final_score < min_authority_threshold:
            continue

        updated = cand.model_copy(update={"final_score": final_score})
        weighted_candidates.append(updated)

    weighted_candidates.sort(key=lambda c: (c.final_score, c.chunk_id), reverse=True)
    return weighted_candidates