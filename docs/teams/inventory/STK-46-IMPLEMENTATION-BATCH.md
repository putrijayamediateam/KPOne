> Owner approval26 Nov: BOTH exact migrations and no-op downs approved; subsequently the exact CREATE OR REPLACE FUNCTION rebuild fix approved. Service/schema implementation and focused validation now pass; independent Billing service review completed without blockers. Earlier draft discussion below records the review history; migration approval is no longer pending.

# STK-46 implementation batch — 26 November 2026

Status: design recovered from `e8cb5898cdac654b0e134c05feb404d1299c49fc` onto main `23639679e`. No original implementation exists on that branch. The owner approved the reviewed migrations on26 Nov. Executable copies and the service/schema slice now live in this isolated worktree and have been tested only on owned synthetic databases. No existing assertion changed. Production application is not authorized by this continuation.

## Decisions already approved; do not ask again

The full 26 November handoff confirms both cost permissions and their exact role sets, retrospective replay from the entry onward with a Changed on notice, and eligibility of pre-STK-10B receipts. The original design's sections 4, 6 and 7 are therefore answered. A different person checks the cost; actual costs are not overwritten; replacement of a previously accepted hand cost retains history. Staff-account management is unaffected.

## Mapping to current code

- `StockMovement` is immutable and uses `stock_movements`; the eligible type constants are `opening_balance` and `purchase_receipt`. Receipt movements link through `reference_type = inventory_goods_receipt` and `reference_public_id` to `inventory_goods_receipts.public_id`.
- `WeightedAverageCostService::replay()` selects movement evidence, then calls pure `replayRows()`. `ownCost()` uses `cost_total_sen * 1000`, otherwise `unit_cost_sen * scaled quantity`; only `cost_source = estimate` marks an estimate. Preserve every old result when no accepted hand cost exists. Introduce an optional map of latest accepted hand costs to pure replay; prefer its whole-sen total and basis `hand`, without mutating movement rows.
- Eligibility: opening requires both original cost columns null. Purchase receipt requires original total null and permits `estimate` or pre-STK-10B null source; explicit `actual` and any existing actual total are refused. Original eligibility remains true after acceptance so a newer checked replacement is possible.
- `ReceiptCorrectionService::reverse()` locks order → location → receiving review → goods receipt, then stock references. New enter/decide commands lock the receipt before the movement/hand-cost record, and recheck `inventory_receipt_corrections.goods_receipt_id`. Neither command may acquire an earlier correction lock after obtaining the receipt. PostgreSQL uses the same receipt lock before validating insert or decision. Two-process race tests must prove correction-versus-entry and correction-versus-acceptance serialise.
- `StockValuationReportService` and `StockJournalSummaryService` consume replay. Journal reads opening and closing with different cutoffs; acceptance must be considered even when accepted after the historical cutoff, because the approved rule restates history. Retain acceptance timestamp and SKU for notices. Transfer propagation requires affected-branch/month evidence, not merely the original receipt branch.
- Existing receiving validates totals up to `999999999999` sen. Use the same integer upper bound so milli-sen calculation remains within the engine's existing contract. No floating point money or quantities.

## Approved schema

The exact PHP draft is `stk46-drafts/2026_11_26_110000_create_inventory_hand_costs.php`. Approved migration `2026_11_26_110000_create_inventory_hand_costs` creates only `inventory_hand_costs`:

| Column | Definition |
|---|---|
| id / public_id | bigint primary key / unique UUID |
| organisation_id | required FK organisations, restrict delete |
| branch_id | nullable FK branches, restrict delete; exactly movement destination branch, including null warehouse |
| stock_movement_id | required FK stock_movements, restrict delete |
| cost_total_sen | bigint, 0 through 999999999999 |
| reason_code | supplier_invoice_found, opening_price_list, estimate_was_wrong, other |
| note | nullable encrypted text; application limits plaintext to 200 characters; required for other |
| reference | nullable string(200) |
| status | waiting, accepted, rejected; inserted waiting |
| entered_by_user_id / entered_at | required user FK / UTC timestamp |
| decided_by_user_id / decided_at | nullable user FK / UTC timestamp; required exactly after decision |
| decision_reason | nullable string(200), required nonblank on rejection |
| lock_version | integer starts 1; decision increments exactly once |
| idempotency_key / payload_hash | required UUID / SHA-256 hex(64), unique organisation/key |
| decision_idempotency_key / decision_payload_hash | nullable UUID / SHA-256 hex(64), required exactly after decision; unique organisation/key when present |

The decision retry fields make the design's “idempotency key on every write” concrete, separating creation from later acceptance/rejection. Existing columns/tables are not altered. Add index organisation/stock_movement/status/decided_at/id, and a partial unique index stock_movement where status=waiting.

Database constraints/triggers: independent decider; correct organisation for movement and actors; branch matches destination; positive initial version; only waiting → accepted/rejected; all original fields immutable; only status/decision fields/version change; no delete/truncate. Insert and acceptance refuse reversed receipts under the receipt row lock. Original actual costs, wrong movement type, cross-organisation movement and reference mismatch fail closed. Encrypted note cannot be validated for plaintext length in PostgreSQL, so the service enforces that rule.

Down is intentionally a no-op, preserving immutable hand-cost history, table, indexes and guard triggers. Any later reversal requires a separately reviewed forward migration. No stock movement is modified in either direction.

The exact PHP draft is `stk46-drafts/2026_11_26_110100_grant_inventory_hand_cost_permissions.php`. Approved migration `2026_11_26_110100_grant_inventory_hand_cost_permissions` adds only the already-approved web permissions:

| Permission | Exact holders |
|---|---|
| inventory.cost.enter.organisation | finance_officer, store_admin |
| inventory.cost.check.organisation | finance_officer, store_admin, director |

Add only those role grants and clear the permission cache; no other role grant changes. Down is intentionally a no-op, matching the recent additive-migration convention and preserving the owner-approved role grants. Any later revocation requires a separately reviewed forward migration. The page additionally requires existing inventory.cost.view.organisation. The permission catalogue changes are already authorized; both exact migration artifacts are now approved, including the subsequent CREATE OR REPLACE rebuild correction.

## Implementation order after schema review

1. New model, eligibility policy and service enter/decide commands; permission/organisation/active-branch checks, immutable hashed request payload, integer validation, receipt/movement/hand-cost locking, fail-closed audit with IDs/codes/state only. No amount, note or reference in audit metadata.
2. New focused feature/constraint/race tests. No existing assertion changes are planned in this first bounded service/schema slice. Any discovered changed assertion is listed individually before modification.
3. Replay override and restatement provenance, with new pure engine and report/CSV tests proving old results unchanged without accepted hand costs; historical beginning/closing balances, replacement, zero cost, rejected/waiting cases and transfer propagation covered.
4. Own Costs to fill in page, scoped menu visibility, two-person flow and Daniel's recorded sheet/dialog conditions. The dedicated page exposes entries/costs, not valuation totals, margin or COGS. Own route/source tests before considering changes to any pinned existing navigation assertion.

## Independent review result

Billing reviewer: no immediate schema blocker; service receipt-before-hand-cost locking and two-process races remain required. The average-cost reversal residual is now explicit in the design. Coordinator review26 Nov selected no-op downs matching recent BIL-38/guardian/additive conventions: schema/history/guards and approved permission grants survive rollback. No destructive history rollback or automatic grant revocation is proposed. Billing reviewer reread both revised downs and confirmed the prior destructive rollback concern closed. Approved executable copies have now been tested on owned synthetic databases only.

## Owner-approved batch —26 November2026

- Approved: the exact new hand-cost table migration above, including decision retry fields, indexes, guard triggers and explicit no-op rollback preserving all history.
- Approved: the exact additive permission migration above with explicit no-op rollback preserving grants; permission names and role sets themselves are already approved.
- Existing assertion changes: none proposed yet. Later UI/report integration may reveal exact pins; inspect and batch their before/after expectations then, without blanket authorization.

A waiting cost can still be rejected after its receipt is reversed, preventing an uncloseable waiting row; accepting it is refused. Independent Billing review26 Nov found no immediate schema blocker, conditional on receipt-first service locking and the planned two-process race proof. It corrected the reversal claim:10×RM10 plus10×RM20 →20×RM15; reversing10 at average leaves RM150 for10 units. Preserve that policy and add this explicit new regression test. Final implementation review must still verify restatement propagation through transfers. The approved executable migrations are under database/migrations; the drafts directory preserves the reviewed artifact. No further schema or existing-assertion changes are proposed in this first slice.


## Service/schema validation

Two successive migrate:fresh --seed runs on the same owned kpone_stk46_rebuild_test database passed. The final focused Unit/Feature run passed 52 tests / 1,206 assertions, followed on that same database by three serial real-process races / 58 assertions, all exit 0. Scoped PHPStan has 0 errors; changed PHP files pass Pint. No-op downs preserve evidence and grant tests pass. The first harness omitted pumping queued GO bytes; corrected without weakening its blocked-process assertions. No existing assertion was changed. Independent Billing service review completed without blockers; the optional cross-checker decision retry regression was added and passes. The integration/full-suite gate remains separate from this focused evidence.

Accepted hand-cost evidence is stored and audited in this slice; it does not yet feed the weighted-average engine. Replay, Changed on report/CSV provenance and the entry/check UI are the next slice, so this partial implementation must not be described as the complete STK-46 workflow.
