# FLOW-FIXES-1 — Registration context, Dispensary discovery and completed consultation

Date: 2026-11-03. Branch `fix/flow-fixes-1`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-flow-fixes-1`. Original survey/full-gate base: `019139c7b2fe8d8c9e0ba01ab9429706ae0d425b`. The owner approved the four surveyed changes before implementation and has now authorised this commit, push and PR. Before release, current main was fetched and fast-forwarded into the branch at `a8f097a`; the exact full post-merge base SHA is recorded below. Not production-approved. Synthetic data only.

## Changes

### R2-004: keep the applied Registration context

The readiness controller already names a fixed Registration or Queue destination. Registration's initial response uses empty search criteria, while its applied filters and page live in component state. The readiness component's `router.visit()` previously remounted Registration and lost that context.

Readiness mutations now preserve component state when their source is Registration. A successful action emits a change event only after its dialog/busy state closes. `PatientBoard` forwards that event; Registration reuses its existing automatic search with the current page and applied filters. Draft filter text is not submitted. Send to Waiting uses the same post-success refresh. No patient search text is placed in a URL.

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
| `tests/Feature/FlowFixesTest.php` | Six new backend tests. |
| `tests/Frontend/flow-fixes-1.test.mjs` | Six new frontend tests and a local mounting harness. |
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

## Validation

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
