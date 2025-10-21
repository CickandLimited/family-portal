"""Administrative API endpoints."""

from __future__ import annotations

import json

from fastapi import (
    APIRouter,
    Depends,
    File,
    Form,
    HTTPException,
    Request,
    UploadFile,
)
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy.orm import selectinload
from sqlmodel import Session, select

from app.core.activity_log import log_activity
from app.core.db import get_session
from app.core.markdown_import import import_markdown_plan
from app.models.activity import ActivityLog
from app.models.devices import Device
from app.models.users import User

router = APIRouter(prefix="/admin")
templates = Jinja2Templates(directory="app/templates")

_ACTIVITY_PAGE_SIZE = 25


def _clean_query_value(raw: str | None) -> str | None:
    if raw is None:
        return None
    cleaned = raw.strip()
    return cleaned or None


def _parse_int(raw: str | None) -> int | None:
    if raw is None or raw == "":
        return None
    try:
        return int(raw)
    except (TypeError, ValueError):
        return None


def _activity_filters(request: Request) -> tuple[dict[str, str | int | None], dict[str, str], int]:
    params = request.query_params
    action = _clean_query_value(params.get("action"))
    entity_type = _clean_query_value(params.get("entity_type"))
    device_id = _clean_query_value(params.get("device_id"))
    user_id = _parse_int(params.get("user_id"))
    page = _parse_int(params.get("page")) or 1
    page = max(page, 1)

    filters = {
        "action": action,
        "entity_type": entity_type,
        "device_id": device_id,
        "user_id": user_id,
    }
    form_state = {
        "action": action or "",
        "entity_type": entity_type or "",
        "device_id": device_id or "",
        "user_id": str(user_id) if user_id is not None else "",
    }

    return filters, form_state, page


def _serialize_activity_entry(entry: ActivityLog) -> dict[str, str | int | dict | None]:
    device_label: str | None = None
    if entry.device:
        friendly = entry.device.friendly_name
        device_label = friendly or f"Device {entry.device.id}"
    elif entry.device_id:
        device_label = f"Device {entry.device_id}"

    user_label: str | None = None
    if entry.user:
        user_label = entry.user.display_name
    elif entry.user_id is not None:
        user_label = f"User {entry.user_id}"

    metadata = entry.metadata_payload

    if metadata is None:
        metadata_json = "null"
    else:
        metadata_json = json.dumps(metadata, sort_keys=True, indent=2)

    return {
        "id": entry.id,
        "timestamp": entry.timestamp,
        "timestamp_display": entry.timestamp.strftime("%Y-%m-%d %H:%M:%S UTC"),
        "action": entry.action,
        "entity_type": entry.entity_type,
        "entity_id": entry.entity_id,
        "metadata": metadata,
        "metadata_json": metadata_json,
        "device_label": device_label,
        "user_label": user_label,
    }


def _activity_entries_context(
    session: Session, filters: dict[str, str | int | None], page: int
) -> dict[str, object]:
    stmt = (
        select(ActivityLog)
        .options(selectinload(ActivityLog.device), selectinload(ActivityLog.user))
        .order_by(ActivityLog.timestamp.desc())
    )
    if filters["action"]:
        stmt = stmt.where(ActivityLog.action == filters["action"])
    if filters["entity_type"]:
        stmt = stmt.where(ActivityLog.entity_type == filters["entity_type"])
    if filters["device_id"]:
        stmt = stmt.where(ActivityLog.device_id == filters["device_id"])
    if filters["user_id"] is not None:
        stmt = stmt.where(ActivityLog.user_id == filters["user_id"])

    offset = (page - 1) * _ACTIVITY_PAGE_SIZE
    rows = session.exec(stmt.offset(offset).limit(_ACTIVITY_PAGE_SIZE + 1)).all()

    has_next = len(rows) > _ACTIVITY_PAGE_SIZE
    visible_rows = rows[:_ACTIVITY_PAGE_SIZE]

    entries = [_serialize_activity_entry(row) for row in visible_rows]

    pagination = {
        "page": page,
        "has_next": has_next,
        "has_previous": page > 1,
        "next_page": page + 1 if has_next else None,
        "previous_page": page - 1 if page > 1 else None,
    }

    return {"entries": entries, "pagination": pagination}


@router.get("/devices", response_class=HTMLResponse)
def devices(request: Request, session: Session = Depends(get_session)):
    """Render the administrative device management view."""
    devices = session.exec(select(Device).order_by(Device.created_at.desc())).all()
    users = session.exec(select(User).order_by(User.display_name)).all()
    return templates.TemplateResponse(
        "admin_devices.html",
        {
            "request": request,
            "devices": devices,
            "users": users,
        },
    )


@router.post("/devices/{device_id}/rename")
def rename_device(
    device_id: str,
    friendly_name: str = Form(""),
    session: Session = Depends(get_session),
):
    """Rename a device to a human-friendly identifier."""

    device = session.get(Device, device_id)
    if device is None:
        raise HTTPException(status_code=404, detail="Device not found")

    normalized_name = friendly_name.strip() or None
    if device.friendly_name != normalized_name:
        previous_name = device.friendly_name
        device.friendly_name = normalized_name
        session.add(device)
        session.flush()

        log_activity(
            session,
            action="device.renamed",
            entity_type="device",
            entity_id=device.id,
            device=device,
            metadata={
                "previous_name": previous_name,
                "new_name": normalized_name,
            },
        )
        session.commit()

    return RedirectResponse(url=router.url_path_for("devices"), status_code=303)


@router.post("/devices/{device_id}/link-user")
def link_device_user(
    device_id: str,
    user_id: str = Form(""),
    session: Session = Depends(get_session),
):
    """Associate or disassociate a device with a user account."""

    device = session.get(Device, device_id)
    if device is None:
        raise HTTPException(status_code=404, detail="Device not found")

    target_user_id: int | None
    if user_id:
        target_user = session.get(User, int(user_id))
        if target_user is None:
            raise HTTPException(status_code=400, detail="Selected user not found")
        target_user_id = target_user.id
    else:
        target_user_id = None

    if device.linked_user_id != target_user_id:
        previous_user_id = device.linked_user_id
        device.linked_user_id = target_user_id
        session.add(device)
        session.flush()

        log_metadata = {
            "previous_user_id": previous_user_id,
            "new_user_id": target_user_id,
        }
        if previous_user_id:
            previous_user = session.get(User, previous_user_id)
            if previous_user:
                log_metadata["previous_user_name"] = previous_user.display_name
        if target_user_id:
            log_metadata["new_user_name"] = target_user.display_name

        action = "device.user_linked" if target_user_id else "device.user_unlinked"
        log_activity(
            session,
            action=action,
            entity_type="device",
            entity_id=device.id,
            device=device,
            metadata=log_metadata,
        )
        session.commit()

    return RedirectResponse(url=router.url_path_for("devices"), status_code=303)


@router.get("/activity", response_class=HTMLResponse)
def activity_log(request: Request, session: Session = Depends(get_session)):
    """Render the activity log with filtering controls."""

    filters, form_state, page = _activity_filters(request)
    entries_context = _activity_entries_context(session, filters, page)

    action_options = session.exec(
        select(ActivityLog.action).distinct().order_by(ActivityLog.action)
    ).all()
    entity_type_options = session.exec(
        select(ActivityLog.entity_type).distinct().order_by(ActivityLog.entity_type)
    ).all()
    devices = session.exec(select(Device).order_by(Device.created_at.desc())).all()
    users = session.exec(select(User).order_by(User.display_name)).all()

    device_options = [
        {
            "value": device.id,
            "label": device.friendly_name or f"Device {device.id}",
        }
        for device in devices
    ]
    user_options = [
        {
            "value": str(user.id),
            "label": user.display_name,
        }
        for user in users
    ]

    context = {
        "request": request,
        "action_options": action_options,
        "entity_type_options": entity_type_options,
        "device_options": device_options,
        "user_options": user_options,
        "filter_form": form_state,
        **entries_context,
    }

    return templates.TemplateResponse("admin_activity.html", context)


@router.get("/activity/entries", response_class=HTMLResponse)
def activity_log_entries(request: Request, session: Session = Depends(get_session)):
    """Return the activity log table for HTMX requests."""

    filters, _, page = _activity_filters(request)
    context = {"request": request, **_activity_entries_context(session, filters, page)}
    return templates.TemplateResponse("components/activity_log_table.html", context)


@router.post("/import")
async def import_md(
    request: Request,
    assignee_user_id: int = Form(...),
    file: UploadFile = File(...),
    session: Session = Depends(get_session),
):
    """Import a markdown plan file."""

    device = getattr(request.state, "device", None)
    user = getattr(request.state, "user", None)
    metadata: dict[str, int | str | None] = {
        "filename": file.filename,
        "assignee_user_id": assignee_user_id,
    }

    try:
        raw_contents = await file.read()
        content = raw_contents.decode("utf-8")
        plan_id = import_markdown_plan(content, assignee_user_id, session)
    except UnicodeDecodeError as exc:
        session.rollback()
        error_detail = "Uploaded file must be valid UTF-8 text."
        metadata_with_error = {**metadata, "error": error_detail}
        log_activity(
            session,
            action="plan.import_failed",
            entity_type="plan",
            entity_id=0,
            metadata=metadata_with_error,
            device=device,
            user=user,
            commit=True,
        )
        raise HTTPException(status_code=400, detail=error_detail) from exc
    except ValueError as exc:
        session.rollback()
        error_detail = str(exc)
        metadata_with_error = {**metadata, "error": error_detail}
        log_activity(
            session,
            action="plan.import_failed",
            entity_type="plan",
            entity_id=0,
            metadata=metadata_with_error,
            device=device,
            user=user,
            commit=True,
        )
        raise HTTPException(status_code=400, detail=error_detail) from exc

    log_activity(
        session,
        action="plan.imported",
        entity_type="plan",
        entity_id=plan_id,
        metadata=metadata,
        device=device,
        user=user,
        commit=True,
    )

    return {"ok": True, "plan_id": plan_id}
