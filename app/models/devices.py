"""SQLModel declarations for registered devices."""

from __future__ import annotations

from datetime import datetime
from uuid import uuid4

from sqlalchemy import Column, ForeignKey, Integer
from sqlmodel import Field, Relationship

from .base import BaseModel


class Device(BaseModel, table=True):
    """Represents a registered device."""

    id: str = Field(
        default_factory=lambda: str(uuid4()),
        primary_key=True,
        index=True,
        max_length=36,
    )
    friendly_name: str | None = Field(default=None, max_length=200)
    linked_user_id: int | None = Field(
        default=None,
        sa_column=Column(
            Integer, ForeignKey("user.id", ondelete="SET NULL"), nullable=True
        ),
    )
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )
    last_seen_at: datetime | None = Field(default=None)

    linked_user: "User" | None = Relationship(back_populates="devices")
    submissions: list["SubtaskSubmission"] = Relationship(
        back_populates="submitted_by_device"
    )
    approvals: list["Approval"] = Relationship(back_populates="acted_by_device")
    attachments: list["Attachment"] = Relationship(back_populates="uploaded_by_device")
    activity_logs: list["ActivityLog"] = Relationship(back_populates="device")
