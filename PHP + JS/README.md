# Family Portal Laravel Stack

This project contains the Laravel implementation that complements the FastAPI service in the primary stack. It ships with Tailwind CSS, Alpine.js, and HTMX pre-configured through Vite so Blade templates can share the same component patterns as the Python application.

## Prerequisites

- PHP 8.3+
- Composer
- Node.js 20+ with npm

## Installation

1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Copy the environment file if it has not been created yet:
   ```bash
   cp .env.example .env
   ```
3. Configure `.env` for the local defaults that match the Python stack. The key settings are already committed for convenience:
   ```ini
   DB_CONNECTION=sqlite
   DB_DATABASE=database/database.sqlite
   FP_SESSION_SECRET=dev-secret-change-me
   FP_UPLOADS_DIR=/var/lib/family-portal/uploads
   FP_THUMBS_DIR=/var/lib/family-portal/uploads/thumbs
   ```
4. Ensure the SQLite database file exists:
   ```bash
   touch database/database.sqlite
   ```
5. Run the framework migrations to bootstrap the schema:
   ```bash
   php artisan migrate
   ```

## Frontend toolchain

The Vite configuration compiles Tailwind CSS and bundles the Alpine.js and HTMX utilities used by the Blade templates.

Install the Node dependencies once:
```bash
npm install
```

### Development build

Use the Vite dev server for fast-refresh development. Run this alongside `php artisan serve`.
```bash
npm run dev
```

### Production build

Generate versioned assets for deployment:
```bash
npm run build
```

## Serving the application

Start the Laravel development server after the dependencies and assets are ready:
```bash
php artisan serve --host=0.0.0.0 --port=8000
```

The application will be available at <http://localhost:8000>. Make sure the FastAPI service continues to manage shared resources such as the uploads directory under `/var/lib/family-portal/uploads`.

## Testing

Run the PHPUnit-powered Laravel test suite to validate the PHP implementation alongside the FastAPI stack:

```bash
php artisan test
```

Individual suites can be targeted with `--testsuite=Feature` or `--filter=` if you only need to run the new parity checks.

## Raspberry Pi deployment

For a production-style deployment on a Raspberry Pi, the repository includes an automated installer that provisions the OS packages, MariaDB database, PHP runtime, frontend build assets, and nginx configuration in one step.

From the repository root on the Pi, run:

```bash
bash scripts/deploy_laravel_pi.sh \
  --db-name family_portal \
  --db-user portal_user \
  --db-pass 'super-secret-password' \
  --session-secret 'paste-a-long-random-string'
```

Key behaviors:

- The script installs nginx, PHP-FPM, Composer, Node.js, MariaDB, and all Laravel prerequisites via `apt-get` if they are not already present.
- MariaDB is configured with the supplied database name, user, and password, and the generated credentials are written into the deployed `.env`.
- Source code is built in place (`composer install --no-dev`, `npm ci`, `npm run build`) and then synchronized to `/var/www/family-portal` (overridable with `--app-root`).
- Environment variables such as `FP_SESSION_SECRET`, `FP_UPLOADS_DIR`, and `FP_DB_URL` are generated automatically so the PHP layer matches the FastAPI stack.
- nginx and PHP-FPM are reloaded to pick up configuration changes, and the deployment can be safely re-run without manual cleanup.

If you prefer to provide the session secret via the environment instead of the command line, export `FP_SESSION_SECRET` before running the script. Additional overrides for the uploads directory (`FP_UPLOADS_DIR`) and thumbnail directory (`FP_THUMBS_DIR`) are also honored.
