# STK-42: Expiry alert days per item and a 6-month at-risk list

Owner decision 25 (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`): **expiry alert days per
item (default 90) plus a 6-month at-risk list sorted by cost.** **Classic layout only** (coordinator, 21 Nov; the Stock
review page has no V2 version anyway). Synthetic data only.

Revision 2, after Mei Ling's review (OK with changes, 21 Nov; revision 2 OK, 21 Nov): Hana's tool, the acknowledgement list, the window
wording, cost leaving the page, the valuation label, who sees value, sorting cost, the usable-stock rule and the query
are now spelled out below.

## What staff see

1. **SKU edit screen (STK-14)** gains "Alert before expiry (days)", optional. Empty means the default, 90. Allowed: a
   whole number from 7 to 365. Help line: "Stock review lists a batch as expiring this many days before its expiry
   date. Leave empty for 90 days." The field shows only when the SKU tracks expiry (`expiry_tracking_required`); when
   it doesn't, it is hidden and a sent value is ignored.
2. **Stock review, "Expiring" list** uses each SKU's own number. The page sentence (`StockReview.vue`, now "within
   {windowDays} days") becomes "within each item's alert days (90 days unless set on the item)". The low-stock and
   expired lists are unchanged.
3. **Stock review gains "At risk in 6 months"**, a separate read-only section (not an acknowledgeable list). It shows
   usable batches expiring within 183 days of the branch-local date at this branch: one row per batch and location,
   with the quantity, expiry date and days left, and search (`q`) as on the other lists. 25 rows a page, with its own
   page parameter (`at_risk_page`).
   - **For people who may see cost** (`inventory.cost.view.organisation`: Director and Finance Officer today, per
     STK-13 and decision 6; Store Admin and Account Assistant only once ROLE-1 grants it), the list is **sorted by
     value, highest first**, with a "Value (RM)" column. Value = quantity × the SKU's current weighted average cost
     at that branch (STK-27). The help line says: "An average cost, not what was paid for this batch."
     - The section carries `StockValuationReportService::BANNER` ("Management report — cost policy to be confirmed
       by the clinic's accountant…"), as AGENTS.md requires, plus the existing disclaimer line.
     - A value resting on an estimated cost (`cost_source = estimate`) shows "(estimate)".
     - A SKU with no cost at all shows "No cost recorded", and sorts after every valued row.
     - A SKU whose stock is only partly costed shows the value of the costed part, marked "(part)".
     - Ties sort by expiry date, then SKU code, then batch, so pages are stable.
   - **This is intended:** a Finance Officer sees a per-SKU average cost here, which they can't see on the Stock
     value page (that page needs `inventory.valuation.view.organisation`, Director only). Opening the list writes no
     audit row; it is a read of the same kind as the supplier price list, which shows cost to the same people without
     audit.
   - **For everyone else**, the same rows are sorted by expiry date, soonest first, with no value column and **no
     value or cost key in the page data**. The sentence above the list says which order is used.

## Data and rules

- New nullable column `inventory_skus.expiry_alert_days` (smallint). PostgreSQL CHECK: NULL, or 7 to 365. NULL means
  90, so every existing SKU behaves exactly as today. `down()` simply drops the column (losing only the setting).
- Saved through the STK-14 path. The field joins `SkuEditService`'s field list and validation (empty becomes NULL),
  then goes through `InventoryReferenceAdministrationService`: the same permission
  (`inventory.references.manage.organisation`), organisation lock, SKU row lock, version check and audit (field name
  and new number only).
- `EXPIRY_WINDOW_DAYS` (90) stays as the default. The expiring query becomes
  `whereRaw('e.expiry_date <= ?::date + COALESCE(s.expiry_alert_days, 90)', [$localDate])` instead of `whereDate`
  against one fixed date.
- **Usable** means the STK-2 eligibility rule (`InventoryBatchEligibility`): an active item and SKU, available, not
  held, not recalled, not expired. It also means active locations only, and never a patient-returns location
  (STK-11). It applies to both the expiring list and the at-risk list.
- **The at-risk list is not one of `LISTS`.** It has its own constant and query, so `acknowledge()` refuses it with a
  404 and writes nothing, no acknowledgement history is loaded for it, and the `stock_review_codes` CHECK is untouched.
- **Speed:** to sort by value before paging, value is worked out for every at-risk row, not just the 25 shown. The
  engine is called with only the SKUs on the list (the `skuIds` argument of `WeightedAverageCostService`), not the
  whole organisation, then sorted in PHP with the fixed tiebreak above.
- **Cost never leaves by another route.** `page()` is also called by the Pharmacy space (`PharmacySpace.php:124`).
  The value is computed in a separate method used only by the Stock review controller. `page()` itself never
  includes the at-risk value or value-sorted rows, so the Pharmacy space, Ask space and agents get no cost. The
  `windowDays` key stays (it now means the default, 90), so the Pharmacy space and the Ask-space sentence ("expiring
  in 90 days", `SpaceAskService.php:399`) keep working unchanged. That sentence becomes slightly loose for items set
  differently, so I'll tell Staff Experience, who own those files, and suggest rewording it later.
- No new permission.

## Hana (inventory analyst)

Hana does **not** follow automatically:
- `InventoryStockReviewTool` has its own copy of the expiring query, with a fixed 90 days.
- `InventoryRules` drops anything over 90.
- The proposal rule caps `days_left` at 90.

This slice makes the tool's query use the same per-item window, capped at 90 for Hana. So an item set to 30 days stops
getting Hana suggestions at 31 to 90 days, which is consistent with Stock review. An item set above 90 is still
suggested only from 90 days, until the agent platform raises its limits.
- The tool file is the agent platform's, so I'll agree the one-line query change with Irfan before building. If he
  prefers to make it himself, this slice leaves the tool alone and says "Hana stays at 90" as a known limit.
- `expiryWindowDays` in the tool's output stays 90 (the default).

## Tests

- **SKU field:** the range 7 to 365 (6 and 366 refused, also by PostgreSQL); empty saved as NULL and behaving as 90;
  a stale version refused; audit with the field and number only; refused without the reference permission; ignored
  when the SKU doesn't track expiry; the migration's `down()`.
- **Expiring list:** SKUs at 30 days, 180 days and empty, against batches at 20, 60 and 150 days, are listed exactly as
  their own window says.
- **Frozen clock in Kuala Lumpur time:** alert day N counts and N+1 doesn't; at-risk day 183 counts and 184 doesn't.
- **At-risk list:**
  - only usable batches: held, recalled, expired, inactive-location and patient-returns stock are left out;
  - search works;
  - sorted by value for a Director and a Finance Officer: value = quantity × WAC, estimate rows marked, no-cost rows
    last, partly costed rows marked, and the order stable across pages;
  - sorted by expiry for a CA Supervisor, whose page data holds no value or cost key;
  - acknowledging an at-risk row is 404 and writes nothing.
- **Pharmacy space:** its payload has no value or cost key, and `windowDays` is still 90.
- **Hana:** her tool's expiring rows follow the per-item window up to 90, or the "stays at 90" test, if Irfan keeps
  the tool.
- **Frontend (Classic):** the SKU field, the reworded sentence, the at-risk section, both sort orders, the value
  column and banner only when allowed, the "(estimate)", "(part)" and "No cost recorded" labels, and pagination.
- **Existing assertions:** any that pins "within 90 days" or `windowDays` on the Stock review page is listed before and
  after, and none is edited. It is asked of the owner directly (never through a red CI run), and the PR does not open
  until the owner has approved each change.

## Not in this slice

A branch-level default, alerts by message (none are sent), changing Hana's thresholds or the proposal limit, the Ask
space wording (Staff Experience), and any change to the expired or low-stock lists.
