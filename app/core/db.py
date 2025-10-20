"""Database session management placeholders."""

from collections.abc import Generator
from contextlib import contextmanager


@contextmanager
def get_session() -> Generator[None, None, None]:
    """Placeholder context manager for database sessions."""
    yield None
