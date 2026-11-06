# BIL-10 design note: refunds after a visit is completed, and partial refunds

Status: owner request 2026-11-06 in the Billing session ("start with 1", the first of the candidates after BIL-9).
Draft for owner decisions (section 4). Synthetic `.test` data only.

## 1. The gap

- BIL-6 refunds only a receipt **reversed as a recording error, before the visit is completed**, and always for the
  receipt's **full** amount.
- KPOne never lets a patient pay more than is due (`PaymentService`). So after completion, a refund always means
  the clinic gives back money it was rightly paid for something on the invoice: medicine returned, a service not
  given, a billing mistake found later, or a goodwill gesture.
- A completed visit's invoice cannot be changed today. A refund alone would leave the invoice saying the refunded
  amount is due again, and sales reports would count the sale as if the money were kept.

## 2. Proposed shape (team recommendation)

- **A post-completion refund is two linked, append-only records, approved together by a second person:**
  - a **refund**: money handed back (amount, any active method, reason code, payout time), against one posted
    receipt of the visit; partial amounts allowed, never more than that receipt less its earlier refunds;
  - an **invoice credit** of the same amount: it lowers what the invoice charges, so nothing becomes due again
    and the books, the Billing page, the Daily summary and Insights net sales all agree.
- **Two people:** recorded by one person, approved or rejected by another, never the recorder (service and
  PostgreSQL). Until approved, the refund and credit are pending and change no figure except cash closing (money
  left the drawer when paid out, as BIL-6).
- **Patient money only:** only what the patient paid can be refunded. A Panel or pay-later share is not touched.
- **No change to stock:** medicine returned to the shelf goes through Dispensary's returns, not Billing.
- **Prints (BIL-9):** the next print of the invoice is REVISED and shows the credit line; the original receipt is
  unchanged (it stays the evidence of what was paid). A refund slip is not printed in BIL-10.
- **Cash closing:** a Cash refund counts as paid out on its payout day (the existing BIL-6 rule); the receipt stays
  counted on its own day.
- **Audit:** figures and codes only, never free text.

## 3. Alternatives

- **Money back only (no invoice credit).** Simpler, but the invoice shows the refunded amount as due again (or it
  must be written off silently), and net sales stay higher than the money kept. Not recommended.
- **Refund only through a void and reissue.** Voiding a completed visit's invoice is not possible today and would
  reopen the visit; much larger.

## 4. Questions for the owner

1. After completion, should a refund also **lower the invoice** (a credit, recommended) or only record money back?
2. **Partial refunds:** allow any amount up to what the patient paid on that receipt (recommended), or full only?
3. **Who:** the same people as BIL-6 (record: CA Supervisor, Finance Officer; approve or reject: Finance Officer,
   Director, never the recorder), recommended, reusing the two BIL-6 permissions?
4. **Time limit:** none (recommended; a reason code is always required), or only within a number of days of the
   visit?

## 5. Not in BIL-10

Stock returns, Panel or pay-later refunds, card or e-wallet provider refunds through a terminal, a printed refund
slip, and refunds spread across several receipts in one record.

## 6. Owner decisions (2026-11-06, answered in the Billing session)

1. A refund after completion **also lowers the invoice** with an invoice credit of the same amount, so nothing
   becomes due again and the Billing page, the Daily summary and sales reports match the money kept. The next
   invoice print is REVISED with the credit line.
2. **Partial refunds** are allowed: any amount up to the receipt's amount, less its earlier refunds.
3. **Who:** the same as BIL-6. CA Supervisor or Finance Officer records; Finance Officer or Director approves or
   rejects, never the recorder. The two existing refund permissions are reused; no new permission.
4. **No time limit:** always with a reason code and a second person's approval.
