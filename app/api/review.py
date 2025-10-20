"""Review workflow API stubs."""

from fastapi import APIRouter

router = APIRouter(prefix="/review", tags=["review"])


@router.get("/queue")
async def get_review_queue() -> list[dict[str, str]]:
    """Placeholder for retrieving items awaiting review."""
    return []
