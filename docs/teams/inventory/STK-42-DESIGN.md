# STK-42: Expiry alert days per item and a 6-month at-risk list

Owner decision 25 (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`): **expiry alert days per
item (default 90) plus a 6-month at-risk list sorted by cost.** Design note for Mei Ling's review; nothing is built
until she agrees. Synthetic data only.

## What staff see

1. **SKU edit screen (STK-14)** gains one field: "Alert before expiry (days)". It is optional; an empty field means
   the default, 90 days. Allowed: a whole number from 7 to 365. Help line: "Stock review lists a batch as expiring
   this many days before its expiry date. Leave empty for 90 days."
2. **Stock review, "Expiring" list** uses each SKU's own number instead of the fixed 90. The page heading stops
   saying "within 90 days" and says "within each item's alert days (90 unless set)". The low-stock and expired
   lists are unchanged.
3. **Stock review gains a fourth list, "At risk in 6 months"**: usable batches (the STK-2 eligibility rule: active
   item and SKU, available, not held, not expired) expiring within 183 days of the branch-local date, at this
   branch, one row per batch and location, with the quantity, the expiry date and the days left.
   - For people who may see cost (`inventory.cost.view.organisation`: Director and Finance Officer today; Store
     Admin and Account Assistant after ROLE-1, decision 6), the list is **sorted by stock value, highest first**,
     with a "Value (RM)" column. The value is quantity times the SKU's current weighted average cost (STK-27,
     `WeightedAverageCostService`), and carries the usual label "Set by KPOne defaults at the owner's instruction;
     not professional accounting advice." A batch with no cost recorded shows "No cost recorded" and sorts last.
   - For everyone else, the **same rows sorted by expiry date, soonest first**, with **no cost column and no
     cost in the page data** (decision 6 keeps cost restricted). The sentence above the list says which order is
     used.
   - Read only: it changes no stock and records nothing. Acknowledging stays on the existing Expiring list.
   - 25 rows a page, like the other lists, in both layouts (Classic and V2).

## Data and rules

- New nullable column `inventory_skus.expiry_alert_days` (smallint). PostgreSQL CHECK: NULL, or 7 to 365. NULL
  means 90, so existing SKUs behave exactly as today.
- Saved through the existing STK-14 path (`SkuEditService` and `InventoryReferenceAdministrationService`): the
  same permission (`inventory.references.manage.organisation`), organisation lock, SKU row lock, version check and
  audit. The audit records the field name and the new number only.
- `InventoryStockReviewService::EXPIRY_WINDOW_DAYS` stays as the default (90). The expiring query compares each
  batch's expiry with `branch-local date + COALESCE(s.expiry_alert_days, 90)`.
- The at-risk list is a new read query in the same service, with a fixed window of 183 days (about 6 months; a
  constant, not a setting).
- No new permission. No new route except the list's page parameter on the existing Stock review page.

## Hana (inventory analyst)

Hana's expiry warning reads the same Stock review rows, so it follows each item's alert days automatically. One
limit: the agent platform's proposal rule `days_left` allows at most 90 (`ProposalTypes`). For an item set above 90,
Hana keeps proposing only from 90 days, until Irfan raises that limit in his own PR. I'll tell him; nothing in this
slice changes agent code.

## Tests

- Saving the field: allowed range, the empty value meaning 90, a stale version refused, audit with field and number
  only, and someone without the reference permission refused.
- Expiring list: a SKU at 30 days and one at 180 days, against batches at 20, 60 and 150 days, are listed exactly
  where their own window says; a SKU left empty behaves as 90.
- At-risk list: only usable batches within 183 days; held, expired and patient-returns stock left out; sorted by
  value for a Director (value equals quantity times WAC; no-cost rows last) and by expiry for a CA Supervisor; the CA
  Supervisor's page data holds no value or cost key (asserted on the Inertia props).
- PostgreSQL CHECK refuses 6 and 366.
- Frontend: the field, the fourth list, both sort orders and the cost column only when allowed, in both layouts.

## Not in this slice

A branch-level default, alerts by message (none are sent), changing Hana's thresholds, and any change to the
expired or low-stock lists.

## Open points for Mei Ling

1. 7 to 365 days as the allowed range?
2. Is "current weighted average cost" right for the value, or should it be each batch's own receipt cost when known?
   I chose WAC so the number matches the Stock value page.
