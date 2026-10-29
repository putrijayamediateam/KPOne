# STK-5 — independent count and write-off approval (uncommitted candidate)

Date: 2026-10-28. Isolated worktree `/Users/klinikputrijaya/Herd/KPOne-stk-5`, branch `feature/stk-5-count-writeoff`, base/fetched `origin/main` **0d78ede3538e4585535ab106363cbdb88bf2ff60** (STK-4 merged in #185). Survey completed on 2026-10-28 before building. On 2026-10-29 the owner approved the exact permission/holders and the new-path approach, explicitly requiring legacy demo behaviour and tests to remain unchanged. Implementation and actual validation are recorded below. No .env or .env.preview accessed. No commit, push or PR. Not production-approved.

## Survey before building

All paths below are relative to this branch at the stated base. No real stock or personal data inspected.

| Existing behaviour | Verified source |
| --- | --- |
| Create a stocktake at an authorised active location; require materialised balances. Transaction locks actor/assignment, location and every existing balance ordered by id; creates draft header plus one line per balance. Each line freezes expected quantity and balance lock_version. This is a whole-location, currently unbounded snapshot. | `app/Domain/Organisation/Inventory/Services/InventoryControlService.php:40–64`. |
| Lifecycle draft → counting → review → posted, with cancellation. Counts must supply every snapshot line once; unknown line ids refused. Physical/variance values are saved into existing mutable lines, using float conversion/number_format. No independent recount or approval record. | Same service `:67–99,144–147`; `app/Domain/Organisation/Inventory/Models/InventoryStocktake.php`; `InventoryStocktakeLine.php`. |
| Post checks header lock_version, source key set, expected balance version and expected quantity, then posts gain/loss movements and marks posted. Reposting is refused by status. The same stocktake permission gates count and post; there is no counter-versus-poster independence check. Current quantity comparison uses float tolerance. | Same service `:101–141,259–267`. |
| Manual adjustment posts immediately under adjustment permission: in/out, positive quantity, correction/damage/found_stock/count_variance/other, optional bounded reason_note, UUID idempotency/hash. No independent proposal approval or disposal evidence. This outer input normalization uses floats. | Same service `:31,150–208`. |
| Existing HTTP controller invokes these domain methods and supplies expected branch and header versions; no separate governance layer is provided by the UI. | `app/Http/Controllers/InventoryOperationsController.php:245–308`. |
| Existing `inventory.stocktake.branch` and `inventory.adjust.branch` are granted to **ca_supervisor only** in the default catalogue. Director and Finance Officer do not implicitly hold them. Existing view permission is Director, CA, CA Supervisor, Finance Officer and Administrator. | `app/Domain/Access/PermissionCatalogue.php:115–135,161–310` (stocktake/adjust role entries `:288–289`); no additional stocktake/adjust grants found in app/migrations/seeders. |
| Movement directory allows ten types: opening_balance, transfer, dispense, purchase_receipt, transfer_dispatch, transfer_receipt, stocktake_gain, stocktake_loss, adjustment_in, adjustment_out. | `app/Domain/Organisation/Inventory/Models/StockMovement.php:15–33`; `app/Domain/Organisation/Inventory/Services/InventoryDirectoryService.php:20–30`. |
| Report derives signed branch quantity from source/destination. Opening is before-range; purchase_receipt → Received; dispense → Dispensed; the three transfer types → Transferred; all other **allowed** in-range types (including opening entries, stocktake gain/loss and adjustments) → Adjusted. Unknown type count causes validation refusal, not silent categorisation. | `app/Domain/Shared/Services/StockMovementReportService.php:42–64`; existing movement directory projection in `InventoryDirectoryService.php:175–210`. |
| Shared movement posting already uses exact STK-1 thousandths, locked/versioned balance changes and range/negative checks. Movement quantity is positive; source/destination sets direction. Each new movement snapshots base/input unit, direction, factor 1.000 and precision 3. Models and PostgreSQL guard movement immutability. | `app/Domain/Organisation/Inventory/Services/InventoryMovementService.php:157–208,246–269,287–323`; `StockUnitQuantity.php:9–57`; `database/migrations/2026_09_04_000100_create_dispensary_inventory_foundation.php:355`; `2026_09_11_000100_create_inventory_operations.php:278`. |
| `recordStocktakeVariance` ignores zero and dispatches exact gain/loss through the shared credit/debit engine. `recordAdjustment` dispatches in/out; helpers check tenant/reference identity and active SKU/location, but do not require batch dispensing eligibility or absence of a branch hold. Held/expired batches can therefore be debited without releasing a hold. | `InventoryMovementService.php:163–215`. Inactive SKU/location refusal is distinct from a held or expired batch; no new exemption is authorised here. |
| STK-4 expired list reads current branch balances > 0 and expiry <= branch-local today, irrespective of hold/status/reference activity. A posted debit to zero naturally removes the batch/location row; acknowledgement is not involved. | `app/Domain/Organisation/Inventory/Services/InventoryStockReviewService.php:49–63`. |

### Existing tests and workers surveyed

- `tests/Feature/Clinical/InventoryOperationsTest.php:625–673`, `test_stocktake_posts_gain_and_loss_once_and_rejects_stale_snapshot`: the same CA Supervisor creates, counts and posts, checks posted state/balances/gain/loss and stale-source refusal. This pins the demo behaviour; do not silently change it or weaken stale checks.
- Same file `:802–843` and `test_adjustment_idempotency_replays_once_and_rejects_changed_intent` cover immediate adjustments, unchanged replay and conflicting intent.
- `tests/Feature/PostgresDispensaryInventoryRegressionTest.php`, `test_concurrent_adjustment_replay_posts_one_adjustment_and_movement` (`:937`) and stocktake-post scenarios cover transaction contention and source races. The class has owner-approved disposable child isolation from STK-3; leave it unchanged unless an explicitly permitted new change is necessary.
- `tests/Support/PostgresDispensaryInventoryWorker.php:87–96,256–266` runs adjustment-hold, adjustment-replay and stocktake-post on explicit worker connections. Do not change its existing contention protocol, assertions, timeouts or cleanup. New STK-5 workers must verify their own unique UTF8 child database, never compare server addresses/ports to client endpoints.

## Survey differences and the owner-approved boundary

The existing **demo** path does not meet the new defaults: same-person posting, no recount/independent approval evidence, mutable count lines and float arithmetic in the count/adjustment orchestration. It cannot be treated as the approved STK-5 lifecycle unchanged. The owner approved a separate governed path on 2026-10-29 and expressly prohibited retrofitting these old routes/services/tests. Existing tests remain unchanged; their actual regression results follow below.

The proposed safest narrow implementation is a new governed evidence path for new STK-5 counts/proposals only, reusing the snapshot/version design and shared movement engine. Existing historical stocktake rows, old movement rows, receiving, holds and dispensing remain untouched. The old parked demo entry points would retain their current behaviour unless the owner separately authorises gating or conversion; the new approval policy must not be claimed to cover those legacy routes. That boundary is a mandatory known limit in this report and any future PR. The owner has not authorised a follow-up to disable, hide or replace the old demo path.

## Design chosen for the new governed path

1. **Snapshot/count evidence:** use the existing grain (branch/location/SKU/batch), expected quantity and balance version, key identity checks and header optimistic version pattern. New append-only snapshot, count and action evidence for new governed records; do not rewrite old stocktake headers/lines. Select bounded targets, 25-row UI pages; do not copy an unbounded whole-location write loop. Exact STK-1 scaled integers/format only, including zero physical counts; signed differences computed without floats.
2. **Unexpected batches:** refuse any count/recount batch/location/SKU key outside the frozen snapshot with a clear reload/new-snapshot response. No automatic batch creation, guessed mapping or silent extension. A changed source key or version invalidates the proposal before posting.
3. **Non-zero variance:** append the first counter's result and a recount by a different person; never overwrite first evidence. Recount is authoritative for proposed physical quantity; discrepancy between counts remains visible. Do not post any non-zero proposal without the independent recount. A genuinely zero variance closes with evidence and no movement or approval; never auto-close a non-zero first count before its required recount.
4. **Approval separation:** reuse stocktake permission for initial count and recount, adjustment permission for write-off proposal. Approver needs the new permission below and must differ from original counter, recounter and proposer. Counting requires two distinct CA Supervisor accounts; approval may be a third independent CA Supervisor, Finance Officer or Director. No CA or Director counting grant is implied. Staff availability for this separation needs owner confirmation, not an extra role grant.
5. **Write-off evidence:** controlled expired/damaged/contaminated/other_approved reason and bounded plain-text disposal reference. The reference is evidence of what was recorded, not proof of physical disposal; never audit the text. Approval and movement post in one transaction, SKU → batch → balance locks, exact available quantity/negative/overflow check, applied-unit snapshot and proposal link. No hold release or dispensing-rule change. Correction must be a new, approved linked reversal, never editing earlier movement/proposal evidence.
6. **Movement reuse:** reuse existing stocktake_gain/loss for approved count differences where source references can be safely parameterised. Prefer two additional outbound types only if needed for the new proposal link: expiry_writeoff for expired reason and stock_writeoff for the other controlled reasons. Disposal is a reference, not an invented third physical-disposal event. Any helper extraction must preserve existing callers and tests; shared balance arithmetic and unit snapshots remain the single engine.
7. **Reporting:** explicitly add the chosen new types to the directory and PostgreSQL shape allowlist; classify them as Adjusted and label that on the report page. Keep the unknown-type guard strict. Test zeroing an expired batch through the approved posting removes its STK-4 live row, without rebuilding that projection.
8. **Evidence/authority:** transaction, fresh permission/organisation/effective branch, canonical locks, independent lock_version, UUID identical retry/conflicting-payload refusal, values-free mandatory audit and PostgreSQL append-only protections. Additive migrations, no backfill/dependency. Tests must compare all unrelated rows and prove reconciliation/concurrent negative-stock refusal.

Implementation below uses these choices; the shared movement engine is retained. No document framework, second stock engine or old-row conversion.

## Permission decision — approved by owner 2026-10-29

**One** new permission, approved exactly: `inventory.variances.approve.branch`.

**Current exact holders: ca_supervisor, finance_officer and director ONLY.** Owner decision on 2026-10-29 supersedes the earlier two-holder decision. CA, Administrator, Technical Admin, Resident Doctor, Panel Officer, queue_display, HR Manager, Marketing and Business Development receive no variance approval permission. Separation of duties is unchanged: approver differs from counter, recounter and proposer in both domain service and PostgreSQL, including retry attempts.

| Action in new governed flow | Permission | Existing/proposed exact holders |
| --- | --- | --- |
| Count and independent recount | `inventory.stocktake.branch` (reuse) | ca_supervisor only; distinct counter and recounter |
| Propose write-off | `inventory.adjust.branch` (reuse) | ca_supervisor only |
| Approve variance/write-off | `inventory.variances.approve.branch` (new, owner approved) | ca_supervisor, finance_officer, director only; approver must differ from counter, recounter and proposer |
| View new pages | `inventory.view.branch` (reuse) | director, ca, ca_supervisor, finance_officer, administrator; no mutation implied |

Every action needs fresh backend authority and a currently effective assignment at the branch. Approval permission grants no general ability to count, edit or bypass independent evidence. Replay-safe additive firstOrCreate grant migration, give only missing grants, log and skip missing roles, never revoke; grant down() no-op. Permission and migration implementation began only after approval.

## Implementation

| File | Scope |
| --- | --- |
| `app/Domain/Organisation/Inventory/Services/GovernedStockService.php` | New snapshot/count/recount/write-off/reversal lifecycle. Fresh authority, tenant/effective branch, transaction, actor/case locks then canonical SKU → batch → balance source locks; exact integer thousandths; expected source quantity/version/base unit; case action version; UUID fingerprint/replay; mandatory values-free audit. Live bounded page projection. |
| `app/Http/Controllers/GovernedStockController.php`, `routes/web.php` | Separate private no-store GET/POST endpoints; explicit own-page validation redirects; throttle 30/minute for mutations. No legacy route change. |
| `resources/js/pages/Inventory/GovernedStock.vue` | New English count and write-off screens, 25-row case and target paging/search, status/next action, snapshot/count/recount/approval/proposal dialogs, read-only evidence history, reversal proposals; permission-filtered controls; branch switch clears forms/history. 44px controls. |
| `resources/js/pages/Inventory/Index.vue` | Additive links to the two new pages only. Existing legacy controls/panel remain untouched. |
| `app/Domain/Organisation/Inventory/Services/InventoryMovementService.php` | One additive `recordGovernedChange` method calls the existing exact credit/debit/balance/movement engine. All pre-existing contents are byte-identical when this added method is excluded. No receiving/dispensing/legacy adjustment or stocktake method changed. |
| `StockMovement.php`, `InventoryDirectoryService.php` | Add `expiry_writeoff` and `stock_writeoff` constants, labels and exact allowed types. Count differences reuse stocktake_gain/loss; reversal uses adjustment_in/out. All new movements link to `inventory_governed_case` and snapshot applied base/input unit, factor 1.000 and precision 3. |
| `resources/js/pages/Reports/BranchReport.vue` | Preserve the existing opening-entry wording; add that approved count variances, write-offs and reversals are in Adjusted. Existing report computation and invalid-type guard remain unchanged. |
| `app/Domain/Access/PermissionCatalogue.php` | Exactly the approved approval permission for ca_supervisor/finance_officer/director. Counting, recounting and proposing retain their existing CA Supervisor-only grants. |
| `tests/Support/GovernedStockFixture.php` | New synthetic fixture with explicit branch and actual source versions; no unordered first-branch query. |
| `tests/Feature/Inventory/GovernedStockTest.php` | 18 new tests covering the governed domain, roles, evidence, precision, retries, scopes, audit and projections. |
| `tests/Feature/Inventory/PostgresGovernedStockTest.php`, `tests/Support/PostgresGovernedStockWorker.php` | New isolated child-database, separate-process dispensing/approval race in both winner orders. |
| `tests/Frontend/governed-stock.test.mjs` | Three new UI/transport/shared-rule checks. |

### New state/evidence model

Each new count freezes **one existing balance tuple**, its source quantity, version and base stock unit. No unbounded whole-location snapshot loop; no insertion of unexpected batches. A count request cannot substitute batch/SKU/location/line identities. Source changes refuse further action and require a new snapshot; stale cases are retained as evidence.

- `created → awaiting_count → counted`: exact physical count. Zero variance closes `closed_zero`, no approval and no movement.
- Non-zero first count → `awaiting_recount`. A different counter must append the recount; the original is retained. If the independent recount equals the snapshot, it closes zero without movement; otherwise `awaiting_approval`.
- Variance approval posts the difference between the authoritative recount and frozen source. The approver differs from proposer, counter and recounter, even if someone has an individual approval grant in addition to their normal role.
- Write-off proposal requires exact positive quantity no greater than on hand, one of expired/damaged/contaminated/other_approved and a bounded plain-text disposal reference. Expired reason requires expiry <= local today. No clinical free text. Approval requires a different person and unchanged source; held stock is allowed and the hold is retained.
- Reversal is a new proposal for the exact quantity of one approved original and needs a new independent approval; original rows/movements are untouched. One reversal proposal per original, no reversal-of-reversal. Credit overflow or debit below zero refuses atomically. Reversal proposals appear on Write-off (governed), including reversals of count movements.

There is **no variance threshold**. Every non-zero first count requires an independent recount. Final positive/negative variances require independent approval. No cost or valuation policy. A disposal reference does not prove disposal.

### Additive migrations

- `2026_11_04_000100_create_governed_stock_evidence.php`: **two new append-only tables**, `inventory_governed_cases` and `inventory_governed_actions`. Restrictive FKs, frozen source/unit, controlled kinds/reasons/actions/states, review actors, independent transitions, case/action versions and UUID replay evidence. PostgreSQL refuses update/delete/truncate; scope and approval quantity/movement linkage enforced. A deferred constraint trigger requires matching approved evidence for governed movements at transaction commit. Existing movement shape constraint retains all old alternatives and adds only the two outbound write-off types. Down retains evidence/types; no backfill or historical rewrite.
- `2026_11_04_000200_grant_inventory_variance_approval.php`: additive firstOrCreate; missing roles logged/skipped, missing grants only, no revoke, down no-op. Catalogue agrees.

Existing databases will need `php artisan migrate` if later released. No dependency added.

## Every changed existing assertion and fixture

**None.** No existing test file, assertion, expected value, name, timeout, skip or fixture/isolation setup changed. New test infrastructure only. Exact permission/hub expectations did not need edits; new pages are linked inside Inventory, not added as hub destinations. No existing cleanup failure has been observed so far; any such failure requiring change remains a stop for owner approval.

Read-only comparison confirms these files byte-identical to main: `InventoryControlService.php`, `InventoryOperationsController.php`, `resources/js/components/inventory/InventoryOperationsPanel.vue`. Removing the single new method from `InventoryMovementService.php` yields the original file byte-for-byte. All legacy route definitions remain intact.

## Mandatory known limit — pilot-blocking legacy path

> the legacy demo stocktake and manual adjustment path still permits same-person count and post and bypasses the independent approval; it must be disabled or hidden for the pilot in a separate slice.

The owner has named the required follow-up **STK-6A — close the legacy stock-adjustment and stocktake bypass**. It remains **pilot-blocking**; STK-6A is not implemented by STK-5.

The owner expressly requires keeping it untouched in this PR. The warning is also visible on both new screens. **The new approval policy covers only the new governed routes. It does not secure the legacy path.** No follow-up implementation is authorised here.

| Exact legacy entry point | Route name / controller action | Screen and permission |
| --- | --- | --- |
| GET `/inventory` | `inventory.index`, `InventoryController::__invoke` | `resources/js/pages/Inventory/Index.vue` hosts `resources/js/components/inventory/InventoryOperationsPanel.vue`; existing Stocktake and manual adjustment controls. View: `inventory.view.branch`. |
| POST `/inventory/stocktakes` | `inventory.stocktakes.store`, `InventoryOperationsController::createStocktake` | Old Stocktake section; `inventory.stocktake.branch`. |
| POST `/inventory/stocktakes/{stocktake}/start` | `inventory.stocktakes.start`, `startStocktake` | Same permission. |
| POST `/inventory/stocktakes/{stocktake}/count` | `inventory.stocktakes.count`, `countStocktake` | Same permission. |
| POST `/inventory/stocktakes/{stocktake}/post` | `inventory.stocktakes.post`, `postStocktake` | Same permission; same-person posting remains possible. |
| POST `/inventory/stocktakes/{stocktake}/cancel` | `inventory.stocktakes.cancel`, `cancelStocktake` | Same permission. |
| POST `/inventory/adjustments` | `inventory.adjustments.store`, `adjustment` | Old manual adjustment controls; `inventory.adjust.branch`. |

Both mutation permissions remain **ca_supervisor only** in the default catalogue. Backend services are `InventoryControlService::{createStocktake,startCounting,recordCounts,postStocktake,cancelStocktake,adjust}`. Old rows are `inventory_stocktakes`, `inventory_stocktake_lines`, `inventory_adjustments`. The later pilot-gating slice must target both UI and direct backend endpoints; hiding a button alone would not close the bypass. That work is not built here.

## Validation actually performed

Task-owned PostgreSQL **18.6**, **127.0.0.1:58684**, cluster `/private/tmp/kpone-stk5-pg18`. Root databases created UTF8/template0: `kpone_stk5_testing`, separate `kpone_stk5_seed`, separate `kpone_stk5_preview`. Effective configured host/port/name, actual database, encoding and owned cluster directory verified before application use. No .env or .env.preview; explicit placeholders; approved task-only **1 GiB** PHP. Independent dependency copies, no new dependency. Preview/test storage roots are separate under `/private/tmp/kpone-stk5-evidence/`; existing isolated-test-storage helper unchanged.

- New governed + PG race suites: **18 passed / 269 assertions / 11.149 s**, `new-tests-complete.log`.
- New race standalone: **1 passed / 33 assertions / 2.173 s**, `new-concurrency-first.log`. Parent holds its winning transaction open, worker is observed blocked on a PostgreSQL lock, then commits; worker refuses. Both approve-first and dispense-first tested, exactly one new 10.000 outbound movement, zero final balance, no old movement change and winner audit count exactly one. Child database created uniquely as `kpone_stk5_race_<random>_testing`, explicit connections for both processes, actual child name and UTF8 verified before migrations/work; always dropped in finally, worker stopped on failure/timeout. No server-address/host/port comparison. CI's postgres role can create/drop databases, as the existing STK-2/3 isolated tests already require.
- Unchanged legacy Inventory Operations, Inventory Directory and Stock Movement Report suites: **34 passed / 1,187 assertions / 16.494 s**, `existing-inventory-first.log`.
- Full frontend so far: **519 passed / zero skips / 2.341 s**, `frontend-final.log`.
- All 13 new/changed PHP files pass `php -l` (`final-php-l.log`). Syntax/Pint/PHPStan level 7/types/lint/format pass early. New-code issues fixed without existing assertion changes: expected balance versions must come from fixtures rather than a guessed initial version; JSONB key order in a new unit-snapshot assertion; imports/response shape in new tests; a cast-aware expiry access; new UI text whitespace matching. No production limit changed.
- Fresh fetch before gate confirms main/base still `0d78ede3538e4585535ab106363cbdb88bf2ff60`; no rebase needed.
- Final source review preserves case-before-source locking for reversal proposals and renders the frozen unit from evidence rather than the live SKU. A redundant new-code null check found by PHPStan was removed; PHPStan then passed without suppression. The full exact sequence is repeated on these final source bytes.
- First complete exact CI/gate run passed: **1,551 backend passed / 31,231 assertions / two existing skips / 845.885 s**; **519 frontend passed / zero skips / 2.554 s**. Pint, PHPStan level 7, types/lint/format, production build, migrate, and separate `migrate:fresh --seed` passed (`full-*.log`). No test weakened, removed or newly skipped. The first result is retained separately from final-byte validation.


### Automated source gate — retained; 403 fix and Chrome evidence verified below

Final exact workflow command sequence: `php artisan wayfinder:generate --with-form --no-interaction`; `node --test tests/Frontend/*.test.mjs`; `php artisan migrate --force`; `npm run build`; `composer ci:check`. Composer runs frontend lint, format, types; configuration clear; Pint parallel test; PHPStan level 7 with 1 GiB; then `php artisan test`. The owner-approved task-only PHP 1 GiB setting is explicit throughout. Then the full frontend glob and the separate seed verification run.

| Final check | Actual result / local log |
| --- | --- |
| `php -l`, all new/changed PHP | 13 files passed; `final-php-l.log`. |
| Exact `composer ci:check` | **1,551 backend passed / 31,231 assertions / two existing skips / 789.411 s**; `final-ci.log`. Total 1,553 reached including the two existing public-registration skips. |
| Pint, PHPStan level 7, types, lint, format | Passed within exact CI; no suppression or baseline added. |
| CI frontend glob and full `node --test tests/Frontend/*.mjs` | **519 passed / zero failures / zero skips**; full-glob duration **2.353 s**, `final-frontend-ci.log`, `final-frontend-gate.log`. |
| Production build | Passed, **1.82 s**, `final-build.log`. |
| Migrate on owned test database | Passed, `final-migrate.log`. |
| Separate `kpone_stk5_seed` `migrate:fresh --seed --force` | Passed; verified owned host/port/database/UTF8 before use, `final-fresh-seed.log`. |
| `git diff --check` | Passed, including new-file whitespace check at handoff. |
| Existing assertion/fixture changes | **None**. No existing test weakened, removed or newly skipped. |
| Required Chrome screenshots | **Complete, 2026-10-29**: 24 workflow captures plus 8 denial captures (both governed pages) at 390/1440 CSS px in light/dark. The 403 theme defect was fixed and reverified; see below. |

Before the subsequently authorised 403 view fix, there were no source changes after the final repeat; only report updates. The served tree remains clean on `main`. The synthetic preview server and task-owned PG18 cluster were stopped after validation; databases/storage were retained, not deleted. No other service, database or worktree was altered. Logs and synthetic review state remain private under `/private/tmp/kpone-stk5-evidence/`.

The full-table regression compares every unrelated table in ID order (stable full-row ordering for keyless pivots), preserves existing movement/audit rows and unrelated balances, and permits only the two declared evidence tables, audit additions, expected movement and its balance update. Audit failure at snapshot/count/recount/approval rolls back, including the proposed movement. Audit metadata keys are exactly case_id/action_id/lock_version/state; no counted quantity, disposal text, clinical or other content.

Additional new tests pin exact roles and fresh revocation, effective/ended branch assignments, foreign branch/organisation, self-approval and same-person recount refusal, zero closure, exact positive/negative count deltas, unchanged source/base unit, unexpected count keys, precision/overflow, replay/conflicting UUIDs, stale versions, append-only SQL, known report classification, unknown type refusal, held counts/write-offs, reversal noninterference and STK-4 expired list removal only after balance reaches zero.

### Bounds

Targets and cases each paginate **25** with search, totals and previous/next; status filter for cases. No fixed total-result truncation. At most four evidence actions per count case (two per write-off/reversal), so displayed history stays bounded without discarding earlier evidence. New test proves one versus 28 cases has identical query count, both lists have 25+3 reachable rows, unique full coverage and search beyond page one; **20-query domain-page ceiling**. Synthetic warmed preview measurement: **13 queries**, `query-proof.json`; cold authentication/session context can add constant queries. No per-row database lookup.

## Chrome evidence — complete 2026-10-29 after authorised 403 fix

Chrome extension access was restored (Putrijaya profile). Verification used only the isolated worktree on `feature/stk-5-count-writeoff` and the retained fictional `.test` accounts. No application code, existing test, assertion, dependency or migration changed during the initial evidence-only resume. The subsequently authorised 403 fix is recorded below.

### Owned environment verified before use

Restarted only PostgreSQL 18.6 at `127.0.0.1:58684` with data directory `/private/tmp/kpone-stk5-pg18`. Before application use, checked effective Laravel connection host/port/database and actual `current_database() = kpone_stk5_preview`, `server_encoding = UTF8`, owned `data_directory`, and PostgreSQL 18 version. Rechecked actual database identity in the final read-only reconciliation. No other database was used, reseeded or migrated.

The synthetic PHP preview ran at `http://127.0.0.1:8884` using `/private/tmp/kpone-stk5-evidence/preview-storage`, file sessions and explicit placeholder configuration. A temporary router under the evidence directory explicitly redirected environment loading to an empty directory and `disabled` filename before bootstrap; neither `.env` nor `.env.preview` was opened. The router served this worktree's existing production assets; it did not alter source or build assets. PostgreSQL shared-memory startup needed sandbox escalation, which was approved. No served-tree access or changes.

### Captures and actual browser actions

Real Chrome screenshots, not headless substitutes or fabricated images. Viewport override was verified from the page as **390 × 844** and **1440 × 1000 CSS px**. Light/Dark were selected through the preview's Appearance UI, and workflow captures verified the document's actual dark class. The desktop snapshot was recaptured after responsive reflow. Measurements and original JPEGs are under `/private/tmp/kpone-stk5-evidence/`.

| Required state | 390 light/dark | 1440 light/dark | Evidence filename suffix |
| --- | --- | --- | --- |
| Count snapshot confirmation | Captured / captured | Captured / captured | `count-snapshot.jpg` |
| First count, populated 8.000 | Captured / captured | Captured / captured | `count-entry.jpg` |
| Independent recount, populated 7.500 | Captured / captured | Captured / captured | `recount.jpg` |
| Independent variance approval | Captured / captured | Captured / captured | `variance-approval.jpg` |
| Expired write-off proposal, 3.000 and synthetic disposal reference | Captured / captured | Captured / captured | `writeoff-proposal.jpg` |
| Independent write-off approval | Captured / captured | Captured / captured | `writeoff-approval.jpg` |
| Denied Marketing role — count page | Captured / captured | Captured / captured | `denied-counts.jpg` |
| Denied Marketing role — write-off page | Captured / captured | Captured / captured | `denied-writeoffs.jpg` |

The 24 workflow files use `screenshots/{390|1440}-{light|dark}-{suffix}`. Additional saved/posted-history captures retain the observed outcomes. `chrome-metrics.json` records actual viewport, theme, document width, dialog bounds/scroll dimensions and new-page/form control bounds. For these six workflow states, no horizontal document/dialog overflow was measured; all measured new-page/form inputs, selects and buttons were at least **44 × 44 CSS px**. No claim is made about all shared navigation controls or untested content extremes.

Chrome created **case 7**, froze 10.000, saved the first count as **Staff 1 / 8.000**, then the separate recounter **Staff 2 / 7.500**, followed by Finance Officer **Staff 3** approval. History retained all four versions and linked **movement 7 / stocktake_loss / 2.500**; final balance **7.500**. The original counter had no recount/approval control; the recounter had no approval control; Finance Officer had approval controls and no target proposal/count controls.

Chrome also created expired write-off **case 6 / 3.000**, reference `SYN-CHROME-DISPOSAL-LOG`, as Staff 1; Staff 3 independently approved it. Posted history linked **movement 8 / expiry_writeoff / 3.000**; final balance **7.000**. The retained pre-resume examples remain evidence; cases sharing those sources can now be stale by design. No prior evidence was edited or deleted. Final read-only PostgreSQL reconciliation confirmed these exact action actors, quantities, movement types/links and balances (`chrome-database-proof.txt`).

### Initial defect stop — dark preference ignored (resolved below)

Reproduction: select **Dark** in the synthetic preview Appearance settings; sign out; sign in as fictional `denied@stock-governed.test` (Marketing); directly open `/inventory/counts` at 390 × 844. The browser correctly denies access with **403 Forbidden**, but renders a white page. DOM inspection shows no app dark class and computed body background **rgb(255, 255, 255)**. Screenshot: `/private/tmp/kpone-stk5-evidence/screenshots/390-dark-preference-count-denied-white-response.jpg`; DOM: `denial-defect-dom.txt`.

The response uses the existing framework minimal error view: `vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views/minimal.blade.php:19–20` hard-codes `bg-white text-black` on body/main. At the initial stop, no application error-view override existed. This is an existing shared error-page presentation defect exposed by the required denial evidence, **not an observed authorisation bypass** and not attributed to new STK-5 source. A selected dark preference therefore does not produce the required dark denial state. No screenshot is labelled as a successful dark-rendered denial.

Per the owner's explicit instruction, verification stopped at this defect before changing code. No dark-mode emulation, DOM/style modification, dependency edit or workaround was used to manufacture evidence. Chrome DevTools was briefly inspected to distinguish theme handling, then closed without changing page styles or emulation. The remaining denial width/theme matrix and write-off denial were not attempted after the stop. At that point the Chrome visual gate remained incomplete. The owner subsequently authorised fixing this defect and finishing the denial evidence; the completed fix and verification follow below.

### Initial resume diff and cleanup

Only this report was edited in the worktree during the initial resume. SHA-256 comparison of every pre-existing candidate changed/new code and test file against the resume baseline found no changes. The refreshed combined tracked/new-file diff was reviewed, including the report update, and `git diff --check` passed; new-file whitespace was checked separately. Existing automated results above remain the supplied final-source results; they were not rerun for a documentation-only change. Existing assertion changes remain **none**.

The temporary preview and owned PostgreSQL cluster were stopped again, retaining databases, storage, logs and screenshots. The temporary Chrome viewport override was reset; DevTools and the task tab were closed, and the password-save prompt was dismissed without saving. No commit, push or PR. The served tree was never opened or touched during this resume. The mandatory legacy pilot-blocking bypass remains unchanged.

### Authorised 403 fix and completed denial evidence

The owner authorised the fix on 2026-10-29, retaining the same worktree, database and no-commit/push/PR boundaries. Added **only `resources/views/errors/403.blade.php`** as application code. No vendor file, middleware, permission, route, test/assertion or inventory logic was changed. This application-owned error view reads the appearance cookie directly because permission middleware can stop the request before `HandleAppearance` runs. It accepts only `light`, `dark` or `system`; missing/invalid values fall back to `system`. Standalone CSS follows explicit light/dark preferences and the system colour scheme without JavaScript or application assets. The response remains HTTP **403**, with a semantic `main`, 403 heading and Forbidden message, and exposes no governed data or controls.

Restarted only the retained cluster and preview. Reverified configured host/port/name and actual PostgreSQL 18.6, `kpone_stk5_preview`, UTF8 and owned data directory **before application use** (`403-owned-db-verification.json`). Used the same explicit environment-loading isolation and synthetic Marketing account. No `.env` or `.env.preview`, other database, served tree, reseed or migration was accessed/run.

Real Chrome captured and inspected all **8 denial combinations**: `/inventory/counts` and `/inventory/writeoffs`, each at **390 × 844** and **1440 × 1000**, light and dark. Files are `screenshots/{390|1440}-{light|dark}-denied-{counts|writeoffs}.jpg`. Each saved JPEG's actual pixel dimensions were verified against the page's CSS viewport. Full-page capture preserves the complete denial viewport, whose document height equals the viewport height; no image was resized or edited. Both pages show only **403 Forbidden**, with no data or mutation controls. Light measured background/text **rgb(255,255,255) / rgb(10,10,10)**; dark measured **rgb(10,10,10) / rgb(250,250,250)**, and the explicit dark class was present. No horizontal overflow. There are no interactive denial controls requiring touch-target measurement. `chrome-denial-metrics.json` records each combination. A further Chrome System-preference check followed the host's dark system scheme (`chrome-403-system.json`).

The complete required evidence matrix is now **32 captures: the retained 24 workflow captures plus 8 denial captures**. The original failed screenshot and extra posted/history screenshots remain as labelled historical evidence. The prior 44px/overflow workflow measurements remain applicable: this fix changes only the standalone forbidden response, not any governed page or frontend asset.

Validation on the final 403 view:

- Actual Laravel exception-handler rendering: **5 preference cases / 30 assertions passed**, including explicit light/dark/system, missing and an invalid injection-shaped preference; every response remained 403, invalid input was not emitted, no scripts were emitted, and rendering executed **zero database queries** (`403-render-check.json`, reproducible task-local `check-403-render.php`). Compiled Blade passed `php -l`.
- All 8 denial screenshots visually inspected and file dimensions verified; Chrome also verified System preference. No style/DOM manipulation or theme emulation was used.
- Full frontend glob repeated: **519 passed / zero failures / zero skips**, **2.310 s** (`403-frontend.log`). Pint, frontend lint, format and types checks passed (`403-pint.log`, `403-lint.log`, `403-format.log`, `403-types.log`). `git diff --check` and untracked-file whitespace checks passed.
- Read-only before/after snapshots of **all full rows** in governed cases/actions, stock balances and movements are byte-identical (`403-inventory-before.json`, `403-inventory-after.json`). The earlier synthetic posted outcomes are retained; denial verification created no inventory changes.
- Original candidate code/test hashes still match the initial evidence-resume baseline. The only new application file is the 403 view. Existing assertions changed: **none**. The previously completed 1,551 backend passes / 31,231 assertions / two existing skips remain historical full-suite evidence. That full suite, PHPStan, build and migrate/seed gates were not rerun for this standalone Blade fix; final-view coverage is the framework rendering check and actual Chrome denial verification above. No other database or destructive seed run was used.

Reviewed the scoped 403 fix and report diff, and regenerated the combined tracked/new-file `STK-5.diff`. The temporary viewport was reset and task Chrome tab closed; the original Dark preview preference was restored. The owned preview and PostgreSQL cluster are stopped again, retaining synthetic state and evidence. No commit, push or PR. No production approval is claimed; the mandatory legacy pilot-blocking approval bypass remains unchanged.

## Limits and deferred items

- Mandatory legacy approval bypass above: pilot-blocking, separate slice only.
- No variance threshold, cost, valuation or write-down; disposal is referenced, not proven.
- One frozen existing tuple per count; no unexpected batch insertion. No whole-location bulk count, automatic resnapshot or historical conversion. Changed source/version/base unit requires a new case; stale evidence remains visible.
- Recounted quantity is authoritative for proposed adjustment while original count remains immutable. A zero independent recount closes without a movement. No variance posting without the required recount.
- Reversal only, one proposal per approved original; no edit/delete or reversal-of-reversal. No rejection/cancellation workflow added beyond the authorised scope; unapproved/stale evidence remains retained.
- No stocktaking of branch-null warehouse locations in this branch-governed slice. Existing active SKU/location rules retained; held/expired batches allowed without releasing holds.
- No receiving, dispensing or hold rule change; no supplier returns, transfers, messaging, import, STK-6 to STK-10 or pilot-gating follow-up.
- Regulated/controlled items, registers, professional responsibility and retention remain **to confirm with a pharmacist or the regulator**. No professional answer encoded.

## Owner-requested final gate replay after the 403 fix — 2026-10-29

HEAD **`0d78ede3538e4585535ab106363cbdb88bf2ff60`**, branch `feature/stk-5-count-writeoff`. The worktree is **not clean**: the candidate, including the 403 view and this report, remains intentionally uncommitted. No commit, push or PR. HEAD identifies the base; the tested uncommitted source is pinned by `/private/tmp/kpone-stk5-evidence/replay-code-hashes.json` (manifest SHA-256 **`b15b353f2e8e6ca5f4daf4f5fae2643fa73bfb71fc87768a270f8cc9760a3273`**). All recorded code/test file hashes were checked unchanged at the end of the replay.

Before the first application request, the process printed **DB_HOST=127.0.0.1, DB_PORT=58684, DB_DATABASE=kpone_stk5_testing**. Verification confirmed PostgreSQL **18.6**, UTF8, actual database name and owned cluster directory `/private/tmp/kpone-stk5-pg18`. Separate disposable seed target **kpone_stk5_seed**, same owned host/port, was independently verified before `migrate:fresh --seed --force`. Explicit placeholder environment and task-only 1 GiB PHP; no .env or .env.preview opened, printed or edited. No preview or demo database was used.

| Final-tree check | Actual result |
| --- | --- |
| Early `php -l` | **14 changed/new PHP files passed**, including the new Blade file; `replay-php-l.log`. |
| Early Pint and PHPStan level 7 | Passed, **zero PHPStan errors**, before the long suite; `replay-pint-early.log`, `replay-phpstan-early.log`. |
| Exact `composer ci:check` | **1,551 passed / 31,231 assertions / two existing skips / zero failures or errors**, 1,553 total reached, **767.363 s**; `replay-ci.log`. The unchanged public-registration tests are gated by disabled Fortify registration. |
| Explicit frontend types, lint and format checks | All passed; `replay-types.log`, `replay-lint.log`, `replay-format.log`. |
| `npm run build` | Passed, **1.73 s**; `replay-build.log`. |
| `node --test tests/Frontend/*.mjs` | **519 passed / zero failures / zero skips**, **2.341 s**; `replay-frontend.log`. Node TAP does not expose an aggregate count of individual assertions. |
| Explicit PostgreSQL class replay | **119 passed / 1,181 assertions / zero skips / zero failures or errors**, **96.117 s**, all **18 classes**; `replay-postgres-tests.log`, `replay-postgres-junit.xml`, `replay-postgres-summary.json`. |
| Separate disposable `migrate:fresh --seed --force` | Passed with synthetic reference/development data; `replay-fresh-seed.log`, `replay-seed-verification.json`. |
| Whitespace, tracked and new files | `git diff --check origin/main` and each new file's no-index whitespace check passed. Repeated after this report update. |

The explicit PostgreSQL replay includes `PostgresGovernedStockTest::test_dispensing_and_writeoff_approval_serialize_with_one_winner_and_no_lost_audit`: **passed, 33 assertions, 1.677 s**, not skipped. It exercises both winner orders. JUnit contains no skipped/failed/error test case in any of the 18 PostgreSQL classes; the two full-suite skips are not PostgreSQL tests. No failed gate output occurred in this replay.

Compared with the owner's reference figures, this run has **18 additional backend passes, 275 additional assertions and 3 additional frontend passes**. No assertion was weakened or newly skipped.

### Existing-test comparison against origin/main

`git diff --name-status origin/main -- tests` is empty: **no existing assertion, expected value, test name, skip, fixture or isolation setup changed**. Only these five new test/support files differ by addition:

- `tests/Feature/Inventory/GovernedStockTest.php`
- `tests/Feature/Inventory/PostgresGovernedStockTest.php`
- `tests/Frontend/governed-stock.test.mjs`
- `tests/Support/GovernedStockFixture.php`
- `tests/Support/PostgresGovernedStockWorker.php`

The comparison is saved as `replay-test-diff.txt`. No code change was made for this gate replay. The 32-state Chrome matrix remains the final source evidence. The owned PostgreSQL cluster was stopped after completion; no other database, service or worktree was changed. The legacy approval bypass remains **pilot-blocking** and its required follow-up is explicitly **STK-6A**. Await owner review; do not commit, push or open a PR yet.

## Handoff and exact next step

No commit, push or PR. Branch `feature/stk-5-count-writeoff` remains uncommitted on base `0d78ede3538e4585535ab106363cbdb88bf2ff60`. Report path: `docs/reports/STK-5.md`. Combined tracked/new-file diff: `/private/tmp/kpone-stk5-evidence/STK-5.diff`.

Chrome evidence is complete and the 403 theme defect is fixed. The uncommitted candidate and refreshed review diff are ready for owner review. The mandatory legacy pilot-blocking approval bypass remains unchanged. Not production-approved.

## Revised holder decision — owner authorised 2026-10-29

Added `ca_supervisor` to `inventory.variances.approve.branch`, alongside `finance_officer` and `director`; no other holder. Edited the same unreleased catalogue/grant files directly, with no additional migration. Additive/replay-safe grant behaviour and no-op down unchanged. The domain service and PostgreSQL independent-approver rules are byte-identical to the prior candidate; counter, recounter and proposer cannot approve.

Updated only the new STK-5 exact-holder/migration tests, added explicit denied-role checks and CA Supervisor domain/SQL/payload/identical-key retry tests. The approve button still uses backend `canApprove`, never a role-name shortcut. Updated the pending-approval UI label to include CA Supervisor and pinned its backend guard in the new frontend test. No existing test file changed. Full gate replay for this revision is pending; prior results above are historical. STK-6A remains the named pilot-blocking legacy-bypass follow-up. No commit, push or PR.

### Revised-holder early validation

The new CA Supervisor test proves proposer, counter and recounter each receive `canApprove=false`, retain the permission, are refused twice with the same UUID, and leave full case/action/balance/movement/audit snapshots unchanged. Direct SQL approval attempts by each participant are refused with SQLSTATE 23514 and `Independent approver required`. A different CA Supervisor receives `canApprove=true`, posts once, and an identical retry changes no row. Revoking that actor's role then refuses the same successful retry key with fresh permission checks. The frontend control remains conditional on backend `row.canApprove`.

New-test setup correction, not a production defect: the first targeted run had one failure (`Failed asserting that two arrays are identical`) because its final revocation check compared against a snapshot taken before role-change audit records. Moved that new test's refusal baseline to immediately after the deliberate role revocation, preserving the full snapshot comparison around the refused call. Original failure retained in `holder-new-tests.log`; no existing assertion changed.

Subsequent new STK-5 tests: **18 passed / 320 assertions / zero skips or failures / 9.851 s**. Unchanged ClinicManagementTest, StaffAuthorityBoundaryTest and PermissionCatalogueMigrationTest: **29 passed / 1,670 assertions / zero skips or failures / 13.336 s**. New frontend file: **4 passed**. Early PHPStan passed. Domain service and evidence migration SHA-256 hashes match the prior candidate exactly. Full replay follows below when complete.

### Revised-holder final gate — passed

HEAD **`0d78ede3538e4585535ab106363cbdb88bf2ff60`**, branch `feature/stk-5-count-writeoff`; the worktree remains **not clean**, intentionally uncommitted. Same owned PostgreSQL **18.6**, **127.0.0.1:58684/kpone_stk5_testing**, UTF8; separate **kpone_stk5_seed** verified before fresh migration/seed. No .env or .env.preview accessed. Owner-approved task-only **1 GiB** setting.

| Final revised-tree gate | Result |
| --- | --- |
| Early syntax/Pint/PHPStan | **14 PHP files passed syntax; Pint passed; PHPStan level 7 zero errors**, before the long suite. `holder-replay-php-l.log`, `holder-replay-pint-early.log`, `holder-replay-phpstan-early.log`. |
| Exact `composer ci:check` | **1,552 backend passes / 31,315 assertions / two existing public-registration skips / zero failures or errors**, 1,554 total, **773.910 s**. `holder-replay-ci.log`. |
| Frontend types/lint/format | All passed. `holder-replay-types.log`, `holder-replay-lint.log`, `holder-replay-format.log`. |
| Production build | Passed, **1.70 s**, `holder-replay-build.log`. |
| Full frontend glob | **520 passed / zero failures / zero skips**, **2.372 s**, `holder-replay-frontend.log`. Node does not report an aggregate individual-assertion count. |
| Explicit PostgreSQL replay | **119 passed / 1,181 assertions / zero skips / zero failures or errors**, **18 classes**, **95.030 s**. Parsed every JUnit testcase and confirmed no skipped/failure/error element. `holder-replay-postgres-tests.log`, `holder-replay-postgres-junit.xml`, `holder-replay-postgres-summary.json`. The dispensing/approval contention test ran. |
| Separate disposable fresh migration/seed | Passed, `holder-replay-fresh-seed.log`; owned seed identity verified in `holder-replay-seed-verification.json`. Fresh-seed role query confirms exactly ca_supervisor, director and finance_officer hold the new permission. |
| Tracked/new-file whitespace | Passed, repeated after report update. |

No existing assertion, expected value, test name, skip or fixture changed: `git diff --name-status origin/main -- tests` is empty. Existing hub and exact permission-set tests passed untouched. The five new STK-5 test/support files listed in the prior comparison remain additions; only the new backend and frontend test files changed for this holder decision. No test was weakened, removed or newly skipped. The initial failure was confined to new test setup as explained above; no final gate failure occurred.

Code hashes remained unchanged throughout the full replay; manifest `holder-replay-code-hashes.json` SHA-256 **`d791bccaacffa9f3b2d74bc73de933d1b1d0f0701061101c7ab0f902f5c6f4fe`**. Domain service and PostgreSQL evidence migration remain byte-identical to the prior candidate. No new migration was added: the existing unreleased grant migration was edited in place.

Changed files in this revision, compared with the prior completed gate:

1. `app/Domain/Access/PermissionCatalogue.php` — add only ca_supervisor as approval holder.
2. `database/migrations/2026_11_04_000200_grant_inventory_variance_approval.php` — same additive grant, same third holder.
3. `tests/Feature/Inventory/GovernedStockTest.php` — update new exact-holder expectations; explicit denied roles; CA Supervisor domain/SQL/payload/retry/revocation proof.
4. `resources/js/pages/Inventory/GovernedStock.vue` — truthful three-role approval prompt only; control guard unchanged.
5. `tests/Frontend/governed-stock.test.mjs` — update new prompt expectation and pin backend-controlled approve button.
6. `docs/reports/STK-5.md` — owner decision, unchanged separation, tests, final counts and this list.

The prior 32-state Chrome matrix predates this holder/label revision; no fresh Chrome capture is claimed for this round. The role-specific visibility check is covered by actual Inertia responses and the Vue control-guard test. The **pilot-blocking legacy bypass remains unchanged**, with **STK-6A** named as the required follow-up. The owned test cluster was stopped after this gate; no preview, demo or other database/service was altered. No commit, push or PR. Await owner review.
