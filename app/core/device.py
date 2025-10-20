"""Device identification helpers."""

from uuid import UUID, uuid4


def generate_device_id() -> UUID:
    """Return a new device identifier."""
    return uuid4()
