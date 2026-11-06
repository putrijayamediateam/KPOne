# Drug label: pharmacist review sheet

**For:** a registered pharmacist reviewing the KPOne drug label before the pilot.
**Please:** read the sample labels, then answer each question in section 3 with *Accept* or the change you
want. KPOne changes nothing until the clinic owner decides on your answers.

The clinic decides which medicines are controlled, what directions are written and each medicine's indication
text. KPOne prints what staff saved; it does not check doses, interactions or legal particulars.

The layout follows the clinic's current printed label (DSP-10, owner decisions 2026-11-06).

## 1. What the label shows

One landscape label per medicine line that was dispensed (quantity above zero; a partly dispensed line needs
the doctor's acknowledgement first). Inside a rounded border, in this order:

1. **Clinic name** (bold capitals), the address (up to two rows) and **Tel: phone** (bold, printed as 03-2123 4567), as entered in the
   clinic's Business details, then a thick rule. No logo and no email.
2. **Patient name** (bold). No patient or visit number.
3. **Medicine name** (bold, the catalogue display name, up to two rows), with **QTY: n** on the right (the number
   only, no unit).
4. Directions in capitals on one block: dosage, frequency and instruction joined with commas. The route is not
   printed.
5. `Precaution:`, `Duration:` and `Indication:`, values in capitals, in one column. The indication is the
   medicine's own catalogue text, not the patient's diagnosis. An empty one is left out.
6. The date supplied (dd/mm/yyyy) on the left and `EXP: dd/mm/yyyy` on the right. `EXP: -` when the line did not
   come from exactly **one** batch with a real expiry. No batch number.
7. On a label printed before, a small boxed **REPRINT** at the top right (DSP-6; it uses none of the rows).

Below the border, centred: **Ubat Terkawal/Controlled Medicine** (bold) when the medicine is marked controlled in
the clinic's catalogue.

Capitals are for printing only; the saved text keeps its own case.

**Not printed:** identity card number, date of birth or age, the patient's phone or address, allergies, the
name of the person who dispensed, the queue number, the route, the batch number and any barcode.

**Size:** sticker 100 × 62 mm (provisional until the real printer test), Courier New 9 pt, 48 characters per
row, at most 13 rows inside the border.

## 2. Sample labels

Made-up patient and clinic. Bold rows are marked with **B**; the thick rule is shown as `━━━`. Rows are exactly as
KPOne prints them for this made-up header; a longer clinic name or address can take a row.

**Sample A — ordinary medicine, one batch (12 rows)**

```
B  KLINIK CONTOH (DEMO)
   No. 1, Jalan Contoh 1, 43000, Bandar Contoh,
   Selangor, Malaysia
B  Tel: 03-0000 0000
   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
B  Pesakit Contoh Satu
B  Paracetamol 500mg tablet                 QTY: 20
   2 BIJI, 4 KALI SEHARI JIKA PERLU, MAKAN SELEPAS
   MAKAN.
   Precaution: JANGAN LEBIH 8 BIJI SEHARI.
   Duration:   5 HARI
   Indication: DEMAM / SAKIT / FEVER / PAIN
   05/11/2026                       EXP: 31/12/2027
```

**Sample B — controlled medicine from two batches (12 rows, plus the line below the border)**

```
B  KLINIK CONTOH (DEMO)
   No. 1, Jalan Contoh 1, 43000, Bandar Contoh,
   Selangor, Malaysia
B  Tel: 03-0000 0000
   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
B  Pesakit Contoh Satu
B  Tramadol 50mg capsule                    QTY: 10
   1 KAPSUL, 2 KALI SEHARI, MAKAN SELEPAS MAKAN.
   Precaution: MENGANTUK. JANGAN MEMANDU / DROWSY,
               DO NOT DRIVE.
   Duration:   5 HARI
   Indication: SAKIT TERUK / SEVERE PAIN
   05/11/2026                                EXP: -

B          Ubat Terkawal/Controlled Medicine        (below the border)
```

**Sample C — long directions, exactly at the 13-row limit**

```
B  KLINIK CONTOH (DEMO)
   No. 1, Jalan Contoh 1, 43000, Bandar Contoh,
   Selangor, Malaysia
B  Tel: 03-0000 0000
   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
B  Pesakit Contoh Satu
B  Tramadol 50mg capsule                    QTY: 10
   1 KAPSUL, 2 KALI SEHARI, MAKAN SELEPAS MAKAN
   DENGAN SEGELAS AIR. MAKAN SELEPAS MAKAN DENGAN
   SEGELAS AIR. SIMPAN DI TEMPAT KERING DAN SEJUK.
   Precaution: MENGANTUK. JANGAN MEMANDU.
   Duration:   5 HARI
   Indication: SAKIT TERUK / SEVERE PAIN
   05/11/2026                                EXP: -
```

**Sample D — too long: blocked, not printed.** A two-row medicine name, two rows of directions, a two-row
precaution and a two-row indication need 16 rows. The screen shows `Label rows: 16 rows; label limit 13.` and
nothing prints until the text is shortened. Directions over three rows show, for example,
`Directions: 4 rows (150 characters); label limit 3 rows.` KPOne never cuts text off or shrinks it.

## 3. Questions

| # | Question | Accept / change wanted |
|---|---|---|
| Q1 | Is the content in section 1 enough for a dispensed-medicine label at this clinic? Is anything required that is missing? | |
| Q2 | Is **QTY with the number only** ("QTY: 10") clear enough, or should the unit print ("QTY: 10 TAB")? There is room for a short unit. | |
| Q3 | The **indication** prints from the medicine's catalogue text (up to 72 characters, two rows). Is printing it acceptable, and is the medicine level (not the patient's diagnosis) right? | |
| Q4 | Is the wording **"Ubat Terkawal/Controlled Medicine"** right, and is printing it below the label border the right place? Should a controlled medicine label show anything else? | |
| Q5 | When one line uses **two or more batches**, the label prints `EXP: -`. Is that acceptable, or should it print the earliest expiry? | |
| Q6 | The **route** and the **batch number** are no longer printed. Is that acceptable? | |
| Q7 | Directions print in **capitals**. Is that acceptable for every medicine? | |
| Q8 | Is **9 pt** text the smallest acceptable size for patients to read at home? | |
| Q9 | Are these **limits** acceptable for real directions: dosage, frequency and instruction together at most 3 rows (instruction 144 characters), precaution 2 rows (72), duration 1 row (36), indication 2 rows (72), medicine name 2 rows (72)? Sample C shows a full label. | |
| Q10 | A label that does not fit is **blocked** until the text is shortened (Sample D). Is blocking right, or should long directions continue on a second sticker? | |
| Q11 | A reprint shows the **current** saved record, so it can differ from a label already on a box. Should staff discard old labels after an edit? | |
| Q12 | A label printed again carries a small boxed **REPRINT** at the top right. Is the word right, or should it read "CETAKAN SEMULA / REPRINT"? | |
| Q13 | Anything else you would change? | |

## 4. Sign-off

| | |
|---|---|
| Pharmacist name | |
| Registration number | |
| Date | |
| Overall | Accept as is / Accept with the changes above / Not acceptable |
| Signature | |

Return the signed sheet to the clinic owner. The clinic keeps the original; do not upload it to KPOne.
