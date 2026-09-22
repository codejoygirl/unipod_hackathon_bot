"""High-level retrieval service orchestrating query processing and search."""

import re
import time

from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.retrieval.hybrid_store import execute_hybrid_search, fetch_url_bearing_chunks
from ai_service.retrieval.reranker import ReRankerPipeline
from ai_service.schemas.retrieval import QueryRequest, RetrievalResponse
from ai_service.translation.expander import QueryExpander

# Conversational / function words so plainto_tsquery does not AND fillers
# (e.g. "What do you know about the hackathon?" → "hackathon").
_LEXICAL_STOPWORDS = frozenset(
    {
        "a",
        "an",
        "and",
        "about",
        "any",
        "are",
        "as",
        "at",
        "be",
        "been",
        "being",
        "by",
        "can",
        "could",
        "did",
        "do",
        "does",
        "for",
        "from",
        "get",
        "got",
        "had",
        "has",
        "have",
        "how",
        "in",
        "into",
        "is",
        "it",
        "its",
        "just",
        "know",
        "like",
        "me",
        "more",
        "my",
        "of",
        "on",
        "or",
        "our",
        "please",
        "some",
        "than",
        "that",
        "the",
        "their",
        "them",
        "then",
        "there",
        "these",
        "they",
        "this",
        "those",
        "to",
        "us",
        "was",
        "we",
        "were",
        "what",
        "when",
        "where",
        "which",
        "who",
        "why",
        "will",
        "with",
        "would",
        "you",
        "your",
        "y",
        "il",
        "elle",
        "un",
        "une",
        "le",
        "la",
        "les",
        "des",
        "du",
        "de",
        "et",
        "ou",
        "est",
        "sont",
        "pas",
        "que",
        "qui",
        "dans",
        "pour",
        "par",
        "sur",
        "avec",
        "ce",
        "cet",
        "cette",
        "au",
        "aux",
        "en",
        "ne",
        "je",
        "tu",
        "nous",
        "vous",
        "ils",
        "elles",
        "t",
        "d",
        "l",
        "n",
        "s",
        "m",
    }
)


def lexical_terms_for_fts(query: str) -> str:
    """Keep content tokens for Postgres plainto_tsquery (AND semantics)."""
    tokens = re.findall(r"\w+", query.lower(), flags=re.UNICODE)
    kept = [t for t in tokens if len(t) > 2 and t not in _LEXICAL_STOPWORDS]
    if kept:
        return " ".join(kept)
    # Fallback: any token longer than 1 char
    loose = [t for t in tokens if len(t) > 1]
    return " ".join(loose) if loose else query.strip()


_LINK_ASK_RE = re.compile(
    r"\b(recording|recordings|youtube|youtu|link|links|slides|video|videos|replay|recap|url|urls)\b",
    re.IGNORECASE,
)
_RECORDING_ASK_RE = re.compile(
    r"\b(recording|recordings|replay|recap|video|videos|youtube|youtu)\b",
    re.IGNORECASE,
)


def _current_question(query: str) -> str:
    marker = "current question:"
    lower = query.lower()
    if marker in lower:
        return query[lower.rfind(marker) + len(marker) :].strip()
    return query.strip()


class HybridRetrievalService:
    """Orchestrates multilingual expansion, hybrid search, and reranking."""

    def __init__(self, embedder, expander: QueryExpander | None = None, reranker_service=None):
        self.embedder = embedder
        self.expander = expander
        self.reranker_service = reranker_service

    async def search(self, session: AsyncSession, request: QueryRequest) -> RetrievalResponse:
        start_time = time.perf_counter()

        # Session memory wraps "Current question: ...". Search and rerank on that
        # question only, or overlap scores collapse and the confidence floor rejects
        # good evidence (e.g. who-is people asks after a few chat turns).
        question = _current_question(request.query)

        if self.expander is not None:
            expansion = await self.expander.expand_query(
                query=question,
                target_language=request.target_language,
            )
            detected_language = expansion.detected_language
            expanded_queries = list(expansion.queries)
        else:
            detected_language = "en"
            expanded_queries = [question]

        link_mode = (request.link_mode or "").strip().lower()
        wants_url_recall = link_mode in {"recordings", "meetings", "assets"} or bool(
            _LINK_ASK_RE.search(question)
        )
        recordings_only = link_mode == "recordings" or (
            link_mode not in {"meetings", "assets"} and bool(_RECORDING_ASK_RE.search(question))
        )

        # Search with each expanded query and merge by chunk id (best RRF wins).
        merged: dict[str, object] = {}
        for q in expanded_queries:
            ts_query_string = lexical_terms_for_fts(q) or question
            query_vector = await self.embedder.embed_query(q)

            candidates = await execute_hybrid_search(
                session=session,
                tenant_id=request.tenant_id,
                community_ids=request.community_ids,
                ts_query_string=ts_query_string,
                query_vector=query_vector,
                limit=request.top_k,
                k=60,
            )
            for candidate in candidates:
                key = str(candidate.chunk_id)
                existing = merged.get(key)
                if existing is None or candidate.rrf_score > existing.rrf_score:  # type: ignore[attr-defined]
                    merged[key] = candidate

        if wants_url_recall:
            url_chunks = await fetch_url_bearing_chunks(
                session=session,
                tenant_id=request.tenant_id,
                community_ids=request.community_ids,
                recordings_only=recordings_only,
                limit=max(request.top_k, 40),
            )
            for candidate in url_chunks:
                key = str(candidate.chunk_id)
                if key not in merged:
                    merged[key] = candidate

        fused = sorted(merged.values(), key=lambda c: c.rrf_score, reverse=True)  # type: ignore[attr-defined]

        rerank_top_n = max(request.rerank_top_n, 30) if wants_url_recall else request.rerank_top_n

        # Prefer injected reranker (sets final_score on a 0-1 scale). RRF alone is ~0.03
        # and fails the synthesizer's confidence floor.
        if self.reranker_service is not None:
            reranked = await self.reranker_service.rerank_candidates(
                question,
                fused,  # type: ignore[arg-type]
                top_n=rerank_top_n,
            )
        else:
            reranked = await ReRankerPipeline.rerank(
                question,
                fused,  # type: ignore[arg-type]
                top_n=rerank_top_n,
            )

        # For link/recording asks, keep any URL-bearing chunks the reranker dropped.
        if wants_url_recall:
            kept_ids = {str(c.chunk_id) for c in reranked}
            url_extras = [
                c
                for c in fused  # type: ignore[attr-defined]
                if str(c.chunk_id) not in kept_ids and "http" in (c.content or "").lower()
            ]
            reranked = list(reranked) + url_extras[:20]
        elapsed_ms = (time.perf_counter() - start_time) * 1000.0

        return RetrievalResponse(
            original_query=request.query,
            expanded_queries=expanded_queries,
            detected_language=detected_language,
            candidates=reranked,
            execution_time_ms=elapsed_ms,
            total_candidates_scanned=len(fused),
        )
