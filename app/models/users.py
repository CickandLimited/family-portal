"""SQLModel declaration for application users."""

from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import TYPE_CHECKING

from sqlmodel import Field, Relationship

from .base import BaseModel

if TYPE_CHECKING:  # pragma: no cover - only used for type checking
    from .activity import ActivityLog
    from .approvals import Approval
    from .attachments import Attachment
    from .devices import Device
    from .plans import Plan
    from .tasks import SubtaskSubmission
    from .xp import XPEvent


class UserRole(str, Enum):
    """Enumerates the supported portal user roles."""

    ADMIN = "admin"
    USER = "user"


class User(BaseModel, table=True):
    """Represents a portal user."""

    id: int | None = Field(default=None, primary_key=True)
    display_name: str = Field(max_length=200, index=True)
    role: UserRole = Field(default=UserRole.USER, sa_column_kwargs={"nullable": False})
    avatar: str | None = Field(default=None, max_length=500)
    is_active: bool = Field(default=True, sa_column_kwargs={"nullable": False})
    created_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )
    updated_at: datetime = Field(
        default_factory=datetime.utcnow, sa_column_kwargs={"nullable": False}
    )

    devices: list["Device"] = Relationship(back_populates="linked_user")
    assigned_plans: list["Plan"] = Relationship(
        back_populates="assignee",
        sa_relationship_kwargs={"foreign_keys": "Plan.assignee_user_id"},
    )
    created_plans: list["Plan"] = Relationship(
        back_populates="creator",
        sa_relationship_kwargs={"foreign_keys": "Plan.created_by_user_id"},
    )
    approvals: list["Approval"] = Relationship(back_populates="acted_by_user")
    attachments: list["Attachment"] = Relationship(back_populates="uploaded_by_user")
    submissions: list["SubtaskSubmission"] = Relationship(
        back_populates="submitted_by_user"
    )
    activity_logs: list["ActivityLog"] = Relationship(back_populates="user")
    xp_events: list["XPEvent"] = Relationship(back_populates="user")
