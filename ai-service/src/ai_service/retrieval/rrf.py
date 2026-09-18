"""Reciprocal Rank Fusion (RRF) for merging multi-modal retrieval result sets."""

from collections.abc import Sequence
import uuid
from ai_service.schemas.retrieval import CandidateChunk

RRF_K_DEFAULT = 60


def compute_rrf_score(rank: int, k: int = RRF_K_DEFAULT) -> float:
    """Calculate RRF reciprocal rank value for a 1-based rank position."""
    if rank < 1:
        raise ValueError(f"Rank must be >= 1, received {rank}")
    return 1.0 / (k + rank)


def merge_candidate_rankings(
    lexical_candidates: Sequence[CandidateChunk],
    vector_candidates: Sequence[CandidateChunk],
    k: int = RRF_K_DEFAULT,
) -> list[CandidateChunk]:
    """Fuse lexical and vector search result sets via Reciprocal Rank Fusion.

    Maintains full lineage of intermediate ranks and scores for explainability.
    If a document appears in only one modality, its score contribution from the
    missing modality is treated as 0.0.

    Args:
        lexical_candidates: Candidates retrieved from full-text search.
        vector_candidates: Candidates retrieved from pgvector search.
        k: Smoothing constant (default: 60).

    Returns:
        List of merged CandidateChunk instances sorted descending by rrf_score.
    """
    merged: dict[uuid.UUID, dict[str, object]] = {}

    # 1. Process Lexical Candidates
    for cand in lexical_candidates:
        rank = cand.lexical_rank or 1
        score_increment = compute_rrf_score(rank, k)
        merged[cand.chunk_id] = {
            "chunk": cand,
            "rrf_score": score_increment,
            "lexical_rank": rank,
            "lexical_score": cand.lexical_score,
            "vector_rank": None,
            "vector_distance": None,
        }

    # 2. Process Vector Candidates
    for cand in vector_candidates:
        rank = cand.vector_rank or 1
        score_increment = compute_rrf_score(rank, k)

        if cand.chunk_id in merged:
            entry = merged[cand.chunk_id]
            entry["rrf_score"] = float(entry["rrf_score"]) + score_increment
            entry["vector_rank"] = rank
            entry["vector_distance"] = cand.vector_distance
        else:
            merged[cand.chunk_id] = {
                "chunk": cand,
                "rrf_score": score_increment,
                "lexical_rank": None,
                "lexical_score": None,
                "vector_rank": rank,
                "vector_distance": cand.vector_distance,
            }

    # 3. Construct Unified Output
    fused_candidates: list[CandidateChunk] = []
    for entry in merged.values():
        base_chunk: CandidateChunk = entry["chunk"]  # type: ignore[assignment]
        rrf_score = float(entry["rrf_score"])  # type: ignore[arg-type]

        updated_chunk = base_chunk.model_copy(
            update={
                "lexical_rank": entry["lexical_rank"],
                "lexical_score": entry["lexical_score"],
                "vector_rank": entry["vector_rank"],
                "vector_distance": entry["vector_distance"],
                "rrf_score": rrf_score,
                "final_score": rrf_score,  # Initialized to RRF before reranking/authority
            }
        )
        fused_candidates.append(updated_chunk)

    # Deterministic sort: descending by RRF score, secondary tie-breaker on chunk_id
    fused_candidates.sort(key=lambda c: (c.rrf_score, c.chunk_id), reverse=True)
    return fused_candidates