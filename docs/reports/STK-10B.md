# STK-10B — receipt cost evidence (option B of the STK-10 analysis)

Date: 2026-11-06. Branch `feature/stk-10b-cost-evidence` from `main` at `92338f9`. Owner authorisation: "yes, do
option B" in the Inventory team's own session, with the owner's choices recorded in TEAM.md section 6 item 29.
Built by Zulkifli, reviewed by Mei Ling, sketched by Wei Jie. Synthetic data only. Not production-approved.

## Why

`ANALYSIS-STK-10.md` found that KPOne's stock cost evidence could not support a valuation:
- an order estimate was saved as if it were the actual cost;
- the main receiving form had no cost box at all;
- whole sen per stock unit cannot hold a cost like RM 12.34 for 100 tablets;
- the screens said "purchase unit" while the value was kept per stock unit.

Option B cleans up that evidence without building any valuation, report or cost screen. The accountant's policy
(questions in the analysis) is still needed before any valuation.

## What changes for staff

- **Both receiving forms** ask for "Total paid for this batch (RM)" (the simple Post receipt row) or "Total paid
  for this lot (RM)" (the split-lots form). Type the total exactly as on the supplier invoice. A help line says it
  is optional and that a blank uses the order's estimate.
- A rejected lot has no cost box, because no stock enters from it.
- The purchase order's estimate box now reads "Estimated cost per stock unit (RM)", with a line saying it is for
  one stock unit (for example one tablet), not one box.
- **No cost is shown anywhere**, as before. Who may see cost is still the owner's open question.

## Design

- **New columns** on `inventory_receipt_lots` and `stock_movements`: `cost_total_sen` (nullable) and `cost_source`
  (`actual`, `estimate` or empty).
- **How a receipt's cost is recorded** (`ProcurementService`):

  | What the receiver gave | `cost_total_sen` | `unit_cost_sen` | `cost_source` |
  |---|---|---|---|
  | A lot total (new forms) | the total | empty | `actual` |
  | A unit cost (older API callers) | empty | the unit cost | `actual` |
  | Nothing, and the order has an estimate | empty | the estimate | `estimate` |
  | Nothing, and no estimate | empty | empty | empty |

- Sending both a unit cost and a total for one lot is refused.
- The movement writer adds the new columns only for a receipt with a cost source, so older schemas still work.
- **PostgreSQL** checks on both tables (`receipt_lots_cost_shape`, `stock_movements_cost_shape`):
  - a total only with `actual` and no unit cost;
  - `estimate` only with a unit cost and no total;
  - `actual` with exactly one of the two;
  - on movements, a cost source only on `purchase_receipt`.

  Every comparison is NULL-safe, because a CHECK that evaluates to NULL passes. The first draft missed this; the
  test caught it.
- **Existing rows are not changed.** Their cost source stays empty, meaning "recorded before STK-10B".
- **Exact money.** The forms turn RM into sen with whole-number arithmetic, never floating point.
- **Remembered drafts.** The remembered Post receipt draft now keeps the total. A draft saved before this change
  loses only its typed cost.
- **No new permission, route, report or screen.** The Ask KPOne view (AG-7) is unchanged and still shows only
  `unit_cost_sen`.
- **Migration** `2026_11_07_000400` is STK-11; this one is `2026_11_07_000700`. `down()` drops the checks and the
  columns. Existing databases need `php artisan migrate`.

## Tests

- `tests/Feature/Inventory/ReceiptCostEvidenceTest.php` (5 tests):
  - a total kept exactly (RM 12.34 for 100 tablets) and marked actual;
  - a blank cost kept as the estimate and marked estimate;
  - a legacy unit cost marked actual;
  - both together, and a negative total, refused;
  - the PostgreSQL checks, every bad shape;
  - the migration replays.
- `tests/Frontend/stk10b-receipt-cost-evidence.test.mjs` (2 tests): both forms' boxes, help and exact conversion;
  the estimate label.
- **Owner-approved existing assertion changes** (TEAM.md section 6 item 29):
  - `tests/Frontend/i2-inventory-operations.test.mjs`: six lines rename the remembered receipt cost from
    `unit_cost_rm`/`unit_cost_sen`/`unitCostSen` to `cost_total_rm`/`cost_total_sen`/`costTotalSen`, with the same
    values and checks;
  - `tests/Frontend/unified-catalogue-setup.test.mjs`: three lines pin the new aria-label and the renamed receipt
    fields.

  No other existing assertion changed.

## Known limits

- Opening balances (Stock setup and the Medicine Catalogue setup) still take a cost per stock unit in whole sen,
  with no cost source. The accountant's opening-value answer decides what they should take.
- A goods receipt line (the legacy single-lot record) keeps only `unit_cost_sen`.
- Who may see cost, and any valuation, report or export, wait for the owner and the accountant.
