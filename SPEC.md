# Family Task Portal — Full Specification & Scaffold Pack

This single document contains **the complete technical spec plus scaffolding** for a Raspberry Pi–hosted, local-only, cookie-identified family task portal built in **pure Python** (FastAPI). It includes:

- Functional/technical specification
- Directory layout for the project
- Minimal, working **FastAPI** app skeleton
- **Markdown importer** spec & stub
- **Device-cookie** identification middleware
- **Self-approval guard** logic
- **SQLite** via SQLModel + **Alembic** migrations
- **Tailwind** (CDN first, CLI optional later)
- Image upload/processing (Pillow) stubs
- **Systemd** service unit and backup timer
- Optional **nginx** reverse proxy config
- `bootstrap_pi.sh` to set up the Pi
- `pyproject.toml` for dependencies
- Example **SPEC-compliant sample plan** in Markdown

You can paste this file into your repo as `SPEC.md`, and have your coding agent generate the project from it.

---

## 0) Goals & Operating Principles

- **Local-first, trust-based** family portal (no logins).
- **Device-recognised actions** via cookies; every change is traceable.
- **Plans imported as Markdown** → parsed into structured tasks/subtasks (unique, on-demand).
- **Lean control**: anyone can administer, but **assignees cannot approve their own subtasks**.
- **Kid-friendly** UX: progress bars, XP, mood feedback; phone/tablet optimised.
- **No reminders or calendar**. Visual rewards/voice assistants out of scope for v1.

---

## 1) Architecture Summary

- **Backend:** FastAPI (Python 3.11+), Uvicorn (ASGI)
- **DB:** SQLite via SQLModel (SQLAlchemy core), Alembic for migrations
- **Templating:** Jinja2
- **Frontend:** Tailwind CSS (CDN for v1, CLI optional later), HTMX for progressive UX, a pinch of Alpine.js if needed
- **Images:** Pillow for resize + EXIF strip → store in local filesystem under `/var/lib/family-portal/uploads`
- **Static hosting:** `/static` via FastAPI (or nginx if used)
- **Device ID:** Cookie `fp_device_id` (UUIDv4); server maps to friendly name & optional linked user

---

## 2) Core Features

- **Family Board** showing users, XP, active plans, overall progress
- **Markdown Plan import**: `# Title`, `## Day N – Title`, `- [ ] Task (+10 XP)`
- **Plan view**: Days as collapsible cards; later days locked until previous approved
- **Subtask submit**: comment + photo
- **Review queue**: approve/deny with mood & comment; **self-approval blocked**
- **Progress bars**: overall and current day
- **XP** ledger and simple levels (every +100 XP)
- **Device Manager**: rename devices, link to user
- **Activity Log**: full traceability of actions

---

## 3) Data Model (SQL)

**Tables (key fields):**

- `users(id, display_name, role, avatar, is_active)`
- `devices(id UUID, friendly_name, linked_user_id, created_at, last_seen_at)`
- `plans(id, title, assignee_user_id, status, created_by_user_id, created_at, total_xp)`
- `plan_days(id, plan_id, day_index, title, locked)`
- `subtasks(id, plan_day_id, order_index, text, xp_value, status, created_at, updated_at)`
- `subtask_submissions(id, subtask_id, submitted_by_device_id, submitted_by_user_id, photo_path, comment, created_at)`
- `approvals(id, subtask_id, action, mood, reason, acted_by_device_id, acted_by_user_id, created_at)`
- `attachments(id, plan_id, subtask_id, file_path, thumb_path, uploaded_by_device_id, uploaded_by_user_id, created_at)`
- `xp_events(id, user_id, subtask_id, delta, reason, created_at)`
- `activity_log(id, timestamp, device_id, user_id, action, entity_type, entity_id, metadata JSON)`

**Rule:** server must block approvals where actor is the plan assignee (by user or device-link).

---

## 4) Directory Layout (Target)

```
family-portal/
  app/
    api/
      __init__.py
      public.py
      review.py
      admin.py
      uploads.py
    core/
      config.py
      db.py
      device.py
      security.py
      imaging.py
      markdown_import.py
      xp.py
      locking.py
    models/
      base.py
      users.py
      devices.py
      plans.py
      tasks.py
      approvals.py
      attachments.py
      activity.py
    templates/
      base.html
      board.html
      plan.html
      review.html
      admin_devices.html
      admin_import.html
      components/
        progress_bar.html
        subtask_item.html
    static/
      css/
        tailwind.css   # (CDN in base.html initially)
      js/
        htmx.min.js
        alpine.min.js
      img/
    main.py
  alembic/
    versions/
    env.py
    script.py.mako
  scripts/
    bootstrap_pi.sh
    backup.sh
  uploads/
    originals/
    thumbs/
  tests/
  pyproject.toml
  SPEC.md
```

---

## 5) Minimal Working App Skeleton

> These stubs are intentionally lean but runnable, so you can iterate quickly.

### 5.1 `app/main.py`

```python
from fastapi import FastAPI, Request, Response
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.middleware.sessions import SessionMiddleware
from app.core.config import settings
from app.core.device import ensure_device_cookie
from app.api.public import router as public_router
from app.api.review import router as review_router
from app.api.admin import router as admin_router
from app.api.uploads import router as uploads_router

app = FastAPI(title="Family Task Portal")
app.add_middleware(SessionMiddleware, secret_key=settings.session_secret)

# Mount static
app.mount("/static", StaticFiles(directory="app/static"), name="static")

# Templates
templates = Jinja2Templates(directory="app/templates")

# Device cookie middleware
@app.middleware("http")
async def device_cookie_mw(request: Request, call_next):
    response: Response = await call_next(request)
    ensure_device_cookie(request, response)
    return response

# Routers
app.include_router(public_router)
app.include_router(review_router)
app.include_router(admin_router)
app.include_router(uploads_router)

# Health
@app.get("/health")
def health():
    return {"ok": True}
```

### 5.2 `app/core/config.py`

```python
from pydantic import BaseSettings
import os

class Settings(BaseSettings):
    session_secret: str = os.environ.get("FP_SESSION_SECRET", "dev-secret-change-me")
    db_url: str = os.environ.get("FP_DB_URL", "sqlite:///./family_portal.db")
    uploads_dir: str = os.environ.get("FP_UPLOADS_DIR", "/var/lib/family-portal/uploads")
    thumbs_dir: str = os.environ.get("FP_THUMBS_DIR", "/var/lib/family-portal/uploads/thumbs")
    max_upload_mb: int = int(os.environ.get("FP_MAX_UPLOAD_MB", "6"))

settings = Settings()
```

### 5.3 `app/core/db.py`

```python
from sqlmodel import SQLModel, create_engine, Session
from app.core.config import settings

engine = create_engine(settings.db_url, connect_args={"check_same_thread": False} if settings.db_url.startswith("sqlite") else {})

def get_session():
    with Session(engine) as session:
        yield session
```

### 5.4 `app/core/device.py`

```python
import uuid
from fastapi import Request, Response

COOKIE_NAME = "fp_device_id"

def ensure_device_cookie(request: Request, response: Response):
    if COOKIE_NAME in request.cookies:
        return
    did = str(uuid.uuid4())
    response.set_cookie(
        COOKIE_NAME,
        did,
        httponly=True,
        samesite="lax",
        secure=False,  # set True if you add TLS via nginx
        max_age=60*60*24*365*5,
    )
```

### 5.5 `app/api/public.py`

```python
from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates
from sqlmodel import Session
from app.core.db import get_session

router = APIRouter()
templates = Jinja2Templates(directory="app/templates")

@router.get("/", response_class=HTMLResponse)
def board(request: Request, session: Session = Depends(get_session)):
    # TODO: query users, plans, progress
    return templates.TemplateResponse("board.html", {"request": request, "data": {}})

@router.get("/plan/{plan_id}", response_class=HTMLResponse)
def view_plan(plan_id: int, request: Request, session: Session = Depends(get_session)):
    # TODO: fetch plan, days, subtasks, lock states
    return templates.TemplateResponse("plan.html", {"request": request, "plan_id": plan_id})
```

### 5.6 `app/api/review.py`

```python
from fastapi import APIRouter, Depends, HTTPException, Request
from sqlmodel import Session
from app.core.db import get_session

router = APIRouter(prefix="/review")

@router.get("")
def queue(request: Request, session: Session = Depends(get_session)):
    # TODO: return submitted subtasks
    return {"items": []}

@router.post("/subtask/{subtask_id}/approve")
def approve(subtask_id: int, request: Request, session: Session = Depends(get_session)):
    # TODO: self-approval guard, mood, comment → change status, award XP, log
    return {"ok": True}

@router.post("/subtask/{subtask_id}/deny")
def deny(subtask_id: int, request: Request, session: Session = Depends(get_session)):
    # TODO: require reason, mood; set denied; log
    return {"ok": True}
```

### 5.7 `app/api/admin.py`

```python
from fastapi import APIRouter, Depends, UploadFile, File, Form
from sqlmodel import Session
from app.core.db import get_session
from app.core.markdown_import import import_markdown_plan

router = APIRouter(prefix="/admin")

@router.get("/devices")
def devices(session: Session = Depends(get_session)):
    # TODO: list devices
    return {"devices": []}

@router.post("/import")
async def import_md(assignee_user_id: int = Form(...), file: UploadFile = File(...), session: Session = Depends(get_session)):
    content = (await file.read()).decode("utf-8")
    plan_id = import_markdown_plan(content, assignee_user_id, session)
    return {"plan_id": plan_id}
```

### 5.8 `app/api/uploads.py`

```python
from fastapi import APIRouter, UploadFile, File, Depends, HTTPException
from sqlmodel import Session
from app.core.db import get_session
from app.core.imaging import process_image

router = APIRouter(prefix="/upload")

@router.post("")
async def upload(file: UploadFile = File(...), session: Session = Depends(get_session)):
    if file.content_type not in {"image/jpeg", "image/png", "image/webp"}:
        raise HTTPException(400, "Unsupported file type")
    saved = await process_image(file)
    return saved
```

### 5.9 `app/core/markdown_import.py`

```python
import re
from sqlmodel import Session
# from app.models import Plan, PlanDay, Subtask  # TODO: implement models

DAY_HDR = re.compile(r"^##\s*Day\s*(\d+)\s*[\-–]\s*(.+)$", re.I)
TASK = re.compile(r"^- \[ \]\s*(.+)$")
XP = re.compile(r"\(\+(\d+)\s*XP\)$", re.I)

DEFAULT_XP = 10

def import_markdown_plan(md_text: str, assignee_user_id: int, session: Session) -> int:
    lines = [l.rstrip() for l in md_text.splitlines()]
    title = None
    plan_days = []
    cur_day = None
    for line in lines:
        if not title and line.startswith('# '):
            title = line[2:].strip()
            continue
        mday = DAY_HDR.match(line)
        if mday:
            if cur_day:
                plan_days.append(cur_day)
            cur_day = {"index": int(mday.group(1)), "title": mday.group(2).strip(), "tasks": []}
            continue
        mt = TASK.match(line)
        if mt and cur_day is not None:
            text = mt.group(1).strip()
            xp = DEFAULT_XP
            mxp = XP.search(text)
            if mxp:
                xp = int(mxp.group(1))
                text = XP.sub('', text).strip()
            cur_day["tasks"].append({"text": text, "xp": xp})
    if cur_day:
        plan_days.append(cur_day)

    # TODO: persist Plan/Days/Subtasks, compute total_xp, set initial locks
    # plan = Plan(title=title, assignee_user_id=assignee_user_id, status="in_progress")
    # session.add(plan); session.commit(); session.refresh(plan)
    # for day in sorted(plan_days, key=lambda d: d["index"]):
    #   ... create PlanDay and Subtasks
    # return plan.id
    return 1  # placeholder
```

### 5.10 `app/core/imaging.py`

```python
import os
import uuid
from PIL import Image, ImageOps
from fastapi import UploadFile
from app.core.config import settings

async def process_image(file: UploadFile):
    os.makedirs(settings.uploads_dir, exist_ok=True)
    os.makedirs(settings.thumbs_dir, exist_ok=True)
    raw = await file.read()
    fid = f"{uuid.uuid4()}"
    orig_path = os.path.join(settings.uploads_dir, f"{fid}.webp")
    thumb_path = os.path.join(settings.thumbs_dir, f"{fid}.webp")
    img = Image.open(bytearray(raw))
    img = ImageOps.exif_transpose(img)
    img = img.convert("RGB")
    # Fit to 1600px max
    img.thumbnail((1600, 1600))
    img.save(orig_path, format="WEBP", quality=85, method=6)
    # Thumb 400px
    t = img.copy()
    t.thumbnail((400, 400))
    t.save(thumb_path, format="WEBP", quality=80, method=6)
    return {"file": orig_path, "thumb": thumb_path}
```

> NOTE: For Pillow, ensure libwebp is available on the Pi (the bootstrap script installs it).

---

## 6) Models (SQLModel stubs)

> These are skeletons — expand with relationships and enums as you implement.

### 6.1 `app/models/base.py`

```python
from sqlmodel import SQLModel

class BaseModel(SQLModel):
    pass
```

### 6.2 `app/models/users.py`

```python
from typing import Optional
from sqlmodel import SQLModel, Field

class User(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    display_name: str
    role: str = "user"  # or "admin"
    avatar: Optional[str] = None
    is_active: bool = True
```

### 6.3 `app/models/devices.py`

```python
from typing import Optional
from sqlmodel import SQLModel, Field

class Device(SQLModel, table=True):
    id: str = Field(primary_key=True)  # UUID string
    friendly_name: Optional[str] = None
    linked_user_id: Optional[int] = Field(default=None, foreign_key="user.id")
    created_at: Optional[str] = None
    last_seen_at: Optional[str] = None
```

### 6.4 `app/models/plans.py`

```python
from typing import Optional
from sqlmodel import SQLModel, Field

class Plan(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    title: str
    assignee_user_id: int = Field(foreign_key="user.id")
    status: str = "in_progress"
    created_by_user_id: Optional[int] = Field(default=None, foreign_key="user.id")
    created_at: Optional[str] = None
    total_xp: int = 0

class PlanDay(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    plan_id: int = Field(foreign_key="plan.id")
    day_index: int
    title: str
    locked: bool = True
```

### 6.5 `app/models/tasks.py`

```python
from typing import Optional
from sqlmodel import SQLModel, Field

class Subtask(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    plan_day_id: int = Field(foreign_key="planday.id")
    order_index: int
    text: str
    xp_value: int = 10
    status: str = "pending"  # pending/submitted/approved/denied
    created_at: Optional[str] = None
    updated_at: Optional[str] = None

class SubtaskSubmission(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    subtask_id: int = Field(foreign_key="subtask.id")
    submitted_by_device_id: str = Field(foreign_key="device.id")
    submitted_by_user_id: Optional[int] = Field(default=None, foreign_key="user.id")
    photo_path: Optional[str] = None
    comment: Optional[str] = None
    created_at: Optional[str] = None
```

### 6.6 `app/models/approvals.py`

```python
from typing import Optional
from sqlmodel import SQLModel, Field

class Approval(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    subtask_id: int = Field(foreign_key="subtask.id")
    action: str  # approve/deny
    mood: str  # happy/neutral/sad
    reason: Optional[str] = None
    acted_by_device_id: str = Field(foreign_key="device.id")
    acted_by_user_id: Optional[int] = Field(default=None, foreign_key="user.id")
    created_at: Optional[str] = None
```

### 6.7 `app/models/attachments.py`

```python
from typing import Optional
from sqlmodel import SQLModel, Field

class Attachment(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    plan_id: Optional[int] = Field(default=None, foreign_key="plan.id")
    subtask_id: Optional[int] = Field(default=None, foreign_key="subtask.id")
    file_path: str
    thumb_path: str
    uploaded_by_device_id: str = Field(foreign_key="device.id")
    uploaded_by_user_id: Optional[int] = Field(default=None, foreign_key="user.id")
    created_at: Optional[str] = None
```

### 6.8 `app/models/activity.py`

```python
from typing import Optional
from sqlmodel import SQLModel, Field

class ActivityLog(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    timestamp: str
    device_id: str = Field(foreign_key="device.id")
    user_id: Optional[int] = Field(default=None, foreign_key="user.id")
    action: str
    entity_type: str
    entity_id: int
    metadata: Optional[str] = None  # JSON string
```

> Note: For timestamps use `datetime.utcnow().isoformat()` in your service code.

---

## 7) Templates (barebones)

### 7.1 `app/templates/base.html`

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Family Task Portal</title>
  <!-- Tailwind CDN for v1 (replace with CLI build later) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="/static/js/htmx.min.js"></script>
</head>
<body class="bg-slate-50 text-slate-900">
  <header class="p-4 bg-white shadow">Family Task Portal</header>
  <main class="p-4">
    {% block content %}{% endblock %}
  </main>
</body>
</html>
```

### 7.2 `app/templates/board.html`

```html
{% extends "base.html" %}
{% block content %}
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
  <!-- TODO: loop users & plans -->
  <div class="p-4 bg-white rounded-xl shadow">Welcome! Configure users and import a plan.</div>
</div>
{% endblock %}
```

### 7.3 `app/templates/plan.html`

```html
{% extends "base.html" %}
{% block content %}
<h1 class="text-xl font-bold mb-4">Plan #{{ plan_id }}</h1>
<!-- TODO: show days, lock states, subtasks -->
{% endblock %}
```

---

## 8) Self-Approval Guard (service logic sketch)

```python
def can_approve(subtask, actor_user_id, actor_device):
    plan = get_plan_by_subtask(subtask.id)
    if actor_user_id and actor_user_id == plan.assignee_user_id:
        return False
    if actor_device and actor_device.linked_user_id == plan.assignee_user_id:
        return False
    return True
```

---

## 9) Progress & Locking

- A day is **unlocked** when **all** its subtasks are `approved`.
- The next day’s `locked` flag flips to `False` immediately after the last approval of the preceding day.
- Completion = all subtasks in all days are approved → plan status `complete`.

---

## 10) XP & Mood Defaults

```yaml
xp:
  default_subtask: 10
  day_completion_bonus: 20
  plan_completion_bonus: 50
moods: [happy, neutral, sad]
```

---

## 11) Alembic Migrations

### 11.1 Initialise Alembic

```bash
alembic init alembic
```

### 11.2 `alembic/env.py` (snippet)

```python
from logging.config import fileConfig
from sqlalchemy import engine_from_config, pool
from alembic import context
from app.core.config import settings
from app.models.users import User
from app.models.devices import Device
from app.models.plans import Plan, PlanDay
from app.models.tasks import Subtask, SubtaskSubmission
from app.models.approvals import Approval
from app.models.attachments import Attachment
from app.models.activity import ActivityLog
from sqlmodel import SQLModel

config = context.config
fileConfig(config.config_file_name)

def run_migrations_offline():
    context.configure(url=settings.db_url, target_metadata=SQLModel.metadata, literal_binds=True)
    with context.begin_transaction():
        context.run_migrations()

def run_migrations_online():
    connectable = engine_from_config(
        {"sqlalchemy.url": settings.db_url},
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        context.configure(connection=connection, target_metadata=SQLModel.metadata)
        with context.begin_transaction():
            context.run_migrations()

if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
```

### 11.3 First Migration Stub

```bash
alembic revision -m "create core tables"
```

Edit the generated file in `alembic/versions/*.py` and create tables referencing `SQLModel.metadata` if autogenerate isn’t used.

---

## 12) pyproject.toml

```toml
[project]
name = "family-portal"
version = "0.1.0"
description = "Local-only family task portal"
requires-python = ">=3.11"

[tool.setuptools]
py-modules = []

[project.dependencies]
fastapi = "^0.115.0"
uvicorn = "^0.30.0"
sqlmodel = "^0.0.22"
alembic = "^1.13.2"
jinja2 = "^3.1.4"
pillow = "^10.4.0"
python-multipart = "^0.0.9"

[tool.uvicorn]
factory = false
```

---

## 13) Raspberry Pi Bootstrap Script

Save as `scripts/bootstrap_pi.sh` and run with `bash scripts/bootstrap_pi.sh` on the Pi.

```bash
#!/usr/bin/env bash
set -euo pipefail

# Update & base packages
sudo apt-get update
sudo apt-get install -y python3-pip python3-venv libjpeg-dev zlib1g-dev libwebp-dev nginx

# Project path
APP_DIR=/opt/family-portal
sudo mkdir -p $APP_DIR
sudo chown "$USER":"$USER" $APP_DIR

# Copy repo into $APP_DIR beforehand or clone; here we assume already present
# python venv
python3 -m venv $APP_DIR/.venv
source $APP_DIR/.venv/bin/activate
pip install --upgrade pip

# Install deps from pyproject (use pip-tools or uv if preferred)
pip install fastapi uvicorn sqlmodel alembic jinja2 pillow python-multipart

# Folders for uploads
sudo mkdir -p /var/lib/family-portal/uploads/thumbs
sudo chown -R "$USER":"$USER" /var/lib/family-portal

# Alembic init if needed
if [ ! -d "$APP_DIR/alembic" ]; then
  alembic init alembic
fi

# Systemd service
SERVICE_FILE=/etc/systemd/system/family-portal.service
sudo tee $SERVICE_FILE >/dev/null <<'UNIT'
[Unit]
Description=Family Task Portal (Uvicorn)
After=network.target

[Service]
User=%i
WorkingDirectory=/opt/family-portal
Environment=FP_SESSION_SECRET=change-me
Environment=FP_DB_URL=sqlite:////opt/family-portal/family_portal.db
Environment=FP_UPLOADS_DIR=/var/lib/family-portal/uploads
Environment=FP_THUMBS_DIR=/var/lib/family-portal/uploads/thumbs
ExecStart=/opt/family-portal/.venv/bin/uvicorn app.main:app --host 0.0.0.0 --port 8080
Restart=always

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable family-portal.service
sudo systemctl start family-portal.service

# Optional: nginx reverse proxy
NG=/etc/nginx/sites-available/family-portal
sudo tee $NG >/dev/null <<'NGINX'
server {
  listen 80;
  server_name _;
  location /static/ {
    alias /opt/family-portal/app/static/;
  }
  location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }
}
NGINX
sudo ln -sf /etc/nginx/sites-available/family-portal /etc/nginx/sites-enabled/family-portal
sudo nginx -t && sudo systemctl reload nginx

echo "Done. Visit http://<pi-ip>/"
```

> If you want HTTPS on-LAN, add a self-signed cert or mDNS name and configure TLS in nginx.

---

## 14) Backups

### 14.1 `scripts/backup.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR=/var/backups/family-portal
mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
cd /opt/family-portal
sudo tar czf "$BACKUP_DIR/fp-$STAMP.tgz" family_portal.db app/uploads || true
find "$BACKUP_DIR" -type f -name 'fp-*.tgz' -mtime +14 -delete
```

### 14.2 systemd timer (optional)

```
# /etc/systemd/system/family-portal-backup.timer
[Unit]
Description=Family Portal Backup Timer

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
```

```
# /etc/systemd/system/family-portal-backup.service
[Unit]
Description=Family Portal Backup

[Service]
Type=oneshot
ExecStart=/bin/bash /opt/family-portal/scripts/backup.sh
```

Enable: `sudo systemctl enable --now family-portal-backup.timer`.

---

## 15) Optional nginx TLS (self-signed)

Add a cert/key and update server block with `listen 443 ssl;` and ssl directives.

---

## 16) Sample Markdown Plan (drop-in)

```md
# Willow’s Room Rescue – 4-Day Plan

## Day 1 – Clear the Floor
- [ ] Sort clothes: laundry vs keep (+10 XP)
- [ ] Put toys into the right bins (+10 XP)
- [ ] Throw away obvious rubbish (+5 XP)

## Day 2 – Surfaces & Shelves
- [ ] Clear and wipe the desk (+10 XP)
- [ ] Dust shelves (+5 XP)
- [ ] Arrange books upright by size or colour (+5 XP)

## Day 3 – Bed & Wardrobe
- [ ] Change bed sheets (+10 XP)
- [ ] Fold and put away clean clothes (+10 XP)
- [ ] Line up shoes neatly (+5 XP)

## Day 4 – Finishing Touches
- [ ] Wipe door handles and light switch (+5 XP)
- [ ] Vacuum floor (+10 XP)
- [ ] Final photo tour of the room (+10 XP)
```

---

## 17) Implementation Milestones

1. DB models + Alembic migration (users/devices/plans/days/subtasks)
2. Device cookie + device manager UI
3. Markdown import → plan/day/subtasks persistence + lock initialisation
4. Plan page (days, subtasks, lock icons) + submit modal (comment/photo)
5. Review queue + approve/deny + **self-approval guard** + XP award
6. Progress bars (per day & overall) + level calc
7. Image processing + thumbnails
8. Activity Log + filters (by device/user/plan)
9. Styling polish for touch (Tailwind) & board layout
10. Backups & service hardening

---

## 18) Notes & Decisions

- Cookie-based device identity is the cornerstone. If a device clears cookies, it’s a “new” device until re-linked.
- Approvals always record mood; denials require reason and are visible to all.
- Later: custom themes, awards table, nicer gallery/timeline from photo uploads.

---

**You’re ready to scaffold.** Start the Pi bootstrap, run Alembic, and open the portal. Iterate feature by feature with your coding agent using this SPEC as source of truth.

