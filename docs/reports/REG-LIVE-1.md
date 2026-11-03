# REG-LIVE-1 — Registration board live refresh

Date: 2026-11-03. Isolated worktree `/Users/klinikputrijaya/Herd/KPOne-reg-live-1`, branch `feature/reg-live-1`. Fetched canonical main and HEAD **d1212d6f74e3cce8b5d46b9dc6791302c22bf32c**, plus this uncommitted candidate. The prerequisite includes THROTTLE-1, D002-ECHO and UI-POLISH-1. This records the validated patch before its owner-authorised commit and PR. Not production-approved. Synthetic data only.

## Resolved validation stop — standalone generation guards

The owner's hard rule is “No existing assertion may change without my approval. Stop and report if one fails.” The complete CI sequence failed one existing test, so implementation/testing stopped without changing the test. The owner then authorised the new-code-only repair and full replay.

- Test: `Tests\Feature\OperationalPatientBoardTest::test_registration_board_discards_out_of_order_frontend_search_responses`, method at `tests/Feature/OperationalPatientBoardTest.php:11`.
- Failing assertion at line 18: `$this->assertStringContainsString('if (generation !== searchGeneration)', $source);`.
- Failure diagnostic ending (the large source dump is omitted): `contains "if (generation !== searchGeneration)" [ASCII](length: 36).`
- Before the repair, the new implementation at `resources/js/pages/Registration/Index.vue:361` combined that comparison with disposal and automatic-interaction checks in a multiline condition. It retains the generation behavior but does not retain the existing exact standalone guard text.
- The same unchanged test also requires `if (generation === searchGeneration)` at line 19. That assertion was not reached after line 18 failed; the new catch/finally conditions are also combined, so both original standalone forms should be preserved in the proposed repair.

**Owner-authorised repair applied:** restored `if (generation !== searchGeneration)` as its own early return, followed by the new disposal/interaction guard. The original `if (generation === searchGeneration)` catch/finally blocks now contain the additional lifecycle checks. No existing assertion, expected value, test name or skip changed. The unchanged `OperationalPatientBoardTest` passes **8 tests / 73 assertions**; all ten new lifecycle tests pass. Pint and PHPStan level 7 pass before the resumed full gate. The sole failed test in the earlier backend run was this source guard test; no other failing backend class was omitted.

## Survey reported before building

The following line references describe the surveyed base `d1212d6` before the Registration page changed.

| Area | Verified behavior and source |
|---|---|
| Queue cadence | `resources/js/pages/Queue/Index.vue:254–278` uses a self-rearming timeout: 3 seconds normally, 6/12/24 seconds after failures, then 24 seconds thereafter. The formula has a 30-second outer cap, but its exponent is capped at three, so 24 seconds is the reachable maximum. |
| Queue request | `Queue/Index.vue:280–370` posts applied filters and both page numbers to `/queue/search`, prevents overlap, checks request generation, merges accepted snapshots and polls readiness alerts. It retries indefinitely; there is no repeated-failure stop. |
| Queue pause/resume | `Queue/Index.vue:550–568` invalidates the request generation, clears the timer and aborts when hidden. Visibility return refreshes the current view. |
| Queue mount/leave | `Queue/Index.vue:569–588` installs the visibility listener and timer on mount; leaving disposes the lifecycle, removes the listener, clears poll/clock timers and aborts. `resources/js/lib/queue-polling.ts:19–39` implements the disposed/generation checks. |
| Registration initial load | `app/Http/Controllers/RegistrationController.php:28–33` supplies the initial rows and options. `resources/js/pages/Registration/Index.vue:102–127` initializes row/paging refs and the existing branch-local date filters. |
| Registration refresh before this fix | `Registration/Index.vue:260–358,486,610–618` refreshes only through Apply, tab selection, Reset and paging. It has no mount/visibility/timer/unmount polling lifecycle. |
| RB-01 contract | `Registration/Index.vue:260–322` increments `searchGeneration`, rejects superseded success/failure responses, clears rows on an accepted failure and exposes the existing error through `boardEmptyMessage`. Existing mounted tests are `tests/Frontend/rb-01-registration-board-search.test.mjs`; they remain unchanged. |
| Existing API and budget | `routes/web.php:713–715` already provides `POST /registration/search`, permission `visits.view.branch`, `throttle:60,1`. `RegistrationController.php:37–39` returns the existing directory result. `app/Http/Requests/SearchVisitsRequest.php:12–28` keeps its existing authorization and filters/page fields. THROTTLE-1 isolates the route's numeric counter. |

Survey outcome: **frontend-only work is sufficient**. No endpoint, backend service, permission, payload or database change is needed. The Queue lifecycle pattern is followed without inheriting its indefinite retries or changing the Queue page itself.

## What changed

The existing search pipeline now supports a background refresh every **10 seconds after the previous request settles**, using the last applied tab/search/filter snapshot and current page. Typing a draft filter does not silently apply it. Explicit Apply, Reset, tab and page controls retain their manual behavior.

- Background requests are blocked while a row action or dialog is active, a mutation is busy, or an input/textarea/select/editable/combobox has focus. The same protection is checked again after awaiting the response, before either replacing or clearing rows. This covers a menu/dialog/edit that begins while a request is in flight. No menu state or extra row field is added to a payload.
- Existing `searchGeneration` still decides which response is current. A newer manual search supersedes and aborts an automatic request. Intentional hide/leave cancellation invalidates the old generation, so a late success or failure cannot render or clear the new view.
- Hidden tabs have no poll timer and abort their in-flight request. Returning triggers one refresh when polling is active; interaction protection can defer it until safe. Leaving removes the visibility listener, clears the timer, aborts the request and prevents rearming after late completion.
- Accepted errors keep RB-01's clearing/error behavior. Retry delays are **20 seconds, then 40 seconds**. After **three consecutive failures**, automatic polling stops with the existing error/empty-board message. Visibility changes do not override this stop. Apply or Reset starts another attempt series; success restores the ten-second cadence.
- The separate QR Intake tab pauses visit-board polling. It is not an operational visit tab and cannot be refreshed from the visit-search payload. Its existing listing and paging behavior are unchanged.
- The browser lifecycle is installed only when document event APIs are available; SSR/non-DOM rendering installs no timer. Existing tests need no mock or assertion changes.

The route, HTTP method, same-origin credentials, CSRF headers and JSON field set are retained. An abort signal is added only to the browser fetch options. Background polling does not show the manual “Applying…” state or disable Apply.

At the requested pace, six polls use **6 of the existing 60 requests/minute**. Normal manual use has the remaining budget. No limit, key, named limiter or cache policy changed.

## Every changed file

| File | Change |
|---|---|
| `resources/js/pages/Registration/Index.vue` | Ten-second lifecycle around the existing search, applied-filter snapshot, interaction guards, abort/generation cleanup and finite error back-off. |
| `tests/Frontend/reg-live-1.test.mjs` | Ten new mounted-component lifecycle tests with fake time and controlled browser events. |
| `docs/reports/REG-LIVE-1.md` | Survey, implementation, validation and rehearsal evidence; this report. |

**Every changed existing assertion: none.** No existing test file, fixture, expected value, test name or skip changed. The RB-01 and RB-02 tests pass unchanged. No test was weakened, removed or newly skipped. No production PHP, controller, domain service, route, permission, migration, shared component, calculation, stored-data contract or dependency changed.

## New tests and red/green evidence

The ten new tests mount the actual Vue page and cover:

1. No early request; polling at ten seconds, existing request/headers/page and six calls in a simulated minute.
2. Applied search, doctor filter, tab and page retained; unfinished draft text is not applied; the same nine JSON keys.
3. Hidden pause/abort, one visible refresh and rejection of a late hidden response.
4. No automatic request or row replacement while a menu, dialog or editing field is active.
5. Protection rechecked on an in-flight success or failure for each of those interactions.
6. An automatic success or failure cannot overwrite a newer manual response.
7. Error clearing, 20/40-second back-off, stop at three failures, no automatic visibility restart, and manual Apply recovery.
8. Unmount removes the timer/listener and aborts before a late response; nothing rearms.
9. QR Intake pause and return to an operational tab.
10. Slow requests do not overlap.

Against the original page, **all ten new tests failed** (`red-frontend.log`). After implementation, **all ten pass** (`new-tests.log`). The complete unchanged-plus-new frontend suite passes **561 tests**, no failures or skips. The test harness is new; no existing harness was edited to accommodate polling.

## Environment and boundaries

Task-owned PostgreSQL **18.6**, **127.0.0.1:58748**, cluster `/private/tmp/kpone-reg-live-1-pg18`, role `kpone_reg_live_1`. Databases **kpone_reg_live_1_testing**, separate **kpone_reg_live_1_seed**, separate **kpone_reg_live_1_preview**, UTF8 from template0. Explicit placeholder process environment and owner-approved task-only PHP/Composer 1 GiB setting.

Effective driver, host, port and database name were printed and checked before application use. Connected database name, UTF8 encoding and the owned cluster directory were also verified, including separately before the first preview HTTP request. No non-task database or port was used. No `.env` or `.env.preview` was opened, printed, created or edited. Dependencies were copied, not added or updated. Tests have isolated temporary storage; preview and seed storage are separate.

No PHP source changed, so changed-file `php -l` has no application/test targets. The private PHP fixture helper passed syntax checking. Pint and PHPStan level 7 passed before the long suite.

## Validation

Candidate: HEAD **d1212d6f74e3cce8b5d46b9dc6791302c22bf32c** plus the three-file uncommitted patch.

| Gate | Actual result |
|---|---|
| Changed-file `php -l` | No application/test PHP changes; private fixture helper passed. |
| Pint / PHPStan level 7 | Passed early and again inside corrected-tree CI. |
| Frontend type, lint, format | Passed again on the corrected tree. |
| `node --test tests/Frontend/*.mjs` | **561 passed / 0 skipped / 0 failures**, including ten new tests. |
| Production build | Passed again on the corrected tree, including the build used in the repeat rehearsal. |
| Separate `migrate:fresh --seed --force` | Passed again on `kpone_reg_live_1_seed`, dummy/reference seed only. |
| Exact `composer ci:check` — corrected tree | **PASSED: 1,571 passed / 33,872 assertions / 2 existing skips / 0 failures**, 1,573 total, 839.788 seconds. |
| Earlier CI run retained | **1,570 passed / 1 failed / 33,871 assertions / 2 existing skips**, 881.838 seconds; standalone-source-guard failure resolved above without a test change. |
| Explicit PostgreSQL replay | **120 passed / 1,202 assertions / 18 classes / 0 skipped / 0 failures / 0 errors**, 97.648 seconds. JUnit checked for skipped, failure and error elements. |
| Final tracked/new-file `git diff --check` | Passed, including both new files. |

## Two-screen rehearsal

Real authenticated UI in headless Playwright, with two independent synthetic CA sessions at one branch. Five existing OTC arrivals were prepared through the existing domain services. A second CA then registered visits six and seven through the real search-first Quick create UI, using fictional `.test` emails, synthetic passport references and reserved synthetic phone numbers.

| Check | Actual result |
|---|---|
| Initial board | Five visits, before the new arrivals. |
| Menu guard | Sixth visit registered on the second screen; the open board retained five rows and sent no poll during a measured 12-second row-menu interval. After closing the menu it reached six in **4,256 ms**, without a document reload. |
| Typing guard | Seventh visit registered on the second screen; the board retained six rows and sent no poll during a measured 12-second editing interval. After clearing the unsubmitted draft and blurring, it reached seven in **5,875 ms**. |
| Dialog guard | A cancellation dialog stayed open for 12 seconds, with seven rows and no poll. “Keep Visit” dismissed it; no cancellation was submitted. |
| No reload | Board document `performance.timeOrigin` remained identical from five through seven visits. A document-lifetime marker survived and no navigation request occurred during the six-to-seven/recovery observation. Only the existing search fetch refreshed the board. |
| Real pacing | Exactly **6 polls in 60,000 ms**; measured inter-request gaps **10,058 / 10,053 / 10,050 / 10,050 / 10,049 ms**. Every observed route limit header stayed **60**. No 429, failed HTTP response or browser page error. |
| Reconciliation | The owned preview database contains exactly **7 Patients and 7 Visits** after the two deliberate registrations. The refresh itself uses the unchanged read-only directory endpoint. |

The corrected-tree replay again verified all checks above and captured all 28 images anew. The private no-reload check in the earlier replay initially counted Playwright frame events, which also include same-document history updates. It was corrected to track document requests and document lifetime. Network history and the unchanged time origin establish that the board did not reload; no application fix or test assertion change was needed. An initial harness selector also waited for a heading the existing board does not render; the harness was corrected to target the actual board.

## Screenshots and review artifacts

Corrected-tree replay root: `/private/tmp/kpone-reg-live-1-evidence/rerun`. **28 new headless screenshots** at 1440 and 390 CSS px, light and dark:

- **24 positive board captures:** `board-five-before-*`, `menu-protects-five-*`, `board-six-live-*`, `typing-protects-six-*`, `board-seven-live-*`, `dialog-protects-seven-*`. The live board has no sideways document overflow in any capture.
- **2 desktop result-page captures:** `registrar-arrival-seven-registrar-1440-*`, showing the second screen's successful registration.
- **2 labelled negative mobile result-page captures:** `registrar-arrival-seven-registrar-390-*-known-overflow-*`, documenting the separate unchanged issue below.

`rerun/evidence-manifest.json` lists new screenshot files and hashes. The rerun folder contains `requests.jsonl`, `arrivals.jsonl`, `menu-replay.json`, `typing-replay.json`, `dialog-replay.json`, `normal-polling.json`, `result-overflow.json` and `reconciliation.txt`. Earlier evidence remains intact in the parent folder. Corrected-tree gate logs are `ci-resume.log`, `frontend-resume.log`, `pg-resume.log`, `pg-resume.xml`, `pg-resume-summary.json`, `seed-resume.log` and the corresponding types/lint/format/build logs in the parent folder. Screenshots, sessions, fixture scripts and evidence stay outside the repository.

## Separate finding — unchanged result-page overflow

While capturing the registrar's result page `/visits/KPV-00000007`, the new private width check found **567 px document width at a 390 px viewport**, in light and dark. `resources/js/pages/Registration/Show.vue:119` uses an unwrapped `flex gap-2` action row; the “Register another Patient” and “Send to Waiting” controls extend beyond the viewport. That source file has no diff against main.

This is an existing, out-of-scope result-page layout issue on the synthetic fixture, not a live-board overflow or a failed existing test. It was **recorded, not fixed**. The board width checks were not relaxed. The negative captures preserve the observed result; a separate owner-authorised layout change can address it.

## Limits and next step

- Guards apply to background refreshes; explicit manual actions retain their RB-01 behavior. Focus is conservatively treated as editing until blur.
- The stop threshold of three consecutive failures is the narrow choice for this slice. Apply/Reset retries; there is no new stopped/retry wording or control.
- QR Intake keeps its existing separate listing behavior. Polling does not advance the chosen date range at midnight or widen the selected filters.
- A request that never settles does not overlap another automatic request. Hiding/leaving or a superseding manual search aborts it; no new request timeout is introduced.
- This is a one-branch synthetic rehearsal, not a production capacity or multi-branch rollout claim.

The approved new-code-only guard repair, complete CI/frontend gate, separate seed, explicit PostgreSQL replay and repeat two-screen rehearsal are complete and passing. The owner subsequently authorised the commit, push and one PR against main; merge remains unauthorised.

Pre-PR review state: browser, preview server and owned PostgreSQL cluster are stopped. Only the three listed task files differ; nothing is staged. The served tree was clean on `main` at task start. An intermediate read-only check found branch `copilot/staff-guides-1` and untracked `docs/guides/`; the resumed read-only check finds a clean `main`. I made no file/checkout changes in that tree and did not open, reset, stash or alter that other work. No commit, push or PR had been made at that review.

## Corrected-tree review identity

The full gate ran at HEAD **d1212d6f74e3cce8b5d46b9dc6791302c22bf32c** plus the uncommitted patch; no candidate commit had been made at that review. Source SHA-256: `3b698f9415269f593883e0f67d702eab5b40402e584ef024f68b353fb6eb5574`. New test SHA-256: `559334f9965c71c5c4cf0ac513b03ebaa108289868206bd924ea2e1a51cfbfdb`. Review diff: `/private/tmp/kpone-reg-live-1-evidence/REG-LIVE-1-review.diff`. Evidence bundle: `/private/tmp/kpone-reg-live-1-evidence/REG-LIVE-1-evidence.zip`.

## PR handoff checks

The owner authorised commit, push and one PR. `git fetch origin main` confirmed main remains **d1212d6f74e3cce8b5d46b9dc6791302c22bf32c**. `git merge --no-edit origin/main` reported “Already up to date.” No merge commit, conflict or rebase was needed. No PR template exists in `.github`.

The requested post-merge checks passed: tracked and new-file whitespace checks; Pint; **561 frontend tests, zero failures/skips**; and the explicit **10 REG-LIVE-1 lifecycle tests, zero failures/skips**. Only the Registration page, its new lifecycle test and this report are included. No existing assertion changed; no environment, screenshot, private evidence or unrelated file is included.

- [x] Owner authorises this branch's commit, push and one PR.
- [ ] Owner authorises merge. Do not merge or enable auto-merge.
