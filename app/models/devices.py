"""Device model placeholder."""

from uuid import UUID

from sqlmodel import Field

from .base import BaseModel


class Device(BaseModel, table=True):
    """Represents a registered device."""

    id: UUID = Field(primary_key=True)
    friendly_name: str | None = None
