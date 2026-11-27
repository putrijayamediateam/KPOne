# Demo and trial data: session notes

This file is for a fresh Claude session taking over the demo and trial-data work. No builder team owns this area; the
coordinator (B.E.R.N.I.S.S) assigns it. Last updated 27 Nov 2026.

## Scope

- **DEMO-1:** `Database\Seeders\KPOneDemoSetupSeeder`, a local and testing one-branch demo setup (PR #215).
- **TRIAL-DATA:** `php artisan kpone:trial-data --phase=1..6`, fictional demo data on the trial server (kpjaya.com,
  APP_ENV=production). Design note: `docs/design/TRIAL-DATA-COMMAND.md`. Operator steps: `docs/DEVELOPMENT.md`.
- **Yezza import:** design note only, `docs/design/YEZZA-IMPORT-DESIGN.md`. Nothing is built; migration stays
  unauthorised until the owner approves a build and later says go-live.

## Code map

- `app/Console/Commands/TrialData.php`: the command; locked by `KPONE_TRIAL_DATA_ALLOWED`, the typed host name and an
  interactive terminal; one hidden shared password; refuses phases above 1 until phase 1 has run.
- `app/Domain/Shared/TrialData/`: `TrialDataContext` (acting as a user), `PlaceholderFiles` (PNG/PDF marked
  FICTIONAL TRIAL DATA), `ClinicFlow` (trait), `PhaseOneFoundation` to `PhaseSixRest`.
- Tests: `tests/Feature/TrialDataCommandTest.php`, `TrialDataPhaseSixTest.php` and `TrialDataEndToEndTest.php` (a
  committed migrate:fresh database, so PostgreSQL's deferred checks fire).

## History (all merged)

| PR | What |
|---|---|
| #215 | DEMO-1 one-branch demo seeder |
| #1031 | Trial data phase 1 (foundation) |
| #1038 | Trial data phases 2 to 6 |
| #1051 | Phase 3 split into committed transactions (deferred completed-visit check on the server) |
| #1053 | Design note: first server run (21 Nov 2026) |
| #1055 | Phase 5 works just after Kuala Lumpur midnight (overtime capped to the session; incident dated yesterday) |
| #1101 | Yezza import design note |

## Owner decisions (all recorded in the design notes)

- Full coverage of every function, role and menu, in phases; `.test` accounts only (`trial.<key>@kpjaya.test`).
- Placeholder files and statutory tables marked FICTIONAL; tables entered and checked by different people.
- Full data at all three branches; Director-only steps run as the owner's Director account.
- Agents are left out of trial data until the agent platform team's registration feature ships.
- Yezza note: "Ship it nota" (25 Nov). The eleven detail questions in its section 6 go to the owner through the
  coordinator; no answers yet.

## Open work

- No open branches or PRs. Every local branch is pushed and merged: `feature/demo-1-setup`,
  `feature/trial-data-command`, `feature/trial-data-phase-2`, `fix/trial-data-phase3-commit`,
  `docs/trial-data-server-run`, `fix/trial-data-kl-midnight`, `docs/yezza-import-design`.
- No owner decision is waiting to be recorded.

## Next slices (each needs the owner's ship-it)

1. Agents in trial data, once the agent registration feature is on `main`.
2. Job applications, locum pay months and open-shift requests in phase 5 or 6, if the owner wants them (empty by
   design today).
3. Yezza import build, only after the owner answers the eleven questions and approves a build.

## Rules to keep

- Never open `.env`; real data never on kpjaya.com; tests on a task-owned PostgreSQL 18 database only.
- Build test and command dates from the branch time zone, and check any "earlier today" time near local midnight
  (BASELINE section 6, item 21; PR #1055).
- Backup-push to own branches; PR only after the owner's ship-it; never merge.
