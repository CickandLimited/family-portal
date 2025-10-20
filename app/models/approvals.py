"""SQLModel declaration for approval decisions."""

from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import TYPE_CHECKING

from sqlalchemy import Column, ForeignKey, Integer, String
from sqlmodel import Field, Relationship

from .base import BaseModel

if TYPE_CHECKING:  # pragma: no cover - only used for type checking
    from .devices import Device
    from .tasks import Subtask
    from .users import User


class ApprovalAction(str, Enum):
    """Possible actions taken during review."""

    APPROVE = "approve"
    DENY = "deny"


class ApprovalMood(str, Enum):
    """Mood values captured during approval."""

    HAPPY = "happy"
    NEUTRAL = "neutral"
    SAD = "sad"


class Approval(BaseModel, table=True):
    """Represents an approval decision for a subtask."""

    id: int | None = Field(default=None, primary_key=True)
    subtask_id: int = Field(
        sa_column=Column(
            Integer, ForeignKey("subtask.id", ondelete="CASCADE"), nullable=False
        )
    )
    action: ApprovalAction = Field(
        default=ApprovalAction.APPROVE, sa_column_kwargs={"nullable": False}
    )
    mood: ApprovalMood = Field(
        default=ApprovalMood.NEUTRAL, sa_column_kwargs={"nullable": False}
    )
    reason: str | None = Field(default=None, max_length=500)
    acted_by_device_id: str = Field(
        sa_column=Column(
            String(length=36),
            ForeignKey("device.id", ondelete="CASCADE"),
            nullable=False,
        )
    )
    acted_by_user_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("user.id", ondelete="SET NULL")),
    )
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )

    subtask: "Subtask" = Relationship(back_populates="approvals")
    acted_by_device: "Device" = Relationship(back_populates="approvals")
    acted_by_user: "User" | None = Relationship(back_populates="approvals")
