"""Tests for image processing helpers."""

from __future__ import annotations

from io import BytesIO
from pathlib import Path
from types import SimpleNamespace

import pytest
from fastapi import UploadFile
from PIL import Image, TiffImagePlugin

from app.core import imaging


def _make_upload_file(image: Image.Image, *, format: str = "JPEG", exif: bytes | None = None) -> UploadFile:
    """Create an in-memory upload file from a Pillow image."""

    buffer = BytesIO()
    save_kwargs = {"format": format}
    if exif is not None:
        save_kwargs["exif"] = exif
    image.save(buffer, **save_kwargs)
    buffer.seek(0)
    return UploadFile(
        filename=f"test.{format.lower()}",
        file=BytesIO(buffer.getvalue()),
        content_type=f"image/{format.lower()}",
    )


def _patch_settings(monkeypatch: pytest.MonkeyPatch, uploads_dir: Path, thumbs_dir: Path) -> None:
    """Override imaging settings to use temporary directories."""

    monkeypatch.setattr(
        imaging,
        "settings",
        SimpleNamespace(uploads_dir=str(uploads_dir), thumbs_dir=str(thumbs_dir)),
    )


@pytest.mark.asyncio
async def test_process_image_resizes_and_generates_thumbnail(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    uploads_dir = tmp_path / "uploads"
    thumbs_dir = tmp_path / "thumbs"
    _patch_settings(monkeypatch, uploads_dir, thumbs_dir)

    # Create an oversized image that will require downscaling.
    image = Image.new("RGB", (3000, 1000), color="green")
    upload = _make_upload_file(image)

    result = await imaging.process_image(upload)

    original_path = Path(result["file"])
    thumb_path = Path(result["thumb"])

    assert original_path.exists()
    assert thumb_path.exists()

    with Image.open(original_path) as processed:
        assert processed.format == "WEBP"
        assert max(processed.size) == imaging.MAX_DIMENSION

    with Image.open(thumb_path) as thumb:
        assert thumb.format == "WEBP"
        assert max(thumb.size) <= imaging.THUMB_DIMENSION


@pytest.mark.asyncio
async def test_process_image_transposes_and_strips_exif(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    uploads_dir = tmp_path / "uploads"
    thumbs_dir = tmp_path / "thumbs"
    _patch_settings(monkeypatch, uploads_dir, thumbs_dir)

    image = Image.new("RGB", (100, 200), color="blue")
    exif_ifd = TiffImagePlugin.ImageFileDirectory_v2()
    exif_ifd[274] = 6  # Orientation tag (Rotate 90 CW)
    upload = _make_upload_file(image, exif=exif_ifd.tobytes())

    result = await imaging.process_image(upload)

    with Image.open(result["file"]) as processed:
        assert processed.size == (200, 100)
        assert processed.info.get("exif") is None


@pytest.mark.asyncio
async def test_process_image_invalid_bytes_cleanup(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    uploads_dir = tmp_path / "uploads"
    thumbs_dir = tmp_path / "thumbs"
    _patch_settings(monkeypatch, uploads_dir, thumbs_dir)

    upload = UploadFile(
        filename="broken.jpg",
        file=BytesIO(b"not an image"),
        content_type="image/jpeg",
    )

    with pytest.raises(imaging.ImageProcessingError) as excinfo:
        await imaging.process_image(upload)

    assert excinfo.value.detail["code"] == "invalid_image"
    assert not any(uploads_dir.iterdir())
    assert not any(thumbs_dir.iterdir())
