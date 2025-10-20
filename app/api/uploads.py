"""File upload endpoints."""

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile
from sqlmodel import Session

from app.core.db import get_session
from app.core.imaging import process_image

router = APIRouter(prefix="/upload")


@router.post("")
async def upload(file: UploadFile = File(...), session: Session = Depends(get_session)):
    """Accept an image upload placeholder."""
    if file.content_type not in {"image/jpeg", "image/png", "image/webp"}:
        raise HTTPException(400, "Unsupported file type")
    saved = await process_image(file)
    return saved
