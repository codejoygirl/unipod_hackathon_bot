from fastapi import APIRouter
from ai_service.api.routes import conversation, health, ingestion, retrieval

api_router = APIRouter()

api_router.include_router(health.router)
api_router.include_router(ingestion.router)
api_router.include_router(retrieval.router)
api_router.include_router(conversation.router)

__all__ = ["api_router", "conversation", "health", "ingestion", "retrieval"]
