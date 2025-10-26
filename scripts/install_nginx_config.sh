#!/usr/bin/env bash
set -euo pipefail

CONFIG_SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/deploy/nginx/family-portal.conf"
CONFIG_DEST="/etc/nginx/sites-available/family-portal.conf"
ENABLED_DEST="/etc/nginx/sites-enabled/family-portal.conf"

MODE="install"
if [[ ${1:-} == "--remove" ]]; then
  MODE="remove"
fi

if [[ $EUID -ne 0 ]]; then
  echo "This script must be run as root (try sudo)." >&2
  exit 1
fi

if [[ "$MODE" == "remove" ]]; then
  echo "Removing Family Portal nginx configuration"
  local_removed=0

  if [[ -L "$ENABLED_DEST" || -e "$ENABLED_DEST" ]]; then
    echo "Removing nginx sites-enabled entry at $ENABLED_DEST"
    rm -f "$ENABLED_DEST"
    local_removed=1
  else
    echo "No nginx sites-enabled entry found at $ENABLED_DEST"
  fi

  if [[ -f "$CONFIG_DEST" ]]; then
    echo "Removing nginx configuration file at $CONFIG_DEST"
    rm -f "$CONFIG_DEST"
    local_removed=1
  else
    echo "No nginx configuration file found at $CONFIG_DEST"
  fi

  if [[ $local_removed -eq 1 ]]; then
    echo "Reloading nginx after cleanup"
    if nginx -t; then
      systemctl reload nginx
      echo "Nginx reloaded after configuration removal."
    else
      echo "nginx configuration test failed; reload skipped." >&2
    fi
  else
    echo "No nginx configuration changes detected; skipping reload."
  fi
  exit 0
fi

if [[ ! -f "$CONFIG_SOURCE" ]]; then
  echo "Cannot find nginx configuration at $CONFIG_SOURCE" >&2
  exit 1
fi

echo "Copying nginx configuration to $CONFIG_DEST"
install -D -m 0644 "$CONFIG_SOURCE" "$CONFIG_DEST"

if [[ ! -L "$ENABLED_DEST" ]]; then
  echo "Linking $CONFIG_DEST to sites-enabled"
  ln -sf "$CONFIG_DEST" "$ENABLED_DEST"
else
  echo "sites-enabled link already present at $ENABLED_DEST"
fi

nginx -t
systemctl reload nginx

echo "Nginx reloaded with family-portal configuration."
