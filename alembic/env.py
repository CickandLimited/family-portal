"""Alembic environment configuration for the Family Portal project."""

from __future__ import annotations

import importlib
import pkgutil
import sys
from logging.config import fileConfig
from pathlib import Path

from alembic import context
from sqlalchemy import pool
from sqlmodel import SQLModel, create_engine

# Ensure the application package is importable when Alembic runs standalone.
PROJECT_ROOT = Path(__file__).resolve().parents[1]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from app.core.config import settings
import app.models


def _import_model_modules() -> None:
    """Import every module in app.models so SQLModel tables register metadata."""

    package = app.models
    for module_info in pkgutil.walk_packages(package.__path__, package.__name__ + "."):
        importlib.import_module(module_info.name)


_import_model_modules()

# this is the Alembic Config object, which provides
# access to the values within the .ini file in use.
config = context.config

# Interpret the config file for Python logging.
# This line sets up loggers basically.
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

# Point Alembic at the runtime database URL from settings.
config.set_main_option("sqlalchemy.url", settings.db_url)

# Use SQLModel's combined metadata for autogeneration support.
target_metadata = SQLModel.metadata

# Reuse the SQLite connect arguments used by the application.
IS_SQLITE = settings.db_url.startswith("sqlite")
CONNECT_ARGS = {"check_same_thread": False} if IS_SQLITE else {}
COMMON_CONFIG = {
    "target_metadata": target_metadata,
    "compare_type": True,
    "render_as_batch": IS_SQLITE,
}


def run_migrations_offline() -> None:
    """Run migrations in 'offline' mode."""

    context.configure(
        url=settings.db_url,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
        **COMMON_CONFIG,
    )

    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    """Run migrations in 'online' mode."""

    connectable = create_engine(
        settings.db_url,
        connect_args=CONNECT_ARGS,
        poolclass=pool.NullPool,
    )

    with connectable.connect() as connection:
        context.configure(
            connection=connection,
            **COMMON_CONFIG,
        )

        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
