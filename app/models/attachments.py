"""SQLModel declaration for uploaded attachments."""

from __future__ import annotations

from datetime import datetime
from typing import TYPE_CHECKING

from sqlalchemy import Column, ForeignKey, Integer, String
from sqlmodel import Field, Relationship

from .base import BaseModel

if TYPE_CHECKING:  # pragma: no cover - only used for type checking
    from .devices import Device
    from .plans import Plan
    from .tasks import Subtask
    from .users import User


class Attachment(BaseModel, table=True):
    """Represents an uploaded attachment."""

    id: int | None = Field(default=None, primary_key=True)
    plan_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("plan.id", ondelete="CASCADE")),
    )
    subtask_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("subtask.id", ondelete="CASCADE")),
    )
    file_path: str = Field(max_length=500)
    thumb_path: str = Field(max_length=500)
    uploaded_by_device_id: str = Field(
        sa_column=Column(
            String(length=36),
            ForeignKey("device.id", ondelete="CASCADE"),
            nullable=False,
        )
    )
    uploaded_by_user_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("user.id", ondelete="SET NULL")),
    )
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )

    plan: "Plan" | None = Relationship(back_populates="attachments")
    subtask: "Subtask" | None = Relationship(back_populates="attachments")
    uploaded_by_device: "Device" = Relationship(back_populates="attachments")
    uploaded_by_user: "User" | None = Relationship(back_populates="attachments")
