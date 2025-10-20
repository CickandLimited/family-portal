"""Administrative API endpoints."""

from fastapi import APIRouter, Depends, File, Form, UploadFile
from sqlmodel import Session

from app.core.db import get_session
from app.core.markdown_import import import_markdown_plan

router = APIRouter(prefix="/admin")


@router.get("/devices")
def devices(session: Session = Depends(get_session)):
    """Return a placeholder list of registered devices."""
    return {"devices": []}


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
