"""Approval model placeholder."""

from sqlmodel import Field

from .base import BaseModel


class Approval(BaseModel, table=True):
    """Represents an approval decision for a subtask."""

    id: int | None = Field(default=None, primary_key=True)
    subtask_id: int = Field(foreign_key="subtask.id")
    action: str = Field(default="pending")
