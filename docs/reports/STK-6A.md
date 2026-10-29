# STK-6A — close legacy stocktake and manual-adjustment bypass

Date: 2026-10-29. Branch `fix/stk-6a-close-legacy-stock-bypass`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-stk-6a`. Base `eba433616ad774fe374fe4859f22c3ca5e6966dd`, merged STK-5 PR #186. Verified `origin/main` points to that commit before creating this worktree.

The owner approved the proposal, its 27 individually named assertion changes and historical fixtures, with additional source-guard, kept-path and real-contention conditions, then separately approved one picker assertion (28 changes total). Implementation is local and uncommitted. No push or PR. No `.env` or `.env.preview` accessed. The STK-5 and served working trees were not changed. Sections 1–10 retain the original survey at the base SHA and the approved old/new contracts; section 11 records implementation and validation.

All source references below refer to the merged base above. They are verified code facts, not observations of a real clinic database. The ledger's actual table is `stock_movements`; no `inventory_movements` writer was found.

## Approved approach

Close the six legacy mutations and their two public legacy ledger helpers in the domain, after fresh authority checks. Keep URL registrations, backend permission grants, historical models/data, ledger classification and every kept flow. Remove the actionable legacy UI and handlers, retain history, and point to STK-5. Use no new permission, revocation, dependency or migration. Do not add a refusal audit.

**Owner-approved scope:** the refusal contract, the original **27 individual existing assertion edits** plus the separately approved picker assertion in section 6, and the specifically named historical-fixture/sequencing changes in section 7. These are deliberate compatibility consequences of withdrawing the old posting contract, not permission to weaken stock safety. Any further existing assertion failure remains a stop.

## 1. Verified legacy routes, controllers, services and controls

All routes are private authenticated staff routes. Both legacy mutation permissions are **CA Supervisor only**, verified in `app/Domain/Access/PermissionCatalogue.php:289` (stocktake) and `:290` (adjustment). Director and Finance Officer have STK-5 approval permission, not either legacy mutation permission (`:403–404`). Do not infer legacy access from STK-5 approval.

| Legacy endpoint / route name | Controller action and domain method | What can change today | Vue control / handler | Proposal |
| --- | --- | --- | --- | --- |
| POST `/inventory/stocktakes`; `inventory.stocktakes.store`, `routes/web.php:573` | `InventoryOperationsController.php:245`, `createStocktake`; `InventoryControlService.php:40` | Creates header and frozen whole-location lines, records creator and audit. No quantity posting yet. | `InventoryOperationsPanel.vue:1128–1142` Create snapshot; handler `:581–590`. | Refuse new legacy snapshots so staff cannot enter a dead-end lifecycle. |
| POST `/inventory/stocktakes/{stocktake}/start`; `.start`, `routes/web.php:574` | Controller `:256`; `InventoryControlService::startCounting`, `:64` | Draft to counting, version and audit. | Panel `:1153–1161` Start counting; shared `transitionStocktake`, `:591`. | Refuse; preserve old status unchanged. |
| POST `/inventory/stocktakes/{stocktake}/count`; `.count`, `routes/web.php:575` | Controller `:267`; `InventoryControlService::recordCounts`, `:70` | Stores physical/variance values; goes to review; no movement until post. | Panel `:1162–1184` physical-count form and Send to review; handler `:591`. | Refuse; historical counted values remain unchanged. |
| POST `/inventory/stocktakes/{stocktake}/post`; `.post`, `routes/web.php:576` | Controller `:278`; `InventoryControlService::postStocktake`, `:101` | Posts gain/loss via `:134`, updates live balances and posted state. Same person may count/post. | Panel `:1185–1193` Post variance; confirmation/transition handlers `:591–615`. | Refuse for every authorised legacy actor, including retries and zero variance. |
| POST `/inventory/stocktakes/{stocktake}/cancel`; `.cancel`, `routes/web.php:577` | Controller `:289`; `InventoryControlService::cancelStocktake`, `:144` | Cancels draft/counting/review status; does not change quantity. | Panel `:1194–1203` Cancel stocktake; same confirmation/transition handlers. | Freeze this as well: no mutation of retained legacy history. This is an explicit part of the proposal for approval. |
| POST `/inventory/adjustments`; `inventory.adjustments.store`, `routes/web.php:578` | Controller `:300`; `InventoryControlService::adjust`, `:150` | Immediately creates adjustment, in/out movement, balance change and audit; same actor proposes/posts. | Panel `:1207–1277` Manual Adjustment form/Post adjustment; handlers `:616–662`. | Refuse all reasons/directions, new keys, repeated keys and changed payloads. No successful replay of an old adjustment through this closed API. |

Controller path: `app/Http/Controllers/InventoryOperationsController.php`. Domain path: `app/Domain/Organisation/Inventory/Services/InventoryControlService.php`. Panel path: `resources/js/components/inventory/InventoryOperationsPanel.vue`.

The stocktake permission gates all five stocktake routes; adjustment permission gates the sixth route. Existing `InventoryAuthorityService::lockForBranch` rechecks active actor/permission and effective branch assignment (`app/Domain/Organisation/Inventory/Services/InventoryAuthorityService.php:33–50`).

### Low-level posting entry points also needing closure

| Method in `app/Domain/Organisation/Inventory/Services/InventoryMovementService.php` | Current writer | Verified runtime callers | Proposal |
| --- | --- | --- | --- |
| `recordStocktakeVariance`, `:162–173` | Calls private exact credit/debit engine for `stocktake_gain` / `stocktake_loss`, reference `inventory_stocktake`; zero returns null. | Only `InventoryControlService::postStocktake`, `:134`. | Keep signature but refuse after fresh relevant permission/branch/reference-scope checks, even for zero. Otherwise a future direct caller could bypass the high-level closure. |
| `recordAdjustment`, `:175–180` | Calls private credit/debit engine for `adjustment_in` / `adjustment_out`, reference `inventory_adjustment`. | Only `InventoryControlService::adjust`, `:205`. | Same fresh-authority-first refusal. Do not change private shared credit/debit methods. |

Repository-wide source searches of `app` and `database/seeders` found no other runtime or seeder caller of these two methods or the six legacy control methods. Additional direct calls are **test setup**, listed below. This is not an unlisted production flow; report fixtures must be preserved explicitly rather than silently routed through a retired writer.

`InventoryMovementService::movement`, `:295–329`, is the sole discovered application `new StockMovement` insertion. The private shared engine also serves receiving, receipt corrections, dispensing and transfers; blocking the shared engine or all `adjustment_out` types would break kept flows.

### Navigation and reachability

- Clinic management exposes Inventory under `inventory.view.branch`, `app/Domain/Access/ClinicManagementNavigation.php:49`. It links to GET `/inventory`, not directly to legacy mutations. Default viewers: Director, CA, CA Supervisor, Finance Officer, Administrator; see catalogue `:174`, `:246`, `:276`, `:298`, `:311`.
- `InventoryController::__invoke`, `app/Http/Controllers/InventoryController.php:27–64`, returns Inventory and operations data.
- `resources/js/pages/Inventory/Index.vue:605–608` embeds the panel. The same page already links to `/inventory/counts` and `/inventory/writeoffs` at `:178–186`.
- `InventoryOperationsDirectoryService.php:51–52` currently derives `permissions.stocktake` / `permissions.adjust` from the grants. Proposed change: retain both payload keys but return false as closed UI capabilities, without revoking either underlying permission. No existing test directly pins those two boolean values in the searched tests.
- Keep the same hub destination, route names and request bindings. STK-5's frontend test explicitly pins the old route registrations too (`tests/Frontend/governed-stock.test.mjs:24`); it can remain untouched if routes stay installed and refuse in the domain.

## 2. Kept paths, with reasons

| Kept path | Permission / default holders | Verified call chain / source | Reason to keep |
| --- | --- | --- | --- |
| Opening balance POST `/inventory/opening-balances` | `inventory.opening_balance.branch`, CA Supervisor; an explicit CA Supervisor role check remains. | `routes/web.php:551–552`; `InventoryMovementController::openingBalance:14`; `InventoryMovementService::openingBalance:24–72`. | One-time opening is explicitly kept. Duplicate opening for the same tuple is refused at `:35–45`. It does not call either legacy writer. |
| STK-1 opening verification POST `/inventory/readiness/opening/{key}` | `inventory.opening.verify.branch`: CA Supervisor, Finance Officer, Director. | Route `routes/web.php:397`; `InventoryReadinessService::verify:19–71`, especially original-recorder exclusion `:59`, unit verification `:60–64`. | Independent append-only sign-off. It follows the opening; do not claim that it is a pre-posting approval or redesign it here. |
| STK-3 purchase receiving POST `/inventory/purchase-orders/{purchaseOrder}/receipts` and review/approval routes | Receive: `inventory.receiving.branch`, CA Supervisor. Exception approval: `inventory.receiving.exceptions.approve.branch`, CA Supervisor/Finance Officer/Director, different from receiver. | `routes/web.php:564–566`; `InventoryOperationsController::receivePurchaseOrder:123`, `requestReceivingReview:146`, `approveReceivingReview:162`; `ProcurementService::receive:144–220`, movement at `:196`; `ReceivingReviewService::request:22`, `approve:74`; `InventoryMovementService::recordPurchaseReceipt:122`. | Existing real-expiry/delivery/reference/approval controls. It has its own writer, not `recordAdjustment`. No behaviour change. |
| STK-3 reversing receiving correction POST `/inventory/receiving-reviews/{review}/reverse` | `inventory.receiving.branch`, original receiver, plus existing approved correction evidence. | `routes/web.php:567`; `InventoryOperationsController::reverseReceivingReceipt:173`; `ReceiptCorrectionService::reverse:25`, fresh authority `:33`, original receiver `:34`, posting `:68`; `InventoryMovementService::recordReceiptCorrection:182–185`. | Legitimate controlled debit uses **`adjustment_out` with `inventory_receipt_correction` reference**. Must not be blocked by a movement-type-only ban. |
| Dispensing completion POST `/dispensary/{dispensaryCase}/complete` | `dispensary.complete.branch`, CA and CA Supervisor. | `routes/web.php:547–548`; `DispensaryService::complete:524`, `debitForDispense` call `:617`; ledger method `:109`. | Patient dispensing allocation/consumption is explicitly kept. Save-time eligibility, holds, expiry and completion checks stay unchanged. |
| Direct stock transfer POST `/inventory/transfers` | `inventory.transfer.branch`, CA/CA Supervisor; warehouse/organisation transfer additionally requires CA Supervisor and `inventory.transfer.organisation`. | `routes/web.php:553–554`; `InventoryMovementController::transfer:26`; `InventoryMovementService::transfer:76–106`, organisation checks `:92–97`. | Moves stock between permitted locations, not a count/loss/write-off correction. Source/destination conservation stays unchanged. |
| Stock-request dispatch and receipt | Dispatch: `inventory.transfers.dispatch.branch`, CA Supervisor. Receipt: `inventory.transfers.receive.branch`, CA/CA Supervisor. | `routes/web.php:568–572`; `InventoryOperationsController::createStockRequest:190`, `approveStockRequest:201`, `rejectStockRequest:212`, `dispatchStockRequest:223`, `receiveStockRequest:234`; `StockRequestService::dispatch:85`, `recordTransferDispatch:128`; `receive:139`, `recordTransferReceipt:164`; ledger methods `:152–159`. | Existing two-stage transfer and idempotency controls. Creation/approval/rejection are kept too; no legacy writer dependency. |
| STK-5 count/write-off/reversal | Count/recount: `inventory.stocktake.branch`; proposal: `inventory.adjust.branch`, CA Supervisor. Approval: `inventory.variances.approve.branch`, CA Supervisor/Finance Officer/Director, independent of proposer/counter/recounter. | `routes/web.php:388–391`; `GovernedStockService::act:90–150`; calls `recordGovernedChange`, ledger `:188–196`. | The only kept application count/loss/write-off adjustment route. No approval rule, counter, table or posting calculation change. |
| Reorder levels, stock review acknowledgements, holds, references, suppliers and purchase-order administration | Existing permissions only. | Reorder route `routes/web.php:579`, `InventoryControlService::setReorderLevel:213`; other separate routes/services. | These are not retired legacy quantity posting APIs. No changes to their stored records, behaviour or grants. |

Catalogue sources for kept roles: `PermissionCatalogue.php:243`, `:247–249`, `:273`, `:277–292`, `:336–337`, `:398–404`. No new access or revocation is proposed.

Kept Vue entry points: `resources/js/components/inventory/InventoryReferenceDataPanel.vue:254–330` and its `submitOpeningBalance:155` post the opening; `Inventory/Readiness.vue` serves the separate sign-off; `InventoryOperationsPanel.vue:429–512` and `components/inventory/VerifiedReceivingForm.vue:65` handle receiving; `components/inventory/ReceivingReviews.vue:75,116–118` handles review/approve/reverse; operations panel `:550–579` handles transfer dispatch/receipt; `pages/Dispensary/Show.vue:1781–1814` keeps completion. No direct Vue caller of the old `/inventory/transfers` endpoint was found; its backend remains kept. No generic adjustment/stocktake refusal may be added to these controls.

There is one obsolete advice string in the kept opening method: `InventoryMovementService.php:44` says to use a manual adjustment after an opening exists. Proposed **wording-only** update, for approval: point to governed Stock count / Write-off for a count or loss and stock transfer for a transfer, preserving the `opening_balance` error key and all opening/sign-off logic. No exact-string assertion for that advice was found. This is not permission to change the kept opening behaviour.

## 3. Historical data and read compatibility

No real database was inspected; actual row counts/data are unknown. A database that used the legacy path can retain:

- `inventory_stocktakes`: public number, location, status (draft/counting/review/posted/cancelled), creator/counter/poster/canceller IDs, dates and lock_version. `2026_09_11_000100_create_inventory_operations.php:157–180`; model `InventoryStocktake.php:13–48`.
- `inventory_stocktake_lines`: expected quantity, frozen balance version, physical quantity and variance per SKU/batch. Same migration `:182–198`.
- `inventory_adjustments`: direction, quantity, controlled reason/note, idempotency key/hash, posting actor/time and references. Same migration `:200–225`; `InventoryControlService.php:178–206`.
- `stock_movements`: immutable gain/loss/adjustment rows and their original references/unit snapshots; current `inventory_stock_balances` include their effects. Associated historical audit rows remain.

**Keep all of these unchanged and readable.** Do not reverse, zero, recalculate, convert, hide by movement type or manufacture approvals for them. Do not delete movement constants or relax immutability. `InventoryAdjustment.php:14–15` forbids model update/delete; `InventoryStocktake.php:27–32` protects identity/deletion.

Existing retained reads:

- Operations directory returns the last 50 stocktakes plus batched line data (`InventoryOperationsDirectoryService.php:36–38,61,96–100`) and the last 50 adjustments (`:62–64`). Preserve this shape/query behaviour.
- Stocktake cards exist now (`InventoryOperationsPanel.vue:1144–1204`). Physical-count inputs are currently mixed into the controls; replace them with read-only expected/physical/variance values. Retain numbers/status/location/line evidence, including unposted histories.
- Manual Adjustment currently has a form but no adjustment-row loop (`:1207–1277`); its payload already contains adjustments. Replace it with a read-only legacy-history presentation using that same payload, and retain the Movements tab. No new patient/staff data or query is required.
- Movement projection scopes retained rows by organisation and source/destination branch without active-reference filtering (`InventoryMovementProjection.php:11–20`). Do not filter legacy references out.
- Stock Movement Summary classifies retained allowed stocktake/adjustment movements in Adjusted, keeps unknown-type refusal and reconciliation (`StockMovementReportService.php:40–62`). Directory types/labels stay intact (`InventoryDirectoryService.php:17–45,175–240`).

Before/after implementation proof must use the same historical fixture, full ID-ordered stock-table snapshots, equal movement-history payloads and exactly equal report numbers. Permit only the expected new read-audit records if those existing reads audit. Refused domain calls themselves add no audit or evidence.

## 4. Proposed refusal and UI contract — not implemented

1. **Permission first:** retain route permission middleware. In each retired public domain method, check active actor, organisation and currently effective selected-branch authority, inside the normal transaction/authority-lock boundary, before the policy refusal and before payload validation/stock or evidence writes. Preserve foreign-scope refusal. Include direct calls to both low-level legacy writers with the corresponding legacy permission and permitted location scope, including existing warehouse scope. Do not insert a broad type check into shared credit/debit routines.
2. **Exact policy error field:** `legacy_stock_path`.
3. **Exact proposed English message:** `Legacy stocktake and manual adjustment are closed. Use Stock count (governed) or Write-off (governed) in Inventory; independent approval is required.`
4. **Browser/Inertia:** existing controller catches ValidationException and explicitly redirects to `/inventory` (`InventoryOperationsController::stayOnInventory:334–336`). It already does this; no controller change or bare back() is needed. The error is displayed in the existing Inventory Operations error surface. Normal JSON validation requests receive 422 with that error. Do not redirect to a destination chosen by untrusted Referer.
5. **Unauthorised actors:** retain existing 403 (and scoped 404/branch validation where applicable). In particular, Director and Finance Officer have no legacy count/adjust grant, so they get the existing access refusal, not a newly granted ability to enter the legacy workflow. The new exact policy message applies after successful authority checks, chiefly CA Supervisor. This resolves the tension between “permission first” and “every role gets the exact message” without granting access or changing shared error handling; owner confirmation is requested.
6. **No new refusal audit:** no new stock, evidence, idempotency or audit row. Identical retry, altered retry, double-click and old successful retry key all refuse and leave the full stock snapshot unchanged. Existing access-audit behaviour, if any, remains intact. Do not claim successful idempotent replay of retired mutations.
7. **UI:** remove the legacy snapshot/count/post/cancel/manual-adjustment forms, buttons, state and endpoint handlers; do not merely hide a live handler. Keep legacy history headings/cards and add a one-line note with 44px links to the governed paths. Keep projection keys but make the two legacy action flags false. Other operations/pickers/receipt remembering/confirmation controls stay unchanged.
8. **Truthful STK-5 notice:** `GovernedStockService.php:27,228` currently emits the pilot-bypass warning and `GovernedStock.vue:238` labels it “Known pilot limit”. Update only this informational copy after closure. Retain the `legacyLimit` payload key for compatibility; do not change the governed posting/approval logic or tests. The existing frontend check pins the field, not the old wording (`tests/Frontend/governed-stock.test.mjs:8`).
9. **No permission or migration change:** keep all grants and exact catalogue/hub tests. No PostgreSQL INSERT guard proposed in this smallest application-path closure. Existing immutable evidence protections remain. Privileged/manual SQL INSERT and unreviewed future code are not prevented by a new database ban in this proposal; a reference-aware database guard would require separate explicit approval and must keep STK-3 `inventory_receipt_correction` and all STK-5 references. Do not pretend a blanket ban on adjustment types is safe.
10. **Static regression guard:** inventory application movement writes must continue through the existing central ledger; pin its exact writer/caller allowlist. Permit governed posting, opening, purchasing, receipt correction, dispensing and transfers. Pin the two retired public helpers as unconditional fresh-authority-first refusals, with no credit/debit call. Fail on a new raw application ledger insert or retired-writer call path. Exempt explicitly named synthetic historical fixtures in tests, not application code. This is a regression guard, not a proof against arbitrary malicious/obfuscated future code.

## 5. Existing test inventory: verified exercises and preservation plan

| File and test | Lines / exercise | Current assertion contract and proposed treatment |
| --- | --- | --- |
| `tests/Feature/Clinical/InventoryOperationsTest.php`, `test_stocktake_posts_gain_and_loss_once_and_rejects_stale_snapshot` | `625–673`: create/start/count/post; positive/negative, repeat, stale quantity and new key. | Old live posting contract conflicts. Preserve historical snapshot/status/quantity/movement assertions using historical fixtures; change the three policy-error-key expectations listed below. New tests prove every retired mutation refuses. |
| Same, `test_operations_directory_batches_document_line_queries` | `761–799`, creates two snapshots at `783`. | Two PO, two request, two historical stocktake documents and exactly one query per line table. Preserve all assertions; fixture-only replacement for snapshot setup. |
| Same, `test_adjustment_and_reorder_visibility_preserve_nonnegative_and_expiry_rules` | `802–843`, adjusts at `808–810`. | Posted adjustment/balance plus reorder/expiry/inactive-reference reads. Preserve historical numbers and all read assertions; two old payload-validation errors become retired-path errors. No negative-stock check is removed from the kept STK-5 path. |
| Same, `test_adjustment_idempotency_replays_once_and_rejects_changed_intent` | `846–870`, domain retries and HTTP retry. | Success-return/id/count/audit/balance and conflict-key assertions must become refusal/no-row/no-balance/full-snapshot assertions, listed individually below. |
| Same, `test_critical_operations_reject_inactive_staff_and_ended_branch_assignments` | `885–907`. | AuthorizationException and zero adjustments for inactive/ended actor. **Keep test and assertions untouched** by doing authority checks before the new refusal. |
| Same, `test_technical_admin_http_route_is_denied_and_posted_evidence_is_immutable` | `927–939`, posted fixture at `936`. | Technical Admin supplier 403 and posted adjustment deletion LogicException. Keep assertions/expectException; replace only posted legacy setup. |
| Same, `test_inventory_operations_permissions_are_explicit_and_technical_admin_has_none` | `872–883`. | Catalogue grants and Technical Admin denials. **Untouched**, since no revocation. |
| Same, `test_movement_projection_labels_signs_directions_and_filters_non_dispensary_types` | `675–714`. | Explicit synthetic movement rows test legacy labels/signs/source/destination. **Untouched**; retain all movement types and historical reads. |
| `tests/Feature/PostgresDispensaryInventoryRegressionTest.php`, `test_concurrent_adjustment_replay_posts_one_adjustment_and_movement` | `937–951`. | Two ADJUSTED results but only one adjustment/movement/audit, stock 11. Must become two policy refusals and unchanged initial stock 10. |
| Same, `test_stocktake_posting_rejects_live_stock_change_after_snapshot` | `953–972`. | Leading legacy adjustment succeeds; following post emits STALE stocktake; stock 11, status review, zero count movements. Historical stale fixture + concurrent retired-path refusals; keep numeric/history assertions, replace two output expectations. |
| Same, `test_stocktake_posting_rejects_a_new_balance_key_committed_after_snapshot` | `974–995`. | Leading legacy adjustment creates extra key; stale post; original 10, extra 1, review, zero count movements. Historical extra-key fixture + concurrent retired-path refusals; preserve numeric/history assertions, replace two output expectations. |
| Same, historical migration/rollback coverage | Direct synthetic old stocktake/adjustment rows at `810–826`. | Does not use retired application methods. Preserve real-migration setup, assertions, immutability and existing child-database isolation unchanged. No new migration means no new FK cleanup change is expected. |
| `tests/Feature/Reports/StockMovementReportTest.php`, `test_exact_quantities_reconcile_to_balances_and_remain_historical_with_no_source_mutations` | `105–end`; direct legacy writes `134–137`. | Closing 12.250 and exact report row (opening 10, received 5.125, dispensed 2, adjusted -0.125, transferred -0.750), historical stability, privacy and unchanged source evidence. **All assertions unchanged**; historical legacy fixture only. |
| `tests/Feature/Reports/PilotScaleReportTest.php`, shared `fixedFixture` | `33–94`, legacy adjustment at `86`; used by `test_batched_reports_equal_original_small_fixture_outputs_and_change_only_audit:112` and `test_ledger_batches_preserve_reconciliation_refusal_and_stock_aggregate_never_saturates:130`. | Small-fixture number identity, query batching, reconciliation refusal and large aggregate. **All assertions unchanged**; replace only the legacy fixture movement, preserving type/reference/quantity/time/unit/source balance. |
| `tests/Frontend/i2-inventory-operations.test.mjs`, `Inventory Operations uses KPOne controls and server-backed idempotent endpoints` | `139–197`. | Three assertions explicitly require retired adjustment/stocktake handlers. Replace those three with absence assertions. Keep every receipt, transfer, error, UI-component and remembering assertion. Keep “Stocktake” and “Manual Adjustment” text in read-only history headings so the existing label checks need not change. |
| Same, `stocktake controls require count and review before posting` | `127–137`. | Pure legacy status predicate; not a mutation/API. Leave helper and assertions unchanged, but remove its import/use from the panel. New tests prove no legacy actionable control exists. No unrelated utility refactor. |
| Same, `adjustment intent identity survives retry and rotates only for new intent` and `filter and operation validation errors remain in separate accessible surfaces` | `1321–1338`, `1340–1384`. | Pure intent/error utilities and legacy error labels. **Untouched**; they do not post or expose an action. |
| `tests/Frontend/i1b-inventory-demo-workspace.test.mjs`, read-only workspace tests | `49`, `84`; legacy movement labels `68–69`. | Keep labels, three directory tabs and delegation. **Untouched**. |
| `tests/Frontend/governed-stock.test.mjs`, `governed transport is separate from old demo routes and redirects explicitly` | `19–35`. | Pins both new and legacy URL registrations. **Untouched**; registrations remain, legacy services refuse. |

No direct existing cancelStocktake test was found. New role/refusal coverage must include it. Existing STK-5 separation/retry/SQL tests and the PostgreSQL governed contention test remain untouched. No runtime flow outside the kept list was found calling either legacy writer.

## 6. Every changed existing assertion — 28, all owner-approved

All entries below are now applied with owner approval: the original 27, followed by the separately approved entry 28. File/test labels and line references use the surveyed base unless stated otherwise:

- **A** — `tests/Feature/Clinical/InventoryOperationsTest.php`, `test_stocktake_posts_gain_and_loss_once_and_rejects_stale_snapshot`.
- **B** — same file, `test_adjustment_and_reorder_visibility_preserve_nonnegative_and_expiry_rules`.
- **C** — same file, `test_adjustment_idempotency_replays_once_and_rejects_changed_intent`.
- **D** — `tests/Feature/PostgresDispensaryInventoryRegressionTest.php`, `test_concurrent_adjustment_replay_posts_one_adjustment_and_movement`.
- **E** — same file, `test_stocktake_posting_rejects_live_stock_change_after_snapshot`.
- **F** — same file, `test_stocktake_posting_rejects_a_new_balance_key_committed_after_snapshot`.
- **G** — `tests/Frontend/i2-inventory-operations.test.mjs`, `Inventory Operations uses KPOne controls and server-backed idempotent endpoints`.

| # | Test / base line | Old expectation | Proposed new expectation | Reason |
| --- | --- | --- | --- | --- |
| 1 | A:641 | Reposting error key `stocktake`. | `legacy_stock_path`, exact policy message. | Retired before old status logic. |
| 2 | A:656 | Stale-source posting error key `stocktake`. | `legacy_stock_path`, same message, full stock state unchanged. | No legacy post can now reach stock mutation. Governed stale checks stay tested. |
| 3 | A:669 | New-balance-key posting error key `stocktake`. | `legacy_stock_path`, same message; existing movement-count assertion stays. | Same retirement policy. |
| 4 | B:809 | Invalid reason error key `reason_code`. | `legacy_stock_path`. | Authorised legacy call is closed before payload processing. |
| 5 | B:810 | Overdraw error key `quantity`. | `legacy_stock_path`. | Closed call cannot debit at all; governed negative-stock tests remain. |
| 6 | C:857 | Successful retries return the same adjustment id. | Both calls refuse; full ID-ordered stock/evidence/audit snapshots equal the pre-call snapshot. | No result or new adjustment can be returned by a retired mutation. |
| 7 | C:858 | One `inventory_adjustments` row. | Zero new rows (zero in this fixture). | Refusal cannot post. |
| 8 | C:859 | One adjustment_in movement. | Zero. | Same. |
| 9 | C:860 | One inventory.adjustment.posted audit. | Zero. | No successful-post audit on a refusal. |
| 10 | C:861 | Balance quantity 5 exists at the fixture location. | No balance row is materialised by the refused call; assert absence and equal snapshot. | This fixture initially has references only, no balance (`inventoryFixture:948–959`). |
| 11 | C:863 | Changed quantity under same key yields `idempotency_key`. | `legacy_stock_path`, no state change. | No key is reserved/replayed by this retired API. |
| 12 | C:864 | Still one adjustment after conflict. | Still zero. | Same. |
| 13 | C:865 | Still one adjustment_in movement. | Still zero. | Same. |
| 14 | C:866 | Balance remains 5. | No new balance row; equal snapshot. | Same. |
| 15 | C:869 | HTTP session error key `idempotency_key`. | `legacy_stock_path` and explicit Inventory redirect. | HTTP mirrors domain retirement. |
| 16 | D:946 | Two `ADJUSTED` worker messages. | Two `STALE legacy_stock_path` policy-refusal messages under the existing worker exception protocol, and exact policy error checked by new domain/HTTP tests. | Both actors refuse instead of posting. |
| 17 | D:947 | One idempotent adjustment row. | Zero. | Refusal. |
| 18 | D:948 | One adjustment_in movement. | Zero. | Refusal. |
| 19 | D:949 | One successful-post audit. | Zero. | Refusal. |
| 20 | D:950 | Balance 11.000. | Initial 10.000, unchanged. | No +1 legacy post. |
| 21 | E:967 | Output contains ADJUSTED. | Output does not contain ADJUSTED. | Retired leader mutation cannot succeed. |
| 22 | E:968 | Output contains STALE stocktake. | Two policy refusals (`STALE legacy_stock_path`); historical baseline still 11.000/review/no count movements. | Authority-first closure supersedes legacy stale-status processing. |
| 23 | F:989 | Output contains ADJUSTED. | Output does not contain ADJUSTED. | Same. |
| 24 | F:990 | Output contains STALE stocktake. | Two policy refusals; retain 10.000 original / 1.000 extra-key / review / zero count movements from the prepared historical fixture. | Same. |
| 25 | G:174 | Component contains `adjustmentIdentity.value = undefined`. | Component contains no `adjustmentIdentity` mutation state. | Remove the retired handler/state rather than hide it. |
| 26 | G:182 | Component contains `confirmStocktakeTransition`. | Component does not contain that actionable handler. | Remove legacy actions. |
| 27 | G:183 | Component contains `confirmAdjustment`. | Component does not contain that actionable handler. | Remove legacy actions. |
| 28 | G:156, `Inventory Operations uses KPOne controls and server-backed idempotent endpoints` | `assert.match(component, /<OperationalSelect/g);` | `assert.match(component, /<InventorySearchSelect/g);` | Separately approved by the owner after the stop: every old OperationalSelect belonged to removed legacy forms; pin the shared search picker that remains in kept operations. The raw `<select>` prohibition and all other assertions remain intact. |

All other existing assertions, test names and skip statuses are proposed to remain unchanged. The old behaviour-oriented test names are retained for traceability; this report explains the deliberate contract change. Do not keep an unsafe production endpoint solely to satisfy a withdrawn success assertion. Preserve the numerical/history assertions with synthetic historical setup and add the new governed/kept-flow and full-snapshot checks instead.

## 7. Approved fixture and test sequencing changes

The named fixture/sequencing changes below are now implemented. Existing workers and generic race helpers remain unchanged.

| File / test | Proposed setup change | Assertions retained / reason |
| --- | --- | --- |
| InventoryOperationsTest A (`625–673`) | Replace legacy create/start/count/post and intervening manual-adjust setup calls with explicitly constructed synthetic historical headers/lines/movements/balances in the same relevant states. Call the closed post and prove refusal around the relevant historical snapshots. | Keep expected 10 snapshot, posted/review statuses, 12/11/12 quantities, one gain/one loss and unchanged movement-count checks; only section-6 error keys change. Retained rows are fixtures, not newly legitimised application APIs. |
| Same, `test_operations_directory_batches_document_line_queries:783` | Insert two historical draft stocktake fixtures/lines instead of calling createStocktake. | Same two documents and one-query-per-line-table budget. No query projection refactor. |
| Same B (`808`) | Prepare one historical posted adjustment with quantity 5 and its matching movement/balance before testing a refused new attempt, then run the existing reorder/expiry checks. | Keep the one historical adjustment, balance 5, expiry/low-stock/inactive filtering assertions. Only the two obsolete validation-key assertions change. |
| Same C (`855–868`) | Capture the references-only baseline, catch each same/changed-key policy refusal, never use a returned adjustment object. | Explicit changed assertions are individually listed above; no synthetic successful post is substituted for these refusal checks. |
| Same, `test_technical_admin_http_route_is_denied_and_posted_evidence_is_immutable:936` | Load a synthetic historical posted adjustment instead of posting a new one through the retired service. | Keep Technical Admin 403 and the exact deletion LogicException. |
| PostgreSQL tests E/F (`958–965`, `979–987`) | Prepare historical review snapshots representing the same stale quantity/new-key situations. Use the existing organisation-lock `race` helper with `adjustment-replay` and `stocktake-post` workers, rather than `leaderThenFollower` which requires a successful retired adjustment to reach LOCKED. | Preserve historical quantities/status/count assertions; only section-6 output expectations change. Both workers still execute the real closed domain methods and must refuse. |
| StockMovementReportTest (`134–137`) | Replace four direct legacy writer calls with synthetic retained legacy rows and matching balance effects, preserving types/references/quantities/unit snapshots/frozen timestamps. | Every report, reconciliation, privacy, historical stability and unrelated-row assertion remains unchanged, including closing 12.250. |
| PilotScaleReportTest `fixedFixture:86` (used by tests at `112`, `130`) | Same synthetic historical replacement for its -1.250 adjustment. | Both reports/scale tests and all expected outputs/query budgets remain unchanged. |

Use a clearly named **new test-only historical fixture helper**; do not disable constraints/triggers, edit migrations, load old application code, rewrite a real row, or add an application bypass for testing. Preserve existing FK/unit/movement shape checks. This is controlled synthetic bootstrap, not a new runtime writer.

**No worker-class, timeout, child-database setup/cleanup or generic contention-protocol change is proposed.** The existing worker `adjustment-replay` and `stocktake-post` branches already catch ValidationException as `STALE <error-key>` (`PostgresDispensaryInventoryWorker.php:256–272`). The retired `adjustment-hold` mode reaches LOCKED only after success (`:87–104`) and would no longer be used by E/F. Keep its support code untouched rather than refactor unrelated worker infrastructure. The existing `race` helper observes database blocking on an organisation lock (`PostgresDispensaryInventoryRegressionTest.php:1278–1304`); retain those assertions. Do not claim that this is a race between two successful stock posts. STK-5's real approval/dispensing race remains unchanged and must run, not skip.

## 8. Planned new tests, gate and evidence — after approval only

- Catalogue-role matrix at six routes and eight domain entry points: authorised CA Supervisor receives the exact policy error; other roles keep permission-first 403; active/ended/revoked/foreign branch/org cases; warehouse legacy scope closed too. No grant or downgrade workaround.
- Full ID-ordered snapshots of all inventory/stock/hold/reorder/evidence tables before/after refusals, including already-posted retry keys, same/changed UUID double-submit, zero-variance input and invalid payloads after successful authority. No successful-post audit created.
- Governed positive/negative count, write-off and reversal remain valid for approved actors; proposer/counter/recounter self-approval still fails in domain and SQL; unchanged STK-5 tests plus focused kept-path regressions.
- Opening/sign-off, real-expiry receiving, approved receipt reversal, dispensing and direct/two-stage transfer still work. Existing kept tests remain; add a focused integration fixture if necessary without changing their assertions.
- Historical payloads and Stock Movement Summary return exactly the same numbers before/after refusal, including legacy gain/loss/adjustment rows and inactive references. No allowlist shrink, no hidden historical types, strict unknown-type refusal.
- Static application writer/caller guard and frontend absence of live legacy handlers/endpoints; retained history, closure note, governed links and 44px targets.
- Early php -l, Pint, PHPStan level 7; exact composer ci:check; frontend type/lint/format, build, full frontend glob, explicit PostgreSQL replay with no skips, separate disposable migrate:fresh --seed, tracked/new-file whitespace checks. No full gate has run for a source-only proposal.
- Create a fresh **STK-6A-owned** PostgreSQL 18 cluster/port and UTF8/template0 test/seed databases only after approval. Print process and effective host/port/name before first use. Never reuse STK-5, demo, served or other-worktree databases. Child tests check actual child database name and UTF8, never server/client address equality. Fixtures choose explicit branches and freeze dates where relevant.
- 390/1440 light/dark Inventory operations page: no old actions, history readable, note/links visible, no horizontal overflow, measured 44px targets. User explicitly allows headless Playwright if the Chrome tool is unavailable; label that evidence honestly. No evidence file in the commit.

## 9. Proposed implementation footprint

After approval only: `InventoryControlService.php` (six closed mutations), `InventoryMovementService.php` (two closed public helpers and approved advice-only copy), `InventoryOperationsDirectoryService.php` (two false legacy capabilities), `InventoryOperationsPanel.vue` (remove actions/retain history/note), `GovernedStockService.php` and `GovernedStock.vue` (informational bypass-warning copy only), new STK-6A tests/historical fixture, the five existing test files named above only for approved compatibility edits, and this report. Routes, controller redirects, catalogue/grants, historic migrations, report calculations, kept posting methods, existing worker implementation and governed approval logic remain unchanged.

## 10. Original approval request — approved by the owner

Approve or amend:

1. Freeze **all six** legacy mutations, including create/start/count/cancel, and both public legacy ledger helpers. Keep history and URL registrations.
2. Permission-first contract: existing 403 for non-holders, including Director/Finance Officer; exact `legacy_stock_path` validation message after authorised checks, explicit Inventory redirect / normal JSON 422. No new audit.
3. No migration/permission change; application closure plus static regression guard. Privileged direct SQL remains outside this proposal's guarantee. A new database guard requires separate approval.
4. The **27 individually listed assertion edits**, named historical-fixture changes and E/F sequencing change. No test deletion/skip, no blanket permission to change anything else. Other assertions, names, workers and timeouts stay.
5. Wording-only updates to obsolete opening advice and the STK-5 bypass notice; no kept-flow business-rule change.

The owner answered approval before implementation. No commit, push or PR is authorised yet. A new runtime caller/unlisted flow, additional assertion failure, cleanup problem, historical-read regression, permission change or foreign database need remains a stop.

## 11. Implementation and validation

### Implemented boundary

`LegacyStockPath` centralises the authority-first refusal. All six `InventoryControlService` legacy methods and both public legacy ledger helpers delegate to it. Existing permissions, active actor and effective branch checks run before the policy error. Existing non-holder HTTP 403 remains; an authorised CA Supervisor gets the exact section-4 message, explicit `/inventory` redirect for form requests and JSON 422. Existing middleware still records its ordinary `authorization.denied` event for a 403; STK-6A adds no refusal audit. Authorised refusals leave audit rows unchanged too.

Routes and controller redirects are retained. No migration, permission, revocation, dependency, stock conversion or historical rewrite. The unused old private stocktake transition/validation helpers and adjustment-only constant/injected ledger property were removed after checking that no kept method uses them. `setReorderLevel`, receiving, transfer and other kept posting bodies remain unchanged except for the specifically approved opening-balance advice text. The obsolete STK-5 bypass warning now states that the legacy paths are closed; governed approval logic is unchanged.

The Inventory operations payload retains its keys, with `stocktake` and `adjust` capabilities false. Legacy inputs, handlers and confirmation actions are removed, while stocktake and adjustment history stays visible, including counted/expected/variance quantities. Two minimum-44px links lead to governed count and write-off. No hidden legacy action remains in the component. A TypeScript optional `varianceQuantity` declaration describes the already-existing projection; it adds no payload value.

### Existing assertion and fixture review

All **28** approved old/new assertion contracts are individually recorded in section 6: the original 27 and the one separately approved picker assertion. No unapproved existing assertion, expected report value, test name, timeout or skip was changed. Section 7 lists the historical fixture changes. The new `LegacyStockHistoryFixture` directly inserts synthetic retained history and its matching balances; it invokes no retired runtime writer, disables no trigger and changes no migration. It is test-only, not an application API.

Method-body comparison against the base confirms that every other existing method in the four changed PHP test files is byte-identical. Existing report, history and immutability expected values remain intact. The only changed existing frontend assertions are section-6 entries 25–28. Private review artifact: `existing-method-review.json` in the evidence directory.

`PostgresDispensaryInventoryRegressionTest` keeps `test_transfer_and_dispense_from_same_balance_serialize_and_reconcile` unchanged: **dispensing races direct stock transfer**, an allowed kept path. A new `test_stk6a_kept_direct_transfer_races_dispensing_with_one_winner_and_conserved_stock` strengthens the same real race with one winner, one validation refusal, exact remaining/destination quantities, unchanged prior movements, exactly one winning audit and no legacy rows. It uses the existing worker and blocking-observation protocol unchanged. The three retired-path races assert exact policy refusal and full unchanged stock snapshots. The E/F switch from the success-dependent `leaderThenFollower` to the existing organisation-lock `race` helper was explicitly part of the approved section-7 proposal; no generic helper or worker was edited.

### New tests and source protection

- `LegacyStockClosureTest::test_exact_message_all_catalogue_roles_and_eight_legacy_entrypoints_leave_stock_unchanged`: all catalogue roles at eight domain entrypoints and six routes, exact message/redirect, stock snapshots; existing denial audit retained for 403.
- `test_fresh_revocation_inactive_ended_assignments_and_same_or_conflicting_retries_stay_closed`: fresh revocation, inactive and ended assignments, repeated/conflicting/historical keys and malformed input, no state change.
- `test_foreign_branch_and_organisation_legacy_history_remain_scoped`: 404 without historical-document disclosure.
- `test_history_report_numbers_and_stock_rows_are_identical_after_json_refusals`: legacy gain/adjustment fixture, identical history and report rows/totals, same stock.
- `LegacyStockWriterBoundaryTest`: guards all eight refusal bodies, the single private movement writer, allowed public and private posting call graph, absence of runtime callers of the retired helpers, and absence of direct new model/table/SQL movement writers outside that boundary. Read-only audit morph-type construction is explicitly distinguished from a writer. New routes/services using an ordinary direct writer or a retired helper fail this guard.
- `legacy-stock-closure.test.mjs`: old controls, endpoints and hidden handlers absent; governed links, minimum target size, retained history fields and kept receiving/transfer/reorder handlers present.

This is an application/source boundary, not a new database ban on all adjustment-type rows. Governed changes and approved receiving corrections legitimately use those types. Privileged direct SQL and deliberately obfuscated dynamic code are outside the source guard's guarantee.

### Kept paths and named proofs

| Kept path | Tests (existing unless marked new) | Why it stays |
| --- | --- | --- |
| Opening balance | `InventoryFoundationTest::test_opening_balance_and_branch_transfer_write_immutable_movements_and_distinct_balances`; `test_opening_balance_cannot_be_recorded_twice_for_the_same_stock_location` | One-time opening, not a loss/count bypass. |
| Independent opening sign-off | `StockUnitReadinessTest::test_exact_grants_all_catalogue_roles_and_independent_checker`; `test_signoff_changes_only_new_evidence_and_audit_and_postgresql_protects_it` | STK-1 evidence/control retained. |
| Receiving | `InventoryOperationsTest::test_purchase_order_lifecycle_partial_and_final_receiving_are_atomic_and_idempotent`; `VerifiedReceivingTest::test_partial_split_lots_exact_conservation_and_idempotent_retry` | Real-expiry/delivery/exception approval remains STK-3. |
| Approved correction | `VerifiedReceivingTest::test_whole_receipt_reversing_correction_requires_independent_approval_and_retains_originals`; `test_correction_audit_failure_rolls_back_and_correction_evidence_is_immutable` | Independent receipt correction legitimately debits adjustment_out. |
| Dispensing/direct transfer | The two PostgreSQL race tests named above, plus the unchanged Inventory Foundation test | Actual stock use/transfer; shared-balance locking retained. |
| Two-stage transfer | `InventoryOperationsTest::test_stock_request_dispatch_and_receipt_are_two_stage_exactly_once_movements` | Dispatch/in-transit/receipt remains intact. |
| Governed count/write-off/reversal | `GovernedStockTest::test_positive_negative_exact_variances_reconcile_and_no_legacy_rows_change`; `test_ca_supervisor_approval_excludes_participants_in_payload_domain_database_and_retries`; `test_reversal_appends_inverse_movement_and_original_evidence_stays_exact`; `PostgresGovernedStockTest` | Independent approval remains the count/loss/write-off path. |
| Reorder levels | `InventoryOperationsTest::test_adjustment_and_reorder_visibility_preserve_nonnegative_and_expiry_rules` | Its reorder assertions unchanged; only the approved adjustment refusal keys changed. |
| Holds | `BatchHoldTest::test_hold_release_change_only_declared_evidence_and_values_free_audit_and_are_immutable`; `PostgresBatchHoldTest` | Holds do not post a count/loss movement. |
| Reporting/history | `StockMovementReportTest::test_exact_quantities_reconcile_to_balances_and_remain_historical_with_no_source_mutations`; `PilotScaleReportTest`; new history comparison above | Same retained data, same numbers and budgets. |

`LegacyStockWriterBoundaryTest::test_kept_posting_helpers_and_reorder_never_call_the_closed_helpers`, together with its exact caller graph, verifies these posting engines never delegate to the refused helpers. Reporting/holds have no retired-helper call anywhere in application source. None of the kept callers was redirected to a refusal.

### Owned validation environment

PostgreSQL **18.6**, process/effective host **127.0.0.1**, port **58686**, test database **kpone_stk6a_testing**, cluster **/private/tmp/kpone-stk6a-pg18**. Separate **kpone_stk6a_seed** and **kpone_stk6a_preview** databases. All created for STK-6A with UTF8/template0. Process host/port/name and actual database/encoding/version/cluster directory verified before application use. No server/client address comparison in tests. Task-only PHP 8.4 / 1 GiB; explicit placeholder environment; independently copied dependency folders, no new dependency or install.

Testing, seed and preview each use a distinct temporary storage root under **/private/tmp/kpone-stk6a-evidence**. No preview server runs while the suites run. All evidence/logs remain private outside the repository.

Early checks: changed/new PHP syntax passed, Pint passed, PHPStan level 7 passed (0 errors). Scoped approved regression run: **29 passed / 820 assertions**. New closure plus original PostgreSQL class before the added stronger race: **33 passed / 904 assertions**, no skips. New stronger real race: **1 passed / 12 assertions**, 1.416 seconds. New static guard: **4 passed / 1,515 assertions**. These are intermediate results; final full-gate totals follow below.

Validation repairs affected new code/setup only: (1) ignored Wayfinder typings generated after creating the temporary view cache directories; (2) new snapshot test now accounts explicitly for existing 403 denial audits while still comparing every stock row; (3) foreign-branch synthetic setup uses forceFill for its guarded organisation_id; (4) new source-guard query matching stops at a statement boundary so a read followed by an unrelated write is not misclassified; (5) unused UI import removed after deleting legacy controls, and new frontend test lint corrected. No existing assertion beyond section 6 changed to make a run pass.

Automatic review rejected a bulk-edit attempt and then a multi-method race patch before either ran. Exact approved section-7 sequencing was rechecked; smaller explicit patches were applied. No unreviewed bulk substitution or change to generic contention helpers was used.

## Resolved validation stop — separately approved picker assertion

The full frontend run reached **521 tests: 520 passed, 1 failed, 0 skipped**. This is not a passing gate. Exact failure:

```text
tests/Frontend/i2-inventory-operations.test.mjs:139
Inventory Operations uses KPOne controls and server-backed idempotent endpoints
The input did not match the regular expression /<OperationalSelect/g.
```

The failing assertion was line 156: `assert.match(component, /<OperationalSelect/g);`, outside the original 27 individually approved changes. It was left untouched at the stop. All component uses of OperationalSelect belonged to the retired stocktake/manual-adjustment forms; keeping a fake or hidden select just to satisfy the test would contradict the approved removal. The now-unused import was removed following the lint error, rather than suppressing lint.

The owner subsequently answered **“Approve this one assertion change.”** That exact one-line replacement is now applied and recorded as section-6 entry 28, preserving the immediately following `assert.doesNotMatch(component, /<select\b/i);` and every other assertion. It pins the actual shared, server-backed picker still used by the kept operations, while the new closure test pins removal of legacy controls/handlers. No assertion was deleted or skipped.

Implementation stopped when this additional failure was identified and resumed only after that approval. The already-running exact `composer ci:check` completed successfully: **1,563 reached / 1,561 passed / 33,298 assertions / 2 existing skips / 0 failures**, **788.607 seconds**. The runtime PHP remains identical; the approved frontend expectation and a further strengthening of the new source guard were then applied. A new exact CI replay checks the final test tree; its result is recorded below. Early PHP checks, standalone frontend type/lint/format, production build and separate disposable fresh migrate/seed passed. Chrome was retried and reported `The browser access host is unavailable`; installed headless Playwright is the authorised fallback, without installing a dependency.

Private logs: `/private/tmp/kpone-stk6a-evidence/frontend-final.log` (retained initial failure), `full-ci-second.log` (successful exact CI invocation), `fresh-seed.log`, and the early/scoped logs above. No commit, push or PR. The worktree remains intentionally dirty for owner review.

### Final source-guard review

The new guard was tightened so only the shared writer's single constructor is exempt, rather than exempting its entire file; raw insertion shortcuts are checked in that file too. The first edit accidentally hit the earlier directory-filter `continue`, producing a warning, a deprecation and directory-read notices in the new test. That invalid replay was interrupted, retained as `ci-approved-final.log`, and is not passing evidence. The exact branch was repaired, with no existing test edit. Focused verification using `--fail-on-warning --fail-on-notice --fail-on-deprecation` then passed **4 tests / 1,518 assertions**, no diagnostics (`guard-final-test.log`). Syntax, Pint and PHPStan passed again before the final clean CI replay (`ci-approved-final-clean.log`).

The explicit PostgreSQL replay passed **18 classes / 120 tests / 1,202 assertions / 0 failures / 0 skipped**, 99.230 seconds (`postgres-replay.log`, `postgres-classes.txt`, `postgres-replay-summary.json`). This includes the original and strengthened real dispensing/direct-transfer races and the unmodified governed, receiving, hold, queue, billing and other PostgreSQL suites. No timeout, worker protocol or child-database guard was relaxed.

The approved full frontend replay passed **521 tests / 0 failures / 0 skipped**, 2.503 seconds (`frontend-approved.log`); types, lint, formatting and production build passed (`frontend-*-approved.log`, `build-approved.log`). The initial 520-pass/one-failure log remains available; it has not been replaced or represented as green.

### Browser fallback setup

The Chrome connector returned `The browser access host is unavailable`. The installed Google Chrome could not start for headless capture either; read-only `codesign --verify` reported `invalid signature (code or signature have been modified)` for arm64. No signature, quarantine, security setting, existing profile or installed browser was changed. Only the task's blank-browser processes were stopped.

The authorised fallback uses existing Playwright **1.57.0** and its pinned, task-only **headless Chromium 143.0.7499.4**, downloaded under `/private/tmp/kpone-stk6a-evidence/playwright-browsers`. A blank launch/close readiness check succeeded. This is a temporary validation tool, not a Composer/npm dependency or repository change. Browser captures must be labelled **headless Playwright**, not connector/interactive Chrome evidence. The application preview stays stopped until all backend tests finish, and then uses the separate owned preview database/storage and normal synthetic login.

## 12. Final gate and handoff

HEAD tested: **eba433616ad774fe374fe4859f22c3ca5e6966dd plus the uncommitted STK-6A diff** on `fix/stk-6a-close-legacy-stock-bypass`. There is no new commit SHA. All 18 changed/new code and test files were fingerprinted before final validation and remained byte-identical after evidence capture (`validated-source-hashes.json`). The nineteenth changed/new file is this report. Worktree intentionally **not clean**, ready for owner diff review.

| Check | Final result | Private log |
| --- | --- | --- |
| `php -l`, all new/changed PHP; final guard checked again | Pass | `php-lint-final.log`, `guard-final-syntax.log` |
| Pint | Pass | `guard-final-pint.log`, final CI log |
| PHPStan level 7 | Pass, 0 errors | `guard-final-phpstan.log`, final CI log |
| Exact `composer ci:check` | **1,563 reached: 1,561 passed, 33,301 assertions, 2 existing skips, 0 failures**; 796.213 seconds | `ci-approved-final-clean.log` |
| Frontend types / lint / formatting | Pass | `frontend-types-approved.log`, `frontend-lint-approved.log`, `frontend-format-approved.log` |
| Production build | Pass | `build-approved.log` |
| `node --test tests/Frontend/*.mjs` | **521 passed, 0 failed, 0 skipped** | `frontend-approved.log` |
| Explicit PostgreSQL replay | **18 classes, 120 passed, 1,202 assertions, 0 failed, 0 skipped** | `postgres-replay.log`, `postgres-replay-summary.json` |
| Separate `migrate:fresh --seed --force` | Pass, synthetic reference/development data, **kpone_stk6a_seed** | `fresh-seed.log`, `seed-db-verification.log` |
| Tracked and new-file `git diff --check` | Pass | Final handoff checks |
| UI evidence | **4 states / 8 screenshots**, headless Playwright | `EVIDENCE.md`, `layout-metrics.json` |

The exact CI sequence runs lint, formatting, types, config clear, Pint, PHPStan and the whole backend suite in the repository's configured order. No production limit, existing test name, skip status, timeout, generic contention protocol, immutability protection or stock/report assertion was weakened. Two existing public-registration skips remain; **no PostgreSQL test was skipped**. All required test commands finished before starting the preview.

### Evidence

Private evidence manifest: **[/private/tmp/kpone-stk6a-evidence/EVIDENCE.md](/private/tmp/kpone-stk6a-evidence/EVIDENCE.md)**. Screenshot paths, all **headless Playwright**:

| State | Full Inventory page | Focused legacy history |
| --- | --- | --- |
| 1440 light | [1440-light-inventory](/private/tmp/kpone-stk6a-evidence/1440-light-inventory-headless-playwright.png) | [1440-light-legacy-history](/private/tmp/kpone-stk6a-evidence/1440-light-legacy-history-headless-playwright.png) |
| 1440 dark | [1440-dark-inventory](/private/tmp/kpone-stk6a-evidence/1440-dark-inventory-headless-playwright.png) | [1440-dark-legacy-history](/private/tmp/kpone-stk6a-evidence/1440-dark-legacy-history-headless-playwright.png) |
| 390 light | [390-light-inventory](/private/tmp/kpone-stk6a-evidence/390-light-inventory-headless-playwright.png) | [390-light-legacy-history](/private/tmp/kpone-stk6a-evidence/390-light-legacy-history-headless-playwright.png) |
| 390 dark | [390-dark-inventory](/private/tmp/kpone-stk6a-evidence/390-dark-inventory-headless-playwright.png) | [390-dark-legacy-history](/private/tmp/kpone-stk6a-evidence/390-dark-legacy-history-headless-playwright.png) |

All four focused captures were visually inspected: history, closure note and governed links remain readable. The legacy action buttons are absent. Document scroll width equals the CSS viewport in all states (**390/390 and 1440/1440**); all governed count/write-off links measure **44px high**, widths **149.73–171.02px**. No page errors or outbound browser requests were recorded. No overflow or hidden controls were found. The preview used normal local synthetic login, separate file-session storage, and disabled SSR only in the temporary harness so no unrelated SSR port was contacted; application code/config was not changed for evidence.

Every inventory table and `stock_movements` is byte-identical before/after the four UI sessions (`inventory-before-ui.json`, `inventory-after-ui.json`), with stock still **11.000**. Ordinary login/audit activity is not claimed absent. Screenshots, snapshots, downloaded browser tooling and logs remain private outside the repository; none is an attachment or proposed committed artifact.

### Limits, deferred work and stopped services

- The closure is an application-path control plus a source regression guard. It is **not** a new PostgreSQL ban on all adjustment-type INSERTs: STK-5 and approved receiving corrections legitimately need these movement types. Privileged direct SQL and deliberately obfuscated future code remain outside this slice's guarantee. A database INSERT guard needs separate approval.
- All legacy states, including old draft/counting/review rows, are retained and read-only. No purge, backfill or automatic conversion into a governed count was added. Start a new governed case rather than completing an old legacy lifecycle.
- No new permission or migration. No role grant/revocation, new document, receiving/expiry/unit policy, professional-regulatory decision or broader STK work. Kept paths, immutable evidence and report numbers remain intact.
- The pilot-blocking application bypass is closed **in this local candidate only**. Served/main code was not deployed or changed. **Not production-approved. Synthetic data only.** Owner review and subsequent release authorisation remain required.

The task preview listener **127.0.0.1:8886** was stopped after snapshots/captures. The task PostgreSQL cluster **/private/tmp/kpone-stk6a-pg18**, port **58686**, was stopped using its own `pg_ctl`. Task browser processes were closed; no unrelated app/database/listener was stopped. Databases and private evidence are retained for review. The served tree remains clean on `main`; the STK-5 worktree was untouched. No `.env` or `.env.preview` read, created or edited. **No commit, push or PR. Wait for the owner.**
