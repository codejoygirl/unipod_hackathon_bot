from fastapi import APIRouter
from ai_service.api.routes import health, ingestion, retrieval

api_router = APIRouter()

api_router.include_router(health.router)
api_router.include_router(ingestion.router)
api_router.include_router(retrieval.router)

__all__ = ["api_router", "health", "ingestion", "retrieval"]