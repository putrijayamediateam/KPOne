# BIL-12 design note: refund credits in the Insights provider table

Status: owner request 2026-11-07 in the Billing session ("start with 1", after BIL-11). Builds on BIL-10 (#392),
which must merge first. Synthetic `.test` data only.

## 1. The gap

BIL-10 takes approved refund credits off Insights headline net sales on the approval day (decisions A and B). The
provider table (one row per attending doctor, plus the OTC row) still sums invoice sales only, so in a period with a
credit its rows add up to more than the headline.

## 2. Owner decision (2026-11-07, answered in the Billing session)

1. **Each credit comes off the provider row of the invoice it belongs to, on the approval day.** A consultation
   invoice's credit comes off its attending doctor's row; an OTC invoice's credit comes off the OTC row. The rows then
   add up to the headline net sales. A row can show a lower, even negative, figure in a period with a large refund.

## 3. Shape

- Same scoping as the headline credit: approved credits only, by approval time in each branch's period, and the
  doctor filter matches the credit's invoice's attending doctor.
- A provider with credits in the period but no invoices in it still appears, with its credit as a negative figure
  and 0 patients.
- Patients and invoice counts per provider are unchanged; only the sales figure moves.
- The Today report's provider table follows the same rule for credits approved today, so it matches the Today
  headline (which BIL-10 already nets).

## 4. Not in BIL-12

No change to item rankings (they stay gross), the headline, reports outside Insights, or permissions.

## 5. Built (2026-11-07)

- `InvoiceSalesProjection::withCredits()` (shared by the Sales report and the Today report) takes each approved
  credit off its invoice's attending doctor, or the OTC row, scoped like the headline credit (branch periods by
  approval time, doctor filter through the invoice's checkout). Rows are re-sorted by net sales, then name.
- Test: `RefundAfterCompletionTest` (sale day full sale; approval day the credit alone, 0 patients; both days add up
  to the headline, with and without the doctor filter; Today's table on the approval day).
- No existing assertion changed; no permission, migration or screen change (the table already shows signed money).
