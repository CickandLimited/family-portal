"""create core tables

Revision ID: 274e47a135b1
Revises: 
Create Date: 2025-10-20 17:33:45.454460

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa


# revision identifiers, used by Alembic.
revision: str = '274e47a135b1'
down_revision: Union[str, Sequence[str], None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None
user_role_enum = sa.Enum("admin", "user", name="userrole", native_enum=False)
plan_status_enum = sa.Enum(
    "draft", "in_progress", "complete", "archived", name="planstatus", native_enum=False
)
subtask_status_enum = sa.Enum(
    "pending", "submitted", "approved", "denied", name="subtaskstatus", native_enum=False
)
approval_action_enum = sa.Enum("approve", "deny", name="approvalaction", native_enum=False)
approval_mood_enum = sa.Enum("happy", "neutral", "sad", name="approvalmood", native_enum=False)


def upgrade() -> None:
    """Upgrade schema."""

    op.create_table(
        "user",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("display_name", sa.String(length=200), nullable=False),
        sa.Column("role", user_role_enum, nullable=False, server_default="user"),
        sa.Column("avatar", sa.String(length=500), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("1")),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.Column(
            "updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
    )
    op.create_index("ix_user_display_name", "user", ["display_name"], unique=False)

    op.create_table(
        "device",
        sa.Column("id", sa.String(length=36), primary_key=True),
        sa.Column("friendly_name", sa.String(length=200), nullable=True),
        sa.Column("linked_user_id", sa.Integer(), nullable=True),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.Column("last_seen_at", sa.DateTime(), nullable=True),
        sa.ForeignKeyConstraint(["linked_user_id"], ["user.id"], ondelete="SET NULL"),
    )
    op.create_index("ix_device_id", "device", ["id"], unique=False)
    op.create_index(
        "ix_device_linked_user_id", "device", ["linked_user_id"], unique=False
    )

    op.create_table(
        "plan",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("title", sa.String(length=200), nullable=False),
        sa.Column("assignee_user_id", sa.Integer(), nullable=False),
        sa.Column(
            "status", plan_status_enum, nullable=False, server_default="in_progress"
        ),
        sa.Column("created_by_user_id", sa.Integer(), nullable=True),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.Column(
            "updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.Column("total_xp", sa.Integer(), nullable=False, server_default="0"),
        sa.ForeignKeyConstraint(
            ["assignee_user_id"], ["user.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["created_by_user_id"], ["user.id"], ondelete="SET NULL"
        ),
    )
    op.create_index(
        "ix_plan_assignee_user_id", "plan", ["assignee_user_id"], unique=False
    )
    op.create_index(
        "ix_plan_created_by_user_id", "plan", ["created_by_user_id"], unique=False
    )
    op.create_index("ix_plan_status", "plan", ["status"], unique=False)

    op.create_table(
        "plan_day",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("plan_id", sa.Integer(), nullable=False),
        sa.Column("day_index", sa.Integer(), nullable=False),
        sa.Column("title", sa.String(length=200), nullable=False),
        sa.Column("locked", sa.Boolean(), nullable=False, server_default=sa.text("1")),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.Column(
            "updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.ForeignKeyConstraint(["plan_id"], ["plan.id"], ondelete="CASCADE"),
        sa.UniqueConstraint("plan_id", "day_index", name="uq_plan_day_plan_index"),
    )
    op.create_index("ix_plan_day_plan_id", "plan_day", ["plan_id"], unique=False)

    op.create_table(
        "subtask",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("plan_day_id", sa.Integer(), nullable=False),
        sa.Column("order_index", sa.Integer(), nullable=False),
        sa.Column("text", sa.String(length=500), nullable=False),
        sa.Column("xp_value", sa.Integer(), nullable=False, server_default="10"),
        sa.Column(
            "status", subtask_status_enum, nullable=False, server_default="pending"
        ),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.Column(
            "updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.ForeignKeyConstraint(["plan_day_id"], ["plan_day.id"], ondelete="CASCADE"),
        sa.UniqueConstraint(
            "plan_day_id", "order_index", name="uq_subtask_plan_day_order"
        ),
    )
    op.create_index(
        "ix_subtask_plan_day_id", "subtask", ["plan_day_id"], unique=False
    )

    op.create_table(
        "subtask_submission",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("subtask_id", sa.Integer(), nullable=False),
        sa.Column("submitted_by_device_id", sa.String(length=36), nullable=False),
        sa.Column("submitted_by_user_id", sa.Integer(), nullable=True),
        sa.Column("photo_path", sa.String(length=500), nullable=True),
        sa.Column("comment", sa.String(length=500), nullable=True),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.ForeignKeyConstraint(
            ["subtask_id"], ["subtask.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["submitted_by_device_id"], ["device.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["submitted_by_user_id"], ["user.id"], ondelete="SET NULL"
        ),
    )
    op.create_index(
        "ix_subtask_submission_subtask_id",
        "subtask_submission",
        ["subtask_id"],
        unique=False,
    )
    op.create_index(
        "ix_subtask_submission_device_id",
        "subtask_submission",
        ["submitted_by_device_id"],
        unique=False,
    )
    op.create_index(
        "ix_subtask_submission_user_id",
        "subtask_submission",
        ["submitted_by_user_id"],
        unique=False,
    )

    op.create_table(
        "approval",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("subtask_id", sa.Integer(), nullable=False),
        sa.Column(
            "action", approval_action_enum, nullable=False, server_default="approve"
        ),
        sa.Column("mood", approval_mood_enum, nullable=False, server_default="neutral"),
        sa.Column("reason", sa.String(length=500), nullable=True),
        sa.Column("acted_by_device_id", sa.String(length=36), nullable=False),
        sa.Column("acted_by_user_id", sa.Integer(), nullable=True),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.ForeignKeyConstraint(
            ["subtask_id"], ["subtask.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["acted_by_device_id"], ["device.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["acted_by_user_id"], ["user.id"], ondelete="SET NULL"
        ),
    )
    op.create_index(
        "ix_approval_subtask_id", "approval", ["subtask_id"], unique=False
    )
    op.create_index(
        "ix_approval_device_id", "approval", ["acted_by_device_id"], unique=False
    )
    op.create_index(
        "ix_approval_user_id", "approval", ["acted_by_user_id"], unique=False
    )

    op.create_table(
        "attachment",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("plan_id", sa.Integer(), nullable=True),
        sa.Column("subtask_id", sa.Integer(), nullable=True),
        sa.Column("file_path", sa.String(length=500), nullable=False),
        sa.Column("thumb_path", sa.String(length=500), nullable=False),
        sa.Column("uploaded_by_device_id", sa.String(length=36), nullable=False),
        sa.Column("uploaded_by_user_id", sa.Integer(), nullable=True),
        sa.Column(
            "created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.ForeignKeyConstraint(["plan_id"], ["plan.id"], ondelete="CASCADE"),
        sa.ForeignKeyConstraint(["subtask_id"], ["subtask.id"], ondelete="CASCADE"),
        sa.ForeignKeyConstraint(
            ["uploaded_by_device_id"], ["device.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["uploaded_by_user_id"], ["user.id"], ondelete="SET NULL"
        ),
    )
    op.create_index("ix_attachment_plan_id", "attachment", ["plan_id"], unique=False)
    op.create_index(
        "ix_attachment_subtask_id", "attachment", ["subtask_id"], unique=False
    )
    op.create_index(
        "ix_attachment_uploaded_by_device_id",
        "attachment",
        ["uploaded_by_device_id"],
        unique=False,
    )
    op.create_index(
        "ix_attachment_uploaded_by_user_id",
        "attachment",
        ["uploaded_by_user_id"],
        unique=False,
    )

    op.create_table(
        "activity_log",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "timestamp", sa.DateTime(), nullable=False, server_default=sa.func.now()
        ),
        sa.Column("device_id", sa.String(length=36), nullable=True),
        sa.Column("user_id", sa.Integer(), nullable=True),
        sa.Column("action", sa.String(length=200), nullable=False),
        sa.Column("entity_type", sa.String(length=100), nullable=False),
        sa.Column("entity_id", sa.Integer(), nullable=False),
        sa.Column("metadata", sa.JSON(), nullable=True),
        sa.ForeignKeyConstraint(["device_id"], ["device.id"], ondelete="SET NULL"),
        sa.ForeignKeyConstraint(["user_id"], ["user.id"], ondelete="SET NULL"),
    )
    op.create_index(
        "ix_activity_log_device_id", "activity_log", ["device_id"], unique=False
    )
    op.create_index(
        "ix_activity_log_user_id", "activity_log", ["user_id"], unique=False
    )
    op.create_index(
        "ix_activity_log_entity", "activity_log", ["entity_type", "entity_id"], unique=False
    )


def downgrade() -> None:
    """Downgrade schema."""

    op.drop_index("ix_activity_log_entity", table_name="activity_log")
    op.drop_index("ix_activity_log_user_id", table_name="activity_log")
    op.drop_index("ix_activity_log_device_id", table_name="activity_log")
    op.drop_table("activity_log")

    op.drop_index("ix_attachment_uploaded_by_user_id", table_name="attachment")
    op.drop_index("ix_attachment_uploaded_by_device_id", table_name="attachment")
    op.drop_index("ix_attachment_subtask_id", table_name="attachment")
    op.drop_index("ix_attachment_plan_id", table_name="attachment")
    op.drop_table("attachment")

    op.drop_index("ix_approval_user_id", table_name="approval")
    op.drop_index("ix_approval_device_id", table_name="approval")
    op.drop_index("ix_approval_subtask_id", table_name="approval")
    op.drop_table("approval")

    op.drop_index("ix_subtask_submission_user_id", table_name="subtask_submission")
    op.drop_index("ix_subtask_submission_device_id", table_name="subtask_submission")
    op.drop_index("ix_subtask_submission_subtask_id", table_name="subtask_submission")
    op.drop_table("subtask_submission")

    op.drop_index("ix_subtask_plan_day_id", table_name="subtask")
    op.drop_table("subtask")

    op.drop_index("ix_plan_day_plan_id", table_name="plan_day")
    op.drop_table("plan_day")

    op.drop_index("ix_plan_status", table_name="plan")
    op.drop_index("ix_plan_created_by_user_id", table_name="plan")
    op.drop_index("ix_plan_assignee_user_id", table_name="plan")
    op.drop_table("plan")

    op.drop_index("ix_device_linked_user_id", table_name="device")
    op.drop_index("ix_device_id", table_name="device")
    op.drop_table("device")

    op.drop_index("ix_user_display_name", table_name="user")
    op.drop_table("user")

    approval_mood_enum.drop(op.get_bind(), checkfirst=False)
    approval_action_enum.drop(op.get_bind(), checkfirst=False)
    subtask_status_enum.drop(op.get_bind(), checkfirst=False)
    plan_status_enum.drop(op.get_bind(), checkfirst=False)
    user_role_enum.drop(op.get_bind(), checkfirst=False)
