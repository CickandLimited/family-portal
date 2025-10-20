"""Base SQLModel metadata placeholder."""

from sqlmodel import SQLModel


class BaseModel(SQLModel):
    """Base class for all SQLModel tables."""

    __abstract__ = True
