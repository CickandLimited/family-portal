"""Application configuration using environment-aware settings."""

from __future__ import annotations

import os
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Runtime configuration pulled from environment variables."""

    session_secret: str = os.environ.get("FP_SESSION_SECRET", "dev-secret-change-me")
    db_url: str = os.environ.get("FP_DB_URL", "sqlite:///./family_portal.db")
    uploads_dir: str = os.environ.get("FP_UPLOADS_DIR", "/var/lib/family-portal/uploads")
    thumbs_dir: str = os.environ.get("FP_THUMBS_DIR", "/var/lib/family-portal/uploads/thumbs")
    max_upload_mb: int = int(os.environ.get("FP_MAX_UPLOAD_MB", "6"))


settings = Settings()
