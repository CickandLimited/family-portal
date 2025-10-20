from datetime import datetime, timedelta

from app.core.locking import refresh_plan_day_locks
from app.models.plans import Plan, PlanStatus
from app.models.tasks import PlanDay, Subtask, SubtaskStatus


def _make_plan_day(plan_id: int, day_index: int, locked: bool, statuses: list[SubtaskStatus]) -> PlanDay:
    day = PlanDay(
        id=day_index + 1,
        plan_id=plan_id,
        day_index=day_index,
        title=f"Day {day_index + 1}",
        locked=locked,
    )

    day.subtasks = [
        Subtask(
            id=(day_index + 1) * 10 + idx,
            plan_day_id=day.id,
            order_index=idx,
            text=f"Task {idx + 1}",
            xp_value=10,
            status=status,
        )
        for idx, status in enumerate(statuses)
    ]

    return day


def test_refresh_plan_day_locks_unlocks_next_day_on_completion():
    plan = Plan(
        id=1,
        title="Test Plan",
        assignee_user_id=1,
        status=PlanStatus.IN_PROGRESS,
        created_at=datetime.utcnow() - timedelta(days=1),
        updated_at=datetime.utcnow() - timedelta(days=1),
    )

    day_one = _make_plan_day(1, 0, locked=True, statuses=[SubtaskStatus.APPROVED, SubtaskStatus.APPROVED])
    day_two = _make_plan_day(1, 1, locked=True, statuses=[SubtaskStatus.PENDING])
    day_three = _make_plan_day(1, 2, locked=True, statuses=[SubtaskStatus.PENDING])

    plan.days = [day_one, day_two, day_three]

    changed = refresh_plan_day_locks(plan)

    assert changed is True
    assert [day.locked for day in plan.days] == [False, False, True]
    assert plan.status == PlanStatus.IN_PROGRESS


def test_refresh_plan_day_locks_marks_plan_complete_when_all_approved():
    plan = Plan(
        id=7,
        title="Adventure",
        assignee_user_id=2,
        status=PlanStatus.IN_PROGRESS,
        created_at=datetime.utcnow() - timedelta(days=2),
        updated_at=datetime.utcnow() - timedelta(days=2),
    )

    day_one = _make_plan_day(7, 0, locked=True, statuses=[SubtaskStatus.APPROVED])
    day_two = _make_plan_day(7, 1, locked=True, statuses=[SubtaskStatus.APPROVED, SubtaskStatus.APPROVED])

    plan.days = [day_one, day_two]

    refresh_plan_day_locks(plan)

    assert [day.locked for day in plan.days] == [False, False]
    assert plan.status == PlanStatus.COMPLETE


def test_refresh_plan_day_locks_reverts_complete_if_subtask_denied():
    plan = Plan(
        id=9,
        title="Regression",
        assignee_user_id=3,
        status=PlanStatus.COMPLETE,
        created_at=datetime.utcnow() - timedelta(days=3),
        updated_at=datetime.utcnow() - timedelta(days=3),
    )

    day_one = _make_plan_day(9, 0, locked=False, statuses=[SubtaskStatus.APPROVED])
    day_two = _make_plan_day(9, 1, locked=False, statuses=[SubtaskStatus.DENIED])

    plan.days = [day_one, day_two]

    changed = refresh_plan_day_locks(plan)

    assert changed is True
    assert [day.locked for day in plan.days] == [False, True]
    assert plan.status == PlanStatus.IN_PROGRESS
