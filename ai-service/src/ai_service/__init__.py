"""Community Assistant AI service package."""


def main() -> None:
    import uvicorn

    uvicorn.run("ai_service.main:app", host="0.0.0.0", port=8001, reload=False)
