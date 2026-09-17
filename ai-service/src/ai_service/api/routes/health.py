from fastapi import APIRouter

router = APIRouter(tags=["health"])


@router.get("/health/live")
def health_live() -> dict[str, str]:
    return {"status": "ok"}


@router.get("/health/ready")
def health_ready() -> dict[str, str]:
    return {"status": "ready"}
