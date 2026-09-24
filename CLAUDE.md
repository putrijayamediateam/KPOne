# KPOne — instructions for Claude

KPOne is Klinik Putrijaya's internal digital operating platform (PHP 8.4, Laravel 13, Inertia 3 + Vue 3 + TypeScript, Tailwind 4, PostgreSQL 18).
The repository rules below were written for Codex and apply unchanged to Claude.

@AGENTS.md
@PROJECT.md
@docs/BASELINE.md

## Precedence

1. The user's explicit instruction in the current session.
2. `AGENTS.md` (non-negotiable boundaries, security, data rules).
3. `docs/BASELINE.md` (current delivery status — newer than `PROJECT.md`).
4. `PROJECT.md` and `docs/PHASE_*.md` (phase history and design detail).

If `PROJECT.md` and `docs/BASELINE.md` disagree about what is delivered, trust `docs/BASELINE.md` and point out the drift.

## Working rules for Claude

- Never open, print, or edit `.env`. Use `.env.example` only.
- Do not commit, push, rebase, or open a PR unless the user asks after reviewing the diff.
- One scoped change per branch: `feature/<id>-<slug>`, `fix/<id>-<slug>`, `validation/<id>-<slug>`.
- A module not marked delivered or authorised in `docs/BASELINE.md` is not authorised — ask before building it.
- Business rules go in `app/Domain/*` services and policies, never only in controllers or Vue.
- Every state-changing operation: backend permission + branch scope, `DB::transaction`, row locks,
  optimistic `lock_version`, idempotency key where retries are possible, and an audit record.
- Patient-facing and staff UI text is Bahasa Melayu unless the surrounding screen is English.
- Explain findings, plans and reports to the user in English; code, commits and docs stay in English.
- Test/preview servers: never use a command that loads the real `.env` (e.g. plain `php artisan serve`).
  Start servers with explicit DB env for a task-owned database, and verify the effective DB host/port/name
  BEFORE the first HTTP request. If a process ever connects to a database or port you did not create
  for the task, stop immediately and report (treat as a PAUSE, not a self-fix).

## Quality gate (run before handing off)

```bash
php artisan test
composer run lint:check      # Pint
composer run types:check     # PHPStan / Larastan
npm run types:check
npm run lint:check
npm run format:check
npm run build
node --test tests/Frontend/*.mjs
php artisan migrate:fresh --seed   # local/test database only
git diff --check
```

PostgreSQL contention tests must run against a disposable database named for testing; a skipped
PostgreSQL test is not release evidence. Report failures honestly — never weaken a test to pass.

## Release flow

implementation → independent review → UAT → freeze candidate SHA → PostgreSQL 18 validation →
PR to `main` (putrijayamediateam/KPOne) → GitHub Actions → merge → post-merge CI.
Nothing reaches `main` without validation.

## Environment notes

- Developer laptop repo: `C:\Users\User\Herd\KPOne` (Laravel Herd, Windows). Canonical repo is on the office PC;
  GitHub owner `putrijayamediateam`.
- Sibling folders `KPOne-*` are UAT/evidence worktrees — do not edit them unless asked.
