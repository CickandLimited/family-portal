"""Utilities for importing structured plans from markdown documents."""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

from sqlmodel import Session

from app.models.plans import Plan, PlanStatus
from app.models.tasks import PlanDay, Subtask


_DAY_HEADING_PATTERN = re.compile(
    r"^##\s+Day\s+(?P<day_number>\d+)\s+[\u2013\u2014\-]\s+(?P<title>.+)$"
)
_TASK_PATTERN = re.compile(r"^[*-]\s+\[\s?]\s*(?P<text>.+)$")
_XP_SUFFIX_PATTERN = re.compile(r"\((?P<xp>\d+)\s*XP\)$", re.IGNORECASE)


@dataclass(slots=True)
class ParsedSubtask:
    """Lightweight container for parsed subtasks."""

    text: str
    xp: int


@dataclass(slots=True)
class ParsedDay:
    """Container representing a parsed day section."""

    title: str
    heading_number: int
    subtasks: list[ParsedSubtask]


@dataclass(slots=True)
class ParsedPlan:
    """Structured representation of a parsed markdown plan."""

    title: str
    days: list[ParsedDay]


def _normalize_markdown_source(source: str | Path) -> str:
    """Return the markdown text from a string or file path."""

    if isinstance(source, Path):
        return source.read_text(encoding="utf-8")
    return str(source)


def _parse_plan_title(lines: Iterable[str]) -> str:
    """Extract the top-level markdown title."""

    for raw_line in lines:
        line = raw_line.strip()
        if not line:
            continue
        if line.startswith("# "):
            return line[2:].strip()
        raise ValueError("Markdown plan must start with a '# ' title heading.")
    raise ValueError("Markdown plan is empty; no title heading found.")


def _extract_xp_value(text: str) -> tuple[str, int]:
    """Return the task text and XP value, defaulting XP to 10 when omitted."""

    match = _XP_SUFFIX_PATTERN.search(text)
    if match:
        xp = int(match.group("xp"))
        cleaned_text = text[: match.start()].rstrip()
        if not cleaned_text:
            raise ValueError("Task description cannot be empty.")
        return cleaned_text, xp
    cleaned_text = text.strip()
    if not cleaned_text:
        raise ValueError("Task description cannot be empty.")
    return cleaned_text, 10


def parse_markdown_plan(source: str | Path) -> ParsedPlan:
    """Parse a markdown plan document into structured data.

    Parameters
    ----------
    source:
        The markdown text or path to a markdown file containing a plan.

    Returns
    -------
    ParsedPlan
        Structured representation of the plan.
    """

    markdown = _normalize_markdown_source(source)
    lines = markdown.splitlines()
    if not lines:
        raise ValueError("Markdown plan is empty.")

    title = _parse_plan_title(lines)

    days: list[ParsedDay] = []
    current_day: ParsedDay | None = None

    for raw_line in lines:
        line = raw_line.strip()
        if not line or line.startswith("# "):
            # Skip blank lines and the title heading (already captured)
            continue

        day_match = _DAY_HEADING_PATTERN.match(line)
        if day_match:
            day_number = int(day_match.group("day_number"))

            if current_day is None:
                expected_number = 1
            else:
                if not current_day.subtasks:
                    raise ValueError(
                        f"Day {current_day.heading_number} has no checklist items."
                    )
                expected_number = current_day.heading_number + 1
                days.append(current_day)

            if day_number != expected_number:
                raise ValueError(
                    "Day headings must be sequential starting at 1; "
                    f"expected Day {expected_number}, found Day {day_number}."
                )

            day_title = day_match.group("title").strip()
            if not day_title:
                raise ValueError("Day title cannot be empty.")

            current_day = ParsedDay(title=day_title, heading_number=day_number, subtasks=[])
            continue

        task_match = _TASK_PATTERN.match(line)
        if task_match:
            if current_day is None:
                raise ValueError("Checklist items must appear under a day heading.")
            task_text = task_match.group("text").strip()
            cleaned_text, xp_value = _extract_xp_value(task_text)
            current_day.subtasks.append(ParsedSubtask(text=cleaned_text, xp=xp_value))
            continue

        # Any other content is considered malformed for this importer.
        raise ValueError(f"Unrecognized markdown content: '{line}'.")

    if current_day is None:
        raise ValueError("No day sections found in markdown plan.")

    if not current_day.subtasks:
        raise ValueError(f"Day {current_day.heading_number} has no checklist items.")

    days.append(current_day)

    return ParsedPlan(title=title, days=days)


def _create_plan_hierarchy(
    plan_data: ParsedPlan,
    assignee_user_id: int,
    session: Session,
) -> Plan:
    """Persist plan, days, and subtasks based on parsed data."""

    plan = Plan(
        title=plan_data.title,
        assignee_user_id=assignee_user_id,
        status=PlanStatus.IN_PROGRESS,
    )
    session.add(plan)

    total_xp = 0

    for day_index, day in enumerate(plan_data.days):
        plan_day = PlanDay(
            day_index=day_index,
            title=day.title,
            locked=day_index != 0,
        )
        plan.days.append(plan_day)

        for order_index, subtask in enumerate(day.subtasks):
            plan_day.subtasks.append(
                Subtask(
                    order_index=order_index,
                    text=subtask.text,
                    xp_value=subtask.xp,
                )
            )
            total_xp += subtask.xp

    plan.total_xp = total_xp
    return plan


def import_markdown_plan(
    md_text: str | Path,
    assignee_user_id: int,
    session: Session,
) -> int:
    """Create plan records based on a markdown document and return the plan ID."""

    parsed_plan = parse_markdown_plan(md_text)
    plan = _create_plan_hierarchy(parsed_plan, assignee_user_id, session)
    session.flush()
    plan_id = plan.id
    if plan_id is None:  # pragma: no cover - defensive guard
        raise RuntimeError("Failed to persist plan from markdown.")
    session.commit()
    return plan_id

