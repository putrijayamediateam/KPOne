# Drug label printer test

**Who:** a CA Supervisor at the pilot branch prints (only a CA or CA Supervisor can print labels). The owner
(Director) or an Administrator sets Business details and the logo first.
**Where:** the real label printer and sticker stock at the pilot branch.
**When:** before the pilot and before the [pharmacist review](pharmacist-review.md).
**Data:** synthetic only. Use the demo or test system with made-up patients; never a real patient.

## What KPOne prints

| Setting | Value |
|---|---|
| Page (sticker) size | 100 mm wide × 150 mm tall, no margin (provisional) |
| Text | Courier New 10 pt, 4 mm per line, 42 characters per line, 3 mm padding |
| Bold lines | Patient name, medicine name, "Ubat Terkawal / Controlled Medicine" |
| Most lines | 35, or 33 when the clinic logo prints (the logo is 6 mm tall, at most 20 mm wide) |
| One label per | Medicine line with a positive dispensed quantity; each label on its own page |

A label that is over a field limit or over the line limit is **blocked, never cut short**. On the preview page,
one blocked label stops **every** label on that page from printing ("Printing blocked — no labels in this
selection will print"). That is why the blocked test (T4) is done in a separate visit.

## Before you start

1. Measure the sticker stock: width ____ mm, height ____ mm. If it is not 100 × 150 mm, stop and tell the
   team: the size and all limits are set for 100 × 150 mm.
2. **Owner or Administrator:** in Business details, enter the pilot branch's clinic name, address and phone or
   email exactly as they will be used, and upload the logo if the clinic will use it (the logo removes two
   lines). The invoice footer is not printed on drug labels.
3. **CA Supervisor:** in the Medicine Catalogue, press "Mark controlled" on the medicine you will use for T2
   and T4.
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
| T1 | Ordinary medicine | Short dosage and frequency, a one-sentence instruction | Prints (around 20 lines) |
| T2 | The medicine marked controlled | Short | Prints with the bold controlled line, "No. Batch" and "Tarikh luput" (around 25 lines) |
| T3 | Any medicine, **logo on** | Instruction text A, precaution text B (below) | Prints at or near the 33-line limit |

If T3 shows "Label lines: N lines; label limit 33.", your clinic header is longer than the sample. Delete one
"Habiskan semua ubat walaupun sudah sihat." sentence for each line over 33, save, and preview again until it
prints. Write down how many sentences you removed. The aim is a label right at the limit, to check that the
last line is not cut off.

Print the three with **Print all labels**, then **Print current labels**.

**Visit 2 (T4).** Register a second OTC visit, press Dispense, record the allergy statement and add the
controlled medicine with instruction text C and precaution text D. Use **Print label** on that medicine.

| # | Medicine | Directions | Expected result |
|---|---|---|---|
| T4 | The medicine marked controlled, logo on | Instruction text C (384 characters), precaution text D (192 characters) | **Blocked**: "Label lines: 38 lines; label limit 33." (the number may be a little higher or lower with your header). Nothing prints |

A line taken from two or more batches prints no batch or expiry. The Dispensary page always takes a line
from one batch, so this case cannot be set up by hand; it is covered by KPOne's automated tests (DSP-2) and by
question Q5 on the pharmacist sheet.

**Text A, instruction (293 characters):**
`Habiskan semua ubat walaupun sudah sihat. Habiskan semua ubat walaupun sudah sihat. Habiskan semua ubat walaupun sudah sihat. Habiskan semua ubat walaupun sudah sihat. Habiskan semua ubat walaupun sudah sihat. Habiskan semua ubat walaupun sudah sihat. Habiskan semua ubat walaupun sudah sihat.`

**Text B, precaution (122 characters):**
`Berhenti dan jumpa doktor jika ada ruam. Berhenti dan jumpa doktor jika ada ruam. Berhenti dan jumpa doktor jika ada ruam.`

**Text C, instruction (384 characters, exactly the field limit):**
`Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Makan selepas makan dengan segelas air. Simpan di tempat kering.`

**Text D, precaution (192 characters, exactly the field limit):**
`Jangan memandu jika mengantuk. Jangan memandu jika mengantuk. Jangan memandu jika mengantuk. Jangan memandu jika mengantuk. Jangan memandu jika mengantuk. Jangan memandu jika mengantuk. Ingat.`

Copy each text without the surrounding marks. The Dispensary page lists the label limits for each field.

## What to check on each printed sticker

Tick each box, or write what went wrong.

| Check | T1 | T2 | T3 |
|---|---|---|---|
| The whole label fits on one sticker; nothing cut off at any edge | ☐ | ☐ | ☐ |
| The last line ("Rekod semasa; cetakan semula mungkin berbeza.") is fully on the sticker | ☐ | ☐ | ☐ |
| The next label starts on a new sticker, not halfway down | ☐ | ☐ | ☐ |
| Text is sharp and readable at arm's length | ☐ | ☐ | ☐ |
| Bold lines stand out (patient, medicine, controlled line) | ☐ | ☐ | ☐ |
| Logo is clear and not stretched | | | ☐ |
| Batch number and expiry date match the batch chosen | ☐ | ☐ | ☐ |
| Background is white and text dark, even with KPOne in dark mode | ☐ | ☐ | ☐ |
| Sticker stays on a real medicine box or bottle and the ink does not smudge when rubbed | ☐ | ☐ | ☐ |

T4: the preview shows the blocked message and "Print current labels" is disabled. ☐

**Reprint (DSP-6).** Print T1 a second time with "Print current labels". It prints with a small boxed
**REPRINT** at the right end of the last line ("berbeza."). Check that the whole box, all four borders and the
full word are on the sticker, that the border is unbroken and the word readable. ☐

Always print with the "Print current labels" button, not the browser's own Print menu or Ctrl+P: only the button
records the print and adds the REPRINT mark. A cancelled print dialog still counts as a print.

## Record

| | |
|---|---|
| Date and branch | |
| Printer make and model | |
| Sticker stock (brand, size) | |
| Done by | |
| Sentences removed from text A for T3 | |
| Result | Pass / Pass with notes / Fail |
| Notes (attach photos of T1 to T3 if you can) | |

If anything fails, send the notes and photos to the Dispensary & OTC team. A different sticker size or a cut-off
bottom line means the label size or the line limit must change before the pilot.
