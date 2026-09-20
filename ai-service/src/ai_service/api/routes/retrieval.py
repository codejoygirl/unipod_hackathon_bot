"""Retrieval and grounded generation endpoints for the Community Assistant platform."""

import logging
import os
import time
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.api.dependencies import (
    get_db_session,
    get_retrieval_service,
    verify_hmac,
)
from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.gemini import GeminiProvider
from ai_service.retrieval.service import HybridRetrievalService
from ai_service.schemas.retrieval import (
    GroundedAnswerRequest,
    GroundedAnswerResponse,
    QueryRequest,
    RetrievalResponse,
)

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/retrieval", tags=["retrieval"])

# Global singleton for the grounded synthesizer using Gemini
gemini_model = GeminiProvider(
    api_key=os.getenv("GEMINI_API_KEY"),
)
_synthesizer = AnswerSynthesizer(chat_model=gemini_model)


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

    # Step 1: Hybrid candidate retrieval
    query_req = QueryRequest(
        query=request.query,
        chat_history=request.chat_history,
        tenant_id=request.tenant_id,
        community_ids=request.community_ids,
        target_language=request.target_language,
        top_k=20,
        rerank_top_n=5,
    )

    retrieval_res = await retrieval_service.search(session=session, request=query_req)

    # Step 2: Synthesis and verification
    # Add generation time tracking to stage_timings
    t0 = time.perf_counter()
    validated_payload, generation_invoked = await synthesizer.synthesize_grounded_answer(
        query=retrieval_res.resolved_query,
        candidates=retrieval_res.candidates,
        total_candidates_scanned=retrieval_res.total_candidates_scanned,
        target_language=request.target_language,
        enable_conflict_detection=request.enable_conflict_detection,
        temperature=request.temperature,
    )
    generation_time_ms = (time.perf_counter() - t0) * 1000.0
    
    if generation_invoked and generation_time_ms == 0.0:
        raise RuntimeError("Invariant violation: generation_invoked is True, but generation time is 0.0 ms.")

    # Create updated diagnostics with generation time
    updated_timings = retrieval_res.stage_timings_ms.model_dump()
    updated_timings['generation'] = generation_time_ms
    from ai_service.schemas.retrieval import StageTimings
    
    final_diagnostics = retrieval_res.model_copy(update={
        "stage_timings_ms": StageTimings(**updated_timings),
        "total_execution_time_ms": retrieval_res.total_execution_time_ms + generation_time_ms,
        "generation_invoked": generation_invoked
    })
    
    # Enforce time ceiling invariant again for the full process
    total_time = final_diagnostics.total_execution_time_ms
    sum_timings = sum([v for v in final_diagnostics.stage_timings_ms.model_dump().values() if v])
    if total_time > 2000.0 and sum_timings < (total_time * 0.5):
        raise RuntimeError(f"Anomalous execution: total time {total_time}ms but only {sum_timings}ms accounted for.")

    elapsed_ms = (time.perf_counter() - start_time) * 1000.0

    return GroundedAnswerResponse(
        query=request.query,
        detected_language=retrieval_res.detected_language,
        validated_payload=validated_payload,
        execution_time_ms=round(elapsed_ms, 2),
        total_chunks_retrieved=len(retrieval_res.candidates),
        retrieval_diagnostics=final_diagnostics
    )