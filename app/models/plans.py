"""Plan model placeholder."""

from sqlmodel import Field

from .base import BaseModel


class Plan(BaseModel, table=True):
    """Represents a plan assigned to a user."""

    id: int | None = Field(default=None, primary_key=True)
    title: str = Field(default="")
