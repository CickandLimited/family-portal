#!/usr/bin/env bash
set -euo pipefail

CONFIG_SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/deploy/nginx/family-portal.conf"
CONFIG_DEST="/etc/nginx/sites-available/family-portal.conf"
ENABLED_DEST="/etc/nginx/sites-enabled/family-portal.conf"

if [[ $EUID -ne 0 ]]; then
  echo "This script must be run as root (try sudo)." >&2
  exit 1
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
