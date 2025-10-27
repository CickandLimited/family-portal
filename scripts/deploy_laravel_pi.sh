#!/usr/bin/env bash
#
# Deploy the Laravel front-end of the Family Portal to a Raspberry Pi.
#
# Environment variables:
#   FP_SESSION_SECRET  - Optional default for --session-secret.
#   FP_UPLOADS_DIR     - Optional override for uploads directory (default /var/lib/family-portal/uploads).
#   FP_THUMBS_DIR      - Optional override for thumbnail directory (default FP_UPLOADS_DIR/thumbs).
#
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: deploy_laravel_pi.sh [options]

Options:
  --app-root <path>       Target deployment directory (default: /var/www/family-portal)
  --db-name <name>        MariaDB database name to create/use (required)
  --db-user <user>        MariaDB user name to create/use (required)
  --db-pass <password>    MariaDB user password (required)
  --session-secret <val>  Session secret for FP_SESSION_SECRET (required unless FP_SESSION_SECRET env set)
  --help                  Show this help message and exit
USAGE
}

log() {
  printf '[deploy] %s\n' "$*"
}

die() {
  echo "[deploy] ERROR: $*" >&2
  exit 1
}

run_sudo() {
  if [[ $EUID -eq 0 ]]; then
    "$@"
  else
    sudo "$@"
  fi
}

run_as_www_data() {
  if [[ $EUID -eq 0 ]]; then
    sudo -u www-data "$@"
  else
    sudo -u www-data "$@"
  fi
}

sql_escape() {
  local value="$1"
  printf "%s" "${value//\'/\'"'"\'}"
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SOURCE_DIR="$(cd "$REPO_ROOT" && cd "laravel-app" && pwd)"

APP_ROOT="/var/www/family-portal"
DB_NAME=""
DB_USER=""
DB_PASS=""
SESSION_SECRET="${FP_SESSION_SECRET:-}"
UPLOADS_DIR="${FP_UPLOADS_DIR:-/var/lib/family-portal/uploads}"
THUMBS_DIR="${FP_THUMBS_DIR:-}"  # will default later

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app-root)
      [[ $# -ge 2 ]] || die "Missing value for $1"
      APP_ROOT="$2"
      shift 2
      ;;
    --db-name)
      [[ $# -ge 2 ]] || die "Missing value for $1"
      DB_NAME="$2"
      shift 2
      ;;
    --db-user)
      [[ $# -ge 2 ]] || die "Missing value for $1"
      DB_USER="$2"
      shift 2
      ;;
    --db-pass)
      [[ $# -ge 2 ]] || die "Missing value for $1"
      DB_PASS="$2"
      shift 2
      ;;
    --session-secret)
      [[ $# -ge 2 ]] || die "Missing value for $1"
      SESSION_SECRET="$2"
      shift 2
      ;;
    --help)
      usage
      exit 0
      ;;
    *)
      die "Unknown argument: $1"
      ;;
  esac
done

[[ -n "$DB_NAME" ]] || die "--db-name is required"
[[ -n "$DB_USER" ]] || die "--db-user is required"
[[ -n "$DB_PASS" ]] || die "--db-pass is required"
[[ -n "$SESSION_SECRET" ]] || die "--session-secret is required (or set FP_SESSION_SECRET)"

if [[ -z "$THUMBS_DIR" ]]; then
  THUMBS_DIR="$UPLOADS_DIR/thumbs"
fi

log "Installing operating system prerequisites"
run_sudo apt-get update
run_sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y \
  nginx php-fpm php-cli php-mysql php-sqlite3 php-gd php-xml php-curl php-zip \
  mariadb-server composer nodejs npm git rsync unzip jq

if ! command -v composer >/dev/null 2>&1; then
  die "composer was not installed successfully"
fi

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

log "Enabling PHP extensions"
run_sudo phpenmod pdo_mysql mysqli gd xml curl zip >/dev/null 2>&1 || true

log "Ensuring services are enabled"
run_sudo systemctl enable --now "$PHP_FPM_SERVICE"
run_sudo systemctl enable --now nginx
run_sudo systemctl enable --now mariadb

log "Securing MariaDB installation"
run_sudo mysql -u root <<'SQL'
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%';
FLUSH PRIVILEGES;
SQL

log "Configuring MariaDB database and user"
ESCAPED_PASS="$(sql_escape "$DB_PASS")"
run_sudo mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$ESCAPED_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$ESCAPED_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Installing PHP dependencies"
(
  cd "$SOURCE_DIR"
  if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if ! git config --global --get-all safe.directory 2>/dev/null | grep -Fx -- "$REPO_ROOT" >/dev/null 2>&1; then
      git config --global --add safe.directory "$REPO_ROOT"
    fi
    if ! git config --global --get-all safe.directory 2>/dev/null | grep -Fx -- "$SOURCE_DIR" >/dev/null 2>&1; then
      git config --global --add safe.directory "$SOURCE_DIR"
    fi
  fi
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
  npm ci
  npm run build
)

log "Preparing deployment root at $APP_ROOT"
run_sudo mkdir -p "$APP_ROOT"
run_sudo rsync -a --delete \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'node_modules' \
  --exclude 'tests' \
  --exclude 'storage/logs/*.log' \
  "$SOURCE_DIR"/ "$APP_ROOT"/

log "Setting initial permissions for storage and cache"
run_sudo chown -R www-data:www-data "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"
run_sudo chmod -R ug+rwX "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

log "Ensuring upload directories exist"
run_sudo mkdir -p "$UPLOADS_DIR" "$THUMBS_DIR"
run_sudo chown -R www-data:www-data "$UPLOADS_DIR" "$THUMBS_DIR"

APP_HOST="$(hostname -I 2>/dev/null | awk '{print $1}')"
if [[ -n "$APP_HOST" ]]; then
  APP_URL_VALUE="http://$APP_HOST"
else
  APP_URL_VALUE="http://localhost"
fi

ENV_TMP="$(mktemp)"
cp "$SOURCE_DIR/.env.example" "$ENV_TMP"

update_env() {
  local key="$1" value="$2"
  python3 - "$ENV_TMP" "$key" "$value" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
key = sys.argv[2]
value = sys.argv[3]

def format_line(raw_value):
    special_chars = set(' #"\'\\$\n')
    if any(ch in special_chars for ch in raw_value):
        escaped = raw_value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$').replace('\n', '\\n')
        return f'{key}="{escaped}"'
    return f"{key}={raw_value}"

target = format_line(value)
lines = path.read_text().splitlines()
found = False
for idx, line in enumerate(lines):
    if line.startswith(f"{key}="):
        lines[idx] = target
        found = True
        break

if not found:
    lines.append(target)

path.write_text("\n".join(lines) + "\n")
PY
}

update_env APP_ENV production
update_env APP_DEBUG false
update_env APP_URL "$APP_URL_VALUE"
update_env DB_CONNECTION mysql
update_env DB_HOST 127.0.0.1
update_env DB_PORT 3306
update_env DB_DATABASE "$DB_NAME"
update_env DB_USERNAME "$DB_USER"
update_env DB_PASSWORD "$DB_PASS"
update_env FP_SESSION_SECRET "$SESSION_SECRET"
update_env FP_UPLOADS_DIR "$UPLOADS_DIR"
update_env FP_THUMBS_DIR "$THUMBS_DIR"
update_env FP_DB_URL "mysql://$DB_USER:$DB_PASS@localhost/$DB_NAME"

log "Syncing environment configuration"
run_sudo install -m 640 -o www-data -g www-data "$ENV_TMP" "$APP_ROOT/.env"
rm -f "$ENV_TMP"

log "Generating application key"
run_as_www_data bash -lc "cd '$APP_ROOT' && php artisan key:generate --force"

log "Running database migrations"
run_as_www_data bash -lc "cd '$APP_ROOT' && php artisan migrate --force"

log "Caching configuration and routes"
run_as_www_data bash -lc "cd '$APP_ROOT' && php artisan config:cache"
run_as_www_data bash -lc "cd '$APP_ROOT' && php artisan route:cache"

log "Linking storage"
run_as_www_data bash -lc "cd '$APP_ROOT' && php artisan storage:link"

log "Setting ownership on deployment root"
run_sudo chown -R www-data:www-data "$APP_ROOT"

log "Configuring nginx"
NGINX_CONF="/etc/nginx/sites-available/family-portal"
run_sudo tee "$NGINX_CONF" >/dev/null <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    root $APP_ROOT/public;
    index index.php index.html;

    client_max_body_size 20m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCKET;
    }

    location ~* \.(?:css|js|jpe?g|gif|png|svg|webp|ico|ttf|otf|woff2?)$ {
        expires 7d;
        add_header Cache-Control "public, max-age=604800";
        try_files \$uri \$uri/ =404;
    }

    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
}
NGINX

run_sudo rm -f /etc/nginx/sites-enabled/default
run_sudo ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/family-portal

log "Testing nginx configuration"
run_sudo nginx -t

log "Reloading services"
run_sudo systemctl reload nginx
run_sudo systemctl reload "$PHP_FPM_SERVICE"

log "Deployment complete"
