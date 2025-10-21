"""File upload endpoints."""

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile
from sqlmodel import Session

from app.core.db import get_session
from app.core.imaging import ImageProcessingError, process_image

router = APIRouter(prefix="/upload")


@router.post("")
async def upload(file: UploadFile = File(...), session: Session = Depends(get_session)):
    """Accept an image upload placeholder."""
    if file.content_type not in {"image/jpeg", "image/png", "image/webp"}:
        raise HTTPException(400, "Unsupported file type")
    try:
        saved = await process_image(file)
    except ImageProcessingError as exc:
        raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
    return saved
