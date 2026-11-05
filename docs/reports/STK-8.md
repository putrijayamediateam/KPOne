# STK-8 — treatment-room usage and waste

Date: 2026-11-05. Branch `feature/stk-8-treatment-room-usage` from `main` at `ed45f76`. Owner authorisation: Inventory
team backlog item 3, chosen 2026-11-05 (see `docs/teams/inventory/TEAM.md` section 9). Built by Zulkifli, reviewed
by Mei Ling. Synthetic data only. Not production-approved.

## What changes for staff

- **New page: Inventory → Treatment room usage** (`/inventory/treatment-usage`). Anyone with `inventory.view.branch`
  sees the branch's entries. Staff appear as "staff #ID", as elsewhere in Inventory.
- **Record (CA or CA Supervisor).** The page lists stock on hand at the branch's active locations. For a batch,
  choose:
  - **Used in treatment**: procedure, wound care or dressing, nebulisation, injection, or other treatment use;
  - **Wasted**: opened but not used, spilled or broken, contaminated, or other waste.

  Enter the quantity and, optionally, the treatment room. The stock comes out straight away through a new
  `treatment_usage` or `treatment_waste` movement. Stock can never go negative.
- **Usable stock only (owner decision 2026-11-05).** An expired, held or unavailable batch cannot be recorded here
  at all, neither as use nor as waste. The page shows why and links to the governed Write-off, which keeps the
  second-person approval for writing off expired or damaged stock.
- **No patient, no bill.** An entry is not linked to a patient or visit, takes no free text, and creates no
  charge. The page tells staff not to enter patient details.
- **Reverse a mistake (CA Supervisor, Finance Officer or Director).** This must be someone **other than the
  person who recorded it**, which is enforced by the domain service and by PostgreSQL. Choose a reason: recorded in
  error, wrong item or batch, wrong quantity, or wrong location. The whole quantity goes back to the same location
  as an `adjustment_in` movement. An entry can be reversed once. A reversal is refused once a governed stock count
  or write-off has been posted for the same batch at that location after the entry, so the stock cannot be counted
  back twice.
- Inventory → Movements shows "Treatment Room Use" and "Treatment Room Waste" with a minus quantity and the
  reference "Treatment room usage". Reversals show as "Adjustment In" with the same reference.

## Design

- **Evidence.** There are two append-only tables:
  - `inventory_treatment_usages`: one row per entry, holding its movement;
  - `inventory_treatment_usage_reversals`: at most one row per entry, holding its credit movement.

  Retry keys and a payload fingerprint are checked under the actor lock. PostgreSQL refuses update, delete and
  truncate on both tables.
- **PostgreSQL guards** (in addition to the domain service):
  - an entry must point at an active location of its own branch, a matching SKU, batch and stock unit, and an
    active treatment room of the same branch (if one is given);
  - the batch must be usable: available, an active SKU and item, expiry after the branch-local date, no active hold;
  - its movement must match type, reference, location, SKU, batch, quantity and actor;
  - a reversal must be by someone other than the recorder, no governed count or write-off may have posted for the
    batch at that location since the entry, and its `adjustment_in` must match the entry;
  - a deferred trigger on `stock_movements` means a `treatment_usage` or `treatment_waste` movement, or any
    movement referencing an entry, cannot exist without its evidence row;
  - `stock_movements_shape_check` now includes the two new source-only types.
- **Ledger.** `InventoryMovementService::recordTreatmentUsage` debits through `debitOperation` (usage, waste) or
  credits through `creditOperation` (reversal), with exact `StockUnitQuantity` arithmetic. It is allowlisted for
  `TreatmentUsageService` only.
- **Every state change** checks the permission and a fresh branch assignment through `InventoryAuthorityService`.
  It runs in `DB::transaction`, locking the organisation and the actor, then (for a reversal) the entry, then the SKU
  before the batch (the shared lock order), then the balance. Each one writes a structural audit row,
  `inventory.treatment_usage.recorded` or `.reversed`, holding identifiers and the kind only. If the audit row
  cannot be written, the change is refused.
- **Permissions (authorisation boundary change).** There is one new permission, `inventory.usage.record.branch`,
  for CA and CA Supervisor, added by the additive migration `2026_11_07_000200` (grant-if-missing, `down()` a no-op)
  and mirrored in `PermissionCatalogue`. Reversal reuses the existing `inventory.variances.approve.branch` (CA
  Supervisor, Finance Officer, Director); no grant changes. Evidence migration: `2026_11_07_000100`. Existing
  databases need `php artisan migrate`.

## Tests

- `tests/Feature/Inventory/TreatmentUsageTest.php` (12 tests) covers:
  - exact role holders;
  - usage and waste debits, the reversal credit and a second reversal refused;
  - recorder independence (domain and PostgreSQL);
  - held and expired batches refused for use and waste;
  - a reversal refused after a governed count or write-off;
  - quantity, precision, unit, reason, room and on-hand limits;
  - retry keys;
  - revoked, ended, inactive, foreign-branch and foreign-organisation actors;
  - audit-failure rollback;
  - append-only and evidence guards;
  - the page, HTTP routes and movement history labels;
  - all unrelated tables unchanged.
- `tests/Feature/Inventory/PostgresTreatmentUsageTest.php` is the contention test, on its own disposable database.
  Two CAs each record 6 from a balance of 10: one wins and the other is refused on `quantity`. Then a CA Supervisor
  and a Finance Officer reverse the same entry: exactly one credit and one audit row are kept. Each losing worker is
  proven to wait on a row lock.
- `tests/Frontend/stk8-treatment-usage.test.mjs` (4 tests) covers:
  - the reason reset when switching kind;
  - labels;
  - the Write-off link for unusable batches, and retry keys;
  - the Inventory link.
- **Owner-approved change to an existing assertion:** `tests/Unit/LegacyStockWriterBoundaryTest.php`.
  `recordTreatmentUsage` is added to the expected writer map, the `creditOperation` and `debitOperation` callers
  lists, and the kept-helpers list. No other existing assertion changed.

## Known limits

- One entry covers one batch at one location.
- An entry is not linked to a patient or visit, has no bill, and there are no kits.
- The treatment room is a label only.
- A reversal returns the whole entry, once.
- The Stock movement summary (owned by the Reports team) counts usage, waste and reversals under "Adjusted".
  It has no column of its own (owner decision 2026-11-05).
- Expired, held or unavailable stock is never recorded here; it goes through the governed Write-off.
- Insights wastage counts stocktake losses and damage adjustments only; treatment-room waste is not in it yet.
- Professional questions for a pharmacist or regulator (which items may be used without dispensing, registers
  for regulated items) remain open.

## Independent review (Mei Ling)

**One major finding, settled by the owner:** as first built, waste let one person write off any batch, including
expired or held stock, which would have bypassed the governed Write-off and its second approver. Owner decision
2026-11-05: usage and waste are for usable stock only. This is now enforced in the service and in PostgreSQL, and
"Expired in the room" was removed as a reason.

Minor findings, also fixed:
- a reversal is refused after a governed count or write-off on the same batch and location;
- a malformed `kind` gives a validation error, not a 500;
- one database-guard test now uses a neutral reference, so it proves the STK-8 trigger;
- unit errors are shown;
- the PostgreSQL room check requires an active room;
- the Inventory movement filter now lists Supplier Return and the two treatment-room types;
- the lock-order wording in this report is corrected.

Accepted as is:
- the contention test settles both races in the domain, so the database unique key and lock are backups;
- a retry that sends "1" instead of "1.000" counts as a different payload;
- Insights wastage leaves out treatment-room waste (listed under known limits).
