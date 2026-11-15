# UI-POLISH-1 — five wording and layout fixes

Date: 2026-10-30. Branch `fix/ui-polish-1`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-ui-polish-1`. Original full-gate base: `87009ebceec0cc01b566df9cc42b6bad98c1a00c`. Owner-requested pre-PR merged base: `a8198874889e2fd6706faa2e24eea815df7ba5f2`. Owner-authorised PR candidate. Not production-approved. Synthetic data only.

## Approved scope

| Finding | Change | What stays unchanged |
|---|---|---|
| D001 | Staff/Create standalone labels have matching control IDs, including passwords and indexed branch assignments. Sign-in method has an explicitly named radio group. Existing wrapping labels remain associated. | Form data, role choices, assignment behavior, provisioning and submission. Only Staff/Create is fixed. |
| D003 | Registration/Create visibly says “Search for the patient first. Quick create appears when no match is found.” The search input references this instruction. | Search handlers and the exact existing Quick create conditions, including the empty-recent state. |
| D004 | The guardian validator receives friendly attribute names: Guardian contact, relationship, name, phone, phone country, email, contact version and request reference. | All rules, nested error keys, refusal paths, permission and guardian persistence. No validation rule was changed. |
| D010 | One display-only formatter in the existing inventory picker utility supplies readable hold, delivery-exception and correction reasons in cards, reviews, selects, stock overview, opening-balance choices and dispensary choices. | Codes, option values, IDs, disabled held choices, payloads, quantities, permissions and actions. Receiver stays “staff #ID”. |
| D011 | The search label/input uses an 18rem flex basis and a minimum of `min(100%,18rem)` on both governed stock modes. Filters and Search wrap onto another line when needed. | Search handlers, query parameters, routes, paging and results. |

No permission, migration, route, payload, calculation, stored-data, dependency or business-rule change. The only production PHP change is the validator's attribute-name map. No controller or other domain service changed.

## Known limit: receiver names are not available

The owner approved keeping “Receiver: staff #ID” on 2026-10-30. Neither receiving row carries the receiver's display name:

| Payload field | Verified source |
|---|---|
| `receiver_user_id` on review rows | `app/Domain/Organisation/Inventory/Services/ReceivingReviewService.php:137` selects the review row; line 141 returns its fields and uses this ID. `resources/js/components/inventory/ReceivingReviews.vue:10` declares the existing field. |
| `received_by_user_id` on receipt rows | `app/Domain/Organisation/Inventory/Services/ReceivingReviewService.php:139` selects this field; line 141 returns it. `resources/js/components/inventory/ReceivingReviews.vue:37` declares it. |

The service has no receiver-name join or projection. No lookup, new field or inferred name was added. A separate owner authorisation is needed to change that payload. Real authenticated screenshots show the existing synthetic receiver ID and the readable reasons together.

## Same label pattern elsewhere — survey only

A source survey of Vue templates looked for a standalone label without `for`, followed by a separate input/select, and confirmed these matches manually. They remain untouched:

| File | Lines and labels |
|---|---|
| `resources/js/pages/Staff/Edit.vue` | 61 Full name; 68 Work email; 76 Staff number; 83 Job title; 90 Department. |
| `resources/js/pages/Staff/Partials/BranchAssignmentDialog.vue` | 108 Assignment type; 131 Valid from; 139 Valid through (inclusive). |

This is the same-pattern survey, not a claim that every form has had a full accessibility audit. Only Staff/Create's associations were changed.

## Every changed file

| File | Change |
|---|---|
| `app/Domain/Patient/Services/PatientGuardianService.php` | Nine added attribute-name map lines; rules and control flow unchanged. |
| `resources/js/pages/Staff/Create.vue` | Associated labels/IDs and named sign-in radio group. |
| `resources/js/pages/Registration/Create.vue` | Search-first guidance and description association. |
| `resources/js/lib/inventory-picker.ts` | Shared display formatter and readable held-option label; values, limits and disabled logic retained. |
| `resources/js/pages/Inventory/BatchHolds.vue` | Readable card and reason-option labels. |
| `resources/js/pages/Inventory/Index.vue` | Readable hold labels in stock and batch tables. |
| `resources/js/components/inventory/InventoryReferenceDataPanel.vue` | Readable opening-balance batch labels. |
| `resources/js/components/inventory/ReceivingReviews.vue` | Readable exception, lot and correction labels; receiver IDs retained. |
| `resources/js/components/inventory/VerifiedReceivingForm.vue` | Readable reason-option text; original values retained. |
| `resources/js/pages/Dispensary/Show.vue` | Readable held-batch text in existing edit/add choices; held options remain disabled. |
| `resources/js/pages/Inventory/GovernedStock.vue` | Responsive search width for counts and write-offs. |
| `tests/Frontend/ui-polish-1.test.mjs` | Five new frontend tests covering all five findings except the PHP-only D004, including two D010 tests. |
| `tests/Feature/Patient/GuardianValidationLabelsTest.php` | Two new tests for required/invalid guardian fields, same error keys/rules and friendly messages. |
| `docs/reports/UI-POLISH-1.md` | This report. |

## Every changed existing assertion

**None.** No existing test file, fixture, expected value, test name or skip changed. Existing tracked tests are identical to `origin/main`; only the two new test files above were added. No test was weakened, removed or newly skipped.

The new frontend tests failed against the original page for unassociated labels, missing guidance, raw hold text and collapsed-width styling. The formatter test also initially found the not-yet-created helper absent. Both new guardian tests failed against the original messages (`guardian.relationship` instead of a friendly name). After implementation, **5 new frontend tests** and **2 new backend tests / 41 assertions** pass.

One new frontend assertion initially checked the entire HTML for a raw code; that was incorrect because option values must retain codes. It now checks visible text and additionally asserts the unchanged option value. Only new code/tests were corrected. Import-order lint errors were corrected before the gate; no existing assertion failure occurred.

## Safety evidence

- Eight affected action scripts compare byte-for-byte with main after removing only the new display-formatter import: Staff/Create, Registration/Create, GovernedStock, BatchHolds, ReceivingReviews, VerifiedReceivingForm, Inventory/Index and Dispensary/Show. Private hashes: `unchanged-action-scripts.json`.
- The reference-data panel's additional script change is only the batch option's label expression. Inventory-picker URL generation, bounds, values and disabled conditions are unchanged.
- The entire `PatientGuardianService.php` diff adds only the custom attribute names. No rule or manual refusal text changed. New tests retain required/allowlist/length/email/version/UUID refusal checks and the same error keys.
- Before/after full ID-ordered comparison: **all 32 inventory tables are unchanged** through the browser session. `stock-before.json` and `stock-after.json` are identical. The deliberately invalid minor create stored no Patient.
- Browser requests: **38 responses**, 31 HTTP 200 and 7 HTTP 302; no HTTP 429 or 5xx, and no browser page errors. The only non-lookup POSTs were three synthetic sign-ins and the intentionally refused registration. No staff account, stock, receipt or medicine mutation was submitted through the UI.

## Environment

Task-owned PostgreSQL **18.6**, host **127.0.0.1**, port **58747**, cluster `/private/tmp/kpone-ui-polish-1-pg18`. Databases: `kpone_ui_polish_1_testing`, separate `kpone_ui_polish_1_seed`, separate `kpone_ui_polish_1_preview`; UTF8/template0. Effective Laravel driver/host/port/name and connected database/encoding/task-owned cluster directory were printed and verified before application use and before the first preview request.

Explicit placeholder environment; owner-approved task-only PHP/Composer 1 GiB configuration. No `.env` or `.env.preview` was opened, printed, edited or created. Dependencies were copied into this worktree; no dependency change. Tests use isolated temporary storage; seed and preview have separate roots. No non-task-owned database or port used. No real personal, financial, stock or clinic-day data.

## Validation

The complete gate below was recorded before the owner-requested main merge. The post-merge checks and their expanded frontend count are recorded separately under PR preparation.

PHP syntax checks passed on both changed/new PHP files before testing. Pint and PHPStan level 7 passed early. The first full backend run passed **1,565 tests / 33,494 assertions / 2 existing skips / 0 failures** (798.628 seconds). Remaining frontend hold-label surfaces were finished while that run was in progress, so the exact CI sequence was restarted on the frozen final source tree. The final exact replay also passed: **1,565 backend passes / 33,494 assertions / 2 existing skips / 0 failures**, 1,567 total, 790.760 seconds. No existing assertion failed in either complete run.

| Check | Result |
|---|---|
| Frontend tests | **543 passed; 0 skipped; 0 failures**, including 5 new tests. |
| Frontend types, lint, format | Passed on the final source. |
| Build | Passed on the final source. |
| Separate `migrate:fresh --seed --force` | Passed on `kpone_ui_polish_1_seed`, dummy/reference seed only. |
| Final exact `composer ci:check` | Passed: lint, format, types, config clear, Pint, PHPStan level 7 and backend **1,565 passed / 33,494 assertions / 2 existing skips / 0 failures**. |
| Explicit PostgreSQL replay, none skipped | **120 passed / 1,202 assertions / 18 classes / 0 skipped/failures/errors**, 99.075 seconds; JUnit verified. |
| Final tracked/new-file `git diff --check` | Passed, including both new tests and this report. |

## Headless browser evidence

**48 local private screenshots**, 12 states at 390 and 1440 CSS px, light and dark, under `/private/tmp/kpone-ui-polish-1-evidence`. No evidence file is added to the repository. Filenames follow `{state}-{role}-{width}-{theme}-headless-playwright.png`:

| State prefix | Role and evidence |
|---|---|
| `D001-staff-create` | Director; label-based entry and label-click focus, password controls, two assignments, unique IDs. Every visible label owns a control. |
| `D003-search-first` | CA; visible instruction with Quick create still hidden before search. |
| `D003-no-match` | CA; settled no-match result with Quick create visible. |
| `D004-guardian-errors` | CA; real refused synthetic minor Quick create shows Guardian relationship/name/phone messages, no internal path in visible text. |
| `D010-batch-hold` | CA Supervisor; readable active hold. |
| `D010-stock-overview` | CA Supervisor; readable stock/batch status. |
| `D010-receiving-reviews` | CA Supervisor; receipt and review keep “Receiver: staff #6”, while exception/lot/correction reasons are readable. |
| `D010-delivery-form` | CA Supervisor; existing unsaved quarantined lot's reason shows “Short expiry”. No receipt submitted. |
| `D010-dispensary` | CA; unsaved medicine form shows the readable held option, still disabled. No item added. |
| `D010-batch-picker` | CA Supervisor; actual opening-balance popup shows readable held choice without a save. |
| `D011-counts` | CA Supervisor; search input measures **358 px** at 390 px; Search visible. |
| `D011-writeoffs` | CA Supervisor; same 358 px search field and visible Search. |

Full-page captures have no document overflow. Review/form section captures use a taller viewport at the same required CSS width to keep the sticky top navigation outside the captured section; this corrects a capture artifact without hiding or changing any app element. Batch-picker captures are normal viewport screenshots of the actual popup.

Private browser selectors were corrected for a debounced search and for a review paragraph containing both the delivery reference and reason summary. These were harness assumptions, not application changes. The evidence was recaptured after the search settled.

## Limits and next step

Receiver names remain deferred as expressly approved. The two other forms with the standalone-label pattern remain unchanged. This slice adds no workflow, permission, payload field or stored data.

## Owner-authorised PR preparation

On 2026-10-30 the owner authorised committing and pushing only `fix/ui-polish-1` and opening one PR to `main`, using this report as the body. `git fetch origin main` resolved to **a8198874889e2fd6706faa2e24eea815df7ba5f2**, which includes THROTTLE-1 and D002-ECHO. The requested normal merge fast-forwarded from `87009eb`; there were **no conflicts**. No UI-POLISH-1 feature code changed during the merge.

Post-merge checks passed: changed/new PHP syntax, **Pint**, **551 frontend tests / 0 skipped / 0 failures** (including all five UI-POLISH-1 tests), and **2 new guardian-label backend tests / 41 assertions / 0 skipped / 0 failures**. The frontend count gains main's eight THROTTLE-1 and D002-ECHO tests over the recorded 543. `git diff --check` passes for tracked and all new files. No existing assertion changed. The recorded full backend and PostgreSQL gates above predate this merge; the owner-requested post-merge replay was targeted.

Only the 14 listed task files form the PR. No environment file, screenshot, private evidence or unrelated change is included. Task-owned browser, preview server and PostgreSQL cluster are stopped. The served tree remains clean on `main` and untouched.

- [x] Owner authorised this commit, branch push and one PR.
- [x] Current main merged without conflict; requested post-merge checks passed.
- [x] No existing assertion changed; receiver-name limit retained.
- [ ] Owner authorises merge. **Do not merge or enable auto-merge.**
