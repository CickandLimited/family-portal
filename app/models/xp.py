"""SQLModel declaration for XP events earned by users."""

from __future__ import annotations

from datetime import datetime
from sqlalchemy import Column, ForeignKey, Integer, String
from sqlmodel import Field, Relationship

from .base import BaseModel


class XPEvent(BaseModel, table=True):
    """Represents an XP change applied to a user."""

    id: int | None = Field(default=None, primary_key=True)
    user_id: int = Field(
        sa_column=Column(
            Integer, ForeignKey("user.id", ondelete="CASCADE"), nullable=False
        )
    )
    subtask_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("subtask.id", ondelete="SET NULL")),
    )
    delta: int = Field(sa_column_kwargs={"nullable": False})
    reason: str = Field(max_length=200, sa_column_kwargs={"nullable": False})
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )

    user: "User" = Relationship(back_populates="xp_events")
    subtask: "Subtask" | None = Relationship(back_populates="xp_events")
