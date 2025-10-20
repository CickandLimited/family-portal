"""Administrative API endpoints."""

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
from sqlmodel import Session, select

from app.core.db import get_session
from app.core.markdown_import import import_markdown_plan
from app.models.activity import ActivityLog
from app.models.devices import Device
from app.models.users import User

router = APIRouter(prefix="/admin")
templates = Jinja2Templates(directory="app/templates")


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
        device.friendly_name = normalized_name
        # TODO: emit audit event documenting the rename action.
        session.add(device)
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
        device.linked_user_id = target_user_id
        # TODO: emit audit event documenting device/user association changes.
        session.add(device)
        session.commit()

    return RedirectResponse(url=router.url_path_for("devices"), status_code=303)


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
        session.add(
            ActivityLog(
                action="plan.import_failed",
                entity_type="plan",
                entity_id=0,
                metadata_payload=metadata_with_error,
                device_id=getattr(device, "id", None),
                user_id=getattr(user, "id", None),
            )
        )
        session.commit()
        raise HTTPException(status_code=400, detail=error_detail) from exc
    except ValueError as exc:
        session.rollback()
        error_detail = str(exc)
        metadata_with_error = {**metadata, "error": error_detail}
        session.add(
            ActivityLog(
                action="plan.import_failed",
                entity_type="plan",
                entity_id=0,
                metadata_payload=metadata_with_error,
                device_id=getattr(device, "id", None),
                user_id=getattr(user, "id", None),
            )
        )
        session.commit()
        raise HTTPException(status_code=400, detail=error_detail) from exc

    session.add(
        ActivityLog(
            action="plan.imported",
            entity_type="plan",
            entity_id=plan_id,
            metadata_payload=metadata,
            device_id=getattr(device, "id", None),
            user_id=getattr(user, "id", None),
        )
    )
    session.commit()

    return {"ok": True, "plan_id": plan_id}
