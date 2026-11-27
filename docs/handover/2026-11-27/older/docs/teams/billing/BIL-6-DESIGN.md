# BIL-6 Refund recording: design note (draft for owner decisions)

Status: owner decisions taken 2026-11-05 (section 6); built as BIL-6.

## 1. The gap
- A receipt reversal (`InvoiceCorrectionService::reverse`) means "recorded in error; the money never came in".
  It is allowed only before the visit is completed, by a Finance Officer who did not record the receipt.
- Nothing in KPOne records money actually handed back to a patient. Cash closing therefore cannot explain cash
  that left the drawer, and the Daily summary shows no refunds.

## 2. Proposed shape (v1)
- A **refund** is an append-only record:
  - the amount, the method (any active payment method, so Cash counts for cash closing), a controlled reason
    code (for example overpaid, service not given, invoice corrected, other) and the date and time it was paid
    out;
  - who recorded it and who approved it.
- **What it links to:** it is linked to one **reversed receipt** of the same branch. It may not exceed that
  receipt's amount, less any earlier refunds of the same receipt.
- **Two people:** a different person approves it, enforced in the service and by PostgreSQL. An approver may
  also **reject** it, with a reason code.
- **No free text in the audit:** the audit holds figures and codes only, never reason text.
- **No change to invoice amounts:** the invoice, its settlement and the ledger are not changed. The reversal has
  already corrected the books; the refund only records that cash or card money went back.
- **Retries:** every action has an idempotency key and lock version. Two people pressing at once is covered by
  PostgreSQL contention tests, as for BIL-4.
- **Where it appears:** on the patient's Billing page (beside the reversed receipt), on Daily cash closing and in
  the Daily summary.

## 3. Effect on numbers
- **The double-count problem.** Cash closing currently leaves reversed receipts out of expected cash, because a
  reversal meant the money never came in. When a reversed receipt has a refund, the money did come in and then
  went out. Leaving the receipt out *and* subtracting the refund would count it twice.
- **Proposed rule:**

  > expected cash for a day = Cash receipts received that day that are posted **or** reversed with a refund
  > − Cash refunds paid out that day

- **When a refund counts:** a refund counts on the day it was paid out (money left the drawer then), whether or
  not it is approved yet. That day's closing cannot be approved while one of its Cash refunds is still waiting
  for approval.
- **Daily summary:** new "Refunded (Cash)" and "Refunded (Non-cash)" columns by payout date. "Total received" is
  unchanged.
- **Proof:** tests prove the figures on the screens, the CSV and cash closing agree.

## 4. Questions for the owner
1. **Who records a refund, and who approves it?**
   - Recommended: record = CA Supervisor and Finance Officer; approve = Finance Officer and Director, never the
     person who recorded it.
   - This needs two new narrow permissions (`payments.refunds.record.branch`,
     `payments.refunds.approve.branch`) through one additive, owner-approved migration.
2. **Should small refunds skip approval?**
   - Recommended: no. Every refund needs a second person; the clinic is small and refunds are rare.
   - Alternative: a per-branch threshold, like invoice adjustments.
3. **Scope of v1:** refunds only against a reversed receipt, before the visit is completed (today's reversal
   rule)?
   - Recommended: yes.
   - A refund after visit completion (for example returned medicine) needs a post-completion correction, which
     KPOne does not have. It would be a larger, separate change.
4. **The cash closing rule in section 3** (a reversed receipt with a refund counts as received; the refund
   counts as paid out on its payout day; a day cannot be approved while a Cash refund of that day waits for
   approval): accept?

## 5. Not in v1
- Card or e-wallet chargebacks or provider refunds through a terminal. Those are recorded manually like any
  refund; there is no integration.
- Partial refunds across several receipts in one record.
- Printing a refund slip.

## 6. Owner decisions (2026-11-05)
1. **Who records and approves:** CA Supervisor and Finance Officer record; Finance Officer and Director approve or
   reject, never the person who recorded the refund.
2. **No threshold:** every refund needs approval.
3. **Scope of v1:** reversed receipts only, before visit completion.
4. **The cash closing rule in section 3:** accepted.
5. **Full amount (team choice, told to the owner):** a refund is always for the full reversed amount. Refunds are
   added to the Daily summary later, as BIL-6b.
