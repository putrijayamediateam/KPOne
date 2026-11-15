# STK-4 — owned stock and expiry review (PR candidate)

Date: 2026-10-28. Branch `feature/stk-4-stock-review`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-stk-4`, fetched main/base `f71baf422bba11704ad973f35554310041641b78`. STK-3 merged in #183 (`de3452f`, including `0df1cfa`). Survey was completed before implementation. The owner approved the exact permission and holders on 2026-10-28. Implementation and validation follow below. Owner subsequently authorised the commit, push and one PR for Job 10; served tree untouched. Not production-approved.

## Survey before building

| Verified existing behaviour | Source at this base |
| --- | --- |
| Reorder levels are per organisation, location and SKU, not one global SKU value or a standalone branch setting. Location determines the branch. Unique identity is organisation/location/SKU; quantity is decimal(15,3), nonnegative. | `database/migrations/2026_09_11_000100_create_inventory_operations.php:224–235,276`; `app/Domain/Organisation/Inventory/Services/InventoryControlService.php:213–224`. |
| Low-stock rows start from saved reorder levels, left-join balances, include zero stock, and compare available quantity <= level. Available means status available and expiry after branch-local today. Active location, SKU and item required. This old query does not exclude branch holds and does not exclude a saved zero level. Rows show location, SKU, generic item name, reorder level and available quantity; cap 100, ordered by SKU and reorder id. | `app/Domain/Organisation/Inventory/Services/InventoryOperationsDirectoryService.php:136–145`. |
| Near-expiry lists positive balances at visible active locations, active SKU/item, status available, expiry after local today and <= today + 90 days. Day 90 is included. It does not exclude branch holds. Rows show SKU, batch, expiry, quantity, location; cap 100, expiry/SKU/balance order. | Same service `:151–161`. |
| Old visibility can include organisation warehouses for actors with warehouse permission. The new review page must stay at the selected branch, excluding branch-null warehouses. | Same service `:16–26`. |
| Existing Inventory operations panel displays these passive lists and “Expiring in 90 days”. No ownership or acknowledgement workflow is present. | `resources/js/components/inventory/InventoryOperationsPanel.vue:1314–1344`. |
| Expired positive stock is absent from near-expiry. It remains inspectable through Inventory's Batch & Expiry directory with All/Inactive filtering, expiry status and quantity; active filtering excludes it. Summary also counts expired positive batches. There is no dedicated always-visible expired-on-hand decision worklist. | `app/Domain/Organisation/Inventory/Services/InventoryDirectoryService.php:145–172,271–283,339–351`; `resources/js/pages/Inventory/Index.vue:428–490`. |
| STK-2 already defines a reusable SQL eligibility query: active SKU/item, status available, expiry strictly after branch-local today, no active hold at this branch. | `app/Domain/Organisation/Inventory/Services/InventoryBatchEligibility.php:17–31`. |
| Existing tests pin low-stock/near-expiry payloads, zero balance inclusion/exclusion and per-location rows, plus inactive references. | `tests/Feature/Clinical/InventoryOperationsTest.php:793–843`; existing directory coverage in `tests/Feature/Clinical/InventoryDirectoryTest.php:206–223`. |

These differences are the survey findings the new authorised worklists must address, not grounds to alter dispensing, receiving or existing stock evidence. Existing old directory assertions remain unchanged pending any separately reported incompatibility. No stock data was inspected or changed.

## Design chosen from the survey

- New branch-scoped Stock review page, three independently searchable/paged lists, 25 rows per page with totals and visible pagination. No unresolved condition hidden by acknowledgement or a fixed 100-row total cap.
- Preserve each existing SKU/location reorder identity; do not silently merge distinct location thresholds into a guessed branch-wide threshold. Show location explicitly. A positive saved level flags eligible quantity <= level, including zero. A zero level means no flag as the owner's new rule requires.
- Reuse `InventoryBatchEligibility::query` for eligible quantities and expiring stock; held quantity is separate. New expired-on-hand list includes all positive branch balances with expiry <= local today regardless of status or hold, until balance is gone. Keep existing Inventory inspection available; “only place” means the dedicated review decision list, not removing existing inventory visibility.
- One named 90-day window constant; day 90 included, today excluded from expiring and included in expired. Use the shared branch-local date definition. No branch setting.
- Append-only acknowledgement evidence and retry evidence, latest review and last-10 history from bounded batch reads, no per-row queries. Codes only: ordered_externally, no_action_needed, monitoring, disposal_requested_external. Required review-by for monitoring/ordered_externally, never later than 14 days ahead; expired review-by shows Review overdue. No stock values in evidence/audit. Omit days-since-first-flagged: current projection and review records cannot prove uninterrupted historical flag duration.
- Fresh permission and effective branch assignment per acknowledgement, transaction, consistent row locks, UUID retry fingerprint and audit-failure rollback. No stock mutation. Stale acknowledgement state must be refused using submitted latest-evidence identity and fresh condition checks; tested with identical/conflicting retry keys and stale versions.
- Reviewer display names are resolved at display time in one organisation-scoped batch selecting name/id only for actors in displayed history. Evidence stores actor ids only; no staff contact or clinical data is read.

## Permission decision — owner approved 2026-10-28

One new permission, approved exactly as proposed: `inventory.stock_reviews.manage.branch`.

Exact holders: **ca_supervisor, finance_officer, director**. No other role: administrator, technical_admin, ca, resident_doctor, panel_officer, queue_display, marketing, hr_manager and business_development receive nothing new.

View reuses `inventory.view.branch` with its unchanged catalogue holders: Director, CA, CA Supervisor, Finance Officer and Administrator. Each acknowledgement additionally requires a currently effective assignment at the selected branch and fresh authority; viewing alone never permits acknowledgement. No role receives a new patient/clinical permission.

Implemented additive replay-safe firstOrCreate grant migration: grant only missing permissions, log and skip missing roles, never revoke, grant down() no-op. The catalogue matches these exact holders. No migration or permission code was written before approval.

## Implementation and files

| File | Change |
| --- | --- |
| `app/Domain/Organisation/Inventory/Services/InventoryStockReviewService.php` | Three live branch worklists; shared eligibility; independent 25-row pagination, escaped search; batched latest acknowledgement and last-10 history; local overdue state; permission/assignment/organisation checks; transactional SKU row lock, fresh condition and review version checks, UUID payload-conflict refusal, values-free audit. |
| `app/Http/Controllers/InventoryStockReviewController.php`, `routes/web.php` | Private no-store view and permission-filtered, throttled acknowledgement POST; explicit own-page redirects; new `{reviewItem}` avoids existing UUID `{item}` binding. |
| `app/Domain/Access/PermissionCatalogue.php` | Exactly the one approved permission and three holders. |
| `resources/js/pages/Inventory/StockReview.vue` | English worklists, visible limits/pagers, acknowledgement dialog, bounded history overlay, overdue state, per-location thresholds and disposal deferral note; clears selection, history and form on branch switch. All new action targets 44 px. |
| `resources/js/pages/Inventory/Index.vue` | Link to Stock review from Inventory. No existing payload change or hub destination change. |
| `tests/Feature/Inventory/StockReviewTest.php` | 12 new backend tests (115 assertions in the scoped run). |
| `tests/Frontend/stock-review.test.mjs` | Three new frontend checks: worklists and branch clearing, transport and explicit redirects, shared eligibility and absence of stock mutations/messaging. |
| `docs/reports/STK-4.md` | This survey, permission decision, safety and validation evidence. |

### Additive migrations

- `2026_11_03_000100_create_inventory_stock_reviews.php`: one new append-only table, `inventory_stock_reviews`. IDs reference organisation, branch, SKU, location, optional batch and reviewer. Controlled worklist/outcome, review-by date, per-item version, UUID retry and hash, UTC creation time. No quantity snapshot. Unique actor retry and item/version constraints; PostgreSQL validates scope, source identity and successive version and refuses update/delete/truncate. `down()` retains evidence. No existing schema, balance or movement changed.
- `2026_11_03_000200_grant_inventory_stock_review_permission.php`: approved replay-safe grant only; no revocation. Existing databases will need `php artisan migrate` if this candidate is later released.

## Every changed existing assertion

**None.** No existing test file or assertion changed, including permission exact-set checks, Clinic management hub map, Inventory payload checks and PostgreSQL cleanup. The new destination is linked inside Inventory, so no exact hub map change is needed. No existing test weakened, removed or newly skipped. The new exact-holder test pins all catalogue roles.

## Safety and test coverage

`StockReviewTest` covers all catalogue roles for view/acknowledge; exact holders and replay-safe migration (including missing-role log); fresh role revocation; ended assignments; branch change, foreign branch and organisation; retry replay/conflict and stale version; zero balances with a positive level and zero-level exclusion; held/unavailable stock excluded from eligible quantity; separate held quantity; strict shared expiry and inclusive day 90; local midnight; expired stock still shown even when item/SKU/location inactive, batch damaged and held; removal only when positive stock is gone; acknowledgements never hiding an item; last-10 history and retained earlier evidence; local overdue state; review-by bounds, required dates, controlled outcomes; pagination beyond 25, search and no repeated query per row; explicit redirects and no-store; PostgreSQL immutability and scope refusal; full ID-ordered before/after comparison of **all unrelated tables**, preserving prior audit rows; exact values-free audit metadata; audit-failure rollback.

The future disposal result test changes only a synthetic balance projection directly in its fixture to prove that a zero balance disappears; no write-off implementation or claim that STK-5 exists. Stock review contains no stock update/delete, movement posting, notification, job or HTTP client path. It appends only its declared review evidence and one audit event. The acknowledgement permission alone grants no authority over any stock or clinical record.

### Bounds and query evidence

- 25 per worklist page; each has independent search/page state, total and previous/next, no fixed total-result truncation. Maximum 75 displayed rows and 10 history records each. History query uses row-number partitioning; one batched names-only actor lookup.
- New test `test_paging_search_no_hidden_unresolved_rows_and_constant_batch_queries`: one versus 28 items has identical query count; domain page query ceiling **20**, all 28 low-stock items reachable over two pages with unique ids.
- Synthetic preview measurement: **14 warmed domain-page queries**, including all three lists, counts, histories and names (`/private/tmp/kpone-stk4-evidence/query-proof.json`). Cold middleware/session requests can add constant authentication/context queries; this is not a whole HTTP-request budget claim. No per-row queries.

## Validation

Task-owned PostgreSQL **18.6**, **127.0.0.1:58674**, cluster `/private/tmp/kpone-stk4-pg18`. Separate UTF8/template0 databases: `kpone_stk4_testing` (tests/CI), `kpone_stk4_seed` (fresh seed), `kpone_stk4_preview` (synthetic Chrome). Effective configured host/port/name, actual database, encoding, PG version and owned data directory were verified before application use. No test compares PostgreSQL's server address to its configured host. No .env or .env.preview opened or created; explicit placeholder environment and approved task-only **1 GiB** PHP configuration. Dependencies copied independently; no new dependency.

Test and preview storage roots are separate under `/private/tmp/kpone-stk4-evidence/`; the existing isolated test-storage helper remains untouched. No test runs against preview storage or database.

Early syntax and PHPStan level 7 pass. New-code defects found and corrected during validation: strict boolean checks for nullable query results; guarded model creation in a synthetic fixture; replaceable new PostgreSQL function for repeated fresh migration; route-parameter collision with existing item binding; missing temporary view cache directory; new-page/test lint formatting. No existing assertion required a change. First full CI attempt stopped at new-page lint errors before backend; fixed only new-page formatting, then restarted exact sequence. Chrome found the shared dialog's 16px default close target: disabled that optional control on this page and used 44px Cancel/Close history controls, followed by fresh types/build/screenshots. No shared component edit.

**Full gate passed, exit 0.** Exact workflow sequence replayed: Wayfinder generation; `node --test tests/Frontend/*.test.mjs`; `php artisan migrate --force`; `npm run build`; exact `composer ci:check` (npm lint/format/types, config clear, Pint, PHPStan level 7, full backend). Then full frontend glob and separate fresh seed. The final dialog close-target adjustment was frontend-only; types, lint, format, full frontend tests and build were rerun on that final UI. No backend source/test/schema changed during the long backend run.

| Check | Actual result / log in `/private/tmp/kpone-stk4-evidence/` |
| --- | --- |
| Full backend | **1,533 passed / 30,956 assertions / two existing public-registration skips** (1,535 reached), **765.468 s**, `full-ci.log` |
| Exact CI sequence | Passed, exit 0, `full-ci.log`; no PostgreSQL skip |
| CI frontend behavior tests | **516 passed**, no skips, `full-frontend-ci.log` |
| Full frontend gate on final UI | **516 passed**, no skips, **2.367 s**, `final-ui-frontend.log` (also `full-frontend-gate.log`) |
| PHP syntax | All new/changed PHP files pass `php -l` |
| Pint / PHPStan level 7 | Passed; zero static errors, `early-pint-final.log`, `early-phpstan-final.log`, `full-ci.log` |
| Frontend lint / format / types on final UI | Passed, `final-ui-lint.log`, `final-ui-format.log`, `final-ui-types.log` |
| Production build on final UI | Passed, **1.68 s**, `final-ui-build.log` (exact CI build also passed, 1.46 s) |
| Separate `migrate:fresh --seed --force` | Passed on **kpone_stk4_seed**, `fresh-seed.log`; database verified before execution |
| `git diff --check` | Passed, including new-file whitespace checks |
| New backend scoped tests | **12 passed / 115 assertions / 7.254 s**, `new-tests-5.log` |

No existing assertion, fixture, expected value, test name, timeout or skip changed. No PostgreSQL cleanup isolation change needed. All existing receiving, hold, dispensary, billing and document suites ran unchanged in the full suite. The sole new permission is operational and does not change staff-authority rules. Owner authorised commit, push and one PR after reviewing the candidate. No merge authorised.

## Chrome evidence

Real Chrome, synthetic `.test` accounts and synthetic stock only. Private screenshots outside the repository: `/private/tmp/kpone-stk4-evidence/screenshots/`. Sixteen final named screenshots are indexed with byte sizes and hashes in `/private/tmp/kpone-stk4-evidence/screenshots-manifest.json`. No screenshot attachment or private storage file is part of the candidate diff.

For each combination `390-light`, `390-dark`, `1440-light`, `1440-dark`:

- `<combination>-lists.jpg`: all three worklists, held and zero-balance low stock, day-90 expiry, expired-on-hand and overdue acknowledgement.
- `<combination>-dialog.jpg`: acknowledgement dialog; exact 390×844 or 1440×1000 CSS viewport captures.
- `<combination>-history.jpg`: bounded history and overdue evidence, with final 44px Close history control.
- `<combination>-denied.jpg`: role without Inventory view permission (captured; standard Laravel 403 page retains its own theme styling).

Browser verification: 390px viewport and document width both **390**, no sideways overflow; stable Cancel and Save controls each **44px** high and inside the viewport. Synthetic acknowledgement saved through UI; held item remained listed with new reviewer/date. No raw HTML rendering. Viewport captures use explicit clip dimensions because the browser's un-clipped screenshot returned a scaled viewport; full-page worklist captures retain actual CSS width. Final screenshots, not earlier 16px-close captures, are retained at the named paths.

## Pre-PR base and 390px navigation investigation

Fetched `origin/main` again at the owner's PR request. It remains **f71baf422bba11704ad973f35554310041641b78**; no rebase or gate rerun is required by that instruction. The changes since the passing gate are this documentation and fresh private screenshots only. No application, schema, test or permission change.

**The navigation below the title in the earlier modal image is a screenshot artifact, not a layout bug.** Confirmed on the normal page at 390×844, with no dialog and scrollY 0: the sticky app header spans viewport **y=0–53**, and the Stock review title spans **y=137–169**. Document width is 390, matching the viewport. The header precedes the main content in the existing ClinicLayout DOM.

Clicking Acknowledge scrolls the page to the button (observed scrollY **179**). The sticky header stays at viewport **y=0–53**; the title is then above the viewport (**y=-42–-10**), and the fixed modal spans **y=198–646**. The old screenshot clip incorrectly began at document y=0 while the page was scrolled. That combined earlier document content with the header painted at its scrolled sticky position, making the navigation appear below the title. Capturing the actual viewport with clip y=scrollY shows the correct top navigation. No layout code fix is needed or applied.

Additional local proof:

- `/private/tmp/kpone-stk4-evidence/layout-proof.json`: measured DOM rectangles with/without modal.
- `screenshots/390-layout-no-dialog-viewport.jpg`: normal dark page, navigation above title.
- `screenshots/390-layout-no-dialog-light.jpg`: normal light page, navigation above title.
- `screenshots/390-layout-dialog-viewport.jpg`: corrected scrolled modal viewport.
- `390-dark-dialog.jpg` and `390-light-dialog.jpg` replaced with correctly offset viewport captures. The private screenshot manifest hashes were refreshed. Earlier history/full-page screenshots are document captures; they must not be used to infer viewport placement of a sticky header.

All files above are private synthetic review artifacts outside the repository. Only this report is committed from the evidence. No real data or .env access. Existing recorded gate results are quoted, not rerun.

## Limits, open questions and deferred work

- Existing thresholds remain per location/SKU; no guessed branch-wide sum of unrelated thresholds. New review page excludes branch-null warehouses. No setting introduced.
- Day 90 included, today belongs to expired; window is `InventoryStockReviewService::EXPIRY_WINDOW_DAYS = 90`. Review-by is today through 14 days ahead. No date required for the other two outcomes, but any supplied date follows the same bounds.
- No first-flagged age claim: no evidence of uninterrupted historical condition. Review history is last 10 displayed; all earlier evidence retained. Reviewer names are current display names, not frozen identity evidence.
- No alert channel, automatic order, write-off, disposal, transfer, supplier return, cost, valuation or import. Acknowledgement does not fix or hide a stock condition, and external outcomes prove no external action. STK-5 to STK-10 remain unauthorised. Existing inspection screens and old passive lists remain unchanged; this slice introduces the dedicated owned review page.
- Regulated/controlled items, professional responsibility, registers and retention remain **to confirm with a pharmacist or the regulator**. This slice encodes no professional answer or legal retention period.
- Task preview server and owned PostgreSQL cluster stopped after validation. Only the three named root databases remained; all regression child databases were dropped by their tests. Served tree remains clean on main and untouched. Owner authorised commit, push and one PR for Job 10. Do not merge. Not production-approved.
