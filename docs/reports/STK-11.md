# STK-11 — patient returns location (for DSP-7)

Date: 2026-11-05. Branch `feature/stk-11-dispensary-returns-location` from `main` at `681c8a9`. Owner
authorisation: "go with recommendations for DSP-7", relayed by B.E.R.N.I.S.S (formerly LUCY), the coordinator,
which counts as the owner's own (TEAM.md section 6 item 16); recorded as section 6 item 27. Built by Zulkifli,
reviewed by Mei Ling, sketched by Wei Jie. Contract agreed with Aisyah (Dispensary & OTC). Synthetic data only.
Not production-approved.

## Why

DSP-7 (Dispensary) will let a CA record medicine a patient brings back after dispensing, checked by a different
person. The owner chose Option B of the DSP-7 design note: units kept for a later decision go to a separate
returns place at the branch, so a single returned strip never holds a whole batch. This piece builds that place
and the one stock movement that fills it. Nothing writes to it until DSP-7 merges.

## What changes for staff

- **Stock setup → New Location** has one more type: **"Patient returns (not for dispensing)"**. When it is
  chosen, a short line explains: "Medicines patients bring back go here. Stock here is never dispensed. It leaves
  only by Write-off or Transfer." A returns location must belong to a branch.
- The movement list shows the new movement as **"Patient Return"** (for example "+2 · To Cheras Patient
  Returns", reference "Patient return check"). The location filter now shows plain type names instead of internal
  codes.
- Stock in a returns location:
  - is never offered by Dispensary, any picker or the availability list, and never counts as usable stock;
  - can be counted and written off with the governed Stock count and Write-off (STK-5);
  - can be moved only to another returns location (coordinator's rule, so it never quietly becomes dispensable
    again; a move back to normal stock would need the owner);
  - cannot be used in treatment, returned to a supplier, or sent on a two-stage transfer.

## Design

- **Location type** `InventoryLocation::TYPE_DISPENSARY_RETURNS = 'dispensary_returns'`.
  - Dispensary already allocates only from `dispensary` locations, so allocation, FEFO and batch eligibility skip
    it.
  - A location never changes type to or from `dispensary_returns` (domain, and a PostgreSQL trigger).
- **Movement** `StockMovement::TYPE_DISPENSARY_RETURN = 'dispensary_return'`: incoming, no source, destination a
  returns location, `reference_type = 'dispensary_return_check'`.
  - Written only by `InventoryMovementService::recordDispensaryReturn(User $actor, InventoryLocation $location,
    InventorySku $sku, InventoryBatch $batch, string $quantity, string $returnCheckPublicId): StockMovement`.
  - It must run inside the caller's transaction, uses exact STK-1 thousandths and refuses a location that is not a
    returns location. It does not apply the STK-2 dispensing eligibility rule, on purpose: a returned unit may come
    from a batch that has since expired or been held (agreed with Aisyah).
  - The DSP-7 check step authorises the checker (`inventory.variances.approve.branch`, never the recorder), checks
    the SKU, batch and quantity against the original line, and adds the deferred trigger that pairs each movement
    with its check row (in its own migration, after this one).
- **One rule, twice.** `InventoryMovementService::assertReturnsRules` runs in the single movement writer, and the
  PostgreSQL trigger `guard_returns_location_movement` repeats it:
  - into a returns location: `dispensary_return`, `stocktake_gain`, `adjustment_in`, or a `transfer` from another
    returns location;
  - out of a returns location: `stocktake_loss`, `adjustment_out`, `expiry_writeoff`, `stock_writeoff`, or a
    `transfer` to another returns location;
  - a `dispensary_return` must go to an active branch returns location with the agreed reference type.
- **Allocations.** A PostgreSQL trigger refuses any `dispensary_item_batch_allocations` row on a returns location.
- **Never usable stock.** Returns locations are left out of:
  - the availability list (both views);
  - treatment usage (list and save);
  - STK-4 low stock and expiring (expired-on-hand keeps them on purpose, because they still have to be written
    off);
  - the Inventory page's low-stock and expiring alerts;
  - Insights inventory low-stock and expiring counts;
  - the agent data sources for branch balances, stock review and dispensing on-hand (Agent platform files, one
    filter line each).
  - Governed counts (STK-5) and the agent stock-counts source keep them, so returned stock is still counted.
- **Reports.** The Stock movement summary counts `dispensary_return` under **"Adjusted"**, as the owner decided
  for the STK-8 movement types. The summary is otherwise unchanged.
- **Migration** `2026_11_07_000400_add_dispensary_returns_location`:
  - rewrites `stock_movements_shape_check` to add `dispensary_return` to the incoming group;
  - adds the three triggers;
  - changes no data;
  - `down()` removes the triggers and restores the previous check, which PostgreSQL refuses if a
    `dispensary_return` row exists.
- **No new permission.** Existing databases need `php artisan migrate`.
- **Test helper** `Tests\Support\ReturnsLocationFixture::create()` creates a returns location through the governed
  reference service, for DSP-7's tests.

## Tests

- `tests/Feature/Inventory/DispensaryReturnsLocationTest.php` (6 tests):
  - the credit, its shape and the refusals, the movement list label and the Stock movement summary ("Adjusted");
  - leaving only by write-off or a move between returns locations, with dispensing, treatment use, supplier
    return and a move back refused;
  - never usable: availability, STK-4 low stock and expiring, the Inventory page alerts;
  - the type is kept, and a returns location needs a branch;
  - PostgreSQL refusals: dispense, treatment waste, purchase receipt into it, a transfer back, a two-stage
    dispatch, a mis-addressed or mis-referenced `dispensary_return`, an allocation row, a type change either way;
  - the migration replays.
- `tests/Frontend/stk11-returns-location.test.mjs` (2 tests): the option, help line and errors, and plain
  labels.
- **Owner-approved existing assertion change:** `LegacyStockWriterBoundaryTest` lists
  `recordDispensaryReturn` as a writer with no caller yet, and as a `creditOperation` caller (the same kind of
  change as STK-7 and STK-8). No other existing assertion changed.

## Known limits

- Nothing writes `dispensary_return` until DSP-7 merges. The movement-to-check pairing is Dispensary's trigger.
- There is no page that lists returns stock on its own; it shows in the stock view and STK-5.
- Expired returned stock stays on STK-4's "expired on hand" list, on purpose.
