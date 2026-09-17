from fastapi import FastAPI

from ai_service.api.routes import api_router
from ai_service.core.logging import configure_logging

configure_logging()

app = FastAPI(
    title="Community Assistant AI Service",
    version="0.1.0",
    description="Private AI/RAG service. Called only by the Laravel backend.",
)
app.include_router(api_router)
