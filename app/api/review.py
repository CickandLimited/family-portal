"""Review queue endpoints for approving or denying submissions."""

from __future__ import annotations

from datetime import datetime
from typing import Any, Iterable

from fastapi import APIRouter, Depends, Form, HTTPException, Request, status
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy.orm import selectinload
from sqlmodel import Session, select

from app.core.db import get_session
from app.core.locking import refresh_plan_day_locks
from app.models.activity import ActivityLog
from app.models.approvals import Approval, ApprovalAction, ApprovalMood
from app.models.devices import Device
from app.models.plans import Plan
from app.models.tasks import PlanDay, Subtask, SubtaskStatus, SubtaskSubmission
from app.models.users import User
from app.models.xp import XPEvent

router = APIRouter(prefix="/review")
templates = Jinja2Templates(directory="app/templates")

_REVIEW_LOAD_OPTIONS: Iterable[Any] = (
    selectinload(Subtask.plan_day)
    .selectinload(PlanDay.plan)
    .selectinload(Plan.assignee),
    selectinload(Subtask.plan_day)
    .selectinload(PlanDay.plan)
    .selectinload(Plan.days)
    .selectinload(PlanDay.subtasks),
    selectinload(Subtask.plan_day).selectinload(PlanDay.subtasks),
    selectinload(Subtask.submissions).selectinload(SubtaskSubmission.submitted_by_user),
    selectinload(Subtask.submissions)
    .selectinload(SubtaskSubmission.submitted_by_device)
    .selectinload(Device.linked_user),
)

MOOD_OPTIONS: list[dict[str, str]] = [
    {"value": ApprovalMood.HAPPY.value, "label": "Happy"},
    {"value": ApprovalMood.NEUTRAL.value, "label": "Neutral"},
    {"value": ApprovalMood.SAD.value, "label": "Concerned"},
]

XP_APPROVAL_REASON = "subtask.approved"


def _subtask_review_statement():
    """Return a select statement with eager-load configuration for reviews."""

    return select(Subtask).options(*_REVIEW_LOAD_OPTIONS)


def _resolve_request_actor(
    session: Session, request: Request
) -> tuple[Device | None, User | None]:
    """Return the acting device and user associated with the request."""

    device_obj = getattr(request.state, "device", None)
    acting_device: Device | None = None
    if device_obj is not None:
        acting_device = session.exec(
            select(Device)
            .where(Device.id == device_obj.id)
            .options(selectinload(Device.linked_user))
        ).one_or_none()
        if acting_device is None:
            acting_device = device_obj  # Fallback to detached instance for attrs

    user_obj = getattr(request.state, "user", None)
    acting_user: User | None = None
    if user_obj is not None:
        acting_user = session.get(User, user_obj.id)

    return acting_device, acting_user


def _device_label(device: Device) -> str:
    """Return a human-friendly label for a device."""

    if device.friendly_name:
        return device.friendly_name
    return f"Device {device.id}"


def _device_context(device: Device | None) -> dict[str, Any] | None:
    """Return a serialisable context payload for a device."""

    if device is None:
        return None

    label = _device_label(device)
    linked_name = None
    if getattr(device, "linked_user", None):
        linked_name = device.linked_user.display_name

    return {
        "id": device.id,
        "label": label,
        "friendly_name": device.friendly_name,
        "linked_user_name": linked_name,
    }


def _latest_submission(subtask: Subtask) -> SubtaskSubmission | None:
    """Return the most recent submission for ``subtask``."""

    if not subtask.submissions:
        return None

    return max(subtask.submissions, key=lambda submission: submission.created_at)


def _submission_context(submission: SubtaskSubmission) -> dict[str, Any]:
    """Build a template context dictionary for a submission."""

    actor: str | None = None
    if submission.submitted_by_user:
        actor = submission.submitted_by_user.display_name

    device_label: str | None = None
    device_linked_user: str | None = None
    if submission.submitted_by_device:
        device_label = _device_label(submission.submitted_by_device)
        if submission.submitted_by_device.linked_user:
            device_linked_user = submission.submitted_by_device.linked_user.display_name

    submitted_by = actor or device_label or "Unknown submitter"
    created_display = submission.created_at.strftime("%b %d, %Y %I:%M %p")

    return {
        "id": submission.id,
        "comment": submission.comment,
        "photo_path": submission.photo_path,
        "submitted_by": submitted_by,
        "submitted_at": submission.created_at,
        "submitted_display": created_display,
        "device_label": device_label,
        "device_linked_user": device_linked_user,
    }


def can_approve(
    subtask: Subtask, *, acting_user: User | None, acting_device: Device | None
) -> tuple[bool, str | None]:
    """Return whether the active actor can approve ``subtask``."""

    plan = subtask.plan_day.plan if subtask.plan_day else None
    assignee_user_id = getattr(plan, "assignee_user_id", None)

    if assignee_user_id is None:
        return True, None

    if acting_user and acting_user.id == assignee_user_id:
        return False, "Assignees cannot approve their own submissions."

    if acting_device and acting_device.linked_user_id == assignee_user_id:
        return False, "Devices linked to the assignee cannot approve this submission."

    return True, None


def _build_queue_item(
    subtask: Subtask,
    *,
    acting_user: User | None,
    acting_device: Device | None,
) -> dict[str, Any] | None:
    """Return a dictionary describing the queue entry for ``subtask``."""

    submission = _latest_submission(subtask)
    if submission is None:
        return None

    plan_day = subtask.plan_day
    if plan_day is None or plan_day.plan is None:
        return None

    plan = plan_day.plan
    allow, message = can_approve(
        subtask, acting_user=acting_user, acting_device=acting_device
    )

    return {
        "subtask_id": subtask.id,
        "subtask_text": subtask.text,
        "xp_value": subtask.xp_value,
        "plan_id": plan.id,
        "plan_title": plan.title,
        "assignee_name": plan.assignee.display_name if plan.assignee else None,
        "day_number": plan_day.day_index + 1,
        "day_title": plan_day.title,
        "latest_submission": _submission_context(submission),
        "approval_allowed": allow,
        "approval_message": message,
    }


@router.get("", response_class=HTMLResponse)
def queue(request: Request, session: Session = Depends(get_session)):
    """Render the pending review queue."""

    acting_device, acting_user = _resolve_request_actor(session, request)

    stmt = (
        _subtask_review_statement()
        .where(Subtask.status == SubtaskStatus.SUBMITTED)
        .order_by(Subtask.updated_at.desc())
    )
    subtasks = session.exec(stmt).all()

    items: list[dict[str, Any]] = []
    for subtask in subtasks:
        item = _build_queue_item(
            subtask, acting_user=acting_user, acting_device=acting_device
        )
        if item:
            items.append(item)

    context = {
        "request": request,
        "items": items,
        "mood_options": MOOD_OPTIONS,
        "default_mood": ApprovalMood.NEUTRAL.value,
        "device": _device_context(acting_device),
    }

    return templates.TemplateResponse("review.html", context)


def _require_submission(
    subtask: Subtask, submission_id: int | None
) -> SubtaskSubmission:
    """Ensure the referenced submission exists and matches expectations."""

    submission = _latest_submission(subtask)
    if submission is None:
        raise HTTPException(status_code=400, detail="No submission available for review.")

    if submission_id is not None and submission.id != submission_id:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="The submission has changed. Refresh the queue and try again.",
        )

    return submission


def _update_plan_state(plan: Plan) -> None:
    """Refresh plan locking state and timestamps."""

    now = datetime.utcnow()
    plan.updated_at = now
    if refresh_plan_day_locks(plan):
        plan.updated_at = datetime.utcnow()


@router.post("/subtask/{subtask_id}/approve")
def approve(
    subtask_id: int,
    request: Request,
    mood: ApprovalMood = Form(...),
    submission_id: int = Form(...),
    notes: str = Form(""),
    session: Session = Depends(get_session),
):
    """Approve the most recent submission for a subtask."""

    acting_device, acting_user = _resolve_request_actor(session, request)
    if acting_device is None:
        raise HTTPException(status_code=400, detail="Device context is required for approvals.")

    subtask = session.exec(
        _subtask_review_statement().where(Subtask.id == subtask_id)
    ).one_or_none()
    if subtask is None:
        raise HTTPException(status_code=404, detail="Subtask not found")

    if subtask.status != SubtaskStatus.SUBMITTED:
        raise HTTPException(status_code=400, detail="Subtask is not awaiting review.")

    allowed, message = can_approve(
        subtask, acting_user=acting_user, acting_device=acting_device
    )
    if not allowed:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail=message)

    submission = _require_submission(subtask, submission_id)

    plan_day = subtask.plan_day
    plan = plan_day.plan if plan_day else None
    if plan is None:
        raise HTTPException(status_code=400, detail="Associated plan could not be loaded.")

    now = datetime.utcnow()
    subtask.status = SubtaskStatus.APPROVED
    subtask.updated_at = now
    if plan_day:
        plan_day.updated_at = now

    approval = Approval(
        subtask_id=subtask.id,
        action=ApprovalAction.APPROVE,
        mood=mood,
        reason=notes.strip() or None,
        acted_by_device_id=acting_device.id,
        acted_by_user_id=getattr(acting_user, "id", None),
    )
    session.add(approval)

    xp_event: XPEvent | None = None
    if plan.assignee_user_id is not None:
        xp_event = XPEvent(
            user_id=plan.assignee_user_id,
            subtask_id=subtask.id,
            delta=subtask.xp_value,
            reason=XP_APPROVAL_REASON,
        )
        session.add(xp_event)

    _update_plan_state(plan)

    session.add(subtask)
    session.add(plan)

    session.add(
        ActivityLog(
            action="subtask.approved",
            entity_type="subtask",
            entity_id=subtask.id,
            metadata_payload={
                "plan_id": plan.id,
                "plan_title": plan.title,
                "plan_day_id": getattr(plan_day, "id", None),
                "mood": mood.value,
                "xp_value": subtask.xp_value,
                "approval_notes": notes.strip() or None,
                "submission_id": submission.id,
                "xp_event": getattr(xp_event, "id", None),
            },
            device_id=acting_device.id,
            user_id=getattr(acting_user, "id", None),
        )
    )

    session.commit()

    return RedirectResponse(url=router.url_path_for("queue"), status_code=status.HTTP_303_SEE_OTHER)


@router.post("/subtask/{subtask_id}/deny")
def deny(
    subtask_id: int,
    request: Request,
    mood: ApprovalMood = Form(...),
    submission_id: int = Form(...),
    reason: str = Form(...),
    session: Session = Depends(get_session),
):
    """Deny the most recent submission for a subtask."""

    acting_device, acting_user = _resolve_request_actor(session, request)
    if acting_device is None:
        raise HTTPException(status_code=400, detail="Device context is required for approvals.")

    subtask = session.exec(
        _subtask_review_statement().where(Subtask.id == subtask_id)
    ).one_or_none()
    if subtask is None:
        raise HTTPException(status_code=404, detail="Subtask not found")

    if subtask.status != SubtaskStatus.SUBMITTED:
        raise HTTPException(status_code=400, detail="Subtask is not awaiting review.")

    allow, message = can_approve(
        subtask, acting_user=acting_user, acting_device=acting_device
    )
    if not allow:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail=message)

    submission = _require_submission(subtask, submission_id)

    plan_day = subtask.plan_day
    plan = plan_day.plan if plan_day else None
    if plan is None:
        raise HTTPException(status_code=400, detail="Associated plan could not be loaded.")

    cleaned_reason = reason.strip()
    if not cleaned_reason:
        raise HTTPException(status_code=400, detail="A reason is required when denying submissions.")

    now = datetime.utcnow()
    subtask.status = SubtaskStatus.DENIED
    subtask.updated_at = now
    if plan_day:
        plan_day.updated_at = now

    approval = Approval(
        subtask_id=subtask.id,
        action=ApprovalAction.DENY,
        mood=mood,
        reason=cleaned_reason,
        acted_by_device_id=acting_device.id,
        acted_by_user_id=getattr(acting_user, "id", None),
    )
    session.add(approval)

    _update_plan_state(plan)

    session.add(subtask)
    session.add(plan)

    session.add(
        ActivityLog(
            action="subtask.denied",
            entity_type="subtask",
            entity_id=subtask.id,
            metadata_payload={
                "plan_id": plan.id,
                "plan_title": plan.title,
                "plan_day_id": getattr(plan_day, "id", None),
                "mood": mood.value,
                "reason": cleaned_reason,
                "submission_id": submission.id,
            },
            device_id=acting_device.id,
            user_id=getattr(acting_user, "id", None),
        )
    )

    session.commit()

    return RedirectResponse(url=router.url_path_for("queue"), status_code=status.HTTP_303_SEE_OTHER)
