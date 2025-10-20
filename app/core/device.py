"""Device identification helpers for cookie-based tracking."""

from __future__ import annotations

import uuid
from fastapi import Request, Response

COOKIE_NAME = "fp_device_id"


def ensure_device_cookie(request: Request, response: Response) -> None:
    """Ensure a device identifier cookie exists for the current client."""
    if COOKIE_NAME in request.cookies:
        return
    device_id = str(uuid.uuid4())
    response.set_cookie(
        COOKIE_NAME,
        device_id,
        httponly=True,
        samesite="lax",
        secure=False,
        max_age=60 * 60 * 24 * 365 * 5,
    )
