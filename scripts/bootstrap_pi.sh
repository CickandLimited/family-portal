#!/usr/bin/env bash
# Bootstrap script for deploying the Family Portal application on a Raspberry Pi.
# This script performs package installation, project deployment, virtual environment setup,
# database and upload directory provisioning, and systemd service configuration.

set -euo pipefail

# -----------------------------
# Resolve key filesystem paths.
# -----------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="/opt/family-portal"
SERVICE_FILE="/etc/systemd/system/family-portal.service"
UPLOAD_ROOT="/var/lib/family-portal"
SERVICE_USER="${SERVICE_USER:-$USER}"

# -------------------------------------
# Update apt repositories and packages.
# -------------------------------------
echo "[bootstrap] Updating apt repositories and installing system packages..."
sudo apt-get update
sudo apt-get install -y \
  python3-pip \
  python3-venv \
  libjpeg-dev \
  zlib1g-dev \
  libwebp-dev \
  nginx \
  rsync

# ------------------------------------------------------
# Ensure the application directory exists and is owned.
# ------------------------------------------------------
echo "[bootstrap] Preparing application directory at $APP_DIR..."
sudo mkdir -p "$APP_DIR"
sudo chown "$SERVICE_USER":"$SERVICE_USER" "$APP_DIR"

# ----------------------------------------------------------------------
# Synchronize the repository into /opt to keep deployment self-contained.
# ----------------------------------------------------------------------
echo "[bootstrap] Syncing repository contents to $APP_DIR..."
rsync -a --delete \
  --exclude ".git" \
  --exclude "uploads" \
  "$REPO_ROOT/" "$APP_DIR/"

# -----------------------------------------------------
# Create the Python virtual environment and install deps.
# -----------------------------------------------------
echo "[bootstrap] Creating Python virtual environment..."
python3 -m venv "$APP_DIR/.venv"

echo "[bootstrap] Installing Python dependencies..."
"$APP_DIR/.venv/bin/pip" install --upgrade pip
"$APP_DIR/.venv/bin/pip" install \
  fastapi \
  uvicorn \
  sqlmodel \
  alembic \
  jinja2 \
  pillow \
  python-multipart

# ------------------------------------------------
# Provision upload directories with appropriate ACLs.
# ------------------------------------------------
echo "[bootstrap] Creating upload directories..."
sudo mkdir -p "$UPLOAD_ROOT/uploads/thumbs"
sudo chown -R "$SERVICE_USER":"$SERVICE_USER" "$UPLOAD_ROOT"

# -----------------------------------------------------------------
# Initialize Alembic scaffolding if it has not been created before.
# -----------------------------------------------------------------
echo "[bootstrap] Ensuring Alembic configuration exists..."
if [[ ! -d "$APP_DIR/alembic" ]]; then
  pushd "$APP_DIR" >/dev/null
  "$APP_DIR/.venv/bin/alembic" init alembic
  popd >/dev/null
fi

# --------------------------------------------------------------
# Write the systemd unit exactly as defined in the project spec.
# --------------------------------------------------------------
echo "[bootstrap] Writing systemd service to $SERVICE_FILE..."
# Operators must populate /opt/family-portal/.env with the required FP_* variables
# (for example FP_SESSION_SECRET, FP_DB_URL, FP_UPLOADS_DIR, and FP_THUMBS_DIR)
# before enabling this service.
sudo tee "$SERVICE_FILE" >/dev/null <<UNIT
[Unit]
Description=Family Task Portal (Uvicorn)
After=network.target

[Service]
User=${SERVICE_USER}
WorkingDirectory=/opt/family-portal
EnvironmentFile=/opt/family-portal/.env
Environment="PATH=/opt/family-portal/.venv/bin"
ExecStart=/opt/family-portal/.venv/bin/uvicorn app.main:app --host 0.0.0.0 --port 8080
Restart=always

[Install]
WantedBy=multi-user.target
UNIT

# -------------------------------------------------------------------
# Reload systemd, enable the application, and start the service.
# -------------------------------------------------------------------
echo "[bootstrap] Enabling and starting systemd service..."
sudo systemctl daemon-reload
sudo systemctl enable family-portal.service
sudo systemctl start family-portal.service

echo "[bootstrap] Deployment complete. The Family Portal should now be reachable on port 8080."
