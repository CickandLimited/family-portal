"""Device identification helpers for cookie-based tracking."""

from __future__ import annotations

from datetime import datetime
import uuid

from fastapi import Request
from sqlmodel import Session

from app.core.db import engine
from app.models.devices import Device

COOKIE_NAME = "fp_device_id"


def ensure_device_cookie(request: Request) -> tuple[Device, bool]:
    """Ensure a device identifier cookie exists and is persisted for the client.

    Returns the corresponding :class:`Device` record along with a boolean flag
    indicating whether the caller needs to set the cookie on the outgoing
    response.
    """

    device_id = request.cookies.get(COOKIE_NAME)
    cookie_missing = device_id is None
    if cookie_missing:
        device_id = str(uuid.uuid4())

    now = datetime.utcnow()
    with Session(engine) as session:
        device = session.get(Device, device_id)
        if device is None:
            device = Device(id=device_id, created_at=now)
            # TODO: emit audit log entry when audit pipeline is available.
            session.add(device)
        device.last_seen_at = now
        session.add(device)
        session.commit()
        session.refresh(device)

    return device, cookie_missing
