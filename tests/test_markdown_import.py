from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path

import pytest

from app.core import markdown_import
from app.core.markdown_import import import_markdown_plan, parse_markdown_plan
from app.models.plans import PlanStatus


FIXTURE_DIR = Path(__file__).parent / "fixtures"


def load_fixture(name: str) -> str:
    return (FIXTURE_DIR / name).read_text(encoding="utf-8")


def test_parse_markdown_plan_structure():
    parsed = parse_markdown_plan(load_fixture("sample_plan.md"))

    assert parsed.title == "Spring Break Adventure"
    assert [day.heading_number for day in parsed.days] == [1, 2, 3]
    assert [day.title for day in parsed.days] == [
        "Arrival",
        "Exploration",
        "Farewell",
    ]

    first_day_xp = [task.xp for task in parsed.days[0].subtasks]
    assert first_day_xp == [20, 15]

    second_day_text = [task.text for task in parsed.days[1].subtasks]
    assert second_day_text == [
        "Visit the science museum",
        "Try the local cafe",
    ]
    assert [task.xp for task in parsed.days[1].subtasks] == [10, 5]
    assert [task.xp for task in parsed.days[2].subtasks] == [25]


def test_parse_markdown_defaults_missing_xp_to_ten():
    parsed = parse_markdown_plan(load_fixture("missing_xp_annotations.md"))

    assert parsed.title == "No XP Provided"
    assert len(parsed.days) == 1
    assert [task.xp for task in parsed.days[0].subtasks] == [10, 10]


@pytest.mark.parametrize(
    "filename, expected_message",
    [
        (
            "duplicate_day_numbers.md",
            "Day headings must be sequential starting at 1; expected Day 2, found Day 1.",
        ),
        ("empty_day_tasks.md", "Day 2 has no checklist items."),
    ],
)
def test_parse_markdown_plan_validation_errors(filename: str, expected_message: str):
    with pytest.raises(ValueError) as excinfo:
        parse_markdown_plan(load_fixture(filename))

    assert str(excinfo.value) == expected_message


@dataclass
class StubSubtask:
    order_index: int
    text: str
    xp_value: int
    id: int | None = None


@dataclass
class StubPlanDay:
    day_index: int
    title: str
    locked: bool
    id: int | None = None
    subtasks: list[StubSubtask] = field(default_factory=list)


@dataclass
class StubPlan:
    title: str
    assignee_user_id: int
    status: PlanStatus
    id: int | None = None
    total_xp: int = 0
    days: list[StubPlanDay] = field(default_factory=list)


class FakeSession:
    def __init__(self) -> None:
        self.added: list[StubPlan] = []
        self.flushed = False
        self.committed = False
        self._next_id = 1

    def add(self, obj: StubPlan) -> None:
        self.added.append(obj)

    def flush(self) -> None:
        if not self.added:
            return
        plan = self.added[0]
        if plan.id is None:
            plan.id = self._next_id
            self._next_id += 1
        for day in plan.days:
            if day.id is None:
                day.id = self._next_id
                self._next_id += 1
            for subtask in day.subtasks:
                if subtask.id is None:
                    subtask.id = self._next_id
                    self._next_id += 1
        self.flushed = True

    def commit(self) -> None:
        self.committed = True


def test_import_markdown_plan_creates_full_hierarchy(monkeypatch):
    session = FakeSession()

    monkeypatch.setattr(markdown_import, "Plan", StubPlan)
    monkeypatch.setattr(markdown_import, "PlanDay", StubPlanDay)
    monkeypatch.setattr(markdown_import, "Subtask", StubSubtask)

    plan_id = import_markdown_plan(
        load_fixture("sample_plan.md"), assignee_user_id=99, session=session
    )

    assert plan_id == 1
    assert session.flushed is True
    assert session.committed is True
    assert len(session.added) == 1

    plan = session.added[0]
    assert plan.title == "Spring Break Adventure"
    assert plan.assignee_user_id == 99
    assert plan.status == PlanStatus.IN_PROGRESS
    assert plan.total_xp == 75

    assert [day.day_index for day in plan.days] == [0, 1, 2]
    assert [day.locked for day in plan.days] == [False, True, True]
    assert [day.title for day in plan.days] == [
        "Arrival",
        "Exploration",
        "Farewell",
    ]

    first_day_tasks = plan.days[0].subtasks
    assert [task.order_index for task in first_day_tasks] == [0, 1]
    assert [task.text for task in first_day_tasks] == [
        "Check in at hotel",
        "Walk the boardwalk",
    ]
    assert [task.xp_value for task in first_day_tasks] == [20, 15]

    second_day_tasks = plan.days[1].subtasks
    assert [task.order_index for task in second_day_tasks] == [0, 1]
    assert [task.text for task in second_day_tasks] == [
        "Visit the science museum",
        "Try the local cafe",
    ]
    assert [task.xp_value for task in second_day_tasks] == [10, 5]

    third_day_tasks = plan.days[2].subtasks
    assert [task.order_index for task in third_day_tasks] == [0]
    assert [task.text for task in third_day_tasks] == ["Pack souvenirs"]
    assert [task.xp_value for task in third_day_tasks] == [25]

