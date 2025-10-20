"""User model placeholder."""

from sqlmodel import Field

from .base import BaseModel


class User(BaseModel, table=True):
    """Represents a portal user."""

    id: int | None = Field(default=None, primary_key=True)
    display_name: str = Field(default="")
