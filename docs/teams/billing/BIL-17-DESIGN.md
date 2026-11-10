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
   its row says "RM X is also due now. A payment pays that first.", because a payment clears that before pay-later. It is sent beside the
   rows, so each row keeps its agreed fields (no existing assertion changes).
5. **Review polish (Siti, Daniel).** The settled line has a check icon and the success colour; voided invoices carry a
   neutral "Voided" badge; both "Use amount due" shortcuts are 44 px outline buttons under their field; in a completed
   visit's payment dialog the hint names the button ("Choose a payment method to enable Settle Outstanding Balance.";
   an open visit keeps "Add Payment"); the pay-later list counts read "1 patient bill" or "2 patient bills".

## Not in BIL-17

No change to how payments are allocated, to voiding, or to who can see the Billing page.
The pay-later list's "1 records" comes from the shared pagination component, owned outside Billing, and is left for
its owner. The pay-later list's phone layout and the "Approved pay later" summary row wording are BIL-18.
