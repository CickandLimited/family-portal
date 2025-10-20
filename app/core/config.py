"""Application configuration stubs."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(slots=True)
class Settings:
    """Placeholder settings container."""

    app_name: str = "Family Portal"
    debug: bool = True


def get_settings() -> Settings:
    """Return default application settings."""
    return Settings()


settings = get_settings()
