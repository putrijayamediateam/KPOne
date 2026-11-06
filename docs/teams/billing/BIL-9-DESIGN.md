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

Further owner decisions (2026-11-06, Billing session, after Siti's sketch):

5. The receipt's right column adds **"Invoice No:"** after Receipt No and Date; the sentence stays as the sample.
6. A void invoice prints a bold **"VOID"** line under the title; other invoices are unchanged.
7. The invoice totals add an **"Invoice total"** row after Tax: Subtotal, Discount, Tax, Invoice total, Total paid,
   Panel covers and Pay later (when not zero), Total due.
8. The receipt's **"Total paid" is this receipt's amount**, matching its sentence.

Sketch notes (Siti): title case on paper as the sample (the design guide leaves print layouts to the owner);
thin 0.5 pt and thick 1.5 pt rules in `border-current`; table cells without "RM " (the headings carry it), totals
with "RM "; the item header repeats on a second page and the category table, totals and closing rule never split;
missing logo, second phone, IC or phone leave their line out; the shared `myr()` mis-formats negative amounts
(-150 sen prints "RM -1.-50") and is fixed first; Print.vue keeps the D03 template for versions issued before
BIL-9 (decision 4) and picks the layout from the snapshot's layout version.

9. A **void invoice prints no "Total due"** row (owner, 2026-11-06, after the A4 images): it is replaced by a new
   invoice, so nothing is due on it. Subtotal, Discount, Tax, Invoice total and Total paid still show.

10. **Page numbers: yes** (B.E.R.N.I.S.S for the owner, 2026-11-06, Daniel's recommendation). The page footer
    reads "<document number> · Page n of N". Print CSS cannot tell a one-page document from a longer one, so the
    first page carries no footer: a one-page document prints none, a longer one numbers pages 2 onward (page 1 has
    the full header and number).
11. **Phone format** (the owner: "go with Daniel's phone format"): every printed phone, the clinic's two and the
    patient's, reads "03-2123 4567" / "012-345 6789" through the shared `App\Domain\Shared\PrintedPhone` (DSP-10);
    the stored value never changes, and copies issued before keep what they printed.

Design approval: Daniel, "OK to build", then "OK" on the A4 images (2026-11-06); title case on paper as the sample;
after his look: the document number in the page footer, the patient's phone formatted, a larger VOID.

## 7. Known limits (built)

- The service-line category is read from the clinical service at print time, not frozen on the invoice line:
  renaming a service's category after an invoice was issued in this layout moves money between category rows, so
  the next print of that invoice is REVISED. Receipts carry no categories, so they are never revised by it.
- The category match is English and exact: "Procedure(s)" and "Investigation(s)"; anything else, including
  "Prosedur" or "Lab test", counts as Service.
- The old live print pages (`/print`, still reachable by URL and marked PREVIEW) keep the D03 layout; only issued
  copies use the clinic layout.
- A copy issued before BIL-9 keeps its D03 layout and reprints as COPY until something it printed changes; a
  change to the patient's IC, phone or a service category alone never revises it.
- More personal data on paper (IC/passport, phone): a PDPA point before real patient use. The copy is opened or
  reprinted only by someone who may see patient identity (`patients.view.organisation`).
- The second phone prints once Catalogue & Settings adds `phone_secondary` (CAT-9); until then one number.

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
