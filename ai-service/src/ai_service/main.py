from fastapi import FastAPI

from ai_service.api.routes import api_router
from ai_service.core.config import settings
from ai_service.core.logging import configure_logging

configure_logging()

app = FastAPI(
    title="Zak AI Service",
    version="0.1.0",
    description=(
        "Private AI/RAG service for Zak. Called only by the Laravel backend. "
        "Not exposed on the public internet in production. "
        "Interactive docs: /docs — OpenAPI JSON: /openapi.json"
    ),
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
)
app.include_router(api_router)


@app.get("/", include_in_schema=False)
def root() -> dict[str, str]:
    return {
        "service": settings.app_name,
        "docs": "/docs",
        "openapi": "/openapi.json",
    }
