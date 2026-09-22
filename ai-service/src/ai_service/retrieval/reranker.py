"""Cross-encoder re-ranking pipeline."""

from collections.abc import Sequence
from ai_service.schemas.retrieval import CandidateChunk

class ReRankerPipeline:
    """Reranks initial candidate chunks using cross-encoders or authority weights."""
    
    @classmethod
    async def rerank(cls, query: str, candidates: Sequence[CandidateChunk], top_n: int = 5) -> list[CandidateChunk]:
        """Simple authority-based reranker placeholder."""
        # A real implementation would call a cross-encoder model like Cohere or BGE
        sorted_candidates = sorted(candidates, key=lambda c: c.rrf_score, reverse=True)
        return sorted_candidates[:top_n]
