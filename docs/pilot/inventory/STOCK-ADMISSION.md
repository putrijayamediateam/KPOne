# Stock admission procedure (pilot)

**For:** the clinic's CA Supervisors, Finance Officer and Director at the pilot branch, and the pharmacist who
reviews this procedure.
**Please:** read it, fill in the blanks (`________`), answer the questions in section 9, and sign section 10.

**Status:** DRAFT for the clinic and a pharmacist to review (STK-34, owner programme 2026-11-11). It is **not
legal or regulatory advice**. It describes how KPOne works today; it does not say what the law requires. All data
in KPOne is synthetic until go-live, and nothing here is production approval.

Quoted screen text is in "double quotes" and matches KPOne `main` at the time of writing. Anything marked
**(planned)** is not on `main` yet.

**Words used here:**
- **SKU:** one stock item as KPOne counts it, for example one medicine in one strength and pack type.
- **Base stock unit:** the smallest unit KPOne counts that SKU in, for example one tablet. Quantities are always
  entered in it, never in boxes.
- **Governed stock count:** the "Stock count (governed)" screen, where a count is checked and approved by a second
  person before stock changes.

## 1. What "admission" means here

Stock is admitted when it becomes usable stock at a branch in KPOne. **New stock** can come in only three ways:

1. **A supplier delivery** against an approved purchase order (section 3).
2. **Opening stock**, recorded once when the branch starts using KPOne (section 5).
3. **A transfer** from another branch or location (section 6).

**Never admitted as usable stock:**

- Medicine that patients bring back. A CA Supervisor checks each return and either disposes of it, refuses it,
  or keeps it in quarantine. **If it is kept**, it goes to a "Patient Returns (not for dispensing)" location.
  Controlled medicines are never kept this way. Stock in that location is never dispensed, and it leaves only by a governed stock count, a write-off, or a move to another returns
  location.
- Anything received outside these three routes. KPOne's legacy manual adjustment and demo stocktake are closed
  (STK-6A).

Stock that is already in KPOne can become usable again in other, controlled ways. For example, a governed stock
count finds more than recorded, a transfer difference is settled, a hold is released, or a recall is lifted. Each
of those needs a second person. They are corrections, not admissions, and are not covered here.

## 2. Who does what

| Step | Who (KPOne role) | Rule |
|---|---|---|
| Raise a purchase request | CA or CA Supervisor | Another person reviews it |
| Create a purchase order | CA Supervisor or Finance Officer | |
| Approve a purchase order | Finance Officer or Director | Not the person who raised or submitted it |
| Receive a delivery | CA Supervisor | |
| Approve a delivery exception | CA Supervisor, Finance Officer or Director | Not the receiver |
| Place a batch safety hold | CA Supervisor, Finance Officer or Director | |
| Release a batch safety hold | Finance Officer or Director | Not the person who placed it |
| Start a recall (every branch) | Director | |
| Lift a recall | Director or Finance Officer | Not the person who started it |
| Record a supplier invoice | CA Supervisor or Finance Officer | |
| Accept or reject an invoice difference | Finance Officer or Director | Not the person who recorded it |
| Record opening stock | CA Supervisor | |
| Sign off an opening | CA Supervisor, Finance Officer or Director | Not the person who recorded it |
| Receive a transfer | CA or CA Supervisor at the receiving branch | |
| Resolve a transfer difference | CA Supervisor, Finance Officer or Director | Not the receiver |
| Check a controlled medicine's register | CA Supervisor, Finance Officer or Director | Not anyone who recorded a movement of it since the last check, and not whoever did the last check |

**The one rule behind the table: the person who records stock never also approves it.** KPOne refuses it on the
server, so the pilot branch needs at least two people who can approve on every shift where stock arrives. This
means two CA Supervisors, or a CA Supervisor with the Finance Officer or Director.

**Responsible person for stock at the pilot branch:** ________ (role: CA Supervisor).
**Deputy:** ________.
**Independent approvers available on delivery days:** ________.

## 3. Supplier delivery, step by step

### 3.1 Before the delivery

1. A CA or CA Supervisor raises a request on "Purchase requests" ("New request"). A different CA Supervisor or
   Finance Officer reviews it and presses "Convert to draft order" or "Reject".
2. The order is created on the Inventory page, under "Purchase Orders & Receiving" ("Create PO draft", then
   "Submit").
3. A Finance Officer or Director who did not raise or submit it presses "Approve". KPOne says: "Approval
   authorizes receiving against this Purchase Order."
4. If the supplier needs a paper order, use "Print PO". An order that is not approved prints "NOT APPROVED, NOT AN
   ORDER" and must not be sent.

### 3.2 At the door

1. Check that the delivery is for this branch and matches an approved order. **No approved order: do not accept
   the delivery into stock.** Keep it apart and tell the responsible person.
2. Count what physically arrived.
3. Read the printed expiry date and the batch number on every pack. **A pack with no printed expiry date, no batch
   number, or an expiry date of today or earlier cannot be recorded in KPOne at all, not even as rejected.** Set it
   aside, leave it out of the quantity you count in KPOne, and handle it with the supplier outside KPOne (see
   section 8).
4. Check the condition of every pack: seals, damage, and storage temperature where it matters (see question 1).
5. Keep the supplier's delivery note or invoice. Its reference is required in KPOne.

### 3.3 Recording the receipt in KPOne

On the Inventory page, open the approved order. Use the form under "Split lots and delivery exceptions".

1. Enter the "Delivery note or supplier invoice reference". KPOne refuses the same reference twice for the same
   supplier and branch.
2. For each order line, enter the "Physical quantity counted" in the SKU's base stock unit (for example tablets,
   never boxes). Receiving less than the order is normal and leaves the rest of the order open. Tick "Declared
   short delivery (requires approval)" only if the supplier says the rest will not come.
3. Split it into lots, one per batch. For each lot enter the "Batch number", the "Real expiry date" and the "Lot
   quantity".
4. Give each lot an "Outcome":
   - **accepted:** it enters usable stock.
   - **quarantined:** it enters stock, but KPOne places a safety hold on the whole batch at this branch. Held stock
     cannot be dispensed until a Finance Officer or Director releases it on "Batch safety holds".
   - **rejected:** it does not enter stock. Give it back to the supplier.

   Quarantined and rejected lots need a "Controlled reason": "Damaged", "Wrong item", "Short expiry", "Missing
   documentation" or "Other approved reason".
5. Accepted, quarantined and rejected quantities must add up to the physical quantity counted.
6. Optional: enter the "Total paid for this lot (RM, optional)" from the supplier invoice.
7. If every lot is accepted and nothing is over the order or declared short, press "Post receipt". **Any
   quarantined or rejected lot, any over-delivery and any declared short delivery always needs approval:** press
   "Request exception approval" (see 3.4).

### 3.4 When the delivery does not match the order

A delivery exception covers:
- more than ordered ("Over delivery");
- less than ordered, when the receiver declares it short ("Short delivery");
- damaged packs ("Damaged");
- the wrong product ("Wrong item");
- an expiry problem ("Expiry problem");
- a paperwork problem ("Documentation issue").

1. The receiver presses "Request exception approval". **Nothing enters stock yet.**
2. Under "Delivery exceptions and corrections", a different CA Supervisor, Finance Officer or Director checks the
   delivery and presses "Approve delivery exception".
3. The original receiver then presses "Post approved delivery".

### 3.5 Hard rules (KPOne enforces these)

- **No real expiry date, no admission.** Every lot, even a rejected one, needs a batch number and a real printed
  expiry date. KPOne refuses a made-up date with "Enter the actual expiry date, not a sentinel date." An item
  without expiry tracking cannot be received until its setup is fixed.
- **No expired stock.** A lot that expires today or earlier is refused, whatever its outcome: "Expired stock cannot
  be received as available inventory."
- **A wrong item is rejected, never substituted.** "A wrong item must be rejected. Receive the correct product
  against an approved order; do not substitute it silently."
- **No delivery into a patient returns location.**
- **A mistake is never edited.** A posted receipt is corrected only by a whole reversing correction ("Request
  reversing correction"). Only the person who received the delivery can request it, and another person approves
  it. Once any of that stock has moved out, or the purchase order is closed, it needs owner review instead.

### 3.6 After recording

1. Put the stock away in its location. Quarantined stock goes in a separate, labelled place: ________.
2. When the supplier's invoice arrives, record it on "Supplier invoices" ("Record invoice"). KPOne compares it
   with what was received. Any difference waits for a Finance Officer or Director who did not record it. **KPOne
   pays nothing:** payment happens outside KPOne.
3. File the delivery note: ________ (where), kept for ________ years.

### 3.7 Controlled medicines

- Which medicines are controlled is set by the clinic in the Medicine catalogue. KPOne has no list of its own.
- They are received in exactly the same way. They then appear on "Controlled register", a draft register that
  shows the banner "Draft register — pending pharmacist review. Not a statutory register until a pharmacist
  approves its format."
- KPOne has no "locked" location type. Where controlled medicines are physically kept: ________ (see question 4).
- After each controlled delivery, a CA Supervisor, Finance Officer or Director counts what is on hand and uses
  "Record check" on that medicine's register. **The checker cannot be anyone who recorded a movement of that
  medicine since the last check, and cannot be whoever did the last check.** So the person who received the
  delivery never checks it: plan for a third person on controlled delivery days.

## 4. Batch safety holds

- Any CA Supervisor, Finance Officer or Director can place a hold on a batch at this branch from "Batch safety
  holds" ("Place safety hold"). Reasons: "Suspected quality issue", "Recall", "Damaged" or "Other safety concern".
- A hold changes no quantity. Held stock cannot be allocated or dispensed.
- Only a Finance Officer or Director who did not place it can release it ("Release hold").
- A hold covers one branch only. To block batches at every branch at once, a Director uses "Recalls" ("Start a
  recall"). KPOne says: "A recall blocks named batches at every branch. It moves no stock." A Director or Finance
  Officer who did not start it lifts it ("Lift recall").

## 5. Opening stock (once, before go-live)

1. **Units first.** On "Pilot readiness", a Director, CA Supervisor or Administrator checks every SKU's base stock
   unit and presses
   "Verify base unit". The stock unit and the dispensing unit must match, or dispensing is refused.
2. **Count physically:** each location, SKU and batch, with its real printed expiry date.
3. **Record:** a CA Supervisor records each opening on the Inventory page, under "Stock Setup" → "Opening
   Balance" ("Record Opening Balance"). A bulk CSV import ("Import opening stock") is **(planned)** (STK-35, not on
   `main` yet). It will not change the sign-off below.
4. **Sign off:** on "Pilot readiness", under "Opening counts awaiting independent check", someone other than the
   recorder checks the batch, location and physical count and presses "Sign off opening". KPOne refuses
   self-sign-off: "The opening recorder cannot sign off their own count."
5. Opening stock never goes into a patient returns location.

**Opening count date:** ________. **Counted by:** ________. **Signed off by:** ________.

## 6. Transfers in

1. The sending branch creates and approves the stock request, then dispatches it ("Dispatch stock"). Stock leaves
   the sender at dispatch, but the receiving branch cannot use it until receipt is confirmed.
2. At the receiving branch, a CA or CA Supervisor counts what arrived and enters "Accepted into stock", "Short",
   "Damaged" and "Extra", then presses "Confirm receipt". **Only the accepted quantity enters stock.**
3. Any difference stays open under "Transfer differences". It is resolved, with a delivery note, inspection log
   or other document reference, by a CA Supervisor, Finance Officer or Director who did not receive the transfer.
   The outcomes:
   - "Arrived later (add to this branch stock)";
   - "Usable after inspection (add to this branch stock)";
   - "Lost in transit (stays out of stock)";
   - "Written off (stays out of stock)";
   - "Returned to the sending location (no stock change here)";
   - "Kept and brought in by a governed stock count".
4. "Stock other branches need" may suggest sending spare stock to another branch. The sending branch approves
   and sends it; only the receiving branch's own staff receive it, as in step 2.

## 7. What stays outside KPOne

- Paying suppliers.
- The paper delivery note and invoice (KPOne keeps only their reference).
- Storage temperature or cold-chain readings.
- Pharmacist sign-off of controlled-medicine records.

## 8. If something goes wrong

| Problem | Do this |
|---|---|
| Delivery with no approved order | Keep it apart; tell the responsible person; do not record it |
| No printed expiry date or batch number, or already expired | Do not record it in KPOne, even as rejected; leave it out of the count and give it back to the supplier |
| Wrong product | Reject the lot ("Wrong item"); never record it as the ordered product |
| Damaged pack | Quarantine or reject the lot, with the reason "Damaged" |
| A posted receipt was wrong | The receiver uses "Request reversing correction"; another person approves it |
| Quality doubt after receipt | "Place safety hold" on the batch |
| No second person available to approve | Leave the exception waiting; do not use stock that has not posted |

## 9. Questions for the clinic and the pharmacist

1. **Cold chain:** which items need a temperature check at delivery, and where is the reading recorded?
2. **Responsible person:** is a CA Supervisor acceptable as the responsible person for stock at the pilot branch,
   or must it be a pharmacist or doctor?
3. **Paper records:** how long are delivery notes and invoices kept, and where?
4. **Controlled medicines:** where are they physically kept, who holds the key, and is a check after every
   controlled delivery enough?
5. **Short expiry:** what is the shortest remaining shelf life the clinic accepts at delivery? KPOne refuses only
   stock that expires today or earlier.
6. **Rejected stock:** who arranges its return to the supplier, and how is that recorded? KPOne's "Supplier
   returns" is for stock already received.

## 10. Sign-off

| | Name | Role | Date | Signature |
|---|---|---|---|---|
| Prepared by | ________ | ________ | ________ | ________ |
| Reviewed by (pharmacist) | ________ | Pharmacist | ________ | ________ |
| Approved by (clinic owner) | ________ | ________ | ________ | ________ |

Version: DRAFT 1 (STK-34). Changes after approval need a new version and a new sign-off.
