"""Concurrency stress tests evaluating connection pooling, burst capacity, and leak prevention."""

import asyncio
import time
import uuid
import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.pool import AsyncAdaptedQueuePool

from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.mock import MockChatModel
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


def make_stress_candidate(idx: int) -> CandidateChunk:
    uid = uuid.uuid4()
    return CandidateChunk(
        chunk_id=uid,
        source_id=uid,
        version_id=uid,
        content=f"Stress test civic notice {idx}: Water filtration station {idx} operational.",
        token_count=18,
        authority_tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
        source_type="pdf",
        community_id=uid,
        final_score=0.88,
        rrf_score=0.88,
    )


@pytest.mark.asyncio
async def test_burst_concurrent_synthesizer_requests():
    """Verify that AnswerSynthesizer can service 50 concurrent requests without race conditions."""
    synthesizer = AnswerSynthesizer(MockChatModel())
    concurrency_count = 50

    async def _execute_single_request(req_id: int):
        candidates = [make_stress_candidate(req_id)]
        start = time.perf_counter()
        result = await synthesizer.synthesize_grounded_answer(
            query=f"Where is station {req_id} located?",
            candidates=candidates,
        )
        duration = time.perf_counter() - start
        return result, duration

    # Dispatch 50 concurrent tasks simultaneously
    tasks = [_execute_single_request(i) for i in range(concurrency_count)]
    results_and_durations = await asyncio.gather(*tasks)

    assert len(results_and_durations) == concurrency_count

    for result, duration in results_and_durations:
        # Every request must resolve to an authentic operational state
        assert result.state.value in ("VERIFIED", "POSSIBLE", "CONFLICT", "INSUFFICIENT_EVIDENCE")
        assert duration < 2.0  # Must finish within 2 seconds under mock load


@pytest.mark.asyncio
async def test_connection_pool_saturation_and_release_cleanliness():
    """Verify connection pool handles burst checkout up to pool limits and cleans up without leaks."""
    engine = create_async_engine(
        "sqlite+aiosqlite:///:memory:",
        poolclass=AsyncAdaptedQueuePool,
        pool_size=5,
        max_overflow=5,
        pool_timeout=5.0,
    )
    session_factory = async_sessionmaker(engine, expire_on_commit=False, class_=AsyncSession)

    concurrency_target = 25  # Exceeds total capacity (5 pool + 5 overflow = 10 active)

    async def _worker(worker_id: int):
        # Stagger slightly to allow queue sequencing
        await asyncio.sleep(0.005 * (worker_id % 5))
        async with session_factory() as session:
            result = await session.execute(text("SELECT 1"))
            val = result.scalar_one()
            await asyncio.sleep(0.02)
            return val

    # Run 25 workers concurrently; QueuePool must sequence checkouts without throwing TimeoutError
    tasks = [_worker(i) for i in range(concurrency_target)]
    outputs = await asyncio.gather(*tasks, return_exceptions=True)

    exceptions = [o for o in outputs if isinstance(o, Exception)]
    assert len(exceptions) == 0, f"Encountered unexpected exceptions under burst load: {exceptions}"
    assert all(o == 1 for o in outputs)

    await engine.dispose()


@pytest.mark.asyncio
async def test_task_cancellation_preserves_pool_integrity():
    """Verify that abrupt client disconnection (task cancellation) does not leak connections."""
    engine = create_async_engine(
        "sqlite+aiosqlite:///:memory:",
        poolclass=AsyncAdaptedQueuePool,
        pool_size=2,
        max_overflow=0,
        pool_timeout=2.0,
    )
    session_factory = async_sessionmaker(engine, expire_on_commit=False, class_=AsyncSession)

    async def _interrupted_worker():
        async with session_factory() as session:
            await session.execute(text("SELECT 1"))
            # Hang until explicitly cancelled
            await asyncio.sleep(10.0)

    # Start task and cancel it immediately while holding session context
    task = asyncio.create_task(_interrupted_worker())
    await asyncio.sleep(0.05)
    task.cancel()

    try:
        await task
    except asyncio.CancelledError:
        pass

    # Verify pool connection was successfully returned and can be checked out again
    async with session_factory() as clean_session:
        result = await clean_session.execute(text("SELECT 42"))
        assert result.scalar_one() == 42

    await engine.dispose()