# REG-LIVE-1 — Registration board live refresh

Date: 2026-11-03. Isolated worktree `/Users/klinikputrijaya/Herd/KPOne-reg-live-1`, branch `feature/reg-live-1`. Fetched canonical main and HEAD **d1212d6f74e3cce8b5d46b9dc6791302c22bf32c**, as the implementation base. PR #198 initially opened at `6cb1035c197151e76538ef67412dac78cb403944`; the owner authorised the focus/failure follow-up below. The prerequisite includes THROTTLE-1, D002-ECHO and UI-POLISH-1. This records the validated patch before its owner-authorised commit and PR. Not production-approved. Synthetic data only.

Current approved follow-up: submitted searches continue polling while focused; dirty search text and IME remain protected. Automatic failures retain rows and paging with the separate pause notice; manual failure handling is unchanged. Final validation after merging main `43936db`: **1,571 backend passes / 33,872 assertions / two existing skips; 574 frontend passes; 120 explicit PostgreSQL tests, none skipped**. Both new browser review cases pass.

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

- Background requests are blocked while a row action or dialog is active, a mutation is busy, or another input/textarea/select/editable/combobox has focus. The board search is protected only while its draft differs from the applied value or IME composition is active. The same protection is checked again after awaiting the response, before either replacing or clearing rows. This covers a menu/dialog/edit that begins while a request is in flight. No menu state or extra row field is added to a payload.
- Existing `searchGeneration` still decides which response is current. A newer manual search supersedes and aborts an automatic request. Intentional hide/leave cancellation invalidates the old generation, so a late success or failure cannot render or clear the new view.
- Hidden tabs have no poll timer and abort their in-flight request. Returning triggers one refresh when polling is active; interaction protection can defer it until safe. Leaving removes the visibility listener, clears the timer, aborts the request and prevents rearming after late completion.
- Manual errors keep RB-01's clearing/error behavior. Automatic HTTP/network errors retain rows, total and pagination and show a separate status notice: “Live refresh paused. Showing last successful results.” Retry delays remain **20 seconds, then 40 seconds**. After **three consecutive failures**, automatic polling stops and appends “Press Search to try again.” Visibility changes do not override the stop. A new manual search clears the notice and starts another attempt series; success clears it and restores the ten-second cadence.
- The separate QR Intake tab pauses visit-board polling. It is not an operational visit tab and cannot be refreshed from the visit-search payload. Its existing listing and paging behavior are unchanged.
- The browser lifecycle is installed only when document event APIs are available; SSR/non-DOM rendering installs no timer. Existing tests need no mock or assertion changes.

The route, HTTP method, same-origin credentials, CSRF headers and JSON field set are retained. An abort signal is added only to the browser fetch options. Background polling does not show the manual “Applying…” state or disable Apply.

At the requested pace, six polls use **6 of the existing 60 requests/minute**. Normal manual use has the remaining budget. No limit, key, named limiter or cache policy changed.

## Every changed file

| File | Change |
|---|---|
| `resources/js/pages/Registration/Index.vue` | Ten-second lifecycle around the existing search, applied-filter snapshot, interaction guards, abort/generation cleanup and finite error back-off. |
| `tests/Frontend/reg-live-1.test.mjs` | Nineteen new mounted-component lifecycle tests with fake time and controlled browser events, including the owner-authorised follow-up. |
| `docs/reports/REG-LIVE-1.md` | Survey, implementation, validation and rehearsal evidence; this report. |

**Every pre-REG-LIVE-1 assertion remains unchanged.** The follow-up changes only the two owner-approved assertions in this PR's own new lifecycle test, listed below. No test name or skip changed. The RB-01 and RB-02 tests pass unchanged. No test was weakened, removed or newly skipped. No production PHP, controller, domain service, route, permission, migration, shared component, calculation, stored-data contract or dependency changed.

## Initial tests and red/green evidence

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

## Initial full-gate validation

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

## Initial two-screen rehearsal

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

- Guards apply to background refreshes; explicit manual actions retain their RB-01 behavior. The search input can remain focused after Enter without pausing polling when its value is applied. Other fields retain conservative focus protection; IME composition always protects the search.
- The stop threshold of three consecutive failures is the narrow choice for this slice. Apply/Reset retries; the approved status notice is new; no new retry control was added. The owner specified “Press Search to try again”; the existing submit control is labelled “Apply”, unchanged in this slice.
- QR Intake keeps its existing separate listing behavior. Polling does not advance the chosen date range at midnight or widen the selected filters.
- A request that never settles does not overlap another automatic request. Hiding/leaving or a superseding manual search aborts it; no new request timeout is introduced.
- This is a one-branch synthetic rehearsal, not a production capacity or multi-branch rollout claim.

The approved new-code-only guard repair, complete CI/frontend gate, separate seed, explicit PostgreSQL replay and repeat two-screen rehearsal are complete and passing. The owner subsequently authorised the commit, push and one PR against main; merge remains unauthorised.

Pre-PR review state: browser, preview server and owned PostgreSQL cluster are stopped. Only the three listed task files differ; nothing is staged. The served tree was clean on `main` at task start. An intermediate read-only check found branch `copilot/staff-guides-1` and untracked `docs/guides/`; the resumed read-only check finds a clean `main`. I made no file/checkout changes in that tree and did not open, reset, stash or alter that other work. No commit, push or PR had been made at that review.

## Corrected-tree review identity

The full gate ran at HEAD **d1212d6f74e3cce8b5d46b9dc6791302c22bf32c** plus the uncommitted patch; no candidate commit had been made at that review. Source SHA-256: `3b698f9415269f593883e0f67d702eab5b40402e584ef024f68b353fb6eb5574`. New test SHA-256: `559334f9965c71c5c4cf0ac513b03ebaa108289868206bd924ea2e1a51cfbfdb`. Review diff: `/private/tmp/kpone-reg-live-1-evidence/REG-LIVE-1-review.diff`. Evidence bundle: `/private/tmp/kpone-reg-live-1-evidence/REG-LIVE-1-evidence.zip`.

## Initial PR handoff checks

The owner authorised commit, push and one PR. `git fetch origin main` confirmed main remains **d1212d6f74e3cce8b5d46b9dc6791302c22bf32c**. `git merge --no-edit origin/main` reported “Already up to date.” No merge commit, conflict or rebase was needed. No PR template exists in `.github`.

The requested post-merge checks passed: tracked and new-file whitespace checks; Pint; **561 frontend tests, zero failures/skips**; and the explicit **10 REG-LIVE-1 lifecycle tests, zero failures/skips**. Only the Registration page, its new lifecycle test and this report are included. No existing assertion changed; no environment, screenshot, private evidence or unrelated file is included.

- [x] Owner authorises this branch's commit, push and one PR.
- [ ] Owner authorises merge. Do not merge or enable auto-merge.

## Owner-authorised PR #198 follow-up

Main was fetched again and remains `d1212d6f74e3cce8b5d46b9dc6791302c22bf32c`; merge reported “Already up to date.” No conflict or merge commit. Changes remain confined to `resources/js/pages/Registration/Index.vue`, this PR's new `tests/Frontend/reg-live-1.test.mjs`, and this report. No backend, permission, route, payload, migration, dependency or stored-data change.

The search input now has id `registration-board-search`. Its focus blocks an automatic refresh only if its draft differs from `appliedFilters.patient_query`; composition events track IME activity independently. An applied search may retain focus and continue polling. Menu/dialog/busy-operation/other-field guards and the post-response protection check remain intact. Both original standalone generation guards remain exact.

Automatic HTTP and thrown/network failures use a separate status notice and never call `clearBoard()`. Rows, total, current page and last page remain the last successful values. The 20/40-second back-off and three-failure stop remain. Success or a new manual search clears the notice. Manual HTTP/network failure still clears rows and displays the existing error; all pre-REG-LIVE-1 RB-01 assertions remain unchanged.

### Every changed assertion in the follow-up

Both changes are in `tests/Frontend/reg-live-1.test.mjs`, test `REG-LIVE-1 errors back off for twenty then forty seconds, stop at three, and manual Apply recovers`, introduced in this PR. Owner explicitly approved these two changes after reviewing #198:

| Location in initial PR head | Old expectation | New expectation | Reason |
|---|---|---|---|
| Line 624 | First failed automatic poll leaves row keys `[]`. | Row keys remain `['ORIGINAL']`. | Owner-approved automatic-failure retention; manual failure is unchanged. |
| Lines 634–636 | Board empty-state message is `Registration search could not be completed.` | Board empty-state message stays empty; a new assertion pins `Live refresh paused. Showing last successful results. Press Search to try again.` in the separate notice after failure three. | Preserve visible last-successful rows and distinguish paused live refresh from a failed manual search. |

No other assertion changed and no pre-REG-LIVE-1 test file differs from `origin/main`. No assertion was removed, skipped or weakened to hide a failure. Menu, dialog and other-field protection assertions remain intact.

Nine additional lifecycle tests cover: Enter submission with retained focus; typing after submission and resubmission without blur; an in-flight success/failure arriving while the search becomes dirty; IME before a request; IME during an in-flight response; HTTP/network retention of rows, total and paging; successful automatic notice/cadence recovery; notice clearing on manual search followed by unchanged manual-error clearing; and stale automatic failure after a newer manual success. The lifecycle file now has **19 tests**, all passing in the targeted run; unchanged RB-01/RB-02 plus this file pass **27 tests**. Against the original PR implementation the expanded file recorded **9 failures / 10 passes**; after repair it passes **19 / 19**. Early lint found seven style errors in the newly added tests; they were fixed only in that new code before the full gate.

### Follow-up validation

Before main advanced, the complete follow-up gate passed: **1,571 backend passes / 33,872 assertions / 2 existing skips / 0 failures**, 810.204 seconds; **570 frontend passes / 0 failures / 0 skips**; explicit PostgreSQL replay **120 passes / 1,202 assertions / 18 classes / 0 skips / 0 failures**, 102.384 seconds. Pint, PHPStan, types, lint, format, build and separate fresh seed passed. Logs use the `followup-` prefix. These results are retained as pre-merge evidence; the merged final tree is being validated again below. PostgreSQL host/port/name were printed and verified again before first use: `127.0.0.1:58748 / kpone_reg_live_1_testing`, UTF8 on the owned PostgreSQL 18.6 cluster. Separate `kpone_reg_live_1_preview` and `kpone_reg_live_1_seed` were each verified before use. No `.env`/`.env.preview` access. The unchanged `OperationalPatientBoardTest` passes **8 / 8, 73 assertions**. No PHP source changed; the private fixture helper passed `php -l`; early Pint, PHPStan level 7, frontend types and lint pass.

### Follow-up browser rehearsal

New evidence root: `/private/tmp/kpone-reg-live-1-evidence/followup`. Headless Playwright, two synthetic CA sessions, four layouts (1440/390 CSS px, light/dark), starting from a fresh five-visit synthetic preview database. The initial rehearsal did not check post-Enter focus; this follow-up explicitly does.

Immediately after Enter and after its response, `document.activeElement` was `{ tag: "INPUT", id: "registration-board-search", value: "Synthetic", placeholder: "Patient name or exact Patient No." }`. While that input stayed focused, a matching sixth visit registered in the second session appeared in **7,317 ms**. Focus remained on the same input, `performance.timeOrigin` stayed identical and no document navigation request occurred. Evidence: `enter-focus.json`, `enter-live-arrival.json` and corresponding screenshots.

The repeated row-menu check retained six rows and sent zero polls for 12 seconds while visit seven was registered. The dirty-search check retained seven rows and sent zero polls for 12 seconds while visit eight was registered; restoring the draft to the applied `Synthetic` value resumed polling **without blur**, with the search still focused. Evidence: `interactions.json`. The cancellation dialog also retained eight rows and sent zero polls for 12 seconds; “Keep Visit” dismissed it without submitting a cancellation (`dialog.json`).


The browser intercepted only automatic `POST /registration/search` requests and returned **three synthetic HTTP 503 responses**. After each, all **eight rows** and the **8 Visits** total stayed visible. The first failure showed exactly “Live refresh paused. Showing last successful results.”; after the third it appended exactly “Press Search to try again.” The measured retry intervals were **20,004 ms / 40,004 ms**. Removing the interception and pressing Enter cleared the notice and retained focus. Evidence: `failure-first.json`, `failure-stop.json`, `recovery.json`, and their screenshots.

After recovery, with the applied search still focused, the board issued **six successful polls in 60,000 ms**, with gaps **10,053 / 10,049 / 10,051 / 10,050 / 10,051 ms**. Route limit headers remain **60**. No 429 or unexpected failure occurred: the only failing responses were the three deliberately injected 503s. The document-lifetime marker/time origin remained stable and no document navigation occurred. The preview database reconciles to exactly **8 Patients / 8 Visits** (`reconciliation.txt`).

There are **44 new headless screenshots** in `followup/`: **40 board captures**, comprising ten states at 1440/390 in light/dark (`enter-focus-five`, `enter-focus-six-live`, `menu-protects-six`, `board-seven-live`, `typing-protects-seven`, `board-eight-live`, `dialog-protects-eight`, `automatic-failure-retains-eight`, `automatic-stopped-retains-eight`, `manual-recovery-eight`); plus two desktop result-page captures and two labelled mobile known-overflow captures. Every board capture passed the document-width check. The unchanged result page still measures **567 px at a 390 px viewport** in both themes, recorded and not fixed. `followup/evidence-manifest.json` contains screenshot hashes. All images and browser artifacts remain private and outside Git.


## Final main merge and validation

During the full follow-up validation, the shared `origin/main` ref advanced. A new `git fetch origin main` confirmed main **43936dbfb04cba10653adac9a09b1dd20294b829**, PR #196 (UI-LEFTOVERS-1). It changes seven unrelated UI/test files and does not change Registration. Main was merged normally without conflicts, producing merge commit **f693464d4061c48cff5a19e68698322acd301700**; the three follow-up files remain the only changes against main. No rebase or force-push.

The full gate was repeated on this merged tree and passed, with actual results below. The complete pre-merge gate above is not substituted for final-tree validation. Logs use `final-` prefixes. A second final-tree browser rehearsal uses fresh synthetic preview data and `/private/tmp/kpone-reg-live-1-evidence/followup-final`; the earlier 44 follow-up captures remain intact.


The final-tree rehearsal also passes. Immediately after Enter, after the response, and after the subsequent refresh, `document.activeElement` remained `INPUT#registration-board-search` with value `Synthetic`. The second CA registered one matching visit and the board changed from five rows to six in **7,483 ms**, with the identical document time origin and no navigation requests. The injected automatic 503 retained all **six rows** and displayed the exact pause notice while the applied search remained focused. A manual Enter search cleared the notice. `followup-final/enter-case.json`, `failure-case.json`, `requests.jsonl`, and **16 new screenshots** (four states × 1440/390 × light/dark) retain this proof; every capture passes the width check. Final synthetic preview reconciliation is **6 Patients / 6 Visits**. Earlier eight-visit/44-capture evidence belongs to the separate pre-merge rehearsal and remains preserved.


### Final merged-tree gate result

Validated merge HEAD **f693464d4061c48cff5a19e68698322acd301700** plus the three-file uncommitted follow-up; main/base **43936dbfb04cba10653adac9a09b1dd20294b829**. The follow-up commit is created only after these checks. No pre-REG-LIVE-1 assertion changed.

| Gate | Final result |
|---|---|
| `php -l` | No changed application/test PHP; private fixture helper passed before use. Main's merge contains no PHP changes. |
| Pint / PHPStan level 7 | Passed inside exact CI, with the owner-approved task-only 1 GiB setting. |
| Exact `composer ci:check` | **1,571 backend passed / 33,872 assertions / 2 existing skips / 0 failures**, 1,573 total; 808.590 seconds. |
| Frontend types / lint / format / production build | Passed. |
| `node --test tests/Frontend/*.mjs` | **574 passed / 0 skipped / 0 failures**, including **19** REG-LIVE-1 lifecycle tests. Four tests arrived with merged main's PR #196, explaining the increase from pre-merge 570. |
| Explicit PostgreSQL replay | **120 passed / 1,202 assertions / 18 classes / 0 skipped / 0 failures / 0 errors**, 96.399 seconds. JUnit independently checked for skipped/failure/error elements. |
| Separate `migrate:fresh --seed --force` | Passed again on `kpone_reg_live_1_seed`, UTF8/task-owned; dummy/reference data only. |
| `git diff --check` / file scope / earlier tests | Passed. Only the Registration page, PR-owned lifecycle test and this report differ against main. Exactly the two approved old expectations changed; no other existing assertion changed. |

Logs: `final-ci.log`, `final-types.log`, `final-lint.log`, `final-format.log`, `final-build.log`, `final-frontend.log`, `final-pg.log`, `final-pg.xml`, `final-pg-summary.json`, and `final-seed.log` in the private evidence root. The prior pre-merge passing logs remain preserved. The source and tests were not edited after the final validation began; only report metadata was completed.

Final task services are stopped; no non-task database, folder, server or served-tree file was changed. No `.env` or `.env.preview` was opened. No new permission, migration, endpoint, payload field, dependency, financial calculation or stored-data change. The owner authorises pushing this follow-up to existing PR #198; merge and auto-merge remain unauthorised.

Final source SHA-256: `ffa3e061e328f0bb3344642bbd483cc95251535ae4305bcc2146c87639dde1c6`. Final PR-owned test SHA-256: `f15baa2a170559d622418e992751fcee14edc96def70cc2d63425c21dd416006`. New review diff: `/private/tmp/kpone-reg-live-1-evidence/REG-LIVE-1-followup-review.diff`. New private bundle: `/private/tmp/kpone-reg-live-1-evidence/REG-LIVE-1-followup-evidence.zip`.
