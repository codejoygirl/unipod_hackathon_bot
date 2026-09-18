"""FastAPI route dependencies for database sessions, HMAC security, and retrieval services."""

from collections.abc import AsyncGenerator
import hashlib
import hmac
import time
from typing import Any

from fastapi import Depends, HTTPException, Request, status
from sqlalchemy.ext.asyncio import AsyncSession

from ai_service.core.config import settings
from ai_service.db.base import async_session_factory

# Dynamically resolve RetrievalService across service modules
RetrievalServiceClass: Any = None
for _mod_path in [
    "ai_service.retrieval.service",
    "ai_service.retrieval.hybrid",
    "ai_service.retrieval.pipeline",
]:
    try:
        _mod = __import__(_mod_path, fromlist=["RetrievalService"])
        if hasattr(_mod, "RetrievalService"):
            RetrievalServiceClass = getattr(_mod, "RetrievalService")
            break
    except ImportError:
        continue


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


# Compatibility aliases
get_db = get_db_session
get_session = get_db_session


def get_settings():
    """Dependency for application settings."""
    return settings


async def get_retrieval_service(
    session: AsyncSession = Depends(get_db_session),
) -> Any:
    """Dependency providing a configured RetrievalService bound to the active session."""
    if RetrievalServiceClass is None:
        return None

    try:
        return RetrievalServiceClass(session=session)
    except TypeError:
        try:
            return RetrievalServiceClass(db=session)
        except TypeError:
            try:
                return RetrievalServiceClass(session)
            except TypeError:
                return RetrievalServiceClass()


async def verify_hmac(request: Request) -> None:
    """Validate constant-time HMAC-SHA256 signature and prevent replay attacks."""
    signature = request.headers.get("X-Signature")
    timestamp = request.headers.get("X-Timestamp")

    if not signature or not timestamp:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing required authentication headers: X-Signature and X-Timestamp.",
        )

    # 1. Verify clock skew within ±300 seconds
    try:
        req_time = int(timestamp)
    except ValueError:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid X-Timestamp header format. Must be integer epoch seconds.",
        )

    current_time = int(time.time())
    if abs(current_time - req_time) > 300:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Request timestamp outside acceptable window (±300s). Check system clock.",
        )

    # 2. Recompute and verify signature
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
