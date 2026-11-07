# BIL-14 design note: retire the old live "PREVIEW" print pages

Status: owner request 2026-11-07 in the Billing session ("start with 1", after BIL-13). Synthetic `.test` data only.

## 1. The gap

Since BIL-5 every print button issues a recorded copy, but the old live print addresses
(`/visits/{visit}/billing/{invoice}/print` and `/visits/{visit}/billing/{invoice}/receipts/{payment}/print`, routes
`billing.print` and `billing.receipt`) still open by URL or bookmark. They show live data in the old layout, marked
PREVIEW, and record nothing.

## 2. Owner decisions (2026-11-07, answered in the Billing session)

1. **The old addresses redirect to that visit's Billing page, at Issued copies,** with the note "Print from here so
   the copy is recorded." Opening an old link prints and records nothing. Same permission as today
   (`billing.print.branch`); a visit or invoice from another branch still refuses.
2. **Existing tests that open the old page move to the recorded copy page:** 28 test lines in 13 files check what a
   print shows (Bill to, notes kept off paper, letterhead, footer, adjustments, recorded-by). Each keeps its check but
   reads the recorded copy; every changed line is listed for approval, one by one, before the PR.

## 3. Shape

- `BillingController::printInvoice` and `printReceipt` redirect; the route names stay, because the Invoices page
  builds its recorded-print links from them (BIL-5b).
- The Print page keeps rendering issued copies (BIL-5, BIL-9); only its live PREVIEW use ends.
- Built after BIL-9 (#388) merges: recorded copies then use the clinic layout, and the moved checks must read it.

## 4. Not in BIL-14

No change to issuing, numbering, labels, permissions or the layout.
