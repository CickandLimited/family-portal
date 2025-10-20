"""Administrative API stubs."""

from fastapi import APIRouter

router = APIRouter(prefix="/admin", tags=["admin"])


@router.get("/devices")
async def list_devices() -> list[dict[str, str]]:
    """Placeholder endpoint returning registered devices."""
    return []
