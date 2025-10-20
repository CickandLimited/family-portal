"""Public-facing API routes for the Family Portal application."""

from collections import defaultdict

from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates
from sqlmodel import Session, select

from app.core.db import get_session
from app.core.xp import calculate_level
from app.models.devices import Device
from app.models.plans import Plan, PlanStatus
from app.models.users import User

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


@router.get("/plan/{plan_id}", response_class=HTMLResponse)
def view_plan(plan_id: int, request: Request, session: Session = Depends(get_session)):
    """Render a placeholder plan detail view."""
    return templates.TemplateResponse("plan.html", {"request": request, "plan_id": plan_id})
