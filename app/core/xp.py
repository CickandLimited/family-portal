"""Utilities for XP calculations and presentation."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Iterable

from app.models.plans import Plan
from app.models.tasks import PlanDay, Subtask, SubtaskStatus
from app.models.xp import XPEvent

XP_PER_LEVEL = 100
DAY_COMPLETION_BONUS = 20
PLAN_COMPLETION_BONUS = 50

XP_APPROVAL_REASON = "subtask.approved"
XP_DAY_COMPLETION_REASON = "plan_day.completed"
XP_PLAN_COMPLETION_REASON = "plan.completed"


@dataclass(frozen=True)
class XPProgress:
    """Represents progress within the current XP level."""

    level: int
    xp_into_level: int
    xp_to_next_level: int
    progress_percent: int


XP_REASON_LABELS: dict[str, str] = {
    XP_APPROVAL_REASON: "Subtask approved",
    XP_DAY_COMPLETION_REASON: "Day completion bonus",
    XP_PLAN_COMPLETION_REASON: "Plan completion bonus",
}


def calculate_level(total_xp: int, xp_per_level: int = XP_PER_LEVEL) -> int:
    """Return the current level for ``total_xp`` with ``xp_per_level`` thresholds."""

    if total_xp <= 0:
        return 0
    return total_xp // xp_per_level


def progress_for_total_xp(total_xp: int, xp_per_level: int = XP_PER_LEVEL) -> XPProgress:
    """Return level and intra-level progress metrics for ``total_xp``."""

    total_xp = max(0, total_xp)
    level = calculate_level(total_xp, xp_per_level)
    xp_into_level = total_xp - (level * xp_per_level)
    xp_to_next_level = xp_per_level - xp_into_level if xp_into_level < xp_per_level else 0
    progress_percent = (
        0 if xp_into_level <= 0 else min(100, round((xp_into_level / xp_per_level) * 100))
    )

    return XPProgress(
        level=level,
        xp_into_level=xp_into_level,
        xp_to_next_level=xp_to_next_level,
        progress_percent=progress_percent,
    )


def calculate_user_total_xp(events: Iterable[XPEvent]) -> int:
    """Aggregate ``events`` into a total XP value."""

    return sum(event.delta for event in events)


def is_day_complete(day: PlanDay) -> bool:
    """Return ``True`` if every subtask in ``day`` has been approved."""

    if not day.subtasks:
        return True

    return all(subtask.status == SubtaskStatus.APPROVED for subtask in day.subtasks)


def is_day_bonus_eligible(day: PlanDay) -> bool:
    """Return ``True`` when ``day`` should award the completion bonus."""

    return bool(day.subtasks) and is_day_complete(day)


def is_plan_complete(plan: Plan) -> bool:
    """Return ``True`` when the plan has at least one task and all days are complete."""

    any_subtasks = False
    for day in plan.days:
        if day.subtasks:
            any_subtasks = True
        if not is_day_complete(day):
            return False

    return any_subtasks


def day_subtask_xp(day: PlanDay) -> int:
    """Return the sum of approved subtask XP for ``day``."""

    approved: Iterable[Subtask] = (
        subtask for subtask in day.subtasks if subtask.status == SubtaskStatus.APPROVED
    )
    return sum(subtask.xp_value for subtask in approved)


def calculate_day_total_xp(day: PlanDay, bonus: int = DAY_COMPLETION_BONUS) -> int:
    """Return total XP for ``day`` including bonuses when eligible."""

    total = day_subtask_xp(day)
    if total > 0 and is_day_bonus_eligible(day):
        total += bonus
    return total


def calculate_plan_total_xp(
    plan: Plan,
    day_bonus: int = DAY_COMPLETION_BONUS,
    plan_bonus: int = PLAN_COMPLETION_BONUS,
) -> int:
    """Return total XP earned for ``plan`` including bonuses."""

    total = sum(calculate_day_total_xp(day, bonus=day_bonus) for day in plan.days)
    if total > 0 and is_plan_complete(plan):
        total += plan_bonus
    return total


def calculate_plan_blueprint_total_xp(
    plan: Plan,
    day_bonus: int = DAY_COMPLETION_BONUS,
    plan_bonus: int = PLAN_COMPLETION_BONUS,
) -> int:
    """Return the total XP available within ``plan`` assuming full completion."""

    base = sum(subtask.xp_value for day in plan.days for subtask in day.subtasks)
    day_bonuses = sum(day_bonus for day in plan.days if day.subtasks)
    plan_bonus_value = plan_bonus if any(day.subtasks for day in plan.days) else 0
    return base + day_bonuses + plan_bonus_value


def reason_label(reason: str) -> str:
    """Return a human-friendly label for an XP ``reason`` string."""

    return XP_REASON_LABELS.get(reason, reason.replace("_", " ").replace(".", " ").title())

