"""Image processing helpers."""

from __future__ import annotations

from pathlib import Path


def prepare_upload_path(base_dir: Path, filename: str) -> Path:
    """Return a resolved upload path for an incoming file."""
    return (base_dir / filename).resolve()
