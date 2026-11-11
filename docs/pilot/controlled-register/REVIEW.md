# Controlled register: pharmacist review sheet

**For:** a registered pharmacist reviewing the KPOne controlled-drug register before the clinic relies on it.
**Please:** read the sample in section 2, then answer each question in section 3 with *Accept* or the change you
want. KPOne changes nothing until the clinic owner decides on your answers.

**Status:** DRAFT, pending pharmacist review (STK-24, owner programme 2026-11-11). Every screen and every printed
page says so. It is not a statutory register until a pharmacist approves its format. All data in KPOne is
synthetic until go-live. This sheet is not legal advice.

## 1. What the register is

- **Which medicines:** every medicine the clinic has marked *controlled* in the Medicine catalogue and linked to
  a stock item (SKU). The clinic decides which medicines are controlled; KPOne has no list of its own.
- **Where the figures come from:** KPOne's stock records only. Every movement of that SKU into or out of the
  branch's locations is listed in the order it was recorded, with a running balance. Nothing is typed into the
  register by hand, and nothing in it can be edited.
- **One register per SKU and branch.** A move between two locations of the same branch (for example the
  dispensary shelf to a locked cupboard) shows both *In* and *Out* and leaves the balance unchanged.
- **Each line shows:** date and time (branch time), what happened (received, dispensed, transfer in or out,
  count variance, write-off, patient return, supplier return, treatment room use), quantity in, quantity out,
  running balance, batch, location, a short reference to the source record, and who recorded it.
- **Not shown in this draft:** the patient, their identity card number, the prescriber and their MMC number
  (see question 1).
- **The check:** a CA Supervisor, Finance Officer or Director counts what is physically on hand and records it
  against the register. KPOne requires a **second person**: the checker cannot be anyone who recorded a movement
  since the last check, and cannot be whoever did the last check. A check that does not match needs a reason
  (a governed stock count will follow; stock is held somewhere else; difference to investigate; other, with a
  note). A check never changes stock; stock is corrected only through the governed stock count, which has its
  own independent approval.
- **Kept:** checks cannot be changed or deleted, and the database refuses it too.

## 2. Sample printed register (synthetic)

> **DRAFT REGISTER — PENDING PHARMACIST REVIEW** (top of every page)
>
> **CONTROLLED MEDICINE REGISTER**
> Medicine: Synthetic Medicine A 10 mg · SKU: SYN-CD-01 · Branch: Synthetic branch · Unit: tablet
> Dates: 2026-11-01 to 2026-11-30 · Printed: 30 Nov 2026, 5:10 PM
>
> Balance at the start: 100
>
> | Date and time | What happened | In | Out | Balance | Batch | Location | Reference | Recorded by |
> |---|---|---:|---:|---:|---|---|---|---|
> | 3 Nov 2026, 10:02 AM | Received | 200 | | 300 | B2611 | Dispensary | Goods Receipt · 3f2a9c1e | Staff A |
> | 3 Nov 2026, 10:15 AM | Moved | 150 | 150 | 300 | B2611 | Dispensary → CD cupboard | Transfer · 77b1d0aa | Staff A |
> | 4 Nov 2026, 11:40 AM | Dispensed | | 20 | 280 | B2611 | Dispensary | Dispensary · 9c04e2b1 | Staff B |
> | 12 Nov 2026, 4:05 PM | Count variance (loss) | | 1 | 279 | B2611 | Dispensary | Governed Case · 51de7f09 | Staff C |
>
> Balance at the end: 279
>
> Page 1 of 1

## 3. Questions for the pharmacist

1. **Patient and prescriber.** Must each supply on the register show the patient's name and identity card
   number, and the prescriber's name and MMC number? Malaysian poisons registers usually record the patient and
   the prescriber for each supply. Is the clinic's own existing register enough until KPOne adds them?
2. **One register per SKU.** Is one register per stock item and branch right, or should it be one per medicine
   and strength (several pack sizes together)?
3. **How often to check.** How often should the register be checked against the physical stock (for example
   daily, weekly, or at each shift change)?
4. **Who may check.** Must the checker be a pharmacist or another registered person, rather than a CA
   Supervisor, Finance Officer or Director?
5. **How long to keep it.** How long must the register and its checks be kept?
6. **Wording and layout.** Are the columns, the words in "What happened" and the printed layout acceptable?

## 4. Answers and sign-off

| Question | Accept / change wanted |
|---|---|
| 1 | |
| 2 | |
| 3 | |
| 4 | |
| 5 | |
| 6 | |

Pharmacist name: ______________________ Registration number: ______________

Signature: ______________________ Date: ______________
