from datetime import datetime, timedelta

from app.core.xp import (
    DAY_COMPLETION_BONUS,
    PLAN_COMPLETION_BONUS,
    XP_APPROVAL_REASON,
    XP_DAY_COMPLETION_REASON,
    calculate_plan_blueprint_total_xp,
    calculate_plan_total_xp,
    calculate_user_total_xp,
    calculate_level,
    progress_for_total_xp,
)
from app.models.plans import Plan, PlanStatus
from app.models.tasks import PlanDay, Subtask, SubtaskStatus
from app.models.xp import XPEvent


def _approved_subtask(subtask_id: int, plan_day_id: int, xp: int) -> Subtask:
    return Subtask(
        id=subtask_id,
        plan_day_id=plan_day_id,
        order_index=subtask_id,
        text=f"Task {subtask_id}",
        xp_value=xp,
        status=SubtaskStatus.APPROVED,
    )


def test_calculate_level_increments_every_hundred_xp():
    assert calculate_level(0) == 0
    assert calculate_level(99) == 0
    assert calculate_level(100) == 1
    assert calculate_level(255) == 2


def test_progress_for_total_xp_reports_remaining_and_percent():
    progress = progress_for_total_xp(135)

    assert progress.level == 1
    assert progress.xp_into_level == 35
    assert progress.xp_to_next_level == 65
    assert progress.progress_percent == 35


def test_calculate_plan_total_xp_includes_day_and_plan_bonuses():
    plan = Plan(
        id=1,
        title="Adventure",
        assignee_user_id=5,
        status=PlanStatus.IN_PROGRESS,
    )
    first_day = PlanDay(id=10, plan_id=plan.id, day_index=0, title="Day 1", locked=False)
    second_day = PlanDay(id=11, plan_id=plan.id, day_index=1, title="Day 2", locked=True)

    first_day.subtasks = [_approved_subtask(100, first_day.id, 20), _approved_subtask(101, first_day.id, 10)]
    second_day.subtasks = [_approved_subtask(200, second_day.id, 15), _approved_subtask(201, second_day.id, 5)]

    plan.days = [first_day, second_day]

    total = calculate_plan_total_xp(plan)

    assert total == (20 + 10 + DAY_COMPLETION_BONUS) + (
        15 + 5 + DAY_COMPLETION_BONUS
    ) + PLAN_COMPLETION_BONUS


def test_calculate_plan_total_xp_skips_bonuses_until_complete():
    plan = Plan(
        id=2,
        title="Training",
        assignee_user_id=9,
        status=PlanStatus.IN_PROGRESS,
    )
    day = PlanDay(id=20, plan_id=plan.id, day_index=0, title="Practice", locked=False)
    approved = _approved_subtask(300, day.id, 30)
    pending = Subtask(
        id=301,
        plan_day_id=day.id,
        order_index=1,
        text="Pending",
        xp_value=40,
        status=SubtaskStatus.SUBMITTED,
    )

    day.subtasks = [approved, pending]
    plan.days = [day]

    assert calculate_plan_total_xp(plan) == 30


def test_calculate_plan_blueprint_total_xp_adds_all_bonuses():
    plan = Plan(
        id=3,
        title="Blueprint",
        assignee_user_id=4,
        status=PlanStatus.IN_PROGRESS,
    )
    day_with_tasks = PlanDay(id=30, plan_id=plan.id, day_index=0, title="Build", locked=False)
    day_empty = PlanDay(id=31, plan_id=plan.id, day_index=1, title="Rest", locked=True)

    day_with_tasks.subtasks = [_approved_subtask(400, day_with_tasks.id, 25)]
    day_empty.subtasks = []

    plan.days = [day_with_tasks, day_empty]

    assert calculate_plan_blueprint_total_xp(plan) == 25 + DAY_COMPLETION_BONUS + PLAN_COMPLETION_BONUS


def test_calculate_user_total_xp_sums_event_deltas():
    now = datetime.utcnow()
    events = [
        XPEvent(
            id=1,
            user_id=7,
            subtask_id=10,
            delta=15,
            reason=XP_APPROVAL_REASON,
            created_at=now - timedelta(days=1),
        ),
        XPEvent(
            id=2,
            user_id=7,
            subtask_id=None,
            delta=DAY_COMPLETION_BONUS,
            reason=XP_DAY_COMPLETION_REASON,
            created_at=now,
        ),
    ]

    assert calculate_user_total_xp(events) == 15 + DAY_COMPLETION_BONUS
