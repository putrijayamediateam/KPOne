# Development

## Prerequisites

- PHP 8.4.1 or newer with `curl`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, and `sodium`
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

The repository defaults to PostgreSQL. The default local PHPUnit configuration overrides the connection to an in-memory SQLite database to keep the routine test workflow fast and isolated; SQLite is not a supported deployed database. GitHub Actions deliberately supplies `DB_CONNECTION=pgsql` and an isolated PostgreSQL 18 service database, so the complete suite also covers production-database semantics.

Phase 1A adds no patient development seeder. Local and automated Patient Master records must remain obviously synthetic; never copy production patient data into local, test, screenshots, fixtures, backups, or debugging tools. The PostgreSQL Patient Master regression uses separate PHP processes to prove counter initialization/allocation and identifier unique-index contention.

Phase 1B likewise adds no Panel, Patient, or Visit seeder. `PanelFactory`, `PatientFactory`, and `VisitFactory` are test-only synthetic fixtures. The separate-process PostgreSQL Visit regression covers idempotency, Visit-counter allocation, repeat-attendance serialization, cross-branch attendance, and update/cancel contention. SQLite remains the routine workflow and explicitly skips PostgreSQL-only process tests.

Phase 1C adds no Queue seeder. `QueueEntryFactory` is test-only. Queue pages use an initial bounded Inertia projection followed by an authorised private POST snapshot approximately every three seconds while visible. The PostgreSQL Queue regression uses separate processes to prove Queue-entry uniqueness, branch/day counter allocation, Call In and Visit/doctor race serialization.

Phase 2A adds no Encounter, vitals, or diagnosis seeder. Its factories and feature fixtures use synthetic content only. The PostgreSQL Clinical regression uses separate processes to prove one Encounter per Visit, Start/Call/security-state serialization, stale aggregate rejection, diagnosis-list integrity, and rollback. Do not use clinical text copied from any real Patient in local tests, screenshots, logs, or debugging tools.

Phase 2B.0 adds no Allergy Profile, Allergy Record, Encounter review, Problem List, or catalogue seeder. Its factories are test-only and all fixtures use obvious synthetic clinical text. The PostgreSQL Clinical safety regression uses separate processes to prove Profile creation/version serialization, no-known versus Allergy races, competing Allergy edits, final-error consistency, review-versus-mutation staleness, authority revalidation, scope isolation, and full rollback. No Treatment Plan, medicine/service order, catalogue data, Dispensary, inventory, or billing fixture is introduced.

PostgreSQL-specific regression tests skip explicitly on SQLite. In PostgreSQL CI they exercise transactional rollback and genuine row-lock contention using separately bootstrapped PHP worker processes and database connections. Never point that workflow at a developer or production database: the tests require `APP_ENV=testing` and a database name clearly marked as a test database before they write fixtures.

## Dummy account

`DatabaseSeeder` always installs reference organisation/RBAC data. It creates the fictional development administrator only when `APP_ENV` is `local` or `testing`:

- `dev.admin@kpone.test`
- `KPOne-Dev-Only!`

Production environments must not use this identity. The seeder does not create staff from planning headcounts.

Seeding is controlled bootstrap, not a runtime administration path. Runtime branch-assignment mutations must use `BranchAssignmentService` so validation, locking, and audit guarantees apply. A synthetic local/testing seeder or test fixture may use explicit guarded writes to establish initial fictional state when routing it through runtime services would create misleading operational audit history.

## Google sign-in

Leave the following placeholders empty unless configuring a local Google OAuth client:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

Google sign-in never provisions users. Create and activate the KPOne account first, and ensure its email matches the intended Google identity. Use only dummy accounts in local development.

## Phase 0B staff provisioning

Authenticated organisation administrators provision staff through the Staff module; public registration remains unavailable. Google-only provisioning stores no password or provider token. Password provisioning accepts a strong bootstrap value, hashes it immediately, and never returns or audits it. Forced first-login rotation is not available in Phase 0B, so use only synthetic credentials locally and follow a separately approved credential-transfer procedure before production use.

An active staff account requires exactly one currently effective primary branch. Additional assignments may be current or future-dated. All runtime assignment changes use `BranchAssignmentService`; do not replace its locking, validation, and audit boundary with direct model writes.

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
- Treat Registration as canonical Visit creation. Do not add a parallel Registration table or Queue/clinical/billing columns to `visits`.
- Treat Queue Entry as the one-to-one operational child of a Consultation Visit. Do not duplicate Patient, doctor, reason, priority, clinical, or billing fields on it.
- Treat Clinical Encounter as the one-to-one in-progress clinical child of a Serving Consultation Visit. Keep clinical note, vitals, and diagnoses out of Patient, Visit, Queue polling, and audit metadata. Do not add signing, completion, handover, treatment, prescribing, or billing semantics in Phase 2A.
- Treat Allergy Profile and Problem List as organisation-level longitudinal clinical data available only through current Encounter care. Never infer no-known from zero rows, auto-review after mutation, copy clinical values into audits/operational projections, or bypass the Profile-version review gate intended for future medicine ordering.
