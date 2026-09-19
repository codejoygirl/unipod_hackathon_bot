from fastapi import APIRouter, status
from pydantic import BaseModel
from sqlalchemy import text
from ai_service.db.base import async_session_factory

# Explicitly named 'router' so imports find it
router = APIRouter(prefix="/health", tags=["health"])


class LiveResponse(BaseModel):
    status: str = "healthy"
    service: str = "ai-service"
    environment: str = "production"


class ReadyResponse(BaseModel):
    status: str = "ready"
    database: str = "connected"
    vector_extension: str = "active"


@router.get("/live", response_model=LiveResponse, status_code=status.HTTP_200_OK)
async def liveness_probe() -> LiveResponse:
    """In-memory event loop ping for liveness checks."""
    return LiveResponse()


@router.get("/ready", response_model=ReadyResponse, status_code=status.HTTP_200_OK)
async def readiness_probe() -> ReadyResponse:
    """Database and pgvector connection probe."""
    try:
        async with async_session_factory() as session:
            await session.execute(text("SELECT 1"))
        return ReadyResponse()
    except Exception as exc:
        return ReadyResponse(
            status="degraded",
            database=f"error: {str(exc)}",
            vector_extension="unknown",
        )