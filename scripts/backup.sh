#!/usr/bin/env bash
set -euo pipefail

APP_ROOT=${APP_ROOT:-/opt/family-portal}
DATA_ROOT=${DATA_ROOT:-/var/lib/family-portal}
BACKUP_ROOT=${BACKUP_ROOT:-/var/backups/family-portal}
RETENTION_DAYS=${RETENTION_DAYS:-30}

log() {
  printf '%s %s\n' "$(date --iso-8601=seconds)" "$*"
}

main() {
  mkdir -p "$BACKUP_ROOT"

  local timestamp backup_file
  timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
  backup_file="${BACKUP_ROOT}/family-portal-${timestamp}.tar.gz"

  local -a sources=()

  if [[ -d "$APP_ROOT" ]]; then
    sources+=("$APP_ROOT")
  else
    log "WARNING: application root $APP_ROOT not found; skipping"
  fi

  if [[ -d "$DATA_ROOT" ]]; then
    sources+=("$DATA_ROOT")
  else
    log "WARNING: data root $DATA_ROOT not found; skipping"
  fi

  if [[ ${#sources[@]} -eq 0 ]]; then
    log "ERROR: nothing to back up" >&2
    exit 1
  fi

  log "Creating archive ${backup_file}"
  tar -czf "$backup_file" "${sources[@]}"

  if [[ -n "${RETENTION_DAYS}" ]]; then
    log "Pruning backups older than ${RETENTION_DAYS} days"
    find "$BACKUP_ROOT" -type f -name 'family-portal-*.tar.gz' -mtime "+${RETENTION_DAYS}" -print -delete || true
  fi

  log "Backup complete"
}

main "$@"
