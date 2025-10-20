"""Attachment model placeholder."""

from sqlmodel import Field

from .base import BaseModel


class Attachment(BaseModel, table=True):
    """Represents an uploaded attachment."""

    id: int | None = Field(default=None, primary_key=True)
    file_path: str = Field(default="")
