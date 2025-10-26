#!/usr/bin/env bash
# Bootstrap script for deploying the Family Portal application on a Raspberry Pi.
#
# This is the one-stop entry point that performs Pi-specific preparation and
# then delegates to the shared installer (scripts/install.sh) to handle the full
# deployment: package installation, application sync, virtual environment
# creation, database migrations, nginx, systemd, and backup scheduling.
#
# The script is intended to be run non-interactively on a freshly provisioned Pi
# but remains safe to rerun thanks to the installer's clean-install option.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_SCRIPT="$SCRIPT_DIR/install.sh"
TARGET_DIR="/opt/family-portal"

log() {
  printf '[bootstrap] %s\n' "$*"
}

require_file() {
  local path="$1"
  if [[ ! -x "$path" ]]; then
    log "Expected executable installer at $path"
    exit 1
  fi
}

generate_session_secret() {
  python3 - <<'PY'
import secrets
print(secrets.token_hex(32))
PY
}

main() {
  require_file "$INSTALL_SCRIPT"

  if ! command -v python3 >/dev/null 2>&1; then
    log "python3 is required to generate a session secret"
    exit 1
  fi

  log "Preparing to run Family Portal installer for Raspberry Pi"

  local session_secret
  session_secret="${FP_SESSION_SECRET:-}"
  if [[ -z "$session_secret" ]]; then
    log "Generating random session secret"
    session_secret="$(generate_session_secret)"
  else
    log "Using session secret from FP_SESSION_SECRET environment variable"
  fi

  local installer_args=(
    "--target" "$TARGET_DIR"
    "--session-secret" "$session_secret"
    "--clean-install"
    "--enable-systemd"
    "--enable-nginx"
    "--enable-backups"
    "--run-migrations"
  )

  log "Invoking installer with target $TARGET_DIR"
  "$INSTALL_SCRIPT" "${installer_args[@]}"

  log "Bootstrap complete. Family Portal should now be configured on the Pi."
}

main "$@"
