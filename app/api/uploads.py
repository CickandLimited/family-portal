"""Upload handling API stubs."""

from fastapi import APIRouter, UploadFile

router = APIRouter(prefix="/uploads", tags=["uploads"])


@router.post("/")
async def upload_file(_: UploadFile) -> dict[str, str]:
    """Placeholder upload endpoint."""
    return {"status": "pending"}
