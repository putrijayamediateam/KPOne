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

## 7. Technical plan (from the reader mapping, 2026-11-06)

- **Records (additive migration):** `payment_refunds` gains `kind` (`reversal`, the BIL-6 rule, default for every
  existing row; or `after_completion`). A new append-only `invoice_credits` row (invoice, refund, amount) is written
  with every `after_completion` refund, for the same amount. The approval or rejection stays in
  `payment_refund_decisions` and covers both records.
- **PostgreSQL rules for `after_completion`:** the receipt is posted (not reversed), of the same branch, its visit is
  completed, and the amount is at least 1 sen and at most the receipt less its earlier refunds that are not
  rejected; the credit's amount equals its refund's. Recorder never decides (unchanged). BIL-6 rows keep their rule.
- **One balance rule, in SQL and PHP:** an approved credit lowers payable (`kpone_invoice_payable` = total +
  adjustment − approved credits) and its approved refund lowers paid (`FinancialLedger`: allocations − reversals −
  approved after-completion refunds). Both move by the same amount, so due is unchanged, `kpone_billing_check_invoice`
  still holds (paid + Panel + pay later ≤ payable) and the ledger's due ≥ 0 check still holds. Pending or rejected
  changes neither. `FinancialLedger::states()` keeps its query cap (one more batched query at most).
- **Patient money only:** the amount is capped at the receipt less its earlier refunds, so payable never drops below
  Panel plus pay later.
- **Cash closing:** unchanged rule. The receipt stays counted on its day; a Cash refund is paid out on its payout day.
  The existing expected-cash query already counts open refunds of posted receipts.
- **Issued copies (BIL-5, BIL-9):** after approval the invoice's figures change, so its next print is REVISED, with a
  "Refund credit" line in the totals; receipts are unchanged.
- **Not changed:** Panel claims (Panel money untouched), completion evidence (the visit stays completed), stock.
- **Reports:** see section 8.
- **Insights date basis:** `kpone_invoice_payable` feeds Insights sales on the invoice's finalisation date, so the
  credit must not be folded into that function (decision 8B). The ledger and reconcile check subtract approved
  credits separately; Insights adds a "Refund credits" row dated by approval.

## 8. Reporting decisions (2026-11-06, approved for the owner by B.E.R.N.I.S.S while "BE ME"; marked "Check this")

These are money reporting choices taken for the owner; the owner can still change them.

- **A.** A credit shows as its own row, "Refund credits", in Insights net sales and the Sales CSV; discounts stay
  discounts.
- **B.** The credit lowers sales on the day the refund is approved; past days' reports never change.
- **C.** The receipt stays counted on its own day, with a new "Refunded after completion" column on the payout day
  in the Daily summary, Transaction Detail and the receipts-by-method CSV. "Total received" keeps its meaning.
- **D.** The two Panel screens that ignored invoice discounts and surcharges are fixed separately by the Panel team
  (PNL-15, Syafiq), not in BIL-10; Billing reviewed the money side.

## 9. Known limits (built)

- **Insights Provider table:** it stays invoice-based. Refund credits come off the headline net sales on their approval
  day, but not off any provider's row, so the headline can be lower than the provider total by the credits approved in
  the period. A day with a credit and no sales shows a negative sales figure with 0 patients in the daily trend.
- **One invoice per receipt:** a refund after completion needs a receipt that pays exactly one invoice (PostgreSQL
  rule). KPOne creates one allocation per receipt today; a future split receipt would need its own refund rule.
- **Race tests:** two refunds recorded at the same moment on one receipt are serialised by the receipt row lock in the
  service and in the PostgreSQL rule, which re-reads the earlier refunds after the lock. There is no dedicated
  PostgreSQL contention test for it yet; the existing billing race tests run.
- **Prints:** the issued invoice (D03 layout here, and the BIL-9 clinic layout once both are merged) prints a "Refund
  credit" row. A copy issued before the credit stays as issued, and the next print is REVISED.
- **Panel claims** use the invoice total before refund credits (the same figure as `kpone_invoice_payable`). A refund
  after completion only gives back the patient's money.
