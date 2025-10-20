"""Database engine and session helpers."""

from __future__ import annotations

from collections.abc import Generator

from sqlmodel import Session, create_engine

from app.core.config import settings

_connect_args = {"check_same_thread": False} if settings.db_url.startswith("sqlite") else {}
engine = create_engine(settings.db_url, connect_args=_connect_args)


def get_session() -> Generator[Session, None, None]:
    """Yield a database session for dependency injection."""
    with Session(engine) as session:
        yield session
