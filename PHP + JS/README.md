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
