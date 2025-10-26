"""SQLModel declaration for the activity log."""

from __future__ import annotations

from datetime import datetime
from typing import Any

from sqlalchemy import Column, ForeignKey, Integer, JSON, String
from sqlmodel import Field, Relationship

from .base import BaseModel

class ActivityLog(BaseModel, table=True):
    """Represents an action recorded in the activity log."""

    id: int | None = Field(default=None, primary_key=True)
    timestamp: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )
    device_id: str | None = Field(
        default=None,
        sa_column=Column(String(length=36), ForeignKey("device.id", ondelete="SET NULL")),
    )
    user_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("user.id", ondelete="SET NULL")),
    )
    action: str = Field(max_length=200)
    entity_type: str = Field(max_length=100)
    entity_id: int = Field(sa_column_kwargs={"nullable": False})
    metadata_payload: dict[str, Any] | None = Field(
        default=None,
        alias="metadata",
        sa_column=Column("metadata", JSON, nullable=True),
    )

    device: "Device" | None = Relationship(back_populates="activity_logs")
    user: "User" | None = Relationship(back_populates="activity_logs")
