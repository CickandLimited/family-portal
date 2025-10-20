"""Public-facing API routes for the Family Portal application."""

from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates
from sqlmodel import Session

from app.core.db import get_session

router = APIRouter()
templates = Jinja2Templates(directory="app/templates")


@router.get("/", response_class=HTMLResponse)
def board(request: Request, session: Session = Depends(get_session)):
    """Render the main family board view."""
    device = getattr(request.state, "device", None)
    return templates.TemplateResponse(
        "board.html",
        {
            "request": request,
            "data": {},
            "device": device,
        },
    )


@router.get("/plan/{plan_id}", response_class=HTMLResponse)
def view_plan(plan_id: int, request: Request, session: Session = Depends(get_session)):
    """Render a placeholder plan detail view."""
    return templates.TemplateResponse("plan.html", {"request": request, "plan_id": plan_id})
