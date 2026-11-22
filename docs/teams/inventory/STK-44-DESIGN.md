# STK-44: Medicine sales page

Owner decision 26, first half (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
**a medicine sales page, top 25 by month: units, sales, gross profit, margin, items sold below cost, for the Director
and Finance.** The new view permission was approved by the owner as P9 ("the medicine sales page with profit:
Director, Finance Officer"). **Classic layout only.** Synthetic data only.

Revision 1, for Mei Ling's review.

## What staff see

A new read-only page, **Medicine sales** (`/inventory/medicine-sales`, `Inventory/MedicineSales.vue`), in the Classic
layout.

1. **Header:** the shared `PageHeader`, "Medicine sales", with one description line: "Your best-selling medicines for
   one month, with the cost of what was dispensed and the profit."
2. **Filters** (a GET form; the page reloads):
   - **Month:** the current month by default, and any of the last 24 months. The current month runs to today.
   - **Branch:** the active branch by default, or "All branches".
   - **Rank by:** Sales (default), Gross profit or Units.
3. **The accountant banner,** `StockValuationReportService::BANNER` ("Management report — cost policy to be confirmed
   by the clinic's accountant…"), as AGENTS.md requires for valuation figures. It sits above the figures, with the
   same wording and style as the Stock value page.
4. **Month totals** in a short line of cards: medicine sales (RM), cost of medicines sold (RM), gross profit (RM),
   margin (%), and the number of medicine lines sold below cost.
5. **Top 25 medicines,** in the shared `OperationalTable` from 1024 px and as cards below that. Columns:
   - Rank, Medicine (display name, with its code muted);
   - Units (the quantity dispensed, in the SKU's stock unit, whole numbers without trailing zeros, for example
     "1,240 tablet");
   - Sales (RM), Cost (RM), Gross profit (RM), Margin (%);
   - Sold below cost: the number of lines, or a dash.
   - When the cost of a medicine is not complete (some dispensed stock had no cost recorded), Cost, Gross profit and
     Margin show "Cost not complete" instead of a figure, with a tooltip and an `aria-label` giving the reason. Such a
     medicine still ranks by Sales and Units, and goes after every complete one when ranking by Gross profit.
   - A line under the table: "Showing the top 25 of N medicines sold this month."
6. **Sold below cost,** a second table: every medicine with at least one line sold for less than its cost this month
   (not only the top 25), with the number of lines and the total shortfall (RM), worst first, 25 a page with its own
   page parameter. An empty state says "No medicine was sold below cost this month."
7. **How this page counts,** a closed disclosure (as on the shared report page), holding the rules below in plain
   words.

The Inventory home gains a "Medicine sales" link next to "Stock value and cost", shown only to holders of the new
permission (the same pattern as STK-28's link in `InventoryController`). No Clinic management row, so the exact
destination map in `ClinicManagementTest` is untouched.

## Who sees it

- **New permission** `inventory.medicine_sales.view.organisation` (P9, owner-approved), for `director` and
  `finance_officer` only. It is not people or access authority. It goes in `PermissionCatalogue` and an additive grant
  migration (`firstOrCreate`, give only what is missing, a missing role logged and skipped, never revoke, `down()` a
  no-op).
- It is separate from `inventory.cost.view.organisation`. ROLE-1 gives cost view to the Store Admin and Account
  Assistant, but the owner named only the Director and Finance for this page.
- The route uses `permission:inventory.medicine_sales.view.organisation`, `sensitive.no-store` and `throttle:60,1`.
  The service checks the permission again, and that the chosen branch belongs to the actor's organisation.
- **Doctors never see cost** (decision 6): no doctor role gets the permission, and a test pins the holders.

## Rules

Everything follows the **invoice**, so each line's sale, quantity and cost belong together:

- **Sales:** medicine lines (`invoice_lines.line_type = medicine`) of invoices **finalised in the month** (branch-local
  dates, Asia/Kuala_Lumpur today) and not voided, at the chosen branch or all branches. This is before invoice-level
  discounts or surcharges, because an invoice adjustment is not split across its lines. It matches STK-28 and the
  Insights item rankings, and the disclosure says so.
- **Units:** the quantity dispensed for those lines, from the dispensing movements of each line's
  `dispensary_item_id` (batch allocations), in the SKU's stock unit. A pack sale from DSP-24 is already stored in the
  base unit (ml), so it counts the same way.
- **Cost:** the cost at which that stock left, from the STK-27 weighted average cost engine (the value of each
  dispensing movement for those allocations). The engine is replayed up to the end of the month, for the SKUs on those
  lines only (`replay(..., skuIds: ...)`), not the whole organisation.
- **Gross profit** = sales − cost. **Margin** = gross profit ÷ sales, to one decimal place, and none when sales are
  zero.
- **Cost not complete:** if any dispensed quantity of a medicine's lines had no cost recorded (the engine's
  `uncosted_quantity`), its cost, profit and margin are not shown, and it is excluded from the profit and margin totals.
  The totals line then says "N medicines with cost not complete are left out of profit and margin."
- **Sold below cost:** a line counts when its cost is complete and its line total is less than its cost. The
  shortfall is cost − line total. This is per line, so one cheap sale shows even when the month's margin is positive.
- **Left out:**
  - lines without a dispensary item (none should exist for medicine lines; they are counted and named in the
    disclosure if any appear, never dropped silently);
  - medicine returned after dispensing (DSP-7): it is not taken off, as in STK-28 and STK-43;
  - treatment-room use, which is not a sale.
- **Difference from Stock value (STK-28), which is intended:** STK-28 counts cost by the date stock was dispensed;
  this page counts it by the invoice's finalised date, so a sale and its cost stay together. Near a month's edge the two
  pages can differ by the medicine dispensed one month and invoiced the next. The disclosure says this in one line.
- Money is whole sen in PHP integers; the engine's milli-sen are rounded once, with `WeightedAverageCostService::sen()`.
- **Stable order:** ties sort by medicine name, then medicine id.

## Speed

One month at one branch is small, but "All branches" could be a few thousand lines.
- Lines come from one grouped query (invoice lines joined to dispensary items).
- The allocations and their dispensing movements come from one more query, with no query per line.
- The engine replays only the SKUs involved.
- I'll measure it on the DEMO-2 walk data and on a synthetic month of 5,000 lines before review, and report the time.

## Audit

Opening the page writes one audit row, `inventory.medicine_sales.viewed`, with the month, the branch id or "all", and
the rank choice. It holds ids and codes only, never figures, as STK-28 does for Stock value. If the audit row can't be
written, the page is not shown.

## Not in this slice

- No download (CSV, XLSX or PDF). Report downloads beyond the authorised ones stay unauthorised.
- No per-doctor or per-patient figures.
- No V2 page; Classic only.
- No change to Stock value, Insights or reorder suggestions.

## Tests (planned)

- Permission holders: exactly Director and Finance Officer, with the additive grant and the migration's `down()`.
  A CA Supervisor, Store Admin, Account Assistant and Resident Doctor all get 403.
- Branch scope: a branch from another organisation gets 404; "All branches" sums every branch.
- Month edges in Kuala Lumpur time: an invoice finalised at 23:59 on the last day counts; one at 00:00 on the 1st of
  the next month doesn't.
- Voided invoices are left out; invoice adjustments are not split.
- Units, sales, cost, profit and margin on a worked example with two batches at different costs. A pack sale from
  DSP-24 counts in ml.
- Cost not complete: an uncosted batch hides that medicine's cost and profit, and leaves it out of the totals with the
  count line.
- Sold below cost: one line under cost in a month that is otherwise profitable; the shortfall, the worst-first order
  and paging.
- Ranking by Sales, Gross profit and Units, with the tiebreak; the top 25 cut and "of N".
- Audit row: ids and codes only; no row is written on a 403.
- The page data holds no patient or doctor field.
- Frontend: the banner, the table and card layouts, "Cost not complete" with its accessible name, the disclosure, and
  the Inventory home link only for holders.

No existing assertion is expected to change.
