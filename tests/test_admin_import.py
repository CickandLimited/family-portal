from __future__ import annotations

from typing import Generator

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient
from sqlmodel import Session

import app.api.admin as admin_module
from app.api.admin import router as admin_router
from app.core.db import get_session


class ActivityLogStub:
    def __init__(
        self,
        *,
        action: str,
        entity_type: str,
        entity_id: int,
        metadata: dict | None = None,
        device_id: str | None = None,
        user_id: int | None = None,
    ) -> None:
        self.action = action
        self.entity_type = entity_type
        self.entity_id = entity_id
        self.metadata = metadata
        self.device_id = device_id
        self.user_id = user_id


def log_activity_stub(
    session: StubSession,
    *,
    action: str,
    entity_type: str,
    entity_id: int,
    metadata: dict | None = None,
    device: object | None = None,
    user: object | None = None,
    device_id: str | None = None,
    user_id: int | None = None,
    commit: bool = False,
) -> ActivityLogStub:
    resolved_device_id = device_id or getattr(device, "id", None)
    resolved_user_id = user_id or getattr(user, "id", None)

    entry = ActivityLogStub(
        action=action,
        entity_type=entity_type,
        entity_id=entity_id,
        metadata=metadata,
        device_id=resolved_device_id,
        user_id=resolved_user_id,
    )
    session.add(entry)
    if commit:
        session.commit()
    return entry


SAMPLE_MARKDOWN = """# Trip Plan\n\n## Day 1 – Arrival\n- [ ] Check in at hotel\n"""


class StubSession:
    def __init__(self) -> None:
        self.added: list[object] = []
        self.commits = 0
        self.rollbacks = 0

    def add(self, obj: object) -> None:
        self.added.append(obj)

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:
        self.rollbacks += 1


@pytest.fixture()
def session() -> StubSession:
    return StubSession()


@pytest.fixture()
def client(session: StubSession) -> Generator[TestClient, None, None]:
    app = FastAPI()
    app.include_router(admin_router)

    def get_session_override() -> Generator[Session, None, None]:
        yield session

    app.dependency_overrides[get_session] = get_session_override
    with TestClient(app) as client:
        yield client
    app.dependency_overrides.clear()


def test_admin_import_success_creates_activity_log(
    client: TestClient, session: StubSession, monkeypatch: pytest.MonkeyPatch
):
    plan_id = 123

    def fake_import(content: str, assignee_user_id: int, db_session: Session) -> int:
        assert assignee_user_id == 7
        assert isinstance(content, str)
        return plan_id

    monkeypatch.setattr(admin_module, "import_markdown_plan", fake_import)
    monkeypatch.setattr(admin_module, "log_activity", log_activity_stub)

    response = client.post(
        "/admin/import",
        data={"assignee_user_id": "7"},
        files={"file": ("plan.md", SAMPLE_MARKDOWN.encode("utf-8"), "text/markdown")},
    )

    assert response.status_code == 200
    body = response.json()
    assert body == {"ok": True, "plan_id": plan_id}

    assert session.rollbacks == 0
    assert session.commits == 1
    assert len(session.added) == 1
    log = session.added[0]
    assert isinstance(log, ActivityLogStub)
    assert log.action == "plan.imported"
    assert log.entity_type == "plan"
    assert log.entity_id == plan_id
    assert log.metadata == {
        "filename": "plan.md",
        "assignee_user_id": 7,
    }
    assert log.device_id is None
    assert log.user_id is None


def test_admin_import_failure_logs_activity(
    client: TestClient, session: StubSession, monkeypatch: pytest.MonkeyPatch
):
    def fake_import(content: str, assignee_user_id: int, db_session: Session) -> int:
        raise ValueError("Markdown plan is empty.")

    monkeypatch.setattr(admin_module, "import_markdown_plan", fake_import)
    monkeypatch.setattr(admin_module, "log_activity", log_activity_stub)

    response = client.post(
        "/admin/import",
        data={"assignee_user_id": "5"},
        files={"file": ("bad.md", b"", "text/markdown")},
    )

    assert response.status_code == 400
    body = response.json()
    assert body == {"detail": "Markdown plan is empty."}

    assert session.rollbacks == 1
    assert session.commits == 1
    assert len(session.added) == 1
    log = session.added[0]
    assert isinstance(log, ActivityLogStub)
    assert log.action == "plan.import_failed"
    assert log.entity_type == "plan"
    assert log.entity_id == 0
    assert log.metadata == {
        "filename": "bad.md",
        "assignee_user_id": 5,
        "error": "Markdown plan is empty.",
    }
