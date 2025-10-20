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
    assignee_user_id: int = Form(...),
    file: UploadFile = File(...),
    session: Session = Depends(get_session),
):
    """Import a markdown plan file placeholder."""
    content = (await file.read()).decode("utf-8")
    plan_id = import_markdown_plan(content, assignee_user_id, session)
    return {"plan_id": plan_id}
