# KPOne

KPOne is the Klinik Putrijaya Digital Operating System: a production healthcare operations platform intended to become the shared foundation for Klinik Putrijaya's staff-facing workflows.

This repository is a new, independent Laravel application. It does not modify or depend on `MiniWeb_KlinikPutrijaya_Fullstack`.

## Current scope: Phase 0A + Phase 0B

Phase 0A provides the platform foundation, and Phase 0B adds internal operational staff identity administration:

- Klinik Putrijaya organisation, branches, and departments
- staff identity and effective multi-branch assignments
- session authentication with inactive-account enforcement
- Spatie roles and explicitly scoped permissions
- pre-provisioned Google staff SSO architecture via Socialite
- append-oriented audit logs and system-event storage
- a responsive Vue/Inertia application shell
- Dashboard, Profile, Staff, Branches, Access Control, and Audit Logs pages
- server-filtered staff directory and operational staff detail/access summary
- transactional internal staff provisioning with Google-only or immediately hashed password access
- explicit staff profile, department, role, branch-assignment, activation, and deactivation workflows
- higher-authority target protection and an active-account primary-branch invariant

No patient, clinical, dispensary, billing, inventory, HR workflow, finance workflow, website integration, Yezza, messaging, OTP, or migration functionality is implemented.

## Stack

- PHP 8.4.1+ and Laravel 13
- Vue 3, TypeScript, Inertia 3, Tailwind CSS, and shadcn-vue
- PostgreSQL 18 in deployed/local application environments
- SQLite for the default isolated local PHPUnit workflow; PostgreSQL 18 in GitHub Actions
- Spatie Laravel Permission and Laravel Socialite
- PHPUnit from the official Laravel Vue starter kit

## Quick start

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for PostgreSQL 18 setup and the complete workflow.

```bash
composer install
npm ci
php artisan key:generate
php artisan migrate --seed
npm run dev
```

Only `.env.example` is versioned. Never commit credentials or a real `.env` file.

The development-only seeder creates one fictional account in `local` and `testing` environments:

- Email: `dev.admin@kpone.test`
- Password: `KPOne-Dev-Only!`

This account must never be enabled or replicated in production.

## Verification

```bash
php artisan test
composer run lint:check
composer run types:check
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

Architecture, security, phase boundaries, and contributor rules are in [`docs/`](docs) and [AGENTS.md](AGENTS.md).

Phase 0B implementation details are documented in [docs/PHASE_0B.md](docs/PHASE_0B.md).
