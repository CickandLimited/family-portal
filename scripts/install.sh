#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

usage() {
  cat <<'EOF'
Usage: install.sh [options]

Options:
  --target <dir>           Target installation directory.
  --session-secret <val>   Session secret to write to the environment file.
  --clean-install          Remove existing services and files before installing.
  --skip-clean-install     Skip clean install even if previously configured.
  --enable-systemd         Install and enable the systemd service.
  --disable-systemd        Skip systemd service installation.
  --enable-nginx           Install nginx reverse proxy configuration.
  --disable-nginx          Skip nginx configuration.
  --enable-backups         Install and enable the backup timer.
  --disable-backups        Skip backup timer installation.
  --run-migrations         Run Alembic migrations after installation.
  --skip-migrations        Do not run Alembic migrations.
  --help                   Show this help message and exit.

Without options the script will prompt interactively for these values.
EOF
}

log() {
  printf '[installer] %s\n' "$*"
}

die() {
  echo "[installer] ERROR: $*" >&2
  exit 1
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    die "Required command '$1' is not available on PATH."
  fi
}

prompt_with_default() {
  local prompt="$1" default_value="$2" response
  read -rp "$prompt [$default_value]: " response
  if [[ -z "$response" ]]; then
    response="$default_value"
  fi
  echo "$response"
}

prompt_secret() {
  local secret confirm
  while true; do
    read -rsp "Enter session secret: " secret
    echo
    if [[ -z "$secret" ]]; then
      echo "Secret cannot be empty. Please try again." >&2
      continue
    fi
    read -rsp "Confirm session secret: " confirm
    echo
    if [[ "$secret" == "$confirm" ]]; then
      echo "$secret"
      return 0
    fi
    echo "Secrets did not match. Please try again." >&2
  done
}

ask_yes_no() {
  local prompt="$1" default_choice="$2" response default_hint
  if [[ "$default_choice" =~ ^[Yy]$ ]]; then
    default_hint="Y/n"
  else
    default_hint="y/N"
  fi
  while true; do
    read -rp "$prompt [$default_hint]: " response
    response=${response:-$default_choice}
    case "$response" in
      [Yy]|[Yy][Ee][Ss]) return 0 ;;
      [Nn]|[Nn][Oo]) return 1 ;;
      '')
        if [[ "$default_choice" =~ ^[Yy]$ ]]; then
          return 0
        else
          return 1
        fi
        ;;
    esac
    echo "Please answer yes or no." >&2
  done
}

abspath() {
  python3 - <<'PY' "$1"
import os, sys
print(os.path.abspath(sys.argv[1]))
PY
}

run_privileged() {
  local sudo_cmd="$1"
  shift
  if [[ -n "$sudo_cmd" ]]; then
    "$sudo_cmd" "$@"
  else
    "$@"
  fi
}

install_systemd_service() {
  local app_dir="$1" env_file="$2" service_user="$3" sudo_cmd="$4"
  log "Installing systemd service for user $service_user"
  local service_path="/etc/systemd/system/family-portal.service"
  local uvicorn_exec="$app_dir/.venv/bin/uvicorn"

  if [[ ! -x "$uvicorn_exec" ]]; then
    die "Expected uvicorn executable at $uvicorn_exec"
  fi

  if [[ -n "$sudo_cmd" ]]; then
    cat <<UNIT | "$sudo_cmd" tee "$service_path" >/dev/null
[Unit]
Description=Family Portal (Uvicorn)
After=network.target

[Service]
Type=simple
User=$service_user
WorkingDirectory=$app_dir
Environment="PATH=$app_dir/.venv/bin"
EnvironmentFile=$env_file
ExecStart=$uvicorn_exec app.main:app --host 0.0.0.0 --port 8080
Restart=always

[Install]
WantedBy=multi-user.target
UNIT
  else
    cat <<UNIT >"$service_path"
[Unit]
Description=Family Portal (Uvicorn)
After=network.target

[Service]
Type=simple
User=$service_user
WorkingDirectory=$app_dir
Environment="PATH=$app_dir/.venv/bin"
EnvironmentFile=$env_file
ExecStart=$uvicorn_exec app.main:app --host 0.0.0.0 --port 8080
Restart=always

[Install]
WantedBy=multi-user.target
UNIT
  fi

  run_privileged "$sudo_cmd" systemctl daemon-reload
  run_privileged "$sudo_cmd" systemctl enable --now family-portal.service
  log "systemd service enabled."
}

perform_clean_install() {
  local target_dir="$1" sudo_cmd="$2"
  local env_file="$target_dir/.env"

  log "Performing clean install cleanup"

  local unit
  for unit in family-portal.service family-portal-backup.service family-portal-backup.timer; do
    log "Stopping $unit if running"
    if run_privileged "$sudo_cmd" systemctl stop "$unit" >/dev/null 2>&1; then
      log "Stopped $unit"
    else
      log "$unit not running or could not be stopped; continuing."
    fi

    log "Disabling $unit if enabled"
    if run_privileged "$sudo_cmd" systemctl disable "$unit" >/dev/null 2>&1; then
      log "Disabled $unit"
    else
      log "$unit not enabled or could not be disabled; continuing."
    fi
  done

  local unit_path
  for unit_path in \
    "/etc/systemd/system/family-portal.service" \
    "/etc/systemd/system/family-portal-backup.service" \
    "/etc/systemd/system/family-portal-backup.timer"; do
    if run_privileged "$sudo_cmd" test -e "$unit_path"; then
      log "Removing systemd unit file $unit_path"
      run_privileged "$sudo_cmd" rm -f "$unit_path"
    else
      log "Systemd unit file $unit_path not present; skipping."
    fi
  done

  log "Reloading systemd configuration after cleanup"
  if run_privileged "$sudo_cmd" systemctl daemon-reload >/dev/null 2>&1; then
    log "systemd daemon reloaded."
  else
    log "systemd daemon-reload failed; please verify manually."
  fi

  if run_privileged "$sudo_cmd" test -f "$env_file"; then
    log "Removing environment file $env_file"
    run_privileged "$sudo_cmd" rm -f "$env_file"
  else
    log "Environment file $env_file not present; skipping."
  fi

  if [[ -z "$target_dir" || "$target_dir" == "/" ]]; then
    log "Refusing to remove unsafe target directory path '$target_dir'."
  elif run_privileged "$sudo_cmd" test -d "$target_dir"; then
    log "Removing target directory $target_dir"
    run_privileged "$sudo_cmd" rm -rf "$target_dir"
  else
    log "Target directory $target_dir not present; skipping."
  fi

  local data_dir
  for data_dir in "/var/lib/family-portal" "/var/backups/family-portal"; do
    if run_privileged "$sudo_cmd" test -d "$data_dir"; then
      log "Removing directory $data_dir"
      run_privileged "$sudo_cmd" rm -rf "$data_dir"
    else
      log "Directory $data_dir not present; skipping."
    fi
  done

  if [[ -x "$SCRIPT_DIR/install_nginx_config.sh" ]]; then
    log "Removing nginx configuration"
    if run_privileged "$sudo_cmd" "$SCRIPT_DIR/install_nginx_config.sh" --remove; then
      log "Nginx configuration cleanup complete."
    else
      log "Failed to remove nginx configuration; continuing."
    fi
  else
    log "Nginx installer script not executable at $SCRIPT_DIR/install_nginx_config.sh; skipping nginx cleanup."
  fi
}

configure_nginx_proxy() {
  local sudo_cmd="$1"
  local nginx_script="$SCRIPT_DIR/install_nginx_config.sh"
  if [[ ! -x "$nginx_script" ]]; then
    die "Expected nginx installer script at $nginx_script"
  fi

  log "Installing nginx configuration"
  if [[ -n "$sudo_cmd" ]]; then
    $sudo_cmd "$nginx_script"
  else
    "$nginx_script"
  fi
}

schedule_backup_timer() {
  local app_dir="$1" sudo_cmd="$2"
  local service_path="/etc/systemd/system/family-portal-backup.service"
  local timer_source="$REPO_ROOT/deploy/systemd/family-portal-backup.timer"
  local timer_dest="/etc/systemd/system/family-portal-backup.timer"
  local data_root="/var/lib/family-portal"
  local backup_root="/var/backups/family-portal"
  local backup_script="$app_dir/scripts/backup.sh"

  if [[ ! -x "$backup_script" ]]; then
    die "Expected backup script at $backup_script"
  fi

  log "Configuring backup systemd units"
  if [[ -n "$sudo_cmd" ]]; then
    cat <<UNIT | "$sudo_cmd" tee "$service_path" >/dev/null
[Unit]
Description=Create Family Portal backup archive
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
Environment=APP_ROOT=$app_dir
Environment=DATA_ROOT=$data_root
Environment=BACKUP_ROOT=$backup_root
Environment=RETENTION_DAYS=30
ExecStart=$backup_script

[Install]
WantedBy=multi-user.target
UNIT
  else
    cat <<UNIT >"$service_path"
[Unit]
Description=Create Family Portal backup archive
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
Environment=APP_ROOT=$app_dir
Environment=DATA_ROOT=$data_root
Environment=BACKUP_ROOT=$backup_root
Environment=RETENTION_DAYS=30
ExecStart=$backup_script

[Install]
WantedBy=multi-user.target
UNIT
  fi

  if [[ -f "$timer_source" ]]; then
    run_privileged "$sudo_cmd" install -D -m 0644 "$timer_source" "$timer_dest"
  else
    if [[ -n "$sudo_cmd" ]]; then
      cat <<'TIMER' | "$sudo_cmd" tee "$timer_dest" >/dev/null
[Unit]
Description=Run Family Portal backup nightly

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
TIMER
    else
      cat <<'TIMER' >"$timer_dest"
[Unit]
Description=Run Family Portal backup nightly

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
TIMER
    fi
  fi

  run_privileged "$sudo_cmd" mkdir -p "$backup_root"
  local owner="${SUDO_USER:-$USER}"
  if [[ -n "$owner" ]]; then
    run_privileged "$sudo_cmd" chown "$owner":"$owner" "$backup_root"
  fi

  run_privileged "$sudo_cmd" systemctl daemon-reload
  run_privileged "$sudo_cmd" systemctl enable --now family-portal-backup.timer
  log "Backup timer enabled."
}

sync_local_source() {
  local source_dir="$1" dest_dir="$2"
  if [[ "$source_dir" == "$dest_dir" ]]; then
    log "Source and target directories are the same; skipping file sync."
    return 0
  fi

  log "Copying application files from $source_dir to $dest_dir"
  python3 - <<'PY' "$source_dir" "$dest_dir"
import os
import shutil
import sys

source_dir, dest_dir = sys.argv[1:3]

os.makedirs(dest_dir, exist_ok=True)

EXCLUDES = {
    '.git',
    '.venv',
    '__pycache__',
    '.mypy_cache',
    '.pytest_cache',
    'uploads',
    'family_portal.db',
}

for entry in os.listdir(source_dir):
    if entry in EXCLUDES:
        continue
    src_path = os.path.join(source_dir, entry)
    dest_path = os.path.join(dest_dir, entry)
    if os.path.isdir(src_path):
        shutil.copytree(src_path, dest_path, dirs_exist_ok=True)
    else:
        shutil.copy2(src_path, dest_path)
PY
}

main() {
  local target_dir_input="${INSTALL_TARGET_DIR:-}" session_secret_opt
  session_secret_opt="${INSTALL_SESSION_SECRET:-${FP_SESSION_SECRET:-}}"
  local clean_install_choice="" enable_systemd_choice="" configure_nginx_choice=""
  local schedule_backups_choice="" run_migrations_choice=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --target)
        [[ $# -lt 2 ]] && die "--target requires a directory argument"
        target_dir_input="$2"
        shift 2
        ;;
      --session-secret)
        [[ $# -lt 2 ]] && die "--session-secret requires a value"
        session_secret_opt="$2"
        shift 2
        ;;
      --clean-install)
        clean_install_choice=1
        shift
        ;;
      --skip-clean-install)
        clean_install_choice=0
        shift
        ;;
      --enable-systemd)
        enable_systemd_choice=1
        shift
        ;;
      --disable-systemd)
        enable_systemd_choice=0
        shift
        ;;
      --enable-nginx)
        configure_nginx_choice=1
        shift
        ;;
      --disable-nginx)
        configure_nginx_choice=0
        shift
        ;;
      --enable-backups)
        schedule_backups_choice=1
        shift
        ;;
      --disable-backups)
        schedule_backups_choice=0
        shift
        ;;
      --run-migrations)
        run_migrations_choice=1
        shift
        ;;
      --skip-migrations)
        run_migrations_choice=0
        shift
        ;;
      --help)
        usage
        return 0
        ;;
      --*)
        usage >&2
        die "Unknown option: $1"
        ;;
      *)
        break
        ;;
    esac
  done

  if [[ $# -gt 0 ]]; then
    usage >&2
    die "Unexpected argument: $1"
  fi

  require_cmd python3
  require_cmd apt-get

  local sudo_cmd=""
  if [[ $EUID -ne 0 ]]; then
    if command -v sudo >/dev/null 2>&1; then
      sudo_cmd="sudo"
    else
      die "This script needs to install system packages. Run as root or ensure 'sudo' is available."
    fi
  fi

  log "Family Portal installer"
  log "Select the clean install option to remove existing services, nginx configuration, and deployment directories before proceeding."

  local default_target="/opt/family-portal"
  if [[ -z "$target_dir_input" ]]; then
    target_dir_input=$(prompt_with_default "Target installation directory" "$default_target")
  fi
  local target_dir
  target_dir=$(abspath "$target_dir_input")

  local clean_install
  if [[ -z "$clean_install_choice" ]]; then
    ask_yes_no "Perform clean install first?" "n" && clean_install=1 || clean_install=0
  else
    clean_install=$clean_install_choice
  fi

  local session_secret
  if [[ -n "$session_secret_opt" ]]; then
    session_secret="$session_secret_opt"
  else
    session_secret=$(prompt_secret)
  fi
  if [[ -z "$session_secret" ]]; then
    die "Session secret must be provided."
  fi

  local run_migrations enable_systemd configure_nginx schedule_backups
  if [[ -z "$enable_systemd_choice" ]]; then
    ask_yes_no "Install or update systemd service?" "y" && enable_systemd=1 || enable_systemd=0
  else
    enable_systemd=$enable_systemd_choice
  fi
  if [[ -z "$configure_nginx_choice" ]]; then
    ask_yes_no "Configure nginx reverse proxy?" "n" && configure_nginx=1 || configure_nginx=0
  else
    configure_nginx=$configure_nginx_choice
  fi
  if [[ -z "$schedule_backups_choice" ]]; then
    ask_yes_no "Schedule nightly backups?" "y" && schedule_backups=1 || schedule_backups=0
  else
    schedule_backups=$schedule_backups_choice
  fi
  if [[ -z "$run_migrations_choice" ]]; then
    ask_yes_no "Run Alembic migrations now?" "y" && run_migrations=1 || run_migrations=0
  else
    run_migrations=$run_migrations_choice
  fi

  if [[ ${clean_install:-0} -eq 1 ]]; then
    perform_clean_install "$target_dir" "$sudo_cmd"
  else
    log "Skipping clean install cleanup."
  fi

  log "Installing apt packages (python3-venv, libjpeg-dev, libwebp-dev, nginx)..."
  run_privileged "$sudo_cmd" apt-get update
  run_privileged "$sudo_cmd" apt-get install -y python3-venv libjpeg-dev libwebp-dev nginx

  if [[ ! -d "$target_dir" ]]; then
    log "Creating target directory $target_dir"
    run_privileged "$sudo_cmd" mkdir -p "$target_dir"
  fi

  local owner_user
  owner_user="${SUDO_USER:-$USER}"
  if [[ -n "$owner_user" ]]; then
    run_privileged "$sudo_cmd" chown -R "$owner_user":"$owner_user" "$target_dir"
  fi

  local git_available=0
  if command -v git >/dev/null 2>&1; then
    git_available=1
  else
    log "git not found on PATH; proceeding without remote updates."
  fi

  local repo_url=""
  if [[ $git_available -eq 1 ]] && git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    repo_url=$(git -C "$REPO_ROOT" remote get-url origin 2>/dev/null || true)
  fi

  if [[ -d "$target_dir/.git" ]]; then
    if [[ $git_available -eq 1 ]] && git -C "$target_dir" remote get-url origin >/dev/null 2>&1; then
      log "Existing git repository detected. Pulling latest changes..."
      git -C "$target_dir" pull --ff-only
    else
      log "Existing git directory without remote; syncing from local source."
      sync_local_source "$REPO_ROOT" "$target_dir"
    fi
  else
    local has_contents=0
    if [[ -d "$target_dir" && -n $(ls -A "$target_dir" 2>/dev/null) ]]; then
      has_contents=1
    fi

    if [[ $has_contents -eq 1 ]]; then
      if ask_yes_no "Target directory $target_dir already contains files. Overwrite with local source?" "y"; then
        log "Overwriting existing files with local source."
        sync_local_source "$REPO_ROOT" "$target_dir"
      else
        die "Aborting install at user request."
      fi
    else
      if [[ -n "$repo_url" ]]; then
        log "No existing checkout detected; using local source files."
      else
        log "No git remote information available; using local source files."
      fi
      sync_local_source "$REPO_ROOT" "$target_dir"
    fi
  fi

  log "Ensuring virtual environment exists..."
  if [[ ! -d "$target_dir/.venv" ]]; then
    python3 -m venv "$target_dir/.venv"
  fi

  log "Installing Python dependencies from pyproject.toml..."
  "$target_dir/.venv/bin/pip" install --upgrade pip
  (cd "$target_dir" && "$target_dir/.venv/bin/pip" install .)

  local data_root uploads_dir thumbs_dir env_file
  data_root="/var/lib/family-portal"
  uploads_dir="$data_root/uploads"
  thumbs_dir="$uploads_dir/thumbs"
  env_file="$target_dir/.env"

  log "Creating uploads directories under $uploads_dir"
  run_privileged "$sudo_cmd" mkdir -p "$thumbs_dir"
  if [[ -n "$owner_user" ]]; then
    run_privileged "$sudo_cmd" chown -R "$owner_user":"$owner_user" "$data_root"
  fi

  log "Writing environment file at $env_file"
  cat >"$env_file" <<ENV
FP_SESSION_SECRET=$session_secret
FP_DB_URL=sqlite:///${target_dir}/family_portal.db
FP_UPLOADS_DIR=$uploads_dir
FP_THUMBS_DIR=$thumbs_dir
ENV
  if [[ -n "$owner_user" ]]; then
    run_privileged "$sudo_cmd" chown "$owner_user":"$owner_user" "$env_file"
  fi
  chmod 600 "$env_file"

  if [[ ${run_migrations:-0} -eq 1 ]]; then
    log "Running Alembic migrations..."
    (cd "$target_dir" && \
      FP_SESSION_SECRET="$session_secret" \
      FP_DB_URL="sqlite:///${target_dir}/family_portal.db" \
      FP_UPLOADS_DIR="$uploads_dir" \
      FP_THUMBS_DIR="$thumbs_dir" \
      "$target_dir/.venv/bin/alembic" upgrade head)
  else
    log "Skipping Alembic migrations at user request."
  fi

  if [[ ${enable_systemd:-0} -eq 1 ]]; then
    install_systemd_service "$target_dir" "$env_file" "$owner_user" "$sudo_cmd"
  else
    log "Skipping systemd service installation."
  fi

  if [[ ${configure_nginx:-0} -eq 1 ]]; then
    configure_nginx_proxy "$sudo_cmd"
  else
    log "Skipping nginx configuration."
  fi

  if [[ ${schedule_backups:-0} -eq 1 ]]; then
    schedule_backup_timer "$target_dir" "$sudo_cmd"
  else
    log "Skipping backup timer installation."
  fi

  log "Installation complete."
}

main "$@"
