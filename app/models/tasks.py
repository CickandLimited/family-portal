"""SQLModel declarations for plan days, subtasks, and submissions."""

from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import TYPE_CHECKING

from sqlalchemy import Column, ForeignKey, Integer, String, UniqueConstraint
from sqlmodel import Field, Relationship

from .base import BaseModel

if TYPE_CHECKING:  # pragma: no cover - only used for type checking
    from .approvals import Approval
    from .attachments import Attachment
    from .devices import Device
    from .plans import Plan
    from .users import User


class PlanDay(BaseModel, table=True):
    """Represents a day within a plan."""

    __table_args__ = (
        UniqueConstraint("plan_id", "day_index", name="uq_plan_day_plan_index"),
    )

    id: int | None = Field(default=None, primary_key=True)
    plan_id: int = Field(
        sa_column=Column(
            Integer, ForeignKey("plan.id", ondelete="CASCADE"), nullable=False
        )
    )
    day_index: int = Field(ge=0, sa_column_kwargs={"nullable": False})
    title: str = Field(max_length=200)
    locked: bool = Field(default=True, sa_column_kwargs={"nullable": False})
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )
    updated_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )

    plan: "Plan" = Relationship(back_populates="days")
    subtasks: list["Subtask"] = Relationship(
        back_populates="plan_day",
        sa_relationship_kwargs={"cascade": "all, delete-orphan"},
    )


class SubtaskStatus(str, Enum):
    """Possible states of a subtask."""

    PENDING = "pending"
    SUBMITTED = "submitted"
    APPROVED = "approved"
    DENIED = "denied"


class Subtask(BaseModel, table=True):
    """Represents a subtask within a plan day."""

    __table_args__ = (
        UniqueConstraint("plan_day_id", "order_index", name="uq_subtask_plan_day_order"),
    )

    id: int | None = Field(default=None, primary_key=True)
    plan_day_id: int = Field(
        sa_column=Column(
            Integer, ForeignKey("plan_day.id", ondelete="CASCADE"), nullable=False
        )
    )
    order_index: int = Field(ge=0, sa_column_kwargs={"nullable": False})
    text: str = Field(max_length=500)
    xp_value: int = Field(default=10, ge=0, sa_column_kwargs={"nullable": False})
    status: SubtaskStatus = Field(
        default=SubtaskStatus.PENDING, sa_column_kwargs={"nullable": False}
    )
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )
    updated_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )

    plan_day: "PlanDay" = Relationship(back_populates="subtasks")
    submissions: list["SubtaskSubmission"] = Relationship(
        back_populates="subtask",
        sa_relationship_kwargs={"cascade": "all, delete-orphan"},
    )
    approvals: list["Approval"] = Relationship(back_populates="subtask")
    attachments: list["Attachment"] = Relationship(back_populates="subtask")


class SubtaskSubmission(BaseModel, table=True):
    """Evidence submitted for a subtask."""

    id: int | None = Field(default=None, primary_key=True)
    subtask_id: int = Field(
        sa_column=Column(
            Integer, ForeignKey("subtask.id", ondelete="CASCADE"), nullable=False
        )
    )
    submitted_by_device_id: str = Field(
        sa_column=Column(
            String(length=36),
            ForeignKey("device.id", ondelete="CASCADE"),
            nullable=False,
        )
    )
    submitted_by_user_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("user.id", ondelete="SET NULL")),
    )
    photo_path: str | None = Field(default=None, max_length=500)
    comment: str | None = Field(default=None, max_length=500)
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )

    subtask: "Subtask" = Relationship(back_populates="submissions")
    submitted_by_device: "Device" = Relationship(back_populates="submissions")
    submitted_by_user: "User" | None = Relationship(back_populates="submissions")
