"""Image processing helpers for handling uploaded photos."""

from __future__ import annotations

import os
import uuid
from contextlib import suppress
from io import BytesIO
from pathlib import Path
from typing import Iterable

from fastapi import UploadFile
from PIL import Image, ImageOps, UnidentifiedImageError

from app.core.config import settings

MAX_DIMENSION = 1600
THUMB_DIMENSION = 400


class ImageProcessingError(Exception):
    """Raised when an uploaded image cannot be processed."""

    def __init__(self, code: str, message: str, *, status_code: int = 400) -> None:
        super().__init__(message)
        self.status_code = status_code
        self.detail = {"code": code, "message": message}


def prepare_upload_path(base_dir: Path, filename: str) -> Path:
    """Return a resolved upload path for an incoming file."""
    return (base_dir / filename).resolve()


def _cleanup_files(paths: Iterable[Path]) -> None:
    """Remove any partially written files, ignoring missing paths."""

    for path in paths:
        with suppress(FileNotFoundError):
            path.unlink()


async def process_image(file: UploadFile) -> dict[str, str]:
    """Persist an uploaded image and generate a thumbnail.

    The original image is normalized to WEBP format and resized to a maximum of
    1600px on the longest edge. A 400px thumbnail is generated for quick
    previews. The resulting paths are returned for storage alongside related
    models.
    """

    uploads_dir = Path(settings.uploads_dir)
    thumbs_dir = Path(settings.thumbs_dir)

    try:
        uploads_dir.mkdir(parents=True, exist_ok=True)
        thumbs_dir.mkdir(parents=True, exist_ok=True)
    except OSError as exc:  # pragma: no cover - extremely rare
        raise ImageProcessingError(
            "storage_error", "Unable to prepare upload directories."
        ) from exc

    raw_bytes = await file.read()
    if not raw_bytes:
        raise ImageProcessingError("empty_upload", "Uploaded file is empty.")

    file_id = str(uuid.uuid4())
    original_path = prepare_upload_path(uploads_dir, f"{file_id}.webp")
    thumb_path = prepare_upload_path(thumbs_dir, f"{file_id}.webp")
    created_paths: list[Path] = []

    try:
        with Image.open(BytesIO(raw_bytes)) as loaded_image:
            image = ImageOps.exif_transpose(loaded_image)
            image = image.convert("RGB")

        image.thumbnail((MAX_DIMENSION, MAX_DIMENSION), Image.LANCZOS)
        image.save(original_path, format="WEBP", quality=85, method=6)
        created_paths.append(original_path)

        thumb = image.copy()
        thumb.thumbnail((THUMB_DIMENSION, THUMB_DIMENSION), Image.LANCZOS)
        thumb.save(thumb_path, format="WEBP", quality=80, method=6)
        created_paths.append(thumb_path)

    except UnidentifiedImageError as exc:
        _cleanup_files(created_paths)
        raise ImageProcessingError(
            "invalid_image", "Uploaded file is not a recognized image."
        ) from exc
    except OSError as exc:
        _cleanup_files(created_paths)
        raise ImageProcessingError(
            "processing_error", "Unable to process uploaded image."
        ) from exc
    finally:
        await file.seek(0)

    return {"file": os.fspath(original_path), "thumb": os.fspath(thumb_path)}
