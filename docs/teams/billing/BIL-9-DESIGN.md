# BIL-9 design note: invoice and receipt layout to match the clinic's paper

Status: owner request 2026-11-06 (shared the clinic's current printed invoice and receipt, de-identified by
B.E.R.N.I.S.S: "this is what i want, dont take the personal info of the patient"). Owner decisions taken
2026-11-06 in the Billing session (section 4). Design for Siti's sketch and Daniel's look. Synthetic `.test` data
only; the clinic's real sample was not forwarded.

## 1. Target layout (A4)

**Official invoice**

1. Letterhead: round clinic logo on the left; on the right the clinic name in bold capitals, the address in
   capitals over two or three lines, "Email: …", "Tel: … / …" (two phone numbers). A thin full-width rule.
2. Title "OFFICIAL INVOICE", centred, bold, underlined.
3. Left column: "To" (bold), then the patient's name, IC/passport number and phone number, one per line. Right
   column, right-aligned: "Invoice No:" and "Date:" (labels bold).
4. Item table under a thick rule: Item | Qty | Unit Price (RM) | Total Price (RM), one row per billed line, amounts
   right-aligned with two decimals.
5. Category table under a thick rule: Category | Total Amount (RM), always seven rows in this order: Service,
   Procedure, Investigation, Consultation, Medicine, Document, Package (RM 0.00 when empty).
6. Totals, labels bold, amounts "RM …": Subtotal, Discount (or Surcharge), Tax, Total paid, Panel covers and Pay
   later (only when not zero), Total due. A thick rule under it.
7. Footer: one centred italic line (the Business details footer text).

**Official receipt**: the same letterhead and To block; title "OFFICIAL RECEIPT"; "Receipt No:" and "Date:"; the
sentence "Received from <name> the sum of RM <amount> by <payment method> being payment for the treatment
charges."; the same item table (no category table); totals Subtotal, Discount, Tax, Total paid (no Total due); the
same footer.

## 2. Compared with today's print (D03, BIL-5)

| Part | Today | BIL-9 |
|---|---|---|
| Letterhead | Name, address, email, "Tel: …" | Name and address in capitals, "Email:" label, two phone numbers |
| To block | Name and KP number only (privacy choice) | Name, IC/passport, phone (owner decision 1) |
| Visit number, status line | Printed | Dropped from the paper (still on screen) |
| Item headings | Item, Qty, Unit price (RM), Total (RM) | Item, Qty, Unit Price (RM), Total Price (RM), right-aligned amounts |
| Category table | None | Seven rows (owner decision 2) |
| Totals | Original subtotal, adjustment, Adjusted total, Self-pay received, Panel responsibility, Approved pay later, Due now | Subtotal, Discount, Tax, Total paid, Panel covers, Pay later, Total due (owner decision 3) |
| Receipt | "Received for … via … towards Invoice …", no item table | New sentence, item table, receipt totals |
| Date | Branch time, current format | "30 Oct 2026, 10:53 AM" (KPOne format; approved by B.E.R.N.I.S.S for the owner, flagged for the owner to check) |
| Bill to block (INV-04b) | Printed when set | Kept, under the To block |
| ORIGINAL / COPY / REVISED and PREVIEW (BIL-5) | Printed | Kept |

## 3. Data

- **IC/passport and phone** are read at issue from the Patient record (current identifier and phone) and frozen in
  the BIL-5 encrypted snapshot like every other printed field. They are never written to audit, logs or reports.
- **Categories:** consultation lines go to Consultation and medicine lines to Medicine; service lines go to Service,
  Procedure or Investigation by the clinical service's category (anything else, or none, counts as Service).
  Document and Package have no source in KPOne today and print RM 0.00.
- **Tax:** printed as RM 0.00, display only. KPOne holds no tax. Tax and e-Invoice suitability stays with the
  clinic's accountant or tax adviser.
- **Second phone:** needs a second phone field in Business details (Catalogue & Settings team's page). Until it
  exists, one number prints.
- **Footer:** the existing Business details footer text; changing the wording is a setting, not code.

## 4. Owner decisions (2026-11-06, answered in the Billing session)

1. The To block prints the patient's **name, IC/passport and phone**, as the sample (replacing D03's name and KP
   number). This puts more personal data on every printed copy: a PDPA point before real patient use.
2. The category table uses **the clinical service's category** for service lines; Document and Package show
   RM 0.00.
3. Totals are **the sample rows plus Panel covers and Pay later** (each only when not zero), with a display-only
   Tax row at RM 0.00.
4. **Already-issued copies stay as issued:** a version issued before the change reprints in the layout it was
   issued in; the new layout applies to new versions; the layout change alone never creates a REVISED version.

## 5. Open before building

- Checked: the people who print (CA, CA Supervisor, `billing.print.branch`) already hold `patients.view.organisation`,
  which shows the full current IC/passport and phone on the patient details page. Printing them needs no new
  permission; the print reads them at issue and checks that permission again.
- The second phone field in Business details (Catalogue & Settings).
- Existing assertions in the print tests (D02, D03, BIL-5) that pin today's text will need the owner's OK, one by
  one, before they change.
- Siti's sketch and Daniel's look at the new layout.

## 6. Not in BIL-9

No tax calculation, e-Invoice format, PDF archive, new charge type, Document or Package charges, or change to
amounts, numbering or who may print.
