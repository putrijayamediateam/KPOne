# BIL-23 design note: "Bills to collect"

Chosen by the owner on 2026-11-10 ("4"). Sketched by Siti, with three owner decisions on 2026-11-11. Read-only, like
BIL-3: no money rule, permission, route or stored-data change.

## Owner decisions (2026-11-11)

1. The list shows every completed visit where the patient still owes money, not only approved pay later.
2. Money due now has no agreed date, so it counts as past due from the day after the visit, labelled "N days since
   visit".
3. The page is renamed "Bills to collect". The link and its address (`/pay-later-collections`) stay the same. The
   owner approved changing the one existing assertion that pinned the old title
   (`tests/Frontend/bil-3-pay-later-collections.test.mjs`).

## What changes

- **Rows.** A row is a current finalised invoice at the branch with either of:
  - approved pay later still to collect (as before, on open or completed visits);
  - money due now on a completed visit (new).

  The owed amount is the Billing page's Outstanding bill formula (`OutstandingBillService`): payable, less paid, plus
  reversed, less approved Panel. Due now is that, less the pay later still to collect.
- **Open visits.** An open visit's own due-now money is not listed; it is being paid at the counter.
- **Due date and order.** A bill's date is its pay-later due date. When money is due now, it is the visit day (or the
  earlier of the two). Rows are ordered by that date, oldest first. A bill is past due from the day after that date.
- **Row fields.** Each row keeps its agreed fields: the exact key list is pinned by an existing test. Due now travels
  in the existing `dueNow` map (BIL-17), and a due-now-only row has approved and pay-later amounts of 0.
- **Desktop table.** Columns: Patient, Visit / Invoice, Visit date, Due date, Approved, Pay later, Due now,
  **Still owed**, Action.
- **Agreed pay-later date.** On a bill with both kinds of money the row's date is the visit day, so the agreed
  pay-later date travels in a side map (`payLaterDue`) and shows beside the pay-later amount.
- **Phone cards (BIL-18 layout).** "Still owed" leads, with the past-due or "since visit" chip. Under it come a Pay
  later / Due now breakdown, the "also due now, a payment pays that first" note on bills with both, the patient, and
  the details.
- **Totals.** The totals cards read "Still owed" and "Owed on bills past due", and count both kinds of money. The
  second counts a whole bill once any part of it is past due (Siti's review; counting only the past-due part is a
  follow-up).
- **Due date.** A bill with money due now shows "Now" as its due date; the agreed pay-later date stays beside the
  pay-later amount. "Approved" reads "Approved pay later", hidden on a card with none. The page help text
  says a payment pays the money due now before pay later.
- **Menu.** The Clinic management card is renamed to match.

## Not changed

- The Billing page's "View all pay-later to collect at this branch" link text: an existing assertion pins it.
- The "Check bill" badge for invoices under review: it would add a row field (exact-keys test).
- The "1 records" pagination count: a shared component.
