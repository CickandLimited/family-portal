"""Plan locking helpers."""

from __future__ import annotations

from datetime import datetime

from app.models.plans import Plan, PlanStatus
from app.models.tasks import PlanDay, SubtaskStatus


def is_day_locked(previous_complete: bool) -> bool:
    """Determine whether a plan day should be locked."""
    return not previous_complete


def _is_day_complete(day: PlanDay) -> bool:
    """Return ``True`` when every subtask in ``day`` has been approved."""

    if not day.subtasks:
        # Allow empty days to unlock subsequent days, but avoid marking the
        # entire plan complete based solely on empty collections.
        return True

    return all(subtask.status == SubtaskStatus.APPROVED for subtask in day.subtasks)


def refresh_plan_day_locks(plan: Plan) -> bool:
    """Synchronise day lock flags and plan completion state."""

    ordered_days = sorted(plan.days, key=lambda day: day.day_index)

    if not ordered_days:
        if plan.status == PlanStatus.COMPLETE:
            plan.status = PlanStatus.IN_PROGRESS
            plan.updated_at = datetime.utcnow()
            return True
        return False

    any_changes = False
    previous_complete = True
    all_days_complete = True

    for day in ordered_days:
        day_complete = _is_day_complete(day)
        all_days_complete = all_days_complete and day_complete

        should_be_locked = is_day_locked(previous_complete)
        if day.locked != should_be_locked:
            day.locked = should_be_locked
            day.updated_at = datetime.utcnow()
            any_changes = True

        previous_complete = day_complete

    if all_days_complete:
        if plan.status != PlanStatus.COMPLETE:
            plan.status = PlanStatus.COMPLETE
            plan.updated_at = datetime.utcnow()
            any_changes = True
    elif plan.status == PlanStatus.COMPLETE:
        plan.status = PlanStatus.IN_PROGRESS
        plan.updated_at = datetime.utcnow()
        any_changes = True

    return any_changes
