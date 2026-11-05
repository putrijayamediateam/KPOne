# Drug label: pharmacist review sheet

**For:** a registered pharmacist reviewing the KPOne drug label before the pilot.
**Please:** read the sample labels, then answer each question in section 3 with *Accept* or the change you
want. KPOne changes nothing until the clinic owner decides on your answers.

The clinic decides which medicines are controlled and what directions are written. KPOne prints what staff
saved; it does not check doses, interactions or legal particulars.

## 1. What the label shows

In this order, one label per medicine line that was dispensed (quantity above zero; a partly dispensed line
needs the doctor's acknowledgement first):

1. Clinic name, address, and phone and/or email, as entered in the clinic's Business details (optional clinic
   logo above)
2. **Patient name** (bold) and the clinic's patient number / visit number
3. **Medicine name** (bold), then **"Ubat Terkawal / Controlled Medicine"** (bold) if the medicine is marked
   controlled in the clinic's catalogue
4. Strength, dosage form, `Kuantiti:` quantity and unit
5. `No. Batch:` and `Tarikh luput:` (dd/mm/yyyy) only when the whole line came from **one** batch
6. `Dos:`, `Kekerapan:`, `Tempoh:`, `Laluan:`, `Arahan:`, `Peringatan:` exactly as the doctor or clinic
   assistant typed them
7. `Dibekalkan:` date supplied (or `Disediakan:` before the case is completed), `Dijana:` date and time printed,
   and `Rekod semasa; cetakan semula mungkin berbeza.` (a reprint shows the current record)

**Not printed:** the indication (reason for the medicine), identity card number, date of birth or age, phone,
address of the patient, allergies, the name of the person who dispensed, the queue number, and any barcode.

**Size:** sticker 100 × 150 mm, Courier New 10 pt, 42 characters per line, at most 35 lines (33 with a logo).

## 2. Sample labels

Made-up patient and clinic. Bold lines are marked with **B**. Line breaks are exactly as KPOne prints them for
this made-up header; a real clinic name, address and contact can add or remove a line or two.

**Sample A — ordinary medicine (21 lines)**

```
   Klinik Contoh (Demo)
   No. 1, Jalan Contoh 1, 43000, Bandar
   Contoh, Selangor, Malaysia
   03-0000 0000 / klinik@contoh.test
B  Pesakit Contoh Satu
   KP-00000001 / KPV-00000001
B  Paracetamol
   500mg
   Tablet
   Kuantiti: 20 tablet
   Dos: 2 biji
   Kekerapan: 4 kali sehari jika perlu
   Tempoh: 5 hari
   Laluan: Oral
   Arahan: Makan selepas makan.
   Peringatan: Jangan lebih 8 biji sehari.
   Dibekalkan: 05/11/2026
   Dijana: 05/11/2026 10:30
   (Asia/Kuala_Lumpur)
   Rekod semasa; cetakan semula mungkin
   berbeza.
```

**Sample B — controlled medicine from one batch (25 lines)**

```
   Klinik Contoh (Demo)
   No. 1, Jalan Contoh 1, 43000, Bandar
   Contoh, Selangor, Malaysia
   03-0000 0000 / klinik@contoh.test
B  Pesakit Contoh Satu
   KP-00000001 / KPV-00000001
B  Tramadol
B  Ubat Terkawal / Controlled Medicine
   50mg
   Kapsul
   Kuantiti: 10 kapsul
   No. Batch: B2611-0042
   Tarikh luput: 31/12/2027
   Dos: 1 kapsul
   Kekerapan: 2 kali sehari
   Tempoh: 5 hari
   Laluan: Oral
   Arahan: Makan selepas makan.
   Peringatan: Boleh menyebabkan mengantuk.
   Jangan memandu.
   Dibekalkan: 05/11/2026
   Dijana: 05/11/2026 10:30
   (Asia/Kuala_Lumpur)
   Rekod semasa; cetakan semula mungkin
   berbeza.
```

**Sample C — long directions with the clinic logo, exactly at the 33-line limit**

```
   Klinik Contoh (Demo)
   No. 1, Jalan Contoh 1, 43000, Bandar
   Contoh, Selangor, Malaysia
   03-0000 0000 / klinik@contoh.test
B  Pesakit Contoh Satu
   KP-00000001 / KPV-00000001
B  Amoxicillin
   250mg
   Kapsul
   Kuantiti: 15 kapsul
   No. Batch: B2611-0007
   Tarikh luput: 30/06/2027
   Dos: 1 kapsul
   Kekerapan: 3 kali sehari
   Tempoh: 5 hari
   Laluan: Oral
   Arahan: Habiskan semua ubat walaupun
   sudah sihat. Habiskan semua ubat walaupun
   sudah sihat. Habiskan semua ubat walaupun
   sudah sihat. Habiskan semua ubat walaupun
   sudah sihat. Habiskan semua ubat walaupun
   sudah sihat. Habiskan semua ubat walaupun
   sudah sihat. Habiskan semua ubat walaupun
   sudah sihat.
   Peringatan: Berhenti dan jumpa doktor
   jika ada ruam. Berhenti dan jumpa doktor
   jika ada ruam. Berhenti dan jumpa doktor
   jika ada ruam.
   Dibekalkan: 05/11/2026
   Dijana: 05/11/2026 10:30
   (Asia/Kuala_Lumpur)
   Rekod semasa; cetakan semula mungkin
   berbeza.
```

**Sample D — too long: blocked, not printed.** A controlled medicine from one batch, with the clinic logo, an
instruction of 384 characters and a precaution of 192 characters (both exactly at their own limits) needs 38
lines. The screen shows `Label lines: 38 lines; label limit 33.` and nothing prints until the directions are
shortened. KPOne never cuts text off or shrinks it.

## 3. Questions

| # | Question | Accept / change wanted |
|---|---|---|
| Q1 | Is the content in section 1 enough for a dispensed-medicine label at this clinic? Is anything required that is missing (for example the indication, which the clinic's current system prints)? | |
| Q2 | Is the wording **"Ubat Terkawal / Controlled Medicine"** right, and is printing it under the medicine name, in bold, the right place? | |
| Q3 | Should a controlled medicine label show anything else (for example a register or prescription reference)? | |
| Q4 | Are **"No. Batch:"** and **"Tarikh luput:"** the right words, and is dd/mm/yyyy the right date format? | |
| Q5 | When one line uses **two or more batches**, KPOne prints no batch or expiry at all. Is that acceptable, or must every batch be printed? | |
| Q6 | Are the Malay headings right: Kuantiti, Dos, Kekerapan, Tempoh, Laluan, Arahan, Peringatan, Dibekalkan, Disediakan, Dijana? | |
| Q7 | Are these **length limits** acceptable for real directions: instruction 384 characters, precaution 192, dosage 96, frequency 96, duration 48, route 48, medicine name 96? Sample C shows how long that is. | |
| Q8 | A label that does not fit is **blocked** until the directions are shortened (Sample D). Is blocking right, or should long directions continue on a second sticker? | |
| Q9 | A reprint shows the **current** saved record, so it can differ from a label already on a box, and the label says so. Is that acceptable, and should staff discard old labels after an edit? | |
| Q10 | Anything else you would change? | |

## 4. Sign-off

| | |
|---|---|
| Pharmacist name | |
| Registration number | |
| Date | |
| Overall | Accept as is / Accept with the changes above / Not acceptable |
| Signature | |

Return the signed sheet to the clinic owner. The clinic keeps the original; do not upload it to KPOne.
