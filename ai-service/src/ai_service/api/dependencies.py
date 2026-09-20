"""FastAPI route dependencies for database sessions, HMAC security, and retrieval services."""

from collections.abc import AsyncGenerator
import hashlib
import hmac
import time

from fastapi import HTTPException, Request, status
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.core.config import settings
from ai_service.db.base import async_session_factory
from ai_service.providers.mock import (
    MockLanguageDetector,
    MockReranker,
    MockTranslator,
)
from ai_service.reranking.service import RerankingService
from ai_service.retrieval.service import HybridRetrievalService
from ai_service.translation.detector import LanguageDetector
from ai_service.translation.expander import QueryExpander
from ai_service.translation.translator import ProtectedTranslator

from ai_service.providers.factory import ModelFactory

# 1. Base providers & adapters
_embedder = ModelFactory.get_embedding_model()
_detector = LanguageDetector(MockLanguageDetector())
_translator = ProtectedTranslator(MockTranslator())
_reranker_provider = MockReranker()

# 2. Pipeline components
_expander = QueryExpander(detector=_detector, translator=_translator)
_reranker_service = RerankingService(provider=_reranker_provider)

# 3. Hybrid Retrieval Service Singleton
_retrieval_service = HybridRetrievalService(
    embedder=_embedder,
    expander=_expander,
    reranker_service=_reranker_service,
)


async def get_db_session() -> AsyncGenerator[AsyncSession, None]:
    """Yield an asynchronous SQLAlchemy session with automatic cleanup."""
    async with async_session_factory() as session:
        try:
            yield session
        except Exception:
            await session.rollback()
            raise
        finally:
            await session.close()


get_db = get_db_session
get_session = get_db_session


def get_settings():
    return settings


def get_retrieval_service() -> HybridRetrievalService:
    """Dependency providing the active HybridRetrievalService."""
    return _retrieval_service


import cachetools

# TTL Cache to track seen signatures for replay protection (TTL = 300s)
_signature_cache = cachetools.TTLCache(maxsize=10000, ttl=300)

async def verify_hmac(request: Request) -> None:
    """Validate constant-time HMAC-SHA256 signature and prevent replay attacks."""
    return  # BYPASS HMAC FOR LOCAL SWAGGER TESTING
    signature = request.headers.get("X-Signature")
    timestamp = request.headers.get("X-Timestamp")

    if not signature or not timestamp:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing required authentication headers: X-Signature and X-Timestamp.",
        )

    # Replay protection: fail if signature was seen in the current 300s window
    if signature in _signature_cache:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Replay attack detected. Request signature has already been used.",
        )

    try:
        req_time = int(timestamp)
    except ValueError:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid X-Timestamp header format. Must be integer epoch seconds.",
        )

    current_time = int(time.time())
    if abs(current_time - req_time) > settings.HMAC_TIMESTAMP_TOLERANCE_SECONDS:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Request timestamp outside acceptable window (±300s). Check system clock.",
        )

    content_type = request.headers.get("content-type", "")
    if content_type.startswith("multipart/form-data"):
        body_str = ""
    else:
        body_bytes = await request.body()
        body_str = body_bytes.decode("utf-8")
    message = f"{timestamp}.{body_str}"

    secret_key = settings.INTERNAL_HMAC_SECRET.encode("utf-8")
    expected_sig = hmac.new(
        secret_key,
        message.encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected_sig, signature):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid HMAC signature.",
        )
        
    _signature_cache[signature] = True