# Drug label printer test

**Who:** a CA Supervisor at the pilot branch prints (only a CA or CA Supervisor can print labels). The owner
(Director) or an Administrator sets Business details first.
**Where:** the real label printer and sticker stock at the pilot branch.
**When:** before the pilot and before the [pharmacist review](pharmacist-review.md).
**Data:** synthetic only. Use the demo or test system with made-up patients; never a real patient.

## What KPOne prints (DSP-10, the clinic's landscape label)

| Setting | Value |
|---|---|
| Page (sticker) size | 100 mm wide × 62 mm tall, landscape, no margin (provisional) |
| Border | rounded box 97 × 55 mm, 1.5 mm in from the edges; a 4.5 mm strip below it is kept for the controlled line |
| Text | Courier New 9 pt, 3.8 mm per row, 48 characters per row |
| Bold rows | Clinic name, "Tel: (…)", patient name, medicine name with QTY, and "Ubat Terkawal/Controlled Medicine" below the border |
| Most rows | 13 inside the border (no logo is printed) |
| One label per | Medicine line with a positive dispensed quantity; each label on its own page |

A label that is over a field limit, a block's row limit or the 13-row limit is **blocked, never cut short**. On
the preview page, one blocked label stops **every** label on that page from printing ("Printing blocked — no
labels in this selection will print"). That is why the blocked test (T4) is done in a separate visit.

## Before you start

1. Measure the sticker stock: width ____ mm, height ____ mm. If it is not 100 × 62 mm, stop and tell the team:
   the size and all limits are set for 100 × 62 mm.
2. **Owner or Administrator:** in Business details, enter the pilot branch's clinic name (at most 40
   characters on the label), address and phone exactly as they will be used. The label prints the phone only,
   not the email, and no logo.
3. **CA Supervisor or Administrator:** in the Medicine Catalogue, press "Mark controlled" on the medicine you
   will use for T2 and T4, and type an **Indication** for each test medicine (for example "Demam / fever").
4. The medicines need stock at this branch's Dispensary (a verified stock unit and signed-off opening stock).
   The Dispensary & OTC team can prepare this on the test system, for example with the demo set-up.
5. In Chrome's print dialog: scale **100%**, **headers and footers off**, margins **none**, the label printer
   selected, paper size matching the sticker.

## Test labels

**Visit 1 (T1 to T3).** Register an OTC ("medicine only") visit for a made-up patient and press **Dispense**.
Record the allergy statement first (the label preview is refused until it is recorded). Then, for each
medicine below, add the medicine, choose its batch, type the directions and save.

| # | Medicine | Directions | Expected result |
|---|---|---|---|
| T1 | Ordinary medicine | Short dosage and frequency, a one-sentence instruction and precaution, a duration | Prints (around 12 rows) with "EXP:" and the batch's expiry |
| T2 | The medicine marked controlled | Short | Prints with the bold "Ubat Terkawal/Controlled Medicine" line below the border |
| T3 | Any medicine | Instruction text A, precaution text B (below) | Prints at or near the 13-row limit |

If T3 shows "Label rows: N rows; label limit 13.", your clinic header is longer than the sample. Shorten text A
by one sentence, save, and preview again until it prints. Write down what you removed. The aim is a label right
at the limit, to check that the bottom row is not cut off.

Print the three with **Print all labels**, then **Print current labels**.

**Visit 2 (T4).** Register a second OTC visit, press Dispense, record the allergy statement and add the
controlled medicine with instruction text C and precaution text D. Use **Print label** on that medicine.

| # | Medicine | Directions | Expected result |
|---|---|---|---|
| T4 | The medicine marked controlled | Instruction text C, precaution text D | **Blocked**, with messages such as "Directions: 4 rows (137 characters); label limit 3 rows.", "Precaution: 91 characters; label limit 72." and "Label rows: 16 rows; label limit 13." (the numbers can differ a little with your header and medicine name). Nothing prints |

A line taken from two or more batches prints "EXP: -". The Dispensary page always takes a line from one batch,
so this case cannot be set up by hand; it is covered by KPOne's automated tests and by question Q5 on the
pharmacist sheet.

**Text A, instruction:**
`Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Simpan di tempat kering dan sejuk.`

**Text B, precaution:**
`Mengantuk. Jangan memandu.`

**Text C, instruction:**
`Habiskan semua ubat walaupun sudah sihat. Makan selepas makan dengan segelas air penuh. Simpan di tempat kering.`

**Text D, precaution:**
`Berhenti dan jumpa doktor jika ada ruam atau sesak nafas. Simpan jauh daripada kanak-kanak.`

Copy each text without the surrounding marks. The Dispensary page lists the label limits for each field.

## What to check on each printed sticker

Tick each box, or write what went wrong.

| Check | T1 | T2 | T3 |
|---|---|---|---|
| The whole label fits on one sticker; nothing cut off at any edge | ☐ | ☐ | ☐ |
| The rounded border is complete on all four sides | ☐ | ☐ | ☐ |
| The bottom row (date and "EXP:") is fully inside the border | ☐ | ☐ | ☐ |
| "QTY:" stays on the medicine's first row, at the right | ☐ | ☐ | ☐ |
| The next label starts on a new sticker, not partway down | ☐ | ☐ | ☐ |
| Text is sharp and readable at arm's length | ☐ | ☐ | ☐ |
| Bold rows stand out (clinic, Tel, patient, medicine) | ☐ | ☐ | ☐ |
| The controlled line is below the border and fully on the sticker | | ☐ | |
| The expiry matches the batch chosen | ☐ | ☐ | ☐ |
| Background is white and text dark, even with KPOne in dark mode | ☐ | ☐ | ☐ |
| Sticker stays on a real medicine box or bottle and the ink does not smudge when rubbed | ☐ | ☐ | ☐ |

T4: the preview shows the blocked message and "Print current labels" is disabled. ☐

**Reprint (DSP-6).** Print T1 a second time with "Print current labels". It prints with a small boxed
**REPRINT** at the top right, inside the border. Check that the whole box and the full word are on the sticker,
that it covers no text, and that the word is readable. ☐

Always print with the "Print current labels" button, not the browser's own Print menu or Ctrl+P: only the button
records the print and adds the REPRINT mark. A cancelled print dialog still counts as a print.

## Record

| | |
|---|---|
| Date and branch | |
| Printer make and model | |
| Sticker stock (brand, size) | |
| Done by | |
| What was removed from text A for T3 | |
| Result | Pass / Pass with notes / Fail |
| Notes (attach photos of T1 to T3 if you can) | |

If anything fails, send the notes and photos to the Dispensary & OTC team. A different sticker size or a cut-off
bottom row means the label size or the row limit must change before the pilot.
