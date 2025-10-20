"""Task and subtask model placeholders."""

from sqlmodel import Field

from .base import BaseModel


class PlanDay(BaseModel, table=True):
    """Represents a day within a plan."""

    id: int | None = Field(default=None, primary_key=True)
    plan_id: int = Field(foreign_key="plan.id")
    day_index: int = 0


class Subtask(BaseModel, table=True):
    """Represents a subtask within a plan day."""

    id: int | None = Field(default=None, primary_key=True)
    plan_day_id: int = Field(foreign_key="plan_day.id")
    text: str = Field(default="")
    xp_value: int = 0
