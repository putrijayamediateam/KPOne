# FLOW-FIXES-1 — Registration context, Dispensary discovery and completed consultation

Date: 2026-11-03. Branch `fix/flow-fixes-1`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-flow-fixes-1`. Original survey/full-gate base: `019139c7b2fe8d8c9e0ba01ab9429706ae0d425b`. The owner approved the four surveyed changes before implementation and has now authorised this commit, push and PR. Before release, current main was fetched and fast-forwarded into the branch at `a8f097a`; the exact full post-merge base SHA is recorded below. Not production-approved. Synthetic data only. The PR #201 review follow-up and latest validation are recorded at the end of this report.

## Changes

### R2-004: keep the applied Registration context

The readiness controller already names a fixed Registration or Queue destination. Registration's initial response uses empty search criteria, while its applied filters and page live in component state. The readiness component's `router.visit()` previously remounted Registration and lost that context.

Readiness mutations now preserve component state when their source is Registration. A successful action emits a change event only after its dialog/busy state closes. `PatientBoard` forwards that event; Registration reuses its existing automatic search with the current page and applied filters. Draft filter text is not submitted. Send to Waiting uses the same post-success refresh. Following the PR review, every successful `runMutation` now requests that refresh; navigation-away disposal prevents a request after Registration unmounts. No patient search text is placed in a URL.

The original standalone generation guards remain intact. The refresh also retains the existing request cancellation, no-overlap checks, hidden-tab pause, typing/IME protection, open-menu/dialog protection before requests and after responses, error back-off and retained-results notice. A protected refresh can wait for the next allowed polling opportunity; it never bypasses those protections. Queue-origin readiness behaviour and fixed controller destinations remain unchanged.

### R2-005: Open Dispensary from All

Registration already fetches cases in one bounded, organisation- and branch-scoped lookup for its 25-row page. The action previously used only an OTC case. The new consultation selection uses that existing collection, with no extra per-row queries. For a registered consultation with a `pending` or `dispensing` case, a CA or CA Supervisor with `dispensary.view.branch` receives the existing `dispensaryStatus`, `dispensaryUrl` and `can.openDispensary` values. Completed and returned cases do not expose that action. No response key is added. OTC selection and Billing hints remain unchanged.

The existing Dispensary route still independently checks permission, CA/CA Supervisor eligibility, organisation and the effective active branch. No permission is granted by an action hint.

### R2-009: distinguish a handed-off consultation

`clinical.treatmentPlan.checkout` already identifies the current handoff. The clinical page now derives a Completed header from that existing value, disables Save clinical record and guards its submit handler. It explains: **Completed means the consultation was handed off, not that payment is finished.** No backend field or stored encounter status changes. In-progress and Held labels/guards are retained. Reopening a consultation continues to use the existing eligibility and workflow.

Backend refusal remains unchanged: a save after checkout returns HTTP 422 JSON with `errors.encounter[0]` equal to `Only a registered Consultation currently Serving can use a clinical Encounter.` The new regression test compares complete ID-ordered source rows before and after that refused save. The earlier survey also verified HTTP 303 for a browser-style Inertia request, returning to the encounter page with the same validation error, both after real checkout and with a controlled completed-Visit fixture. That fixture was not a payment/completion rehearsal.

### Send to Waiting: stay on Registration

Registration's board and visit-detail form now send optional validated `from: registration`. Consultation entry uses a fixed Registration destination for success and validation refusal, including FormRequest validation. Callers without that source keep the Queue fallback. OTC keeps its Registration destination and existing service. Raw return URLs and `back()` are not used. Queue number generation, retry rules, version checks, transactions and audits are unchanged.

## Changed files

| File | Change |
| --- | --- |
| `app/Domain/Visit/Services/VisitDirectoryService.php` | Select eligible consultation cases from the existing scoped batch for existing action fields. |
| `app/Http/Controllers/QueueController.php` | Explicit source-aware consultation Send to Waiting success/refusal destinations. |
| `app/Http/Requests/SendToWaitingRequest.php` | Validate optional source and use the fixed destination for request validation errors. |
| `resources/js/components/queue/CallReadinessActions.vue` | Preserve Registration state and emit successful completion after releasing busy state. |
| `resources/js/components/patient-board/PatientBoard.vue` | Forward readiness completion to the Registration page. |
| `resources/js/pages/Registration/Index.vue` | Reuse applied-context refresh after successful readiness/Send to Waiting mutations; send Registration source. |
| `resources/js/pages/Registration/Show.vue` | Send Registration source from visit detail. |
| `resources/js/pages/Clinical/Show.vue` | Completed header, explanatory note, disabled Save and submit guard. |
| `tests/Feature/FlowFixesTest.php` | Eight backend tests: six original slice tests and two review cases. |
| `tests/Frontend/flow-fixes-1.test.mjs` | Sixteen frontend tests: six original slice tests and ten review cases, with a local mounting harness. |
| `docs/reports/FLOW-FIXES-1.md` | This report. |

## Every changed existing assertion

**None.** No existing test file, assertion, expectation, test name or skip changed. The new frontend file copies the existing mounting-harness conventions without editing the original harness/tests. Comparison against `origin/main` contains no change to an existing test file; the only task test differences are the two new files listed above.

Existing regressions were run unchanged, including `OperationalPatientBoardTest`'s exact standalone generation guards; REG-LIVE-1 polling/stale-response/focus/error tests; `CallReadinessTest`; `PilotBoardScaleTest::test_four_hundred_waiting_visits_have_bounded_registration_queue_and_tv_reads`; `ClinicalEncounterConcurrencyTest::test_queue_state_change_blocks_later_clinical_save`; `ClinicalEncounterDirectoryTest`; and the OTC route tests.

## Permissions and data boundaries

No new permission, role grant, migration, dependency, route, response field or calculation. Existing permissions remain:

- Readiness set/clear: `queue.call_hold.set.branch` / `queue.call_hold.clear.branch`, CA and CA Supervisor.
- Open Dispensary: `dispensary.view.branch`, with the existing CA/CA Supervisor and effective-branch/organisation restrictions.
- Clinical Save: `encounters.update.own`, with the existing attending-doctor authority and backend state checks.
- Send to Waiting: `queue.enter.branch`, Director, CA and CA Supervisor, with existing backend branch authority.

The new source input only chooses between fixed destinations; it grants no authority and is not persisted. Twelve complete ID-ordered table projections are compared in the new read-only/refused-save tests: visits, queue entries, clinical encounters, vital observations, diagnoses, treatment plans, dispensary cases, stock movements, stock balances, invoices, payments and audit. Successful Send to Waiting and readiness actions still create their normal existing operational/audit evidence. There is no new financial or stock mutation.

## Owned environment

PostgreSQL **18.6**, **127.0.0.1:58785**, cluster `/private/tmp/kpone-flow-fixes-1-pg18`, dummy role `kpone_flow_fixes_1`. UTF8/template0 databases created for this task:

- `kpone_flow_fixes_1_testing`: new/unchanged regression suites, full CI sequence and PostgreSQL replay.
- `kpone_flow_fixes_1_preview`: persistent synthetic browser fixtures only.
- `kpone_flow_fixes_1_seed`: separate fresh migration/seed check.

Explicit placeholder environment, task-only PHP/Composer 1 GiB configuration, array mail and synchronous queue. No `.env` or `.env.preview` was opened, created or edited. Effective process host, port and database plus current database, encoding and cluster directory were verified before application use. No PostgreSQL server-address comparison was added. The preview uses a native PHP router on owned `127.0.0.1:8909` with an empty environment directory. Test, preview and seed storage roots are separate. Persistent fixtures never call test setup or fake-storage reset.

The served tree stays clean on main and untouched. PRINT-1, TV-5 and other worktrees were not modified.

## Original implementation validation

These results use the original `019139c` base. The latest complete review gate is recorded below.

| Check | Actual result |
| --- | --- |
| `php -l` | Passed for all three changed production PHP files and the new PHP test before the long suite. |
| Early Pint / PHPStan level 7 | Passed; zero PHPStan errors. |
| Related backend regression | **137 passed, 1,317 assertions**, zero failures or skips. |
| Frontend types, lint, format | Passed on final code. |
| `node --test tests/Frontend/*.mjs` | **580 passed, 0 failed, 0 skipped**. |
| `npm run build` | Passed on final code. |
| `composer ci:check` | **1,577 passed, 33,951 assertions, 2 existing skips, 0 failures**, exit 0; 797.355 seconds (1,579 tests including skips). |
| Explicit PostgreSQL replay | **120 passed in 18 classes, 1,202 assertions, 0 skipped, 0 failures, 0 errors**, exit 0; 98.191 seconds. JUnit XML verified. |
| Separate `migrate:fresh --seed --force` | Passed on `kpone_flow_fixes_1_seed`, dummy data only. |
| Tracked and new-file whitespace | Passed, including all three new files. |

Early failures were confined to new code/harnesses and corrected without editing an existing assertion: the new table snapshot used `stock_balances` instead of `inventory_stock_balances`, then created a supervisor after the audit snapshot; the new note test initially assumed one source line despite formatting; two CI attempts stopped at new-code lint spacing/braces before backend execution. The private preview fixture initially used a guessed medicine enum; it was corrected to the existing named constant and the owned preview database was rebuilt before browser use. The browser harness initially selected a tab as a button, then submitted before the tab's pending request enabled Apply; it was corrected to follow the actual accessible tab and Apply controls. These initial logs remain private. No production constraint, permission or test was weakened.

## Browser rehearsal and screenshots

Private evidence root: `/private/tmp/kpone-flow-fixes-1-evidence`. Headless Chromium through Playwright, real authenticated routes and synthetic `.test` identities only. Requests outside owned preview origin are blocked. Browser rehearsal passed with **zero page errors, zero failed HTTP responses and zero 429s**. The main rehearsal's **33** observed Registration search responses were all HTTP 200. Every main capture checked document width against viewport width; none overflowed horizontally.

Thirty-two main captures cover each state below at **390 and 1440 px**, **light and dark**. Four additional normal-viewport Registration captures distinguish the sticky-header full-page capture artifact from actual page layout: **36 screenshots total**.

| Capture suffix | Observed result |
| --- | --- |
| `flag-dialog` | Administrative reason selected on the filtered Waiting page two. |
| `flag-set-page-two` | Same applied name filter, Waiting tab and page two; flag/badge updated. |
| `flag-cleared-page-two` | Same filter/tab/page; flag cleared and Mark not ready restored. |
| `all-tab-dispensary-menu` | All shows Open Dispensary for the pending consultation. |
| `dispensary-open` | That action opens the real case with the synthetic medicine. |
| `completed-consultation` | Completed header and explanatory note visible; Save clinical record disabled. |
| `send-board-stays-registration` | Board action enters Waiting, retaining Registration and its applied filter. |
| `send-detail-stays-registration` | Visit-detail action enters Waiting and returns to Registration. |
| `registration-viewport` | Supplemental normal viewport at scroll position zero. |

Paths: `screenshots/{390|1440}-{light|dark}-{suffix}.png`. `browser-results.json` records URLs, dimensions, search bodies and statuses. `screenshot-manifest.json` lists every screenshot and its dimensions/SHA-256 hash. `review.diff` contains all eleven changed/new files; `FLOW-FIXES-1-evidence.zip` is the curated private evidence bundle, excluding environment files, login scripts, cookies and sessions. `browser.log` records the successful rehearsal. Opening-transition overlays were retaken after animations settled (`overlays.log`) for readable menus/dialogs; no application change was made for capture timing. Full-page images taken after scrolling can place the sticky header part-way down the stitched page; normal viewport captures show the header correctly at the top. The main rehearsal and its assertions remain intact.

## Limits and deferred scope

Completed means the consultation is handed off, not financially settled. Existing local editors remain visible; this slice guards the clinical Save button and handler, while backend authority still refuses the save. Other existing controls and reopening eligibility are unchanged. Protected board refreshes may wait for the next allowed poll. No fixes to unrelated Registration-result mobile overflow, other layouts, billing, stock, permissions or queue rules are included.

The complete CI sequence and the explicit PostgreSQL replay passed. No existing assertion changed and no new skip was added. The owner has authorised the scoped commit, push and PR; merge and auto-merge remain unauthorised. The owned preview server and PostgreSQL cluster are stopped; synthetic databases and evidence remain private and local. The served tree remains clean on main and untouched.

## PR release checks after merging current main

Fetched `origin/main` and merged it by fast-forward on 2026-11-03. Post-merge base/HEAD before the FLOW-FIXES-1 commit: `a8f097a5a72852a1d4b4a82b65f868148a7f8f1f`. No file conflicted. Main includes PRINT-1 and the guides fix. `resources/js/pages/Inventory/GovernedStock.vue` and `resources/js/lib/inventory-picker.ts` match main exactly, including `formatInventoryReason` and `count_variance: 'Count variance'`; FLOW-FIXES-1 changes neither file.

The full gate above was run on the original base with the final FLOW-FIXES-1 code. The owner requested these quick checks after the main merge; they all passed on the merged tree:

| Check | Post-merge result |
| --- | --- |
| `php -l` | All three changed production PHP files and the new PHP test passed. |
| Pint `composer run lint:check` | Passed. |
| `node --test tests/Frontend/*.mjs` | **583 passed, zero failures/skips**; includes main's three additional frontend tests. |
| New FLOW-FIXES-1 frontend file | **6 passed, zero failures/skips**. |
| New `FlowFixesTest` backend class | **6 passed, 79 assertions, zero failures/skips**. |
| Existing assertion comparison | No existing test file differs from main; only the two new test files. |
| `git diff --check`, including new files | Passed. |

The task-owned PostgreSQL 18 database was verified as `127.0.0.1:58785 / kpone_flow_fixes_1_testing`, UTF8, cluster `/private/tmp/kpone-flow-fixes-1-pg18`, before the checks. The initial restart command omitted the server port; PostgreSQL attempted its default port 5432 and failed to bind, without connecting to or modifying another database. It was restarted with the explicit task port 58785, verified, and stopped after the checks. No existing assertion or source change was made to obtain these passes. No full-suite or new browser replay after the merge is claimed; the requested quick checks are listed separately from the earlier full gate.

## PR #201 review follow-up — mutation refresh and reopening

Review base: `cec3ef1874c58d55987aacf1b9012711ad26a3fa`, with main `a8f097a5a72852a1d4b4a82b65f868148a7f8f1f` already an ancestor. A fresh `git fetch origin main` confirmed that main had not moved. The owner authorised the follow-up commit and push to the same branch/PR. No new scope, permission, migration, endpoint or payload field.

### Every Registration mutation caller and destination

There are exactly **five direct `runMutation` callers**, all in `resources/js/pages/Registration/Index.vue:518–568`. The wrapper preserves state, so an Inertia response to the same component updates page props without reinitialising the local `rows` ref. This matters only while Registration remains mounted.

| Action | Caller | Successful destination | Behaviour before this follow-up | Behaviour after this follow-up |
| --- | --- | --- | --- | --- |
| Send to Waiting | `sendToWaiting`, line 518 | Registration | Already requested the applied search after success. | Same applied-context refresh. |
| Call In | `callIn`, line 529 | Queue | Leaves Registration; no stale Registration row remains mounted. | Same destination; no post-unmount Registration request. |
| Open Consultation | `openConsultation`, line 541 | Encounter | Leaves Registration. | Same destination; no post-unmount Registration request. |
| Dispense (OTC) | `dispenseOtc`, line 553 | Dispensary case | Leaves Registration. | Same destination; no post-unmount Registration request. |
| Call to Dispensary (OTC) | `callOtcToDispensary`, line 561 | Registration | Retained local rows until the next poll. The announcement does not itself change the Waiting status. | Requests the current applied filter/tab/page immediately after success, subject to existing protections. |
| Cancel Visit | Own `VisitCancellationDialog`, not `runMutation` | Visit detail | Leaves Registration; not affected by this wrapper change. | Unchanged. |
| Call Again | Not wired/exposed on Registration | Not applicable | The shared board supports `recall`, but Registration provides no recall flag or event listener. | Unchanged; no new action added. |
| Call to Treatment Room | Not wired/exposed on Registration | Not applicable | The shared board supports this event, but Registration provides no call-treatment flag or listener. | Unchanged; no new action added. |
| Open Dispensary, Billing, Patient and history links | Direct links, not `runMutation` | Their existing destinations | Navigate away. | Unchanged. |
| Set/clear not-ready flag | Own `CallReadinessActions` router visit, not `runMutation` | Registration when invoked there | Already emits readiness-changed after success and refreshes the applied board. | Unchanged. |

Evidence for destinations: `QueueController.php:128–146` sends a successful Call In to Queue; `from: registration` controls its validation-refusal destination. `ClinicalEncounterController.php:28` sends Open Consultation to the encounter. `DispensaryController.php:70` sends OTC Dispense to its case. `RoomCallController.php:39–64` returns the OTC announcement to Registration. `VisitController.php:42–47` returns cancellation to Visit detail. `PatientBoard.vue:381–391` contains the shared recall/treatment controls, while Registration's bindings and `VisitDirectoryService.php:414–430` expose neither action. No redirect was changed in this follow-up.

### Browser answer and smallest refresh change

**The suspected stale Call In was not reproduced.** On the reviewed pre-fix tree, a synthetic CA Supervisor pressed Call In on Registration. The real PATCH returned 303 and navigated to `/queue` in **378 ms**; the patient row showed **Serving Now**, with **zero active Call In menu items**. A regular CA correctly had no Call In permission/action; the probe used the existing CA Supervisor authority, not a synthetic grant.

The pre-fix OTC Call to Dispensary probe did stay on Registration and made **no immediate search** (the observed search count remained two before/after the action). Its row correctly remained Waiting, because the service records a TV announcement without changing the queue status. This confirmed the extra same-page caller could retain older local data until polling; it did not demonstrate an incorrect OTC status transition.

The production fix removes only the optional refresh flag and refreshes after **every successful `runMutation`**. The `onFinish` callback first clears `busyKey`, then calls the existing `refreshAppliedBoard` (`Index.vue:483–516`). Refusals never refresh. The existing `search` disposed guard at line 328 prevents a request once navigation has unmounted Registration. Existing typing/IME, menu/dialog, hidden-tab, in-flight and stale-response guards still apply, including the response-arrival check; a protected refresh may wait for the next allowed poll.

On the final code, a second real Call In navigated to Queue with Serving Now in **358 ms** and **zero extra Registration searches**. The OTC announcement triggered the same applied Registration search in **383 ms**, HTTP 200, retaining its patient query, All tab, dates and page 1. No endpoint or permission changed.

### Reopen answer: checkout becomes null; Hold is conditional

Reopening supersedes the retained checkout and sets `current_visit_guard` to null (`CheckoutEvidenceService.php:90–94`). `TreatmentPlanDirectoryService.php:35,56–57` selects only that current guard, so the detail payload becomes **`treatmentPlan.checkout: null`**. The Completed computation therefore becomes false without changing production code.

`ReopenConsultationCheckoutService.php:53–55` restores Serving and calls `holdReturningConsultation`. That helper returns without creating a hold when the doctor has no other active consultation (`ConsultationHoldService.php:166–180`). Therefore:

- **Doctor free:** the reopened page shows In progress; Save clinical record is enabled immediately.
- **Doctor busy with another consultation:** the returning consultation is held, the checkout is still null, and Save remains disabled by the existing Hold guard. Once the other active consultation is held or completed and this consultation is resumed, Save is enabled.

The initial chat statement that reopening always creates a Hold was incorrect; the conditional rule above is the verified behaviour. No reopen service, hold rule, Completed condition, clinical Save logic or existing assertion was changed. The final browser rehearsal reopened the original synthetic no-medicine checkout with a free doctor: the Completed note disappeared and Save was enabled. New backend coverage also exercises the busy-doctor case, then successfully saves after Resume.

### New tests and unchanged assertions

Only the PR's own two new test files were extended:

- `tests/Frontend/flow-fixes-1.test.mjs`: ten new mounted-component cases, bringing this file to **16 tests**. Five actions refresh applied results after success while Registration remains mounted; a refused OTC action does not refresh; Call In, Open Consultation and Dispense do not search after unmount; replacing a current checkout with null clears Completed and allows Save after Resume. The test helper now forwards PATCH like POST and uses reactive clinical props for the new transition case.
- `tests/Feature/FlowFixesTest.php`: two new methods, bringing this file to **8 tests / 105 assertions**. `test_registration_call_in_goes_to_queue_with_current_serving_actions` verifies the real redirect, Serving row and disabled call hint. `test_reopening_clears_checkout_with_idle_and_busy_doctor_then_allows_save` verifies current-checkout removal, retained superseded evidence, exact idle/busy Hold state, Resume where needed and the original successful clinical-save route.

Four **new** frontend assertions failed on the original wrapper (two search requests instead of three), and all pass with the uniform refresh. All six original frontend cases passed in that red run. The six original PHP methods remain byte-identical; all **23 original frontend assertion expressions** remain identical after parsing/normalisation. No test outside this PR's two new files changed. **Every changed existing assertion: none.** No expected value, test name, skip, timeout, permission or protection was weakened.

An initial new test incorrectly expected every reopen to be held. It was corrected to test the actual idle and busy cases explicitly; no existing test was changed. A new helper syntax error and a new-test blank-line lint error were also corrected before the final gate. A private fixture initially tried the consultation queue helper for OTC, which was correctly refused; the fixture resumed through the real OTC service without rebuilding or altering unrelated evidence. Private logs retain the red regression run, initial lint refusal and fixture refusal; these are not presented as passing validation.

### Follow-up browser evidence

Private root: `/private/tmp/kpone-flow-fixes-1-evidence/review-followup`. Real authenticated UI, headless Playwright Chromium, synthetic `.test` accounts only, requests restricted to the owned origin `127.0.0.1:8909`. No browser page errors. Twelve screenshots:

- `before-call.png`, `after-call-queue.png`, `otc-announcement-registration.png`: reviewed pre-fix behaviour.
- `after-fix-call-queue.png`: final Call In transition.
- `after-{390|1440}-{light|dark}-otc-refreshed.png`: four refreshed Registration captures.
- `after-{390|1440}-{light|dark}-reopened-save-enabled.png`: four reopened encounter captures.

`browser-results.json` and `after-browser-results.json` record the actual routes, response status, timings, applied request body and enabled state. `screenshot-manifest.json` lists dimensions and hashes. The eight final responsive captures preserve existing layouts; the new behaviour adds no visible control or wording. Representative 390 px light/dark captures were inspected. Physical devices, sound and printers are outside this follow-up.

### Latest complete review gate

The executable tree tested is the follow-up diff on `cec3ef1874c58d55987aacf1b9012711ad26a3fa`, with main `a8f097a5a72852a1d4b4a82b65f868148a7f8f1f` already merged. `source-hashes.json` verifies the three executable/test files remained identical from the full gate through handoff. Only report completion followed the runs.

| Check | Final review result |
| --- | --- |
| `php -l` | All three original production PHP files and the extended new PHP test passed. |
| Early Pint and PHPStan level 7 | Passed; zero static-analysis errors. |
| Exact `composer ci:check` | **1,579 passed / 33,977 assertions / two existing skips / zero failures or errors**; 1,581 total tests reached, exit 0, 805.045 seconds. |
| Frontend types, lint and format | All passed on the final executable tree. |
| Production build | `npm run build` passed. |
| `node --test tests/Frontend/*.mjs` | **593 passed, zero failures/skips**, 2.969 seconds. |
| Extended FLOW-FIXES-1 frontend file | **16 passed, zero failures/skips**. |
| Extended `FlowFixesTest` | **8 passed / 105 assertions**, zero failures/skips. |
| Explicit PostgreSQL replay | **120 passed in 18 classes / 1,202 assertions / zero skipped, failed or errored**, exit 0, 95.855 seconds. JUnit XML independently verified. |
| Separate fresh migration and seed | Passed on `kpone_flow_fixes_1_seed`, dummy/reference data only. |
| `git diff --check origin/main` | Passed across the complete PR, including its new files. |
| Existing assertions | None changed; original PHP methods byte-identical, all 23 original frontend assertion expressions identical. No test outside this PR's own new files changed. |

The two full-suite skips remain the pre-existing disabled public-registration tests; the explicit PostgreSQL replay has **none skipped**, including contention coverage. The host/port/database were verified before each application run: `127.0.0.1:58785`, testing database `kpone_flow_fixes_1_testing`, separate seed database `kpone_flow_fixes_1_seed`, preview database `kpone_flow_fixes_1_preview`. All remain task-owned PostgreSQL 18.6, UTF8/template0, with separate storage roots and the approved task-only 1 GiB setting. No `.env` or `.env.preview` was opened, printed, created or edited.

The review follow-up changes exactly four files: `Registration/Index.vue`, the PR's two new test files and this report. Full logs, assertion comparison, before/after screenshots and manifests are under `/private/tmp/kpone-flow-fixes-1-evidence/review-followup/`. The private review diff is `review-followup.diff`; the curated local bundle is `FLOW-FIXES-1-review-evidence.zip`. The original implementation evidence is retained separately.

All task-owned services are stopped; ports 8909 and 58785 have no listeners. No served-tree or other-worktree source change. This follow-up is authorised for commit and push to update PR #201 only. **Do not merge or enable auto-merge. Not production-approved. Synthetic data only.**
