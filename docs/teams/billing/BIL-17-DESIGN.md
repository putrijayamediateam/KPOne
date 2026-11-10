# BIL-17 design note: Billing page display fixes

Status: owner request 2026-11-10 in the Billing session ("start with 1", after BIL-16). Display only: no money,
permission, migration or stored-data change. Synthetic `.test` data only. Built on BIL-16 (its PR opens after
BIL-16 merges).

## What changes

1. **Settled pay-later.** A fully paid pay-later card reads "Paid in full · nothing left to collect" instead of "Due <date>". A part-paid
   one reads "RM X still to collect · Due <date>".
2. **Voided invoices stay visible.** After a void, the Billing page lists each voided invoice of the visit:
   - its number, total, when and by whom it was voided, and its receipts with their status;
   - never the void reason, following the activity log rule (INV-03).
3. **"Use amount due" on a completed visit.** The shortcut also shows on a completed visit when money is due
   (only after a BIL-16 correction).
4. **The pay-later collection list notes money due now.** When an invoice also has money due after a correction,
   its row says "Also RM X due now: collect this first", because a payment clears that before pay-later. It is sent beside the
   rows, so each row keeps its agreed fields (no existing assertion changes).

## Not in BIL-17

No change to how payments are allocated, to voiding, or to who can see the Billing page.
