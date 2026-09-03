# KPOne

KPOne is the Klinik Putrijaya Digital Operating System: a production healthcare operations platform intended to become the shared foundation for Klinik Putrijaya's staff-facing workflows.

This repository is a new, independent Laravel application. It does not modify or depend on `MiniWeb_KlinikPutrijaya_Fullstack`.

## Current scope: Phase 0A through Phase 2C, with Phase 3A-Core under implementation

The unreleased Dispensary and minimal inventory foundation is documented in [docs/PHASE_3A.md](docs/PHASE_3A.md).

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
- organisation-level Patient Master identity, privacy-minimised search, identifier history, and controlled demographic administration
- branch-scoped Patient Registration with canonical Visits, Consultation/OTC, eligible doctor assignment, urgency, provisional coverage, idempotency, repeat warnings, edit/cancel, and a dense operational console
- branch-local Consultation Queue numbers, Waiting, Urgent-first FIFO ordering, own-doctor Queue, Call In to Serving, carry-over visibility, and a live CA/doctor Queue console
- one in-progress own-doctor Clinical Encounter per Serving Consultation, with one current vitals observation, one clinical note, ordered diagnoses, bounded care-related history summaries, and optimistic locking
- an organisation-level structured Allergy Profile/version ledger, explicit per-Encounter Allergy review, longitudinal Problem List, current-care-only clinical safety access, and a reusable stale-review gate for future medicine ordering
- one in-progress own-clinician Treatment Plan with governed medicine/service catalogue selection, immutable order snapshots, exact stale-write protection, mandatory current Allergy review for medicine mutations, and retained withdrawn orders

Phase 1A answers “Who is this patient?”. Phase 1B registers that Patient into a canonical branch Visit. Phase 1C places Consultation Visits into Waiting and ends when the Patient is Serving. Phase 2A records an in-progress clinical assessment. Phase 2B.0 adds structured Allergy and Problem List safety. Phase 2B adds in-progress medicine and service/procedure orders but stops before prescription signing, finalization, handover, completion, fulfilment, stock, dispensing, billing, claims, patient login, QR/OTP, or legacy integration.

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
Phase 1A scope, privacy gate, and production-readiness conditions are documented in [docs/PHASE_1A.md](docs/PHASE_1A.md).
Phase 1B Registration, Visit invariants, concurrency rules, and the Phase 1C boundary are documented in [docs/PHASE_1B.md](docs/PHASE_1B.md).
Phase 1C Queue architecture, permissions, polling, concurrency, and the Phase 2 boundary are documented in [docs/PHASE_1C.md](docs/PHASE_1C.md).
Phase 2A Clinical Encounter ownership, privacy, aggregate concurrency, and production limitations are documented in [docs/PHASE_2A.md](docs/PHASE_2A.md).
Phase 2B.0 Allergy/Profile review, Problem List, stale-review safety, privacy, and future medicine/catalogue contracts are documented in [docs/PHASE_2B_0.md](docs/PHASE_2B_0.md).
Phase 2B Treatment Plan ownership, catalogue snapshots, Allergy gate, concurrency, privacy, and downstream boundaries are documented in [docs/PHASE_2B.md](docs/PHASE_2B.md).
