# KPOne Baseline

Snapshot date: 2026-09-24 · `main` at `1cdc9b7` (PR #32) · supersedes the status line in `PROJECT.md`.
Phase status confirmed by the owner on 2026-09-21.

Yezza remains the operational source of truth. Nothing below is production-approved; all data is synthetic.

## 1. Delivery status

| Phase / slice | Scope | Status |
|---|---|---|
| 0A | Organisation, 3 branches (CHERAS, SUNGAI_BESI, PUCHONG), 9 departments, RBAC, auth, audit, shell | Merged |
| 0B | Staff provisioning, identity admin, branch assignments | Merged |
| 1A | Patient Master (KP-number, NRIC/passport history, masked search) | Merged |
| 1B | Patient Registration → Visit | Merged |
| 1C | Consultation Queue & CA console | Merged |
| 2A | Clinical Encounter (vitals, note, diagnoses) | Merged |
| 2B.0 | Allergy profile & Problem list | Merged |
| 2B | Treatment Plan & medicine/service orders | Merged |
| 2C | Operational shell & clinical workspace | Merged |
| 3A-Core | Dispensary handoff & minimal inventory | Merged (PR #5) |
| 3B | Completed Patient v1: checkout → dispensary → billing → complete visitation | Merged (PR #6) |
| R1A / R1B | Registration validation, visit-reason catalogue | Merged (PR #7, #8) |
| R1C1–R1C5 | Global UX, registration/consultation, billing/panel/finance UI, main menu, responsive/a11y | Merged (PR #9–#13) |
| P1A | Queue performance | Merged (PR #14) |
| UI polish | Dropdown/dark mode, login confirmation, clinical confirmation | Merged (PR #15–#17) |
| Q1-B1 | Public check-in link foundation (QR link issue/rotate/revoke) | Merged (PR #18) |
| M1-B1 | Medicine catalogue governance | Merged (PR #19) |
| I1A / I1B | Inventory reference governance, inventory demo workspace | Merged (PR #20, #21) |
| P0 | Pricing reference governance | Merged (PR #22) |
| CI1 | Exact-SHA validation dispatch | Merged (PR #23) |
| I1C | Payment method governance & visit-completion safety | Merged (PR #24) |
| I2 | Inventory operations demo v1 + 4 remediation PRs | Merged (PR #25–#29) |
| Q1-B2-D3 | One authorisation, one branch (`fix/q1-b2-d3-registration-qr-hold`): (a) registration-integrated QR intake — public form → Pending Intake → CA review in Registration → Patient/Visit/Queue; (b) visit-reason picker fix; (c) Consultation On Hold / Resume (one active consultation per doctor) | Merged in PR #30 |
| Q1-B2-D3-R1 | Remediation of smoke + review findings: mojibake, permission rollout by migration, retention scheduling, privacy-notice correction fix, QR list performance, mobile cards, shared disclosure component, On Hold visibility, small defects, release notes | Merged in PR #30 |
| Q1-B2-D3 UAT | Human UAT, 43 cases, 2026-09-23 (Haziq): 41 pass, 1 skip (QR-02, not applicable), 1 fail (OH-06 clinical note not shown after Hold/Resume) | Done; OH-06 fixed, short re-test pending |
| Merge with `main` | `main` merged into the branch (`6960453`), sole conflict `resources/js/app.ts` resolved in favour of `readInitialInertiaPage()` from `main`; frontend bootstrap test updated. PostgreSQL 18 validation of `6960453`: 649 tests, 647 passed, 2 expected Fortify skips, 0 failures, 7,390 assertions, no contention test skipped. Preview refreshed to this SHA by migrate alone | Merged in PR #30 |
| OH-06c / OH-06d | UAT follow-ups: unsaved-work guard before Hold (Save and hold / Discard and hold / Cancel, reusing the operational confirm dialog, also saving a dirty treatment-plan draft), and Hold/Resume navigation fixed at the controller — `to_route('encounters.show')` instead of `back()`, including the blocked-Resume path via `redirectTo()` plus an error toast, so the doctor stays on the consultation | Merged in PR #30 |
| CI parity fix | `ac462bf`: the test suite no longer depends on ambient environment. CI copies `.env.example`, whose `PUBLIC_PATIENT_INTAKE_ENABLED=false` beat PHPUnit's `<env force>` because Laravel's `env()` reads `$_SERVER` first — fixed by pairing `<env>` with `<server>` in `phpunit.xml`. `.env.example` keeps `false` as the correct production default | Merged |
| **Q1-B2-D3 shipped** | PR #30 merged into `main` as `8ef519b` (merge commit, no squash). Post-merge CI green. Branch `fix/q1-b2-d3-registration-qr-hold` kept | **Merged 2026-09-24** |
| OH-06 / OH-06b | Inertia `preserveState` left `useForm` state stale after Hold/Resume. Data was never lost in the database; the form was not re-synced. Fixed in `Clinical/Show.vue` (`e796620`) and `TreatmentPlanPanel.vue` (`a376069`): clean form adopts content and lock version together, dirty form is never overwritten and shows a drift banner | Merged in PR #30 |
| **RB-01 / RB-02 shipped** | Registration board: stale rows after a failed or thrown search cleared; each visit's own registered date projected. PR #31 merged into `main` as `bc29c28` (merge commit). Post-merge CI green | **Merged 2026-09-24** |

End-to-end synthetic flow working on `main`: Registration → Queue → Consultation → Treatment Plan →
Dispensary → Billing/payment → Completed Visit.

## 2. Domain map (`app/Domain`)

Organisation (branches, public check-in links, Inventory) · Identity · Access (`PermissionCatalogue`, branch access) ·
Audit · Patient (Patient Master, public intake) · Visit · Queue · Clinical (encounter, allergy, treatment plan,
checkout, hold, Dispensary) · Shared.

## 3. Roles

`director`, `resident_doctor`, `ca`, `ca_supervisor`, `panel_officer`, `finance_officer`, `business_development`,
`marketing`, `hr_manager`, `technical_admin`. Source of truth: `app/Domain/Access/PermissionCatalogue.php`
(+ `BillingPermissions`). Technical admin never gains clinical or patient-content access by implication.

## 4. Not authorised yet

Do not build or store data for these without an explicitly approved phase:

- Appointments; patient portal / patient login; WhatsApp, SMS, OTP, email to patients
- Procurement, receiving, stocktake, production stock migration
- Panel claims submission and advanced finance; HR workflows and staff roster
- Website integration; marketing modules; management analytics
- Yezza integration or data migration
- Splitting Q1-B2-D3 into separate PRs — it ships as one authorised scope
- Production deployment and go-live (needs a security, privacy/PDPA and legal gate first)

## 5. Key decisions

- Modular monolith, one Laravel app, one PostgreSQL database. No microservices or queues/brokers without approval.
- Patients are organisation-level; Visits, Queue entries and Encounters are branch-owned.
- Public QR submissions only ever create a **Pending Intake**; a CA must review and accept before any Patient,
  Visit or Queue record exists.
- QR bearer token travels in the URL fragment (`/check-in#token`), is exchanged for httpOnly cookies, and is
  stored hashed (plus an encrypted copy for re-display of the active QR). Links expire after 90 days by default.
- Intake payloads are encrypted, retained 30 days, then purged by `public-intakes:cleanup`.
- Every mutation: backend permission + branch scope, transaction, row lock, `lock_version`, idempotency where
  retried, and an audit event without secrets.
- PostgreSQL 18 validation of an exact frozen SHA is mandatory before merge.

## 6. Open issues and decisions

Resolved in R1 (`a29a3ad`): mojibake; permission rollout now runs from a migration; `public-intakes:cleanup`
scheduled daily and unused intake sessions pruned; correction under a changed privacy-notice version;
QR list pagination and N+1; mobile card layout; shared disclosure component; On Hold button visibility;
dispensary UUID route, age guard, cookie `secure`; release notes in `docs/releases/Q1-B2-D3.md`.

Still open:

1. ~~Commit the Claude setup files~~ **Done.** `CLAUDE.md`, `docs/BASELINE.md` and `.claude/settings.json`
   merged in PR #32 as `1cdc9b7`; the office PC and any other clone now inherit them as tracked files.
2. HC-01 — hold cap of three per doctor plus a held-too-long warning, from the owner decision below.
   Authorised, not yet built; its own branch off the updated `main`.
3. Production gate before any go-live: set `PUBLIC_PATIENT_INTAKE_ENABLED=true` deliberately, set
   `PUBLIC_PATIENT_INTAKE_TRUSTED_PROXIES`, run the scheduler, rotate and reprint every QR code, and
   replace the `uat-draft` privacy-notice version.
4. Existing QR links must be rotated and reprinted — the URL format changed; no data backfill is planned.
5. `PUBLIC_PATIENT_INTAKE_TRUSTED_PROXIES` must be set in every deployed environment.
6. `privacy_notice_version` is still `uat-draft-2026-09-19-v1`; the production value needs a legal decision.
7. Reported, not fixed: hold/resume lock-order inversion (PostgreSQL retries it, `DB::transaction(..., 3)`);
   single-branch "doctor busy" check; `resident_doctor` hard-coded in hold; minors must supply their own mobile
   number.
8. Housekeeping: stray root file `toArray())`; add `.pnpm-store/` to `.gitignore`; refresh `PROJECT.md` and the
   "not yet authorised" list in `AGENTS.md`; `PREVIEW_README.txt` release marker still says D3.

### Registration board — branch `fix/registration-board-stale-rows-and-dates` (base `main` `8ef519b`)

**RB-01 — stale rows after a failed search (`f91c8cc`) — confirmed and fixed, with red-green evidence.**
The defect: `Registration/Index.vue` set an error on a non-OK response, and on a thrown/aborted request,
without ever clearing `rows`, `total` and pagination — so the previously selected tab's rows stayed on screen
under the newly selected tab. Fixed with a shared `clearBoard()` on both paths; the failing status is logged
and the error is surfaced through `PatientBoard`'s `emptyMessage`. The pre-existing generation-based
out-of-order guard is untouched.
Evidence: four frontend tests. Tests 1 and 2 (failed response; thrown/aborted request) **fail** against the
pre-fix `Index.vue` from `8ef519b` (`actual: ['SERVING-1']` vs `expected: []`) and pass after the fix — they
reproduce the authorised symptom. Tests 3 and 4 (superseded responses) already passed before the fix, because
the generation guard predates this work; they are regression cover, not red-green evidence.

**Unresolved — the mechanism actually observed in UAT.** DevTools showed **both** `/registration/search`
requests returning **200**, yet the wrong tab's rows were displayed. None of the four tests reproduce that, and
neither the pre-fix nor the post-fix code has an identified path that would produce it. RB-01 is therefore a
defensive fix: it removes a real defect, but it does not explain the incident that was reported. This
"two 200s, stale tab" mechanism remains **unreproduced and unresolved** — never confirmed against a captured
live status code, only inferred from the code-level defect. If the wrong-tab rows appear again, capture the
status codes, the response bodies and the tab sequence before changing any code.

**RB-02 — date filter showed the range label on every row (`0191d6f`) — fixed.**
The backend row projection carried no per-row date, so `boardRows` fell back to the global filter range label
for every row's `arrivedDate`, and `PatientBoard.vue` rendered it `whitespace-nowrap`, overlapping the Visit
Notes column. `VisitDirectoryService` now projects `registeredAtDate` (branch-local `Y-m-d`), `arrivedDate`
derives from it, the range label moved to the results bar, and the Arrived cell truncates.

PostgreSQL 18 validation of `0191d6f`: 656 tests, 654 passed, 2 expected Fortify skips, 0 failures,
7,417 assertions, re-run identically before the push. Preview `KPOne-Preview-D2` refreshed to `0191d6f`.
Human verification of RB-01 and RB-02 on the preview passed on 2026-09-24 (owner): each row shows its own
registered date, the range label appears once in the results bar, no overflow at 1440x900 or 390x844, and rapid
tab switching keeps rows matching the active tab.
**Shipped: PR #31 merged into `main` as `bc29c28` (merge commit, no squash) on 2026-09-24. Post-merge CI green.**
Branch `fix/registration-board-stale-rows-and-dates` kept; `validation/rb-01-rb-02-registration-board` kept, in
line with all 21 earlier `validation/*` branches, which are retained permanently as release evidence.

### PostgreSQL contention harness (TH-01)

Ten `tests/Feature/Postgres*RegressionTest.php` files run worker subprocesses, each with its own copy of the
wait helpers; there is no shared trait. Roughly 30 hardcoded deadlines existed between them. Nine files used
10 seconds; `PostgresBillingRegressionTest` used 12, the most generous in the codebase, and it is the one that
errored during the `docs/claude-setup` PostgreSQL gate on 2026-09-24 ("Phase 3A worker did not report READY",
both workers alive). Measured startup on a rested, idle machine: 10.63s cold, then 6.91s and 5.40s warm — about
1.4 seconds of margin against the 12-second deadline when cold. The failure occurred on a loaded machine, after
a full suite run and a build. The project history already held five commits spent stabilising this harness;
this was the sixth occurrence.

TH-01 replaced every hardcoded deadline and worker process timeout with `Tests\Support\ContentionTimeouts`, a
single source of truth: `readyTimeoutSeconds()` and `protocolTimeoutSeconds()` each default to 45 seconds,
overridable via `KPONE_CONTENTION_READY_TIMEOUT` and `KPONE_CONTENTION_PROTOCOL_TIMEOUT`; `processTimeoutSeconds()`
is enforced in code to be at least 3x both. No assertion, test name or contention protocol step changed.

On 2026-09-24, the same test on the same machine measured 10.63s worker boot when cold that morning, and under
1 second once the machine had run dozens of suites that day and was warm. A forced-failure check using a
1-second `KPONE_CONTENTION_READY_TIMEOUT` override could not be made to fail on demand as a result. More than
ten times variance within one day is why the old 10-12 second deadlines sat inside the noise band rather than
above it.

Still open, not acted on: the ten duplicated worker classes in `tests/Support/` (`PostgresBillingWorker`,
`PostgresClinicalEncounterWorker`, `PostgresClinicalSafetyWorker`, `PostgresDispensaryInventoryWorker`,
`PostgresPatientCreationWorker`, `PostgresPrimaryChangeWorker`, `PostgresQueueWorker`, `PostgresTreatmentPlanWorker`,
`PostgresVisitReasonWorker`, `PostgresVisitRegistrationWorker`) remain unconsolidated.

Owner decisions (settled 2026-09-24):

- **Held patients per doctor: maximum 3, plus an age warning.** A doctor may hold at most three patients at a
  time; a fourth Hold is refused by the backend with a clear Bahasa Melayu message, and a patient held beyond
  the warning threshold is flagged on the queue board so nobody is forgotten. At most one active consultation
  stays unchanged. Authorised as its own scoped branch; not yet built.
- **Marketing and Business Development keep organisation-wide staff-directory access.** The clinic group is
  small and BD genuinely coordinates across all three branches. This is the widest people-data grant outside
  HR and is accepted deliberately; it is directory data only (name, role, branch) and never clinical or
  patient content. Revisit if the group grows or if a Marketing module changes what those roles can see.


## 7. Updating this file

Update this baseline in the same PR that merges a phase or changes an authorisation boundary.
