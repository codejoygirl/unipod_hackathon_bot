from fastapi import FastAPI
from ai_service.api.routes import api_router

app = FastAPI(
    title="Community Assistant AI Service",
    description="Private AI/RAG Service. Called only by the Laravel backend.",
    version="0.1.0",
)

app.include_router(api_router)