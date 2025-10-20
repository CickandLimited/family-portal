from __future__ import annotations

from dataclasses import dataclass, field

from app.core import markdown_import
from app.core.markdown_import import import_markdown_plan, parse_markdown_plan
from app.models.plans import PlanStatus


MARKDOWN_PLAN = """# Summer Adventure Plan

## Day 1 – Arrival
- [ ] Check in at hotel (20 XP)
- [ ] Explore the riverside

## Day 2 – Adventure
- [ ] Hike the mountain trail (30 XP)
- [ ] Take sunset photos (15 xp)
"""


def test_parse_markdown_plan_structure():
    parsed = parse_markdown_plan(MARKDOWN_PLAN)

    assert parsed.title == "Summer Adventure Plan"
    assert len(parsed.days) == 2
    assert parsed.days[0].title == "Arrival"
    assert [task.xp for task in parsed.days[0].subtasks] == [20, 10]
    assert parsed.days[1].subtasks[1].xp == 15


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

    plan_id = import_markdown_plan(MARKDOWN_PLAN, assignee_user_id=99, session=session)

    assert plan_id == 1
    assert session.flushed is True
    assert session.committed is True
    assert len(session.added) == 1

    plan = session.added[0]
    assert plan.title == "Summer Adventure Plan"
    assert plan.assignee_user_id == 99
    assert plan.status == PlanStatus.IN_PROGRESS
    assert plan.total_xp == 75

    assert [day.day_index for day in plan.days] == [0, 1]
    assert [day.locked for day in plan.days] == [False, True]
    assert [day.title for day in plan.days] == ["Arrival", "Adventure"]

    first_day_tasks = plan.days[0].subtasks
    assert [task.order_index for task in first_day_tasks] == [0, 1]
    assert [task.xp_value for task in first_day_tasks] == [20, 10]
    assert [task.text for task in first_day_tasks] == [
        "Check in at hotel",
        "Explore the riverside",
    ]

    second_day_tasks = plan.days[1].subtasks
    assert [task.xp_value for task in second_day_tasks] == [30, 15]

