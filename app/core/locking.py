"""Plan locking helpers and progress utilities."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from typing import Iterable
from weakref import WeakKeyDictionary

from app.models.plans import Plan, PlanStatus
from app.models.tasks import PlanDay, SubtaskStatus


@dataclass(frozen=True)
class DayProgress:
    """Progress metrics for an individual plan day."""

    approved_subtasks: int
    total_subtasks: int

    @property
    def percent_complete(self) -> int:
        """Return the percentage of approved subtasks for the day."""

        if self.total_subtasks == 0:
            return 100
        return round((self.approved_subtasks / self.total_subtasks) * 100)

    @property
    def is_complete(self) -> bool:
        """Return ``True`` when the day has no pending approvals."""

        if self.total_subtasks == 0:
            return True
        return self.approved_subtasks == self.total_subtasks


@dataclass(frozen=True)
class PlanProgress:
    """Aggregate progress metrics across an entire plan."""

    approved_subtasks: int
    total_subtasks: int
    completed_days: int
    total_days: int

    @property
    def percent_complete(self) -> int:
        """Return percentage of approved subtasks for the plan."""

        if self.total_subtasks == 0:
            return 0
        return round((self.approved_subtasks / self.total_subtasks) * 100)

    @property
    def day_percent_complete(self) -> int:
        """Return the percentage of days marked complete."""

        if self.total_days == 0:
            return 0
        return round((self.completed_days / self.total_days) * 100)

    @property
    def is_complete(self) -> bool:
        """Return ``True`` when all days are complete."""

        if self.total_days == 0:
            return False
        return self.completed_days == self.total_days


class ProgressCache:
    """Memoize day and plan progress computations within a request scope."""

    def __init__(self) -> None:
        self._day_cache: WeakKeyDictionary[PlanDay, DayProgress] = WeakKeyDictionary()
        self._plan_cache: WeakKeyDictionary[Plan, PlanProgress] = WeakKeyDictionary()

    def day_progress(self, day: PlanDay) -> DayProgress:
        """Return cached day progress for ``day``."""

        try:
            return self._day_cache[day]
        except KeyError:
            metrics = _calculate_day_progress(day)
            self._day_cache[day] = metrics
            return metrics

    def plan_progress(self, plan: Plan) -> PlanProgress:
        """Return cached plan progress for ``plan``."""

        try:
            return self._plan_cache[plan]
        except KeyError:
            metrics = _calculate_plan_progress(plan, cache=self)
            self._plan_cache[plan] = metrics
            return metrics


def _calculate_day_progress(day: PlanDay) -> DayProgress:
    """Return fresh progress metrics for ``day``."""

    approved = sum(
        1 for subtask in day.subtasks if subtask.status == SubtaskStatus.APPROVED
    )
    total = len(day.subtasks)
    return DayProgress(approved_subtasks=approved, total_subtasks=total)


def _calculate_plan_progress(plan: Plan, *, cache: ProgressCache) -> PlanProgress:
    """Return fresh plan progress metrics for ``plan`` using ``cache`` for days."""

    approved_subtasks = 0
    total_subtasks = 0
    completed_days = 0
    total_days = 0

    for day in sorted(plan.days, key=lambda d: d.day_index):
        total_days += 1
        metrics = cache.day_progress(day)
        approved_subtasks += metrics.approved_subtasks
        total_subtasks += metrics.total_subtasks
        if metrics.is_complete:
            completed_days += 1

    return PlanProgress(
        approved_subtasks=approved_subtasks,
        total_subtasks=total_subtasks,
        completed_days=completed_days,
        total_days=total_days,
    )


def calculate_day_progress(day: PlanDay, *, cache: ProgressCache | None = None) -> DayProgress:
    """Return cached or direct progress metrics for ``day``."""

    if cache is None:
        cache = ProgressCache()
    return cache.day_progress(day)


def calculate_plan_progress(plan: Plan, *, cache: ProgressCache | None = None) -> PlanProgress:
    """Return cached or direct plan progress metrics."""

    if cache is None:
        cache = ProgressCache()
    return cache.plan_progress(plan)


def is_day_locked(previous_complete: bool) -> bool:
    """Determine whether a plan day should be locked."""
    return not previous_complete


def _iter_ordered_days(plan: Plan) -> Iterable[PlanDay]:
    """Return plan days ordered by ``day_index``."""

    return sorted(plan.days, key=lambda day: day.day_index)


def _is_day_complete(day: PlanDay, *, cache: ProgressCache | None = None) -> bool:
    """Return ``True`` when every subtask in ``day`` has been approved."""

    if cache is None:
        cache = ProgressCache()
    return cache.day_progress(day).is_complete


def refresh_plan_day_locks(plan: Plan) -> bool:
    """Synchronise day lock flags and plan completion state."""

    progress_cache = ProgressCache()
    ordered_days = list(_iter_ordered_days(plan))

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
        day_complete = _is_day_complete(day, cache=progress_cache)
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
