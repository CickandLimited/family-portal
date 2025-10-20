"""Public-facing API routes for the Family Portal application."""

from fastapi import APIRouter

router = APIRouter()


@router.get("/health", tags=["public"])
async def healthcheck() -> dict[str, str]:
    """Simple healthcheck endpoint placeholder."""
    return {"status": "ok"}
