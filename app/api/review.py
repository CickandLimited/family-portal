"""Review queue endpoints for approving or denying submissions."""

from fastapi import APIRouter, Depends, HTTPException, Request
from sqlmodel import Session

from app.core.db import get_session

router = APIRouter(prefix="/review")


@router.get("")
def queue(request: Request, session: Session = Depends(get_session)):
    """Return the pending review queue placeholder."""
    return {"items": []}


@router.post("/subtask/{subtask_id}/approve")
def approve(subtask_id: int, request: Request, session: Session = Depends(get_session)):
    """Approve a subtask submission placeholder."""
    return {"ok": True}


@router.post("/subtask/{subtask_id}/deny")
def deny(subtask_id: int, request: Request, session: Session = Depends(get_session)):
    """Deny a subtask submission placeholder."""
    return {"ok": True}
