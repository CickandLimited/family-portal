"""Image processing helpers for handling uploaded photos."""

from __future__ import annotations

import os
import uuid
from io import BytesIO
from pathlib import Path

from fastapi import UploadFile
from PIL import Image, ImageOps

from app.core.config import settings


def prepare_upload_path(base_dir: Path, filename: str) -> Path:
    """Return a resolved upload path for an incoming file."""
    return (base_dir / filename).resolve()


async def process_image(file: UploadFile) -> dict[str, str]:
    """Persist an uploaded image and generate a thumbnail.

    The original image is normalized to WEBP format and resized to a maximum of
    1600px on the longest edge. A 400px thumbnail is generated for quick
    previews. The resulting paths are returned for storage alongside related
    models.
    """

    uploads_dir = Path(settings.uploads_dir)
    thumbs_dir = Path(settings.thumbs_dir)
    uploads_dir.mkdir(parents=True, exist_ok=True)
    thumbs_dir.mkdir(parents=True, exist_ok=True)

    raw_bytes = await file.read()

    image = Image.open(BytesIO(raw_bytes))
    image = ImageOps.exif_transpose(image)
    image = image.convert("RGB")
    image.thumbnail((1600, 1600))

    file_id = str(uuid.uuid4())
    original_path = prepare_upload_path(uploads_dir, f"{file_id}.webp")
    thumb_path = prepare_upload_path(thumbs_dir, f"{file_id}.webp")

    image.save(original_path, format="WEBP", quality=85, method=6)

    thumb = image.copy()
    thumb.thumbnail((400, 400))
    thumb.save(thumb_path, format="WEBP", quality=80, method=6)

    # Ensure the caller can safely re-read the file if needed.
    await file.seek(0)

    return {"file": os.fspath(original_path), "thumb": os.fspath(thumb_path)}
