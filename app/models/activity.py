"""Activity log model placeholder."""

from datetime import datetime

from sqlmodel import Field

from .base import BaseModel


class ActivityLog(BaseModel, table=True):
    """Represents an action recorded in the activity log."""

    id: int | None = Field(default=None, primary_key=True)
    timestamp: datetime = Field(default_factory=datetime.utcnow)
    action: str = Field(default="")
