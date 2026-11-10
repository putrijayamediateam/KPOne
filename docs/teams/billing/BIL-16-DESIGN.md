# BIL-16 design note: correct a receipt recorded in error after the visit is completed

Status: owner request 2026-11-10 in the Billing session ("start with 1", after BIL-15). Draft for owner decisions
(section 4). Synthetic `.test` data only.

## 1. The gap

- Before a visit is completed, a Finance Officer who did not record a receipt can reverse it as a recording error
  (`InvoiceCorrectionService::reverse`, permission `payments.reverse.branch`). It is never a cash refund.
- After completion this is refused ("Post-completion corrections are excluded from v1", TEAM.md section 7).
- After completion KPOne still accepts new receipts (pay-later instalments on a return visit). So a wrong amount,
  wrong method or duplicate entered on a completed visit stays wrong in KPOne. It then misstates the Daily summary,
  Transaction Detail, the cash closing and the terminal reconciliation, and staff fix it outside KPOne.
- Refunds after completion (BIL-10) are a different thing: money really goes back to the patient. A recording error
  means the money never moved the way the receipt says.

## 2. Proposed shape (team recommendation)

- **"Correct receipt" on a completed visit's Billing page and Manage payments**, for a posted receipt that has no
  refund. The rules are the same as the existing reversal:
  - a recording error only, with a required reason;
  - the person correcting must not be the person who recorded the receipt;
  - the reversal is append-only, the original receipt is kept and marked Reversed, and the action is audited.
- **Replace in one step:** the person correcting enters what should have been recorded (amount and method, or "no
  money was received"). KPOne records the reversal and the replacement receipt together, in one transaction.
  - When the amount is the same, nothing becomes due and the visit stays completed and settled.
  - When less, or nothing, was really received, the difference shows as due on that visit (like a pay-later
    balance) and appears in the patient's outstanding bills.
  - A replacement can never be more than the reversed receipt.
- **Reports follow the existing reversal rules:**
  - the original receipt shows as reversed on its own date and the replacement on the correction date;
  - an approved cash closing day shows "changed after approval";
  - the terminal reconciliation and reports show the change.
- **Not allowed:**
  - a receipt that has a refund (pending or approved) or an invoice credit;
  - a receipt that pays more than one invoice;
  - a receipt already reversed.
- **No change** to invoice totals, Panel amounts, claims or approved pay-later amounts.

## 3. Alternatives

- **Reverse only, then record the right receipt separately.** This is simpler, but between the two steps the
  completed visit shows money due, and a second person is needed again if the same person cannot do both.
- **Leave it outside KPOne.** This is today's behaviour; reports stay wrong.

## 4. Questions for the owner

1. **Who may correct?**
   - A Finance Officer only, as before completion (recommended);
   - or a Finance Officer and a CA Supervisor.
   - Either way, never the person who recorded the receipt.
2. **One person or two?**
   - The correction takes effect at once, because the corrector is already a different person from the recorder
     (recommended, the same as before completion);
   - or it waits for approval by a third person, like refunds (BIL-6 and BIL-10).
3. **How far back?**
   - Any time, with approved cash closing days showing "changed after approval" (recommended);
   - or only receipts from the current month.

## 5. Not in BIL-16

- No cash refund (BIL-10 covers that).
- No change to invoice lines or voiding after completion.
- No Panel or claim change.
- No new report columns.

## 6. Owner decision (2026-11-10, answered in the Billing session)

1. **A Finance Officer only**, never the person who recorded the receipt.
2. **The correction takes effect at once** (no third-person approval).
3. **Any time**; an approved cash closing day shows "changed after approval".
4. **Replace in one step**: the wrong receipt is reversed and the right one recorded together; only money really not
   received becomes due.
