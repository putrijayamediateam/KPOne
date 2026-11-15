# STK-7 — supplier returns

Date: 2026-11-05. Branch `feature/stk-7-supplier-returns` from `main` at `2c4c596`. Owner authorisation: Inventory
team backlog item 2, chosen 2026-11-05 (see `docs/teams/inventory/TEAM.md` section 9). Built by Zulkifli, reviewed
by Mei Ling. Synthetic data only. Not production-approved.

## What changes for staff

- **New page: Inventory → Supplier returns** (`/inventory/supplier-returns`). Anyone with `inventory.view.branch`
  sees the branch's returns and their history. Staff appear as "staff #ID", the same as elsewhere in Inventory.
- **Request (CA Supervisor).** The page lists returnable lots from verified deliveries at the branch. For each lot
  it shows the received quantity, the quantity already promised to other returns, the quantity on hand, and the
  most that can still go back (the smaller of what is left on the lot and what is on hand). The supervisor
  enters the quantity, a reason (damaged, expiry too short, quality issue, recall, wrong item, overstock agreed
  with the supplier, other agreed with the supplier) and an optional supplier RMA reference. Nothing leaves stock yet.
- **Approve or reject (Finance Officer or Director).** This must be someone other than the requester. A
  rejection needs a reason: supplier has not agreed, stock still usable, wrong quantity, or other. A rejected
  return no longer holds the quantity, so it can be requested again.
- **Cancel.** The requester can cancel the return while it is waiting for approval or approved but not dispatched.
- **Dispatch (CA Supervisor).** Once approved, a CA Supervisor dispatches the stock, entering a courier or
  delivery note reference. This is the only step that moves stock: one new `supplier_return` movement takes the
  quantity out of the receiving location. Stock can never go negative.
- **Record credit note (Finance Officer or Director).** After dispatch, a credit-note reference and an optional
  amount in ringgit are recorded. KPOne refuses the same credit-note reference twice for the same supplier
  (case and spaces ignored). This is a reference only: no payment, invoice match or supplier balance.
- Inventory → Movements shows these entries as "Supplier Return" with a minus quantity and the reference
  "Supplier return".
- A return can go back from a quarantined (held) lot. Returning stock never releases the hold.
- If the delivery is reversed by a receipt correction before dispatch, approval and dispatch are refused with a
  message saying so; the return can only be rejected or cancelled. If the SKU or location has been made inactive,
  dispatch is refused with a message asking to reactivate it first.

## Design

- **Evidence.** Two append-only tables, `inventory_supplier_returns` (one row per return, frozen at request) and
  `inventory_supplier_return_actions` (one row per step, with a sequential `lock_version`). Every step has a retry
  key and a payload fingerprint, checked after the row lock. PostgreSQL refuses update, delete and truncate on
  both tables.
- **PostgreSQL guards** (in addition to the domain service):
  - a return needs a verified, uncorrected receipt lot that is accepted or quarantined, from the same supplier
    and branch, at the order's receiving location, with the same batch, SKU and stock unit;
  - the lot row is locked, so the quantity promised to open returns can never exceed the lot;
  - steps must follow the allowed order;
  - the requester can never approve or reject their own request, and only the requester can cancel it;
  - a return whose delivery was reversed by an STK-3 receipt correction can no longer be approved or dispatched
    (the service and the trigger lock the same goods-receipt row as the correction, so the two cannot overlap;
    such a return can still be rejected or cancelled);
  - dispatch must point at a matching `supplier_return` movement (same return, location, SKU, batch, quantity and actor);
  - a duplicate credit-note reference per supplier is refused;
  - a deferred trigger on `stock_movements` means a `supplier_return` movement cannot exist without dispatch evidence.
- **Ledger.** `InventoryMovementService::recordSupplierReturn` debits through the existing `debitOperation`
  (exact `StockUnitQuantity` arithmetic, refuses a debit below zero). It is allowlisted for `SupplierReturnService`
  only. `stock_movements_shape_check` accepts `supplier_return` as a source-only movement.
- **Every state change** checks permission and fresh branch assignment through `InventoryAuthorityService` and runs
  in `DB::transaction` with row locks (return, lot, SKU, batch, balance, supplier for credit notes) and `lock_version`.
  Each one writes a structural audit row `inventory.supplier_return.<action>` holding identifiers, version and state
  only. If the audit row cannot be written, the change is refused.
- **Permissions (authorisation boundary change).** There are two new permissions, added by an additive migration
  `2026_11_06_000200` (grant-if-missing, `down()` a no-op) and mirrored in `PermissionCatalogue`:
  - `inventory.supplier_returns.request.branch` for CA Supervisor;
  - `inventory.supplier_returns.approve.branch` for Finance Officer and Director.

  Neither is people or access authority. Evidence migration: `2026_11_06_000100`. Existing databases need
  `php artisan migrate`.

## Tests

- `tests/Feature/Inventory/SupplierReturnTest.php` (14 tests) covers:
  - exact role holders;
  - the full lifecycle with exact balances;
  - requester independence (domain and PostgreSQL);
  - returnable caps (lot, on hand, released by reject and cancel, precision and unit);
  - the state machine, versions and retry keys;
  - duplicate credit notes;
  - revoked, ended, inactive, foreign-branch and foreign-organisation actors;
  - audit-failure rollback;
  - append-only and evidence guards;
  - a held quarantined lot;
  - a reversed receipt (domain and PostgreSQL) and an inactive SKU at dispatch;
  - page, HTTP routes and the movement history label;
  - all unrelated tables unchanged.
- `tests/Feature/Inventory/PostgresSupplierReturnTest.php` is the contention test, on its own disposable database.
  Two CA Supervisors each ask to return 6 from a lot of 10: one wins and the other is refused on `quantity`. Then a
  Finance Officer and a Director approve the same return: exactly one decision and one audit row are kept. Each
  losing worker is proven to wait on a row lock.
- `tests/Frontend/stk7-supplier-returns.test.mjs` (5 tests) covers:
  - ringgit to sen conversion;
  - invalid amounts are sent as a marker the server refuses (never reinterpreted as sen);
  - labels;
  - action buttons and retry keys;
  - the Inventory link.
- **Owner-approved change to an existing assertion:** `tests/Unit/LegacyStockWriterBoundaryTest.php`.
  `recordSupplierReturn` is added to the expected writer map and the `debitOperation` callers list, and to the
  kept-helpers list. No other existing assertion changed.

## Known limits

- One return covers one lot. Only lots from verified, uncorrected receipts can be returned.
- A dispatched return cannot be undone in KPOne.
- A credit note is a reference only.
- The Stock movement summary (Reports team) counts supplier returns under "Adjusted" with a signed quantity.
  It has no column of its own.
- Professional questions for a pharmacist or regulator (regulated items, recall paperwork, retention) remain open.

## Independent review (Mei Ling)

One major finding, fixed before hand-off: a return requested or approved before a receipt correction could still
be dispatched afterwards, taking the same stock out twice. Approval and dispatch now refuse in the service and in
PostgreSQL, with a regression test. Minor findings also fixed:
- the request path now locks SKU before batch (the shared lock order);
- an inactive SKU or location at dispatch gives a clear message instead of a bare 404;
- an invalid credit amount can no longer be read as sen at a different scale;
- stale-page self-approval or cancel shows a message in the dialog instead of a 403 page;
- reason fields show their errors;
- the PostgreSQL credit-note check now locks the supplier row.

Accepted as is (nits): the return number uses a random suffix (a collision fails the request rather than
retrying), and the retry fingerprint is keyed on the app key, as STK-6 is.
