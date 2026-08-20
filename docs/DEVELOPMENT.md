# Development

## Prerequisites

- PHP 8.3 or newer with `curl`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, and `sodium`
- Composer 2
- Node.js 22+ and npm
- PostgreSQL 18

## Local setup

1. Install backend and frontend dependencies:

   ```bash
   composer install
   npm ci
   ```

2. Create a local, untracked environment file from `.env.example`. Fill only local PostgreSQL credentials and generate a unique application key. Never commit or share the file.

   ```bash
   php artisan key:generate
   ```

3. Create a UTF-8 PostgreSQL 18 database and restricted application role, then run:

   ```bash
   php artisan migrate --seed
   ```

4. Start the application:

   ```bash
   composer run dev
   ```

The repository defaults to PostgreSQL. PHPUnit overrides the connection to an in-memory SQLite database to keep tests isolated and deterministic; SQLite is not a supported deployed database.

## Dummy account

`DatabaseSeeder` always installs reference organisation/RBAC data. It creates the fictional development administrator only when `APP_ENV` is `local` or `testing`:

- `dev.admin@kpone.test`
- `KPOne-Dev-Only!`

Production environments must not use this identity. The seeder does not create staff from planning headcounts.

## Google sign-in

Leave the following placeholders empty unless configuring a local Google OAuth client:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

Google sign-in never provisions users. Create and activate the KPOne account first, and ensure its email matches the intended Google identity. Use only dummy accounts in local development.

## Commands

Backend:

```bash
php artisan test
composer run lint:check
composer run types:check
```

Frontend:

```bash
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

Migration verification with dummy data:

```bash
php artisan migrate:fresh --seed
```

Run destructive migration commands only against a confirmed local/test database. Laravel prohibits destructive database commands in production.

## Working conventions

- Use `app/Domain` for business rules and keep controllers thin.
- Add a policy or scope service before exposing a branch- or organisation-sensitive route.
- Add a test proving both the allowed and denied path.
- Use `.test` email domains and obviously fictional names.
- Do not add later-phase tables or placeholder personal/clinical fields speculatively.
