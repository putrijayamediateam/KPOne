# Pharmacist review pack: controlled register and FEFO

**For:** a registered pharmacist reviewing how KPOne handles controlled medicines and the order in which batches
are used, before the clinic relies on it at the pilot branch.
**Please:** read part A and the controlled register sheet it points to. Then read part B and answer its
questions with *Accept* or the change you want. KPOne changes nothing until the clinic owner decides on your
answers.

**Status:** DRAFT, pending pharmacist review (STK-36, owner programme 2026-11-11). It describes what KPOne does
today; it does **not** say what the law or professional standards require. **It is not legal or regulatory
advice.** All data in KPOne is synthetic until go-live.

Text in "double quotes" is exactly what KPOne shows on screen.

**Words used here:**
- **Batch:** one supplier lot of one stock item, with its own batch number and printed expiry date.
- **SKU:** one stock item as KPOne counts it, for example one medicine in one strength and pack type. The same
  medicine can have more than one SKU.
- **FEFO:** first expiry, first out. When there is a choice, the batch that expires first is used first.
- **Branch-local date:** today's date in the branch's own time zone.

## Part A. Controlled register

The controlled register already has its own review sheet:
[`docs/pilot/controlled-register/REVIEW.md`](../controlled-register/REVIEW.md) (STK-24). It explains what the
register shows, has a synthetic sample page, and asks six questions: patient and prescriber details, one register
per SKU, how often to check, who may check, how long to keep it, and the wording and layout.

**Please answer those six questions on that sheet.** Two points from the related procedures are worth reading
alongside it:

- **The second-person check.** The person who checks a register cannot be anyone who recorded a movement of that
  medicine since the last check, and cannot be whoever did the last check. So the person who received a
  controlled delivery never checks it.
- **Where controlled medicines are kept.** KPOne has no "locked" location type. A move to a locked cupboard is an
  ordinary transfer between two locations of the same branch, and the register shows it as both In and Out with
  no change to the balance. Where the cupboard is, and who holds the key, are for the clinic to decide (see the
  stock admission procedure, [`STOCK-ADMISSION.md`](STOCK-ADMISSION.md), question 4).

## Part B. Which batches may be dispensed, and in what order

### B1. When a batch may be dispensed at all

At Dispensary, a batch can be saved on a medicine line and completed only when **all** of these are true. KPOne
checks them again at "Complete Dispensary", so a batch that became unusable in between is refused.

| Rule | What the CA sees if it fails |
|---|---|
| The stock item and its SKU are active | "the stock SKU is inactive" / "the inventory item is inactive" |
| The batch status is available | "the batch status is not available" |
| The expiry date is **after** today's branch-local date. A batch that expires today cannot be dispensed today | "the batch expiry must be after the branch-local current date" |
| The batch is not under a recall | "the batch is under a recall at every branch" |
| The batch is not held at this branch | "the batch is held at this branch" (followed by the hold reason) |
| The stock is in a dispensary location of this branch (never a patient returns location) | The batch is not offered |

**Held and recalled batches** are shown in the batch list but cannot be selected. **Expired batches** that are not
held are not shown at all.

### B2. Which batch KPOne offers first

- The batch list shows the branch's dispensary stock for that SKU, up to 100 batches. They are sorted by:
  1. this branch's dispensary locations first;
  2. then the earliest expiry date;
  3. then the batch received earliest.
- **KPOne pre-selects the first batch that can be used,** which is the earliest-expiring usable batch. The CA can
  choose another.
- Each option reads like: "120 capsule on hand · exp 30 Oct 2027 · B2611 · Dispensary".
- Scanning a box only marks the matching batches ("Matches the box · …"). It never picks one, and the screen
  says "Pick the batch printed on the box."

### B3. The FEFO rule (choosing a later batch)

If the CA chooses a batch while an **earlier-expiring usable batch of the same SKU** has stock in any dispensary
location of the same branch, KPOne refuses the save unless the CA chooses a reason in "FEFO exception reason
(later batch only)":

- "Earlier batch damaged"
- "Earlier batch insufficient"
- "Earlier batch held"
- "Other approved"

The refusal reads: "an eligible earlier-expiry batch exists. Choose a FEFO exception reason for this later
batch." The screen also says: "A later batch needs a controlled reason when an eligible earlier-expiry batch
exists."

**How the rule works in detail:**
- **Same SKU only.** An earlier batch of the same medicine under a different SKU (for example a different pack
  size) does not count.
- **Same expiry date.** Two batches with the same expiry date never trigger the rule, whichever is chosen.
- **Any dispensary location.** The earlier batch counts if it has stock in any dispensary location of the branch,
  not only the location chosen.
- **When it is checked.** The rule is checked when the line is saved. There is no warning while the CA is still
  choosing.
- **Who.** Any CA or CA Supervisor who may edit the Dispensary case can choose the reason. There is no second
  approver and no free-text note.
- **What is recorded.** The reason code is stored on the batch allocation, with an audit entry. After "Complete
  Dispensary" it can never be changed.
- **What is not recorded or reported.**
  - If the line is saved again before completion, only the audit log keeps the earlier reason.
  - No report or screen lists FEFO exceptions.
- **Not checked.** A reason can be given even when no earlier batch exists.

### B4. Expiry warnings outside Dispensary

- "Stock review" lists "Expiring soon": usable stock expiring after today, up to and including day 90. It also
  lists "Expired stock still on hand", whatever its state, until the stock is written off.
- There is **no short-dated warning at Dispensary.** The CA sees only "exp <date>" on each batch.
- KPOne does not stop a short-dated batch being dispensed. Only a batch expiring today or earlier is refused.

### B5. Other ways stock leaves a location

| Route | Usable-batch rules (B1) | FEFO (B3) |
|---|---|---|
| Treatment room usage | Yes. Expired, held or unavailable stock is refused and sent to the governed write-off | No |
| Stock request dispatch to another location or branch | Only part: the batch must be available, and expired stock is refused ("Expired stock cannot be dispatched."). Holds and recalls are **not** checked | No |
| Direct transfer between locations of one branch | No expiry, hold or recall check | No |

**Note:** these two transfer gaps are recorded for the Inventory team. They are not changed by this pack.

### B6. Questions for the pharmacist

1. **Pre-selection.** Is pre-selecting the earliest-expiring usable batch, with the CA free to change it, the
   right default?
2. **Same SKU only.** Should FEFO also look across different SKUs of the same medicine and strength (for example
   two pack sizes)?
3. **Reasons.** Are the four reasons enough? Should "Other approved" need a short note, or a second person's
   approval?
4. **Who may choose a reason.** Is a CA allowed to choose a FEFO exception, or only a CA Supervisor?
5. **Last usable day.** KPOne refuses a batch on its expiry date and allows it the day before. Is that right, or
   should a batch stop being dispensed earlier?
6. **Short-dated supply.** Should KPOne warn, or refuse, when a batch would expire before the patient finishes
   the course (for example within 30 days)? KPOne does not know the course length for every line, so this would
   need a clinic rule.
7. **Review of exceptions.** Should someone review FEFO exceptions regularly? If so, who, and how often? There is
   no report of them today.
8. **Transfers.** Should moving held, recalled or expired stock between locations or branches be refused, or is
   it acceptable when it is moved to quarantine or for return?
9. **Controlled medicines and FEFO.** Do controlled medicines need any rule beyond the ones above, for example
   always a CA Supervisor for a FEFO exception?

### B7. Answers and sign-off

| Question | Accept / change wanted |
|---|---|
| B6.1 | |
| B6.2 | |
| B6.3 | |
| B6.4 | |
| B6.5 | |
| B6.6 | |
| B6.7 | |
| B6.8 | |
| B6.9 | |

Controlled register questions: answered on [`../controlled-register/REVIEW.md`](../controlled-register/REVIEW.md)
(yes / no): ______

Pharmacist name: ______________________ Registration number: ______________

Signature: ______________________ Date: ______________

Clinic owner decision on the answers: ______________________ Date: ______________
