# BIL-24: Bills to collect at scale

Owner pick 2026-11-11 ("start with 1", after BIL-23). Make the Bills to collect list (BIL-23) cheaper on a long
history without changing what it shows. Backend only; no migration, permission, route, payload or wording change.

## The change

`PayLaterCollectionService::eligible()` used to work out what every finalised invoice of the branch owes, then keep
the ones owing money. It now first keeps only invoices that **can** still owe money:

- an approved pay-later receivable with money still to collect (as before BIL-23), or
- a **completed** visit whose current invoice has at least one payment reversal.

Why the second rule is enough:

- A visit completes only when nothing is due now (`CompleteVisitationService`).
- Adjustments, invoice void and reissue, and receipt reversal at the counter are allowed only before completion.
- After completion a bill's balance can change only through a payment (which lowers it), a refund of a reversed receipt
  (the receipt already has a reversal), or a BIL-16 receipt correction (which always writes a payment reversal).

An open visit's own due-now money is not listed (BIL-23 rule), so open visits need only the pay-later rule.

The rule rests on application checks: the database would still let a completed visit's receivable or Panel share be
superseded. Any new post-completion path that raises what is owed must write a payment reversal or extend this
filter. Kumar traced every current writer (completion, reopen, responsibility, void and reverse, Panel claims and
corrections, adjustments, payments, refunds, receipt corrections, patient merge) and found none that breaks it.
`BillsToCollectTest` pins both branches, including a settled pay-later bill that owes again only through a correction.

The money sums are still grouped joins, as in BIL-23. `payment_allocations`, `payment_reversals` and
`coverage_allocations` have no `invoice_id` index, so a per-bill sum scans each table once per bill. That version was
tried first and took more than ten minutes on 20,000 bills. An index migration is left as the next step if ever needed.

## Measured (task-owned throwaway database `kpone_bil24_scale`, PostgreSQL 18)

Fixtures are copies of a real synthetic bill from the local UAT database, made with a bulk SQL copy
(triggers off for the copy only). The page was timed through the service with 3 warm runs each, then the old and new
JSON outputs were compared byte for byte.

| Fixture | Old: page / past-due | New: page / past-due | Output |
|---|---|---|---|
| 20,000 completed bills paid in full (3 posted receipts) | ~1.1 s / 1.2-2.2 s | ~0.56 s / ~0.22 s | identical |
| 20,000 completed bills owing RM 10 after a receipt correction | ~3.5 s / ~4.2 s | ~3.5 s / ~4.3 s | identical |

The first is the normal case: most finished bills are paid. The second is the worst case, where every bill really
owes money and must be worked out. It is unchanged.
