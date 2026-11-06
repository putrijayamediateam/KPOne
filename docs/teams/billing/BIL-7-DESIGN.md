# BIL-7 design note: monthly cash closing view

Status: started 2026-11-05 (B.E.R.N.I.S.S for the owner: "yes, start BIL-7"); design for Daniel's look.

## 1. Purpose

The daily cash closing page (BIL-4, `/cash-closings`) shows one day. A supervisor or Finance Officer cannot see a
month at a glance: which days were never counted, which still wait for approval, which were returned, and how big
the differences were. BIL-7 adds a read-only month view.

## 2. Shape

- **Page:** `/cash-closings/month?month=YYYY-MM` (default: the current month in branch time), linked from the daily
  page ("Month view") and back ("Open day" on each row). Active branch only, like the daily page.
- **Permission:** the same as the daily page, `payments.reconcile.branch` (CA, CA Supervisor, Finance Officer,
  Director). No new permission. Read only: no count, approve or return from this page.
- **One row per day** of the month, up to today (future days are not listed):
  - date (with weekday);
  - status: Approved, Waiting for approval, Returned, Not counted (day ended), Open (today);
  - counted cash, expected cash and difference of the **latest count** (for an approved day, the approved count);
  - counted by, approved or returned by;
  - flags: "No count" for an ended day with no closing; "Not approved" for an ended day whose latest count is
    waiting or returned; "Changed after approval" when an approved day's live expected cash no longer matches its
    approved snapshot (the same rule as the daily page).
- **Month summary** above the rows: days approved, days waiting, days returned, days not counted, total counted,
  total expected (of the counts shown) and total difference.
- **Money:** sen, shown as RM with two decimals, minus allowed (BIL-6 refunds can make a day negative).
- **No export, no PDF, no new tables or migrations.** Data comes from `cash_closings` and `cash_closing_decisions`
  plus the existing `CashClosingService::expected()` for the "changed after approval" flag.

## 3. Not in BIL-7

- Counting or approving from the month view (the daily page stays the only place).
- Other branches or an all-branch view; a year view; CSV.
- Refund columns (the day page shows refunds; the month view shows the counted, expected and difference only).

## 4. Questions

None needed: everything follows owner-approved BIL-4 rules. Daniel's look is requested for the new page layout.
