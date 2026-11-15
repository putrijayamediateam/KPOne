# TV-5 — last-called heading after fifteen minutes

Date: 2026-11-03. Branch `fix/tv-5-last-called`; isolated worktree `/Users/klinikputrijaya/Herd/KPOne-tv-5`. Original survey/full-gate base: `019139c7b2fe8d8c9e0ba01ab9429706ae0d425b`, with the TV-5 changes described below. The initial implementation fetch was already up to date. The owner has now authorised this commit, push and PR; current main was fetched and merged before the release checks recorded below. Not production-approved. Synthetic data only.

## Owner decision and implementation

The owner approved anchoring call age to `feed.serverTime`, advancing with `performance.now()` between successful feed responses, and resynchronising after every successful response. At 900 seconds or more the heading becomes **Last called**; below 900 seconds it is **Now calling**. A new call or recall supplies its own `calledAt`. Recall is a small secondary indicator under either heading, never a replacement heading.

`resources/js/pages/QueueDisplay/Screen.vue:39–57` implements the anchor and inclusive comparison. The successful-poll path at line 218 resets the anchor even when the call ID has not changed. A separate one-second monotonic age timer is installed and cleared at lines 279–291. Failed polls leave the anchor intact, so elapsed age continues. The new heading and secondary Recall markup are at lines 499–513. The number and room stay visible.

The feed already supplies UTC `serverTime` and `calledAt` (`app/Domain/Queue/Display/QueueDisplayFeedService.php:47,54`), so no backend or payload change was needed. Existing uniqueness by queue entry, service and room at line 40 remains unchanged. The recent-call slice in `Screen.vue:38` remains unchanged. No patient identity data is added. The browser checked the exact default call keys: `id`, `number`, `room`, `service`, `calledAt`, `isRecall`.

The displayed clock still uses the original `new Date()` interval. The entire audio, speech and announcement source block is byte-identical to the base (SHA-256 `4c7125dee076f0f1c775b0e07536c82b672370730ce7b61af11d558fb89c595d`). `unchanged-contracts.json` records these comparisons. No permission, role grant, migration, route, queue-state rule, chime or spoken-announcement change.

## Changed files and existing assertions

| File | Change |
| --- | --- |
| `resources/js/pages/QueueDisplay/Screen.vue` | Server-anchored monotonic age, timer cleanup, age-dependent heading and secondary Recall indicator. |
| `tests/Frontend/tv-5-last-called.test.mjs` | Ten new mounted-component timing and lifecycle tests. |
| `docs/reports/TV-5.md` | This report. |

**Every changed existing assertion: none.** Comparing tracked test files against `origin/main` shows no differences. The original `assert.ok(screen.includes("'Recall'"))` remains unchanged. No existing assertion, expected value, test name or skip was edited. No PHP application file changed.

## New tests

The new file compiles and mounts the actual Vue component with controlled monotonic time and an independently skewed wall clock. Its ten tests cover:

1. 899,999 ms: Now calling, with unchanged number and room.
2. 900,000 ms: Last called, with unchanged number and room.
3. 3,600,000 ms: Last called, with unchanged number and room.
4. Crossing 900 seconds between polls without another request.
5. A TV wall clock five minutes slow, including a runtime wall-clock jump.
6. A TV wall clock five minutes fast, including a runtime wall-clock jump.
7. A recall's fresh `calledAt` resets Now calling, then ages to Last called; Recall remains secondary.
8. Failed polls keep the last anchor and continue aging across the boundary.
9. Every successful poll resynchronises, including unchanged call IDs and corrected server time.
10. Timer/listener cleanup and preservation of the displayed-clock interval and Recall literal.

The new and existing TV frontend files together passed 30 tests. The full frontend result is recorded below. An initial lint warning for an unused import in the new test helper was corrected in that new file; no existing test changed.

## Task-owned validation environment

PostgreSQL **18.6**, host **127.0.0.1**, port **58786**, cluster `/private/tmp/kpone-tv5-pg18`, role `kpone_tv5`. Databases, each created with UTF8 and `TEMPLATE template0`:

| Purpose | Database |
| --- | --- |
| Full suite and explicit PostgreSQL replay | `kpone_tv5_testing` |
| Synthetic browser rehearsal | `kpone_tv5_preview` |
| Separate `migrate:fresh --seed` | `kpone_tv5_seed` |

The process environment's host, port and database were printed and checked against `current_database()`, UTF8 encoding and the owned cluster before application use. There is no server-address-versus-configured-host comparison. Explicit placeholder environment, array cache/session for tests, synchronous queue, array mail and the owner-approved task-only 1 GiB PHP configuration were used. Preview uses separate session/storage paths. Tests never ran against preview storage. No `.env` or `.env.preview` was opened, created, printed or edited. Dependencies were copied into this worktree without a new dependency or writable shared symlink.

## Browser rehearsal and evidence

Headless Chromium through Playwright, using the actual built app and real synthetic TV login on task-owned `127.0.0.1:8910`. Only this origin was allowed. Sixteen captures cover 1920 × 1080 and 390 × 844, light and dark, for each of these states:

| Screenshot suffix | Actual result |
| --- | --- |
| `last-called-finished-visit` | The old completed synthetic visit remains visible as Last called with A-001 and Room 1. |
| `now-calling-new` | A real new A-002 call replaces the old call through the normal feed and restores Now calling. |
| `now-calling-recall` | A real recall after the existing 20-second cooldown restores Now calling and shows secondary Recall. |
| `last-called-recall` | Advancing only the private preview server clock by 901 seconds produces Last called while retaining Recall, A-002 and Room 1. |

All paths are under `/private/tmp/kpone-tv5-evidence/screenshots/`, named `{1920|390}-{light|dark}-{suffix}.png`. The exact sixteen paths, dimensions and SHA-256 hashes are in `screenshot-manifest.json`. Browser assertions checked the heading, number, room, Recall indicator and absence of sideways overflow for every capture. No browser page error or failed HTTP response occurred. Representative mobile light/dark and desktop captures were visually inspected; the new heading and indicator are readable and do not clip.

This is a controlled-time rehearsal, not a claim that a real fifteen-minute wait was observed. Threshold, skew, failed-poll and recall timing are separately covered by the mounted-component tests. No retained call timestamp was rewritten. Complete ID-ordered before/after hashes across `queue_calls`, `queue_entries`, `visits`, `clinical_encounters`, `consultation_checkouts`, `inventory_stock_balances`, `stock_movements`, `invoices`, `invoice_lines` and `payments` were identical during both age-only phases. Real call and recall actions used the existing domain services and each added one expected queue-call row.

An initial private fixture attempt to complete the old visit without billing evidence was correctly refused with PostgreSQL `23514`: `Completed Visit requires current final sources and Invoice evidence`. The disposable preview fixture was rebuilt using an explicit synthetic zero-price consultation, normal invoice build/finalise and the existing visit completion service. No constraint or production code was changed or bypassed. The refusal log is retained locally.

Screenshots, logs, fixtures and the evidence bundle are private local review artifacts; they are not committed or PR attachments.

## Limits and deferred work

- Label updates have one-second timer resolution. Server time is anchored when the feed response arrives; transit/render delay is not estimated. Each normal successful five-second poll resynchronises it.
- The displayed clock intentionally remains the device clock. It can be wrong while the heading correctly follows the server anchor.
- Browser suspension, physical TV hardware, real Wi-Fi, audible chime and spoken output were not verified headless. Audio and announcement source is unchanged; no hardware claim is made.
- At 390 px the existing recent-calls number column can show an ellipsis; this slice does not change that layout. The current call's number and room remain fully visible in the captures.
- No feed format, recent-list policy, queue workflow, naming mode or patient data disclosure change. No unrelated issue was fixed.

## Full implementation gate before the main merge

All executable changes were in place before these runs and their SHA-256 hashes are retained in `source-hashes.json`. These full-gate runs used HEAD/base `019139c7b2fe8d8c9e0ba01ab9429706ae0d425b` with the three then-uncommitted TV-5 files listed above. Report completion after the runs did not change executable code. Post-merge quick checks are listed separately below.

| Check | Actual result |
| --- | --- |
| Early `php -l` | No new or changed repository PHP files; not applicable to the patch. Private PHP fixture, router, action and snapshot helpers were syntax-checked before use. |
| Early Pint and PHPStan level 7 | Passed; also passed again in `composer ci:check`, PHPStan zero errors. |
| Exact `composer ci:check` | Exit 0; 1,573 backend tests reached: **1,571 passed, 33,872 assertions, two existing skips, zero failures/errors**, 780.329 seconds. |
| Separate frontend types, lint and format | `npm run types:check`, `npm run lint:check`, `npm run format:check`: all passed. |
| Production build | `npm run build`: passed. |
| Full frontend behavior suite | `node --test tests/Frontend/*.mjs`: **584 passed, zero failures/skips**, 5.063 seconds. |
| Existing and new TV frontend tests | **30 passed**, zero failures/skips. Ten of these are the new TV-5 tests. |
| Explicit PostgreSQL replay | **120 passed in 18 classes, 1,202 assertions, zero skipped/failures/errors**, 95.416 seconds. JUnit independently confirms each count. |
| Separate fresh migration and seed | `php artisan migrate:fresh --seed --force` on `kpone_tv5_seed`: passed. Synthetic/reference seed only. |
| Whitespace, including new files | `git diff --check`, plus `git diff --no-index --check /dev/null` for each new file: passed. |
| Existing assertion comparison | No existing test file differs from `origin/main`; the only task test addition is the new TV-5 file. No existing assertion changed. |

The two full-suite skips are the existing public-registration tests (`tests/Feature/Auth/RegistrationTest.php:20,27`), guarded by disabled Fortify registration at `tests/TestCase.php:51–55`. The explicit PostgreSQL replay has **no skips**, including contention coverage. No limit, timeout, assertion or protection was weakened.

The exact Composer script sequence is unchanged: frontend lint, frontend format, frontend types, configuration clear, Pint, PHPStan, then the backend suite. Logs: `ci.log`, `frontend.log`, `types.log`, `lint.log`, `format.log`, `build.log`, `tv-frontend.log`, `seed.log`, `pg-replay.log` and `pg-replay.xml`, all under `/private/tmp/kpone-tv5-evidence/`.

## Handoff

Local report: `docs/reports/TV-5.md`. Private review diff: `/private/tmp/kpone-tv5-evidence/review.diff`. Private screenshot manifest: `/private/tmp/kpone-tv5-evidence/screenshot-manifest.json`. Private evidence bundle: `/private/tmp/kpone-tv5-evidence/TV-5-evidence.zip`.

The TV-5 preview server and PostgreSQL cluster are stopped. Ports 8910 and 58786 have no listeners. Only task-owned services/databases/storage were used; the served tree remains clean on main and untouched. The owner has authorised the scoped commit, push and PR. Merge and auto-merge remain unauthorised.

## PR release checks after merging current main

On 2026-11-03, `git fetch origin main` was followed by a fast-forward merge to `a8f097a5a72852a1d4b4a82b65f868148a7f8f1f`. No file conflicted. Main includes PRINT-1 and the guides fix. The TV-5 component and new test retain exactly the source hashes validated in the original full gate; there was no implementation change to resolve the merge.

| Requested check | Post-merge result |
| --- | --- |
| `git diff --check`, including new files | Passed. |
| Pint `composer run lint:check` | Passed. |
| `node --test tests/Frontend/*.mjs` | **587 passed, zero failures/skips**; main adds three frontend tests to the earlier total. |
| `node --test tests/Frontend/tv-5-last-called.test.mjs` | **10 passed, zero failures/skips**. |
| Existing assertions | No existing test file or assertion changed. The original Recall assertion remains intact. |

No database or preview server was started for these quick checks. No full-suite or browser replay after the merge is claimed. The earlier full gate, PostgreSQL replay and browser evidence are reported above with their original base and counts. Post-merge logs are `pr-pint.log`, `pr-frontend.log` and `pr-tv-tests.log` under the private evidence root.
