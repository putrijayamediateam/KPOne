# BIL-18 design note: pay-later list on phones, "Pay later still owed"

Two layout and wording items from the Lead UI/UX debt list (Daniel), taken by the owner on 2026-11-10 ("i want to start
all that"). Sketched by Siti before building. Layout and wording only: no money rule, permission, route, payload or
stored-data change.

## What changes

1. **The pay-later collection list is cards below `lg`.** The 7-column table had a 960 px minimum width, so on a phone
   it scrolled sideways inside its box. Below 1024 px each bill is a card (one column on phones, two from `md`),
   following the Price history pattern. The table is unchanged from `lg` up. Card order:
   - "Still to collect" and the amount, with the past-due chip on the same line;
   - the due-now note under it ("RM X is also due now. A payment pays that first.");
   - the patient name, KP number and merge note;
   - due date, visit date, visit, invoice and approved amount;
   - a full-width 44 px "Open billing" link.

   The "Show only bills past their due date" checkbox label is 44 px tall.
3. **The past-due chip pluralises:** "1 day past due", "3 days past due" (owner OK on 2026-11-10 to change the
   existing assertion in `bil-3-pay-later-collections.test.mjs`, which pinned "day(s)").
2. **"Approved pay later" becomes "Pay later still owed"** on the Billing page summary, the completed-visit Invoice
   page and Visit history. The figure is the approved pay-later still owed (RM 20.00 of an approved RM 30.00 after a
   part payment), so "Approved" read as if it contradicted the RM 30.00 card below it. Prints keep "Approved pay
   later": issued copies are frozen (BIL-5) and the print layout test pins the label.

## Not in BIL-18

- The "1 records" count comes from the shared pagination component; Daniel is logging it as a Staff Experience item.
