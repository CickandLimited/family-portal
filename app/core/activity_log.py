"""Utility helpers for recording activity log entries."""

from __future__ import annotations

from collections.abc import Mapping
from typing import TYPE_CHECKING, Any

from sqlmodel import Session

from app.models.activity import ActivityLog

if TYPE_CHECKING:  # pragma: no cover - only imported for typing
    from app.models.devices import Device
    from app.models.users import User


def log_activity(
    session: Session,
    *,
    action: str,
    entity_type: str,
    entity_id: int,
    metadata: Mapping[str, Any] | None = None,
    device: "Device | None" = None,
    user: "User | None" = None,
    device_id: str | None = None,
    user_id: int | None = None,
    commit: bool = False,
) -> ActivityLog:
    """Persist an ``ActivityLog`` row and optionally commit the transaction.

    Parameters
    ----------
    session:
        The open database session that will persist the log entry.
    action:
        A stable action name describing what occurred (``plan.imported`` etc.).
    entity_type:
        The domain entity that was affected (``plan``, ``subtask``...).
    entity_id:
        Identifier for the target entity.
    metadata:
        Optional JSON-serialisable payload with additional context. The mapping
        is copied to avoid mutating caller-provided dictionaries.
    device / user / device_id / user_id:
        Actor context. ``device`` and ``user`` objects take precedence when
        provided. ``device_id`` / ``user_id`` fall back to the corresponding
        attributes on the objects when omitted.
    commit:
        When ``True`` the helper commits the session after adding the log.

    Returns
    -------
    ActivityLog
        The instance that was added to the session. Callers may inspect the
        returned object if needed (for example during testing).
    """

    payload: dict[str, Any] | None
    if metadata:
        payload = dict(metadata)
    else:
        payload = None

    log = ActivityLog(
        action=action,
        entity_type=entity_type,
        entity_id=entity_id,
        metadata_payload=payload,
        device_id=device_id or getattr(device, "id", None),
        user_id=user_id or getattr(user, "id", None),
    )

    session.add(log)

    if commit:
        session.commit()

    return log


__all__ = ["log_activity"]

