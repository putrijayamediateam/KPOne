# STK-44: Medicine sales page

Owner decision 26, first half (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
**a medicine sales page, top 25 by month: units, sales, gross profit, margin, items sold below cost, for the Director
and Finance.** The new view permission was approved by the owner as P9 ("the medicine sales page with profit:
Director, Finance Officer"). **Classic layout only.** Synthetic data only.

Revision 2, after Mei Ling's review (OK with changes, 22 Nov): the replay cut-off, voided and reissued invoices, the
margin denominator, estimates, free lines, units, time zones, an index and more tests are spelled out below.

**Owner decision 2026-11-22** (relayed by B.E.R.N.I.S.S): the purchasing order starts with this slice.

## What staff see

A new read-only page, **Medicine sales** (`/inventory/medicine-sales`, `Inventory/MedicineSales.vue`), in the Classic
layout.

1. **Header:** the shared `PageHeader`, "Medicine sales", with one description line: "Your best-selling medicines for
   one month, with the cost of what was dispensed and the profit."
2. **Filters** (a GET form; the page reloads):
   - **Month:** the current month by default, and any of the last 24 months. The current month runs to today.
   - **Branch:** the active branch by default, or "All branches".
   - **Rank by:** Sales (default) or Gross profit. Not by units, because units are in different stock units across
     medicines (a 1,000 ml syrup would outrank tablets). **Question for the owner:** the decision says "units"; the
     page shows them as a column. If the owner still wants a units ranking, it ranks within one stock unit only.
3. **The accountant banner,** `StockValuationReportService::BANNER` ("Management report — cost policy to be confirmed
   by the clinic's accountant…"), as AGENTS.md requires for valuation figures. It sits above the figures, with the
   same wording and style as the Stock value page.
4. **Month totals** in a short line of cards: medicine sales (RM), cost of medicines sold (RM), gross profit (RM),
   margin (%), the number of medicine lines sold below cost and the number given free.
5. **Top 25 medicines,** in the shared `OperationalTable` from 1024 px and as cards below that. Columns:
   - Rank, Medicine (display name, with its code muted);
   - Units (the quantity dispensed, in the SKU's stock unit, whole numbers without trailing zeros, for example
     "1,240 tablet"). A medicine whose lines used more than one stock unit (its SKU mapping changed) shows each unit
     on its own line, for example "1,200 tablet · 40 capsule", marked "mixed units";
   - Sales (RM), Cost (RM), Gross profit (RM), Margin (%);
   - Sold below cost: the number of lines, or a dash.
   - Given free: the number of lines charged RM 0.00, or a dash. A free line is shown here and **not** counted as sold
     below cost, because it was charged nothing on purpose. The disclosure says so.
   - When the cost of a medicine is not complete (some dispensed stock had no cost recorded), Cost, Gross profit and
     Margin show "Cost not complete" instead of a figure, with a tooltip and an `aria-label` giving the reason. Such a
     medicine still ranks by Sales, and goes after every complete one when ranking by Gross profit.
   - A cost that rests partly on an order estimate (`cost_source = estimate`; the engine marks such movements) shows
     "(estimate)" after the figure, as STK-42 does.
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

- **Sales:** medicine lines (`invoice_lines.line_type = medicine`) of invoices **finalised in the month** and still
  finalised (status `finalized`; a voided invoice is left out), at the chosen branch or all branches. The month is cut
  in **each invoice's branch time zone**. Every branch is Asia/Kuala_Lumpur today, and that is assumed; a test pins it.
- **A voided and reissued invoice** carries the same dispensary item on two invoices. Only the finalised one counts,
  in the month it was finalised, so an item is never counted twice. A test pins one finalised line per dispensary item
  for the period.
- **A past month can change later:** if an invoice is voided after the month ends, it leaves that month's figures.
  The disclosure says so. This is before invoice-level
  discounts or surcharges, because an invoice adjustment is not split across its lines. It matches STK-28 and the
  Insights item rankings, and the disclosure says so.
- **Units:** the quantity dispensed for those lines, from the dispensing movements of each line's
  `dispensary_item_id` (batch allocations), in the SKU's stock unit. Every medicine line has a dispensary item:
  the `invoice_line_shape` CHECK makes `dispensary_item_id` NOT NULL for `line_type = medicine`
  (`2026_09_05_000200_create_billing_foundation.php`). A test asserts it rather than handling a missing one. A pack sale from DSP-24 is already stored in the
  base unit (ml), so it counts the same way.
- **Cost:** the cost at which that stock left, from the STK-27 weighted average cost engine (the value of each
  dispensing movement for those allocations). The engine is replayed **up to now**, not to the month's end, for the
  SKUs on those lines only (`replay(..., skuIds: ...)`), not the whole organisation. A movement's cost is fixed when
  it happens, so replaying further changes no earlier value; cutting at the month's end would miss dispensing recorded
  after an invoice that was finalised just before midnight. The replay runs once per request and is reused for every
  figure on the page.
- **Gross profit** = sales − cost. **Margin** = gross profit ÷ sales, to one decimal place, and none when sales are
  zero.
- **The total margin** uses only medicines whose cost is complete: total gross profit ÷ the sales of those medicines.
  The sales total itself still counts every medicine.
- **Cost not complete:** if any dispensed quantity of a medicine's lines had no cost recorded (the engine's
  `uncosted_quantity`), its cost, profit and margin are not shown, and it is excluded from the profit and margin totals.
  The totals line then says "N medicines with cost not complete are left out of profit and margin."
- **Sold below cost:** a line counts when its cost is complete, its line total is above RM 0.00 and less than its
  cost. The shortfall is cost − line total. This is per line, so one cheap sale shows even when the month's margin is
  positive. A line at RM 0.00 is counted as **given free** instead.
- **Left out:**
  - medicine returned after dispensing (DSP-7): it is not taken off, as in STK-28 and STK-43;
  - treatment-room use, which is not a sale.
- OTC invoices count the same way as consultation invoices (they also carry medicine lines with a dispensary item).
- **Difference from Stock value (STK-28), which is intended:** STK-28 counts cost by the date stock was dispensed;
  this page counts it by the invoice's finalised date, so a sale and its cost stay together. Near a month's edge the two
  pages can differ by the medicine dispensed one month and invoiced the next. The disclosure says this in one line.
- Money is whole sen in PHP integers; the engine's milli-sen are rounded once, with `WeightedAverageCostService::sen()`.
- **Stable order:** ties sort by medicine name, then medicine id.

## Speed

One month at one branch is small, but "All branches" could be a few thousand lines, and the replay can then touch most
SKUs, close to a full history replay.
- **New index** (this slice's migration, in the Inventory range): `invoices (organisation_id, branch_id, status,
  finalized_at)`. None exists on `finalized_at` today. It's additive, and `down()` drops it.
- Lines come from one grouped query (invoice lines joined to dispensary items).
- The allocations and their dispensing movements come from one more query, with no query per line.
- The engine replays only the SKUs involved.
- I'll measure it on the DEMO-2 walk data and on a synthetic month of 5,000 lines, and report the time. **If it is
  over about 2 seconds,** "All branches" is refused for that month with a plain message ("Too much to work out at
  once: choose one branch.") above a set line count, and the limit goes in the note before review.

## Audit

Opening the page writes one audit row, `inventory.medicine_sales.viewed`, with the month, the branch id or "all", and
the rank choice. It holds ids and codes only, never figures, as STK-28 does for Stock value. If the audit row can't be
written, the page is not shown.

## Not in this slice

- No download (CSV, XLSX or PDF). Report downloads beyond the authorised ones stay unauthorised.
- No per-doctor or per-patient figures.
- No V2 page; Classic only.
- No change to Stock value, Insights or reorder suggestions.

## Known limits

- Units are not comparable across medicines, so there is no units ranking unless the owner asks for one.
- A past month changes if an invoice is voided later.
- Invoice adjustments are not split across lines, so a discounted invoice shows its lines at their full price.

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
- Ranking by Sales and Gross profit, with the tiebreak; the top 25 cut and "of N".
- Audit row: ids and codes only; no row is written on a 403; if the audit write fails, the page is not shown (503).
- A voided invoice reissued in a later month: counted once, in the reissue month; one finalised line per dispensary
  item.
- Dispensing recorded after the invoice: its cost is still included.
- The total margin's denominator leaves out medicines with incomplete cost.
- A line costed from an order estimate shows "(estimate)".
- A RM 0.00 line counts as given free, not as sold below cost.
- Mixed stock units for one medicine show both units and "mixed units".
- OTC invoices are included.
- "All branches" equals the sum of each branch.
- Every medicine line has a dispensary item (asserted).
- The number of queries does not grow with the number of lines.
- A DSP-7 return is not subtracted.
- The exact holder list, pinned through `PermissionCatalogue`.
- The page data holds no patient or doctor field.
- Frontend: the banner, the table and card layouts, "Cost not complete" with its accessible name, the disclosure, and
  the Inventory home link only for holders.

No existing assertion is expected to change. The new permission enters the role lists, so I'll confirm on the full
suite. The PR description records the new permission as a change of authorisation boundary.
