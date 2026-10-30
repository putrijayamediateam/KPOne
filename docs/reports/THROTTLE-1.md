# THROTTLE-1 — isolate numeric throttle counters

Date: 2026-10-30. Owner-authorised response to DRY-RUN-1 D005, D008 and D009. **Owner-authorised PR candidate; not production-approved.**

## Base and scope

Fetched `origin/main`: **87009ebceec0cc01b566df9cc42b6bad98c1a00c**. The implementation and full gate below were validated from that SHA. The pre-PR fetch still resolved to the same main; merging `origin/main` reported “Already up to date”, with no conflict or merge commit. Worktree `/Users/klinikputrijaya/Herd/KPOne-throttle-1`, branch `fix/throttle-1-isolate-counters`. Served tree stays clean on `main`.

The patch changes only numeric throttle counter identity. Every existing limit number, time window, route, permission, payload, domain operation and named limiter remains unchanged. No migration, dependency or role grant.

## Survey before implementation

- Laravel numeric `throttle:N,1` uses an empty default prefix and a user-only request signature when authenticated. Different routes with different limits consequently share that counter: framework `ThrottleRequests.php:85–100,224–229`.
- No deliberately shared **numeric cross-route** budget or existing assertion requiring that accidental counter was found in routes, providers, tests or baseline contracts.
- The deliberate shared report-download budget is already the **named** `reports-downloads` limiter. It is outside the numeric change and is explicitly preserved, as authorised. Existing shared-budget assertions remain verbatim in `DailyReportExportTest`, `PatientBillingReportTest`, `DailySummaryReportTest`, `TransactionReportTest`, `StockMovementReportTest` and `PanelSummaryReportTest`.
- All eight application-defined named limiters remain under the original dispatcher: `login`, `patient-report-views`, `reports-downloads`, `patient-whatsapp`, `public-checkin-view`, `public-intake-exchange`, `public-intake-submit`, `public-intake-status`. Their provider/config files have no diff. The public check-in view is included alongside the three intake limiters.
- No route declaration was edited. The global alias also covers existing settings and framework/vendor alias declarations. A registered-route guard checks the resolved middleware for every numeric route; static guards reject direct framework numeric middleware bypasses.

## Mechanism

`RouteIsolatedThrottle` extends Laravel's existing `ThrottleRequests` and overrides **only** `resolveRequestSignature()`:

```text
scope = name:<route name>
     or uri:<HTTP method> <URI pattern> for an unnamed route
numeric signature = SHA256("numeric-route:" + scope + "|" + original user/IP signature)
```

The parent still adds any explicitly supplied numeric prefix. Authenticated users retain user-based separation; unauthenticated requests retain the parent's domain/IP basis, now isolated by route too. URI **patterns**, never concrete patient keys or query values, prevent parameter/query changes from creating fresh budgets. Missing matched routes fail closed.

Named dispatch never calls this method. The inherited `handleRequestUsingNamedLimiter()` still uses the exact original `md5(limiter-name + logical-key)` counters, limits, callbacks and windows. Limit enforcement, 429 construction, `Retry-After`, remaining headers and cache operations are inherited without duplication. No cache flush, stored-row update or data import.

## Every changed file

| File | Exact change |
|---|---|
| `app/Http/Middleware/RouteIsolatedThrottle.php` — new | One subclass and one protected signature override; route namespace plus inherited user/IP signature; refuses missing route. |
| `bootstrap/app.php` | Import subclass and map the existing global `throttle` alias to it. No route/permission or other middleware configuration change. |
| `tests/Feature/ThrottleIsolationTest.php` — new | Six tests covering route/user/guest isolation, parameter/query discipline, 429/retry/recovery, resolved route guard, exact eight named limiters, polling regression and ten registrations/lookups followed by payment. |
| `tests/Frontend/throttle-isolation.test.mjs` — new | Two static guards: global alias/limited override and no direct framework numeric-throttle bypass in application/routes/bootstrap sources. |
| `docs/reports/THROTTLE-1.md` — new | Survey, implementation, regression evidence, validation, browser replay, changed-file inventory and limits. |

**Every changed existing assertion: none.** No existing test file, expected value, fixture, skip or test name changed. Routes, provider definitions, limiter config, permissions and migrations have no diff against base.

## New regression evidence

1. Exhaust route A: two successes then 429 with `Retry-After: 60` under a frozen clock. Route B and another actor still succeed; A recovers after expiry. Different concrete parameters and queries do not bypass A's budget.
2. Guest unnamed route: same IP shares the URI-pattern budget across parameters; another method, route and IP have separate budgets.
3. Registered-route guard: every resolved numeric middleware is the isolating class, including framework routes. Static guards additionally reject source-level bypasses and overrides of named dispatch/limit enforcement.
4. Named-limiters test pins exact keys, limits and 60-second windows for all eight, then invokes both original and new middleware and proves they increment the same original framework cache keys. The named-dispatch method is declared by Laravel's parent class.
5. Polling: frozen-clock requests every three seconds for sixty seconds, including queue search, readiness updates and waiting counts; encounter start then Call In succeed. First request is at t=3 and final actions at t=60, so the old counter has not expired. The first consultation is held before calling another, preserving the doctor-busy rule.
6. Ten real HTTP registrations, patient/reason lookups and queue searches, followed by a real payment; retained rows prove all ten visits and one payment.

**Red/green proof:** temporarily selected original Laravel middleware only for the two new workflow regressions; both failed with HTTP429. Restored the candidate alias byte-for-byte in `finally`. All six new tests then passed: **6 tests / 374 assertions**. No existing test was run under that deliberate mutation or changed.

## Environment and validation discipline

Task-owned PostgreSQL **18.6**, **127.0.0.1:58745**, cluster `/private/tmp/kpone-throttle-1-pg18`, user `kpone_throttle_1`. Testing DB **kpone_throttle_1_testing**, UTF8/template0. Explicit placeholder process environment, Laravel effective configuration and connected database/encoding/cluster verified before application requests. Owner-approved task-only PHP8.4 CLI memory **1 GiB**.

Never opened, printed, created or edited `.env`/`.env.preview`; only `.env.example` placeholders were read. Separate test temporary storage, seed storage and preview storage; no test suite runs against a live preview. No non-task-owned database or port used. Synthetic `.test` identities only.

Early validation found only new-test setup omissions (HTTP-kernel alias initialization, required `search_type`, and governed cash method fixture); fixed those new tests without changing their required outcomes. The initial wider frontend run had **one setup error before an existing assertion executed**: `i2-inventory-operations.test.mjs`, “both production logout surfaces retire encrypted history before their single Fortify visit”, could not open generated `resources/js/routes/index.ts` (ENOENT). No assertion failed and that file was not edited. Generated Wayfinder routes using the workflow prerequisite, then reran unchanged tests successfully. A missing blank line in the new frontend test was also fixed. These failed setup attempts are not claimed as a passing gate.

| Gate | Actual result |
|---|---|
| PHP syntax | Both changed/new production PHP files and the new PHP test pass `php -l`. |
| Pint / PHPStan | Passed; PHPStan level7,0errors. |
| `composer ci:check` | Passed: lint, formatting, TypeScript, config clear, Pint, PHPStan and full backend. **1,569 passed / 33,831 assertions / 2 unchanged public-registration skips / 0 failures**,1,571total;785.530s backend. |
| Frontend behavior | **540 passed / 0 failed / 0 skipped**;2.520s. |
| Production build | Passed;1.85s. |
| Separate fresh seed | Passed on `127.0.0.1:58745/kpone_throttle_1_seed`, UTF8/template0, dummy data. |
| Explicit PostgreSQL replay | **120 passed / 1,202 assertions / 18 classes / 0 skipped / 0 failures**,97.764s. JUnit also has120testcases and no skip/failure/error nodes. |
| Final tracked/new-file whitespace | Passed, including every new file (checked via no-index diff as well as tracked diff). |

The two existing public-registration skips remain in `tests/Feature/Auth/RegistrationTest.php` because public registration is disabled. No new skip or weakened assertion.

## Browser replay

Browser replay uses a **separate UTF8/template0 `kpone_throttle_1_preview` database** on127.0.0.1:58745 and an explicit empty environment-file directory. The private fixture prints and verifies effective driver/host/port/name plus database/encoding/cluster before use. No other database/port is contacted. No test suite runs while preview is live.

The fixture is synthetic setup only: default-role CA and Resident Doctor, one chosen room, one pending RM40 invoice, one Serving and one Waiting consultation, and one saved reason. Normal replay actions use real UI controls. The deliberate search overrun is a separately labelled negative browser request probe with existing CSRF/session protection, not a new application endpoint.

The first private fixture attempt correctly refused the testing-only branch bootstrap under APP_ENV=local; no browser request had occurred. Stopped the preview listener, reran the disposable fixture under its required testing environment (without editing or bypassing the guard), verified it, then started the local preview. No application fix was needed.

| Replay step | Actual result | Recorded limit/remaining |
|---|---|---|
| Doctor queue polling | **60.592seconds**,19automatic queue searches and19readiness polls in that measured interval;0errors. | Existing120/min and60/min budgets retained. |
| Encounter after polling | POST `/visits/KPV-00000002/encounter` **302**, rendered encounter200;0HTTP429. | Limit20, remaining19. |
| Hold then Call In | Existing hold respected doctor-busy rule; PATCH `/visits/KPV-00000003/queue/call` **303**;0HTTP429. | Limit30, remaining29. |
| Ten CA Quick-create registrations and Waiting handoffs | **10/10 created in10.541seconds**, with normal lookups and queue handoffs;0HTTP429. Retained synthetic Visit keys KPV-00000004 through KPV-00000013. | Registration limit30; remaining29→20, one decrement per registration. |
| Payment immediately after burst | Normal UI recorded **RM1** against the existing synthetic RM40 invoice, leavingRM39. Payment/redirect completed by12.809seconds from burst start;0HTTP429. Double-click generated one retained payment. | Payment limit20, remaining19. |
| Deliberate search overrun |70additional authenticated/CSRF-protected POST `/patients/search` probes:57successes then13HTTP429. First refusal atprobe58; existing requests had consumed3slots in that live window. | Limit60, remaining0, **Retry-After11seconds**. |
| Other route while search exhausted | Same CA, POST `/queue/search` **200** immediately after refusals. | Limit120, remaining119. |

**Normal replay total:0HTTP429. Intentional overrun:13HTTP429, all on `/patients/search`.** No counter flush/reset, limit increase, permission grant or retry-based concealment. Headers and individual statuses retained in `requests.jsonl`, `normal-stats.json` and `overrun-stats.json`.

One private replay selector initially used the textbox role for the existing Visit Reason combobox and timed out before the first registration; corrected only the private helper. No application validation, route or existing test changed. All replay HTTP responses before the deliberate overrun were successful; selector timeouts are not counted as created visits.

Private evidence root: `/private/tmp/kpone-throttle-1-evidence`. **10headless Playwright screenshots**,1440and390CSSpx: `polling-60s-*`, `encounter-after-poll-*`, `call-after-poll-*`, `registration-burst-complete-*`, `payment-after-burst-*`. `evidence-manifest.md` lists filenames and SHA256 values; `steps.jsonl` contains step outcomes. `reconciliation.txt` independently confirms10burst visits, one100-sen receipt and unchanged4000-sen invoice original amounts. No screenshots, session states, environment file, private fixture or browser helper is committed.



## Limits and rollout notes

- There is no longer an accidental aggregate budget across unrelated numeric routes. Deliberate aggregate budgets must be named; the existing named budgets remain shared exactly as before.
- Deployment begins fresh numeric namespaces; old numeric keys expire normally. No cache clearing or migration. Renaming a route changes its numeric namespace.
- Existing configured cache backend, counter operations and time windows remain Laravel's. No new Redis/database/infrastructure choice.
- Synthetic localhost replay proves the requested scenarios, not production capacity, proxy correctness or multi-server behavior. No live clinic/Yezza data or external service is involved.
- The prior stale Registration board and other DRY-RUN-1 findings are not fixed here.

## Owner-authorised PR preparation

On 2026-10-30 the owner authorised committing and pushing only `fix/throttle-1-isolate-counters` and opening one PR to `main`, using this report as its body. Current main remains `87009ebceec0cc01b566df9cc42b6bad98c1a00c`; there was nothing new to merge. No conflict, feature change or existing assertion change occurred.

Requested checks were replayed: changed/new PHP syntax passed, Pint passed, the six new backend throttle tests passed **6 tests / 374 assertions / 0 skips / 0 failures**, and the two new frontend guards passed **2 tests / 0 skips / 0 failures**. `git diff --check` covers tracked and all four new files. The full gate above is retained; a full rerun was unnecessary because main and the feature code are unchanged.

Only the five listed repository files form this PR. No evidence files, screenshots, environment files, secrets or real data are included. The task-owned database was stopped after replay; no preview/browser service was started. The served tree remains clean on `main` and untouched.

- [x] Owner authorised this commit, branch push and one PR.
- [x] Requested pre-PR checks passed; no existing assertion changed.
- [ ] Owner authorises merge. **Do not merge or enable auto-merge.**
