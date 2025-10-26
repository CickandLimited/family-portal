"""SQLModel declarations for care plans and related metadata."""

from __future__ import annotations

from datetime import datetime
from enum import Enum
from sqlalchemy import Column, ForeignKey, Integer
from sqlmodel import Field, Relationship

from .base import BaseModel


class PlanStatus(str, Enum):
    """Supported life-cycle stages for a plan."""

    DRAFT = "draft"
    IN_PROGRESS = "in_progress"
    COMPLETE = "complete"
    ARCHIVED = "archived"


class Plan(BaseModel, table=True):
    """Represents a plan assigned to a user."""

    id: int | None = Field(default=None, primary_key=True)
    title: str = Field(max_length=200)
    assignee_user_id: int = Field(
        sa_column=Column(
            Integer, ForeignKey("user.id", ondelete="CASCADE"), nullable=False
        )
    )
    status: PlanStatus = Field(
        default=PlanStatus.IN_PROGRESS, sa_column_kwargs={"nullable": False}
    )
    created_by_user_id: int | None = Field(
        default=None,
        sa_column=Column(Integer, ForeignKey("user.id", ondelete="SET NULL")),
    )
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )
    updated_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )
    total_xp: int = Field(default=0, ge=0, sa_column_kwargs={"nullable": False})

    assignee: "User" = Relationship(
        back_populates="assigned_plans",
        sa_relationship_kwargs={"foreign_keys": "Plan.assignee_user_id"},
    )
    creator: "User" | None = Relationship(
        back_populates="created_plans",
        sa_relationship_kwargs={"foreign_keys": "Plan.created_by_user_id"},
    )
    days: list["PlanDay"] = Relationship(
        back_populates="plan",
        sa_relationship_kwargs={"cascade": "all, delete-orphan"},
    )
    attachments: list["Attachment"] = Relationship(back_populates="plan")
