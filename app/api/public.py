"""Public-facing API routes for the Family Portal application."""

from __future__ import annotations

from collections import defaultdict
from datetime import datetime
from typing import Any

from fastapi import (
    APIRouter,
    Depends,
    File,
    Form,
    HTTPException,
    Request,
    UploadFile,
    status,
)
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy.orm import selectinload
from sqlmodel import Session, select

from app.core.db import get_session
from app.core.locking import refresh_plan_day_locks
from app.core.config import settings
from app.core.xp import calculate_level
from app.models.attachments import Attachment
from app.models.devices import Device
from app.models.plans import Plan, PlanStatus
from app.models.tasks import PlanDay, Subtask, SubtaskStatus, SubtaskSubmission
from app.models.users import User
from app.core.imaging import process_image

router = APIRouter()
templates = Jinja2Templates(directory="app/templates")


def _build_board_context(session: Session) -> dict:
    """Aggregate board data for rendering and HTMX partials."""

    users = session.exec(
        select(User).where(User.is_active.is_(True)).order_by(User.display_name)
    ).all()
    plans = session.exec(select(Plan).order_by(Plan.created_at.desc())).all()
    devices = session.exec(select(Device)).all()

    plans_by_user: dict[int, list[Plan]] = defaultdict(list)
    for plan in plans:
        plans_by_user[plan.assignee_user_id].append(plan)

    board_users: list[dict] = []
    family_total_xp = 0

    for user in users:
        user_plans = plans_by_user.get(user.id, [])
        active_plans = [plan for plan in user_plans if plan.status == PlanStatus.IN_PROGRESS]
        completed_plans = [plan for plan in user_plans if plan.status == PlanStatus.COMPLETE]

        total_xp = sum(plan.total_xp for plan in user_plans)
        family_total_xp += total_xp

        level = calculate_level(total_xp)
        xp_into_level = total_xp - (level * 100)
        xp_into_level = max(0, xp_into_level)
        xp_to_next_level = 100 - xp_into_level if total_xp > 0 or xp_into_level > 0 else 100
        progress_percent = (
            0 if xp_into_level <= 0 else min(100, round((xp_into_level / 100) * 100))
        )

        most_recent_plan: Plan | None = None
        if active_plans:
            most_recent_plan = max(
                active_plans,
                key=lambda plan: plan.updated_at or plan.created_at,
            )
        elif user_plans:
            most_recent_plan = max(
                user_plans,
                key=lambda plan: plan.updated_at or plan.created_at,
            )

        current_plan: dict | None = None
        if most_recent_plan is not None:
            current_plan = {
                "id": most_recent_plan.id,
                "title": most_recent_plan.title,
                "status": most_recent_plan.status.value.replace("_", " ").title(),
                "total_xp": most_recent_plan.total_xp,
            }

        linked_devices = [device for device in devices if device.linked_user_id == user.id]

        board_users.append(
            {
                "id": user.id,
                "display_name": user.display_name,
                "avatar": user.avatar,
                "level": level,
                "total_xp": total_xp,
                "xp_into_level": xp_into_level,
                "xp_to_next_level": xp_to_next_level,
                "progress_percent": progress_percent,
                "active_plan": current_plan,
                "plan_counts": {
                    "total": len(user_plans),
                    "active": len(active_plans),
                    "completed": len(completed_plans),
                },
                "device_count": len(linked_devices),
            }
        )

    active_plan_count = sum(1 for plan in plans if plan.status == PlanStatus.IN_PROGRESS)
    board_totals = {
        "user_count": len(users),
        "plan_count": len(plans),
        "active_plan_count": active_plan_count,
        "completed_plan_count": sum(
            1 for plan in plans if plan.status == PlanStatus.COMPLETE
        ),
        "device_count": len(devices),
        "family_total_xp": family_total_xp,
    }

    return {
        "users": board_users,
        "totals": board_totals,
        "has_any_plans": board_totals["plan_count"] > 0,
        "has_any_users": bool(board_users),
    }


@router.get("/", response_class=HTMLResponse)
def board(request: Request, session: Session = Depends(get_session)):
    """Render the main family board view or HTMX fragments."""

    device = getattr(request.state, "device", None)
    board_context = _build_board_context(session)

    partial = request.query_params.get("partial")
    if request.headers.get("HX-Request") == "true" and partial:
        template_name = {
            "plan-summary": "components/board_plan_summary.html",
            "user-cards": "components/board_user_cards.html",
        }.get(partial)

        if template_name:
            return templates.TemplateResponse(
                template_name,
                {
                    "request": request,
                    "board": board_context,
                },
            )

    return templates.TemplateResponse(
        "board.html",
        {
            "request": request,
            "device": device,
            "board": board_context,
        },
    )


def _attachment_context(attachment: Attachment) -> dict[str, Any]:
    uploaded_by: str | None = None
    if attachment.uploaded_by_user:
        uploaded_by = attachment.uploaded_by_user.display_name
    elif attachment.uploaded_by_device:
        uploaded_by = (
            attachment.uploaded_by_device.friendly_name
            or f"Device {attachment.uploaded_by_device.id}"
        )

    file_name = attachment.file_path.split("/")[-1]

    return {
        "id": attachment.id,
        "file_name": file_name,
        "file_path": attachment.file_path,
        "thumb_path": attachment.thumb_path,
        "uploaded_by": uploaded_by,
        "created_at": attachment.created_at,
    }


def _submission_context(submission: SubtaskSubmission) -> dict[str, Any]:
    actor: str | None = None
    device: str | None = None

    if submission.submitted_by_user:
        actor = submission.submitted_by_user.display_name
    if submission.submitted_by_device:
        device = (
            submission.submitted_by_device.friendly_name
            or f"Device {submission.submitted_by_device.id}"
        )

    if actor and device:
        submitted_by = f"{actor} via {device}"
    elif actor:
        submitted_by = actor
    elif device:
        submitted_by = device
    else:
        submitted_by = "Unknown submitter"

    created_display = submission.created_at.strftime("%b %d, %Y %I:%M %p")

    return {
        "id": submission.id,
        "submitted_by": submitted_by,
        "created_at": submission.created_at,
        "created_display": created_display,
        "comment": submission.comment,
        "photo_path": submission.photo_path,
    }


PLAN_STATUS_BADGES: dict[PlanStatus, str] = {
    PlanStatus.DRAFT: "bg-slate-200 text-slate-700",
    PlanStatus.IN_PROGRESS: "bg-blue-100 text-blue-700",
    PlanStatus.COMPLETE: "bg-emerald-100 text-emerald-700",
    PlanStatus.ARCHIVED: "bg-slate-200 text-slate-600",
}


SUBTASK_STATUS_BADGES: dict[SubtaskStatus, str] = {
    SubtaskStatus.PENDING: "bg-slate-200 text-slate-700",
    SubtaskStatus.SUBMITTED: "bg-indigo-100 text-indigo-700",
    SubtaskStatus.APPROVED: "bg-emerald-100 text-emerald-700",
    SubtaskStatus.DENIED: "bg-rose-100 text-rose-700",
}


def _plan_detail_statement(plan_id: int):
    """Return a select statement that eager-loads plan relationships."""

    return (
        select(Plan)
        .where(Plan.id == plan_id)
        .options(
            selectinload(Plan.assignee),
            selectinload(Plan.attachments).selectinload(Attachment.uploaded_by_user),
            selectinload(Plan.attachments).selectinload(Attachment.uploaded_by_device),
            selectinload(Plan.days)
            .selectinload(PlanDay.subtasks)
            .selectinload(Subtask.submissions)
            .selectinload(SubtaskSubmission.submitted_by_user),
            selectinload(Plan.days)
            .selectinload(PlanDay.subtasks)
            .selectinload(Subtask.submissions)
            .selectinload(SubtaskSubmission.submitted_by_device),
            selectinload(Plan.days)
            .selectinload(PlanDay.subtasks)
            .selectinload(Subtask.attachments)
            .selectinload(Attachment.uploaded_by_user),
            selectinload(Plan.days)
            .selectinload(PlanDay.subtasks)
            .selectinload(Subtask.attachments)
            .selectinload(Attachment.uploaded_by_device),
        )
    )


def _load_plan_for_render(session: Session, plan_id: int) -> Plan:
    """Fetch a plan with all relationships and refresh locks if needed."""

    stmt = _plan_detail_statement(plan_id)
    plan = session.exec(stmt).one_or_none()
    if plan is None:
        raise HTTPException(status_code=404, detail="Plan not found")

    if refresh_plan_day_locks(plan):
        session.add(plan)
        session.commit()
        plan = session.exec(stmt).one()

    return plan


def _submission_identity_options(session: Session) -> list[dict[str, Any]]:
    """Return active users for identity selection in submission forms."""

    users = session.exec(
        select(User).where(User.is_active.is_(True)).order_by(User.display_name)
    ).all()
    return [{"id": user.id, "display_name": user.display_name} for user in users]


def _render_plan_page(
    request: Request,
    session: Session,
    plan: Plan,
    *,
    status_code: int = 200,
    active_modal: str | None = None,
    active_subtask_id: int | None = None,
    submission_errors: list[str] | None = None,
    submission_form: dict[str, Any] | None = None,
):
    """Render the plan template with common context information."""

    plan_context = _build_plan_context(plan)
    identity_options = _submission_identity_options(session)
    context = {
        "request": request,
        "plan": plan_context,
        "title": f"{plan_context['title']} • Plan",
        "identity_options": identity_options,
        "max_upload_mb": settings.max_upload_mb,
        "active_modal": active_modal,
        "active_subtask_id": active_subtask_id,
        "submission_errors": submission_errors or [],
        "submission_form": submission_form
        or {"comment": "", "user_id": "", "subtask_id": None},
    }

    return templates.TemplateResponse("plan.html", context, status_code=status_code)


def _build_plan_context(plan: Plan) -> dict[str, Any]:
    days = sorted(plan.days, key=lambda day: day.day_index)

    plan_attachments = [_attachment_context(attachment) for attachment in plan.attachments]

    total_subtasks = 0
    completed_subtasks = 0
    day_contexts: list[dict[str, Any]] = []

    for day in days:
        subtasks = sorted(day.subtasks, key=lambda subtask: subtask.order_index)
        subtask_contexts: list[dict[str, Any]] = []
        day_completed = 0

        for subtask in subtasks:
            status_label = subtask.status.value.replace("_", " ").title()
            submissions = sorted(
                (_submission_context(submission) for submission in subtask.submissions),
                key=lambda item: item["created_at"],
                reverse=True,
            )
            attachments = [
                _attachment_context(attachment) for attachment in subtask.attachments
            ]

            if subtask.status == SubtaskStatus.APPROVED:
                day_completed += 1
                completed_subtasks += 1

            total_subtasks += 1

            subtask_contexts.append(
                {
                    "id": subtask.id,
                    "text": subtask.text,
                    "xp_value": subtask.xp_value,
                    "status": subtask.status.value,
                    "status_label": status_label,
                    "status_badge_class": SUBTASK_STATUS_BADGES[subtask.status],
                    "submissions": submissions,
                    "attachments": attachments,
                    "can_submit": subtask.status in {SubtaskStatus.PENDING, SubtaskStatus.DENIED},
                    "can_review": subtask.status == SubtaskStatus.SUBMITTED,
                }
            )

        day_total = len(subtasks)
        day_complete = day_completed == day_total
        progress_percent = 100 if day_total == 0 else round((day_completed / day_total) * 100)

        day_contexts.append(
            {
                "id": day.id,
                "index": day.day_index,
                "title": day.title,
                "locked": day.locked,
                "complete": day_complete,
                "progress_percent": progress_percent,
                "completed_subtasks": day_completed,
                "total_subtasks": day_total,
                "subtasks": subtask_contexts,
            }
        )

    completed_days = sum(1 for day in day_contexts if day["complete"])
    total_days = len(day_contexts)
    progress_percent = 0
    if total_subtasks:
        progress_percent = round((completed_subtasks / total_subtasks) * 100)

    assignee = None
    if plan.assignee:
        assignee = {
            "id": plan.assignee.id,
            "display_name": plan.assignee.display_name,
            "avatar": plan.assignee.avatar,
        }

    return {
        "id": plan.id,
        "title": plan.title,
        "status": plan.status.value,
        "status_label": plan.status.value.replace("_", " ").title(),
        "status_badge_class": PLAN_STATUS_BADGES[plan.status],
        "total_xp": plan.total_xp,
        "assignee": assignee,
        "attachments": plan_attachments,
        "days": day_contexts,
        "completed_days": completed_days,
        "total_days": total_days,
        "completed_subtasks": completed_subtasks,
        "total_subtasks": total_subtasks,
        "progress_percent": progress_percent,
        "updated_at": plan.updated_at,
    }


@router.get("/plan/{plan_id}", response_class=HTMLResponse)
def view_plan(plan_id: int, request: Request, session: Session = Depends(get_session)):
    """Render the detailed plan view including days and subtasks."""
    plan = _load_plan_for_render(session, plan_id)
    return _render_plan_page(request, session, plan)


@router.post("/plan/{plan_id}/submit", response_class=HTMLResponse)
async def submit_subtask(
    plan_id: int,
    request: Request,
    subtask_id: int = Form(...),
    comment: str | None = Form(None),
    user_id: str | None = Form(None),
    photo: UploadFile | None = File(None),
    session: Session = Depends(get_session),
):
    """Handle submission of subtask evidence including optional photo uploads."""

    plan = _load_plan_for_render(session, plan_id)

    device = getattr(request.state, "device", None)
    if device is None:
        raise HTTPException(status_code=400, detail="Device not recognized")

    trimmed_comment = (comment or "").strip()
    submitted_user: User | None = None
    if user_id:
        try:
            parsed_user_id = int(user_id)
        except (TypeError, ValueError):
            parsed_user_id = None
        if parsed_user_id is None:
            submission_errors = ["Select a valid family member or leave the field blank."]
            form_state = {
                "comment": trimmed_comment,
                "user_id": user_id or "",
                "subtask_id": subtask_id,
            }
            return _render_plan_page(
                request,
                session,
                plan,
                status_code=status.HTTP_400_BAD_REQUEST,
                active_modal="submit",
                active_subtask_id=subtask_id,
                submission_errors=submission_errors,
                submission_form=form_state,
            )

        submitted_user = session.get(User, parsed_user_id)
        if submitted_user is None or not submitted_user.is_active:
            submission_errors = ["Select a valid family member or leave the field blank."]
            form_state = {
                "comment": trimmed_comment,
                "user_id": user_id or "",
                "subtask_id": subtask_id,
            }
            return _render_plan_page(
                request,
                session,
                plan,
                status_code=status.HTTP_400_BAD_REQUEST,
                active_modal="submit",
                active_subtask_id=subtask_id,
                submission_errors=submission_errors,
                submission_form=form_state,
            )

    subtask_stmt = (
        select(Subtask)
        .where(Subtask.id == subtask_id)
        .options(selectinload(Subtask.plan_day))
    )
    subtask = session.exec(subtask_stmt).one_or_none()

    errors: list[str] = []
    if subtask is None or subtask.plan_day.plan_id != plan_id:
        errors.append("We couldn't find that subtask.")
    elif subtask.status not in {SubtaskStatus.PENDING, SubtaskStatus.DENIED}:
        errors.append("This subtask isn't accepting submissions right now.")

    if photo is None or not photo.filename:
        errors.append("Please attach a photo to submit evidence.")
    else:
        if photo.content_type not in {"image/jpeg", "image/png", "image/webp"}:
            errors.append("Please upload a JPEG, PNG, or WEBP image.")
        else:
            contents = await photo.read()
            max_bytes = settings.max_upload_mb * 1024 * 1024
            if len(contents) > max_bytes:
                errors.append(
                    f"Photo is too large. Maximum allowed size is {settings.max_upload_mb} MB."
                )
            await photo.seek(0)

    if errors:
        form_state = {
            "comment": trimmed_comment,
            "user_id": user_id or "",
            "subtask_id": subtask_id,
        }
        return _render_plan_page(
            request,
            session,
            plan,
            status_code=status.HTTP_400_BAD_REQUEST,
            active_modal="submit",
            active_subtask_id=subtask_id,
            submission_errors=errors,
            submission_form=form_state,
        )

    try:
        saved = await process_image(photo)
    except Exception:
        form_state = {
            "comment": trimmed_comment,
            "user_id": user_id or "",
            "subtask_id": subtask_id,
        }
        return _render_plan_page(
            request,
            session,
            plan,
            status_code=status.HTTP_400_BAD_REQUEST,
            active_modal="submit",
            active_subtask_id=subtask_id,
            submission_errors=[
                "We couldn't process that photo. Please try again with a different image."
            ],
            submission_form=form_state,
        )

    now = datetime.utcnow()
    subtask.status = SubtaskStatus.SUBMITTED
    subtask.updated_at = now
    subtask.plan_day.updated_at = now
    plan.updated_at = now

    submission = SubtaskSubmission(
        subtask_id=subtask.id,
        submitted_by_device_id=device.id,
        submitted_by_user_id=submitted_user.id if submitted_user else None,
        comment=trimmed_comment or None,
        photo_path=saved["file"],
    )

    attachment = Attachment(
        plan_id=plan.id,
        subtask_id=subtask.id,
        file_path=saved["file"],
        thumb_path=saved["thumb"],
        uploaded_by_device_id=device.id,
        uploaded_by_user_id=submitted_user.id if submitted_user else None,
    )

    session.add(submission)
    session.add(attachment)
    session.add(subtask)
    session.add(plan)
    session.commit()

    redirect_url = request.url_for("view_plan", plan_id=str(plan_id))
    return RedirectResponse(redirect_url, status_code=status.HTTP_303_SEE_OTHER)
