"""Retrieval and grounded generation endpoints for the Community Assistant platform."""

import logging
import time
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.api.dependencies import (
    get_db_session,
    get_retrieval_service,
    verify_hmac,
)
from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.factory import ModelFactory
from ai_service.retrieval.service import HybridRetrievalService
from ai_service.schemas.retrieval import (
    GroundedAnswerRequest,
    GroundedAnswerResponse,
    QueryRequest,
    RetrievalResponse,
)

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/retrieval", tags=["retrieval"])

_synthesizer = AnswerSynthesizer(chat_model=ModelFactory.get_chat_model())


def get_synthesizer() -> AnswerSynthesizer:
    return _synthesizer


@router.post(
    "/candidates",
    response_model=RetrievalResponse,
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="Search and retrieve permission-filtered ranked candidate passages",
)
async def retrieve_candidates(
    request: QueryRequest,
    session: AsyncSession = Depends(get_db_session),
    service: HybridRetrievalService = Depends(get_retrieval_service),
) -> RetrievalResponse:
    """Execute multi-modal hybrid retrieval over knowledge chunks."""
    try:
        return await service.search(session=session, request=request)
    except ValueError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=str(exc),
        ) from exc
    except Exception as exc:
        logger.error("Unexpected retrieval failure: %s", str(exc), exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="An internal error occurred while retrieving candidate passages.",
        ) from exc


@router.post(
    "/grounded-answer",
    response_model=GroundedAnswerResponse,
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="End-to-end hybrid retrieval, grounded answer generation, and citation verification",
)
async def generate_grounded_answer(
    request: GroundedAnswerRequest,
    session: AsyncSession = Depends(get_db_session),
    retrieval_service: HybridRetrievalService = Depends(get_retrieval_service),
    synthesizer: AnswerSynthesizer = Depends(get_synthesizer),
) -> GroundedAnswerResponse:
    """Execute complete RAG pipeline:

    1. Scoped Hybrid Retrieval (Lexical + Vector + RRF + Rerank + Authority).
    2. Conflict Detection across equal-tier sources.
    3. Grounded LLM generation with XML evidence fencing.
    4. Deterministic citation and exact-quote verification.
    5. 4-State decision resolution (VERIFIED, POSSIBLE, CONFLICT, INSUFFICIENT_EVIDENCE).
    """
    start_time = time.perf_counter()

    # Retrieval: expand non-English queries toward the English knowledge corpus.
    # Reply language is separate: auto-detect from the question unless the client overrides.
    explicit_reply_lang = (request.target_language or "").strip().lower()
    if explicit_reply_lang in {"", "auto"}:
        explicit_reply_lang = None

    query_req = QueryRequest(
        query=request.query,
        tenant_id=request.tenant_id,
        community_ids=request.community_ids,
        target_language="en",
        link_mode=request.link_mode,
        top_k=40,
        rerank_top_n=20,
    )

    retrieval_res = await retrieval_service.search(session=session, request=query_req)

    # Reply language: explicit client override only.
    # Auto mode leaves target_language unset so the synthesizer instructs the model
    # to match the User Question language (any language; no hardcoded catalog).
    # Do NOT pass heuristic detected_language into generation: wrong/"en" defaults
    # force English answers when evidence is English (common RAG failure mode).
    reply_language = explicit_reply_lang

    # Step 2: Synthesis and verification
    validated_payload = await synthesizer.synthesize_grounded_answer(
        query=request.query,
        candidates=retrieval_res.candidates,
        target_language=reply_language,
        enable_conflict_detection=request.enable_conflict_detection,
        temperature=request.temperature,
        link_mode=request.link_mode,
        language_hint=retrieval_res.detected_language,
    )

    elapsed_ms = (time.perf_counter() - start_time) * 1000.0

    return GroundedAnswerResponse(
        query=request.query,
        detected_language=retrieval_res.detected_language,
        validated_payload=validated_payload,
        execution_time_ms=round(elapsed_ms, 2),
        total_chunks_retrieved=len(retrieval_res.candidates),
    )