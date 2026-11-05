# DSP-7: medicine returns after dispensing (design note)

**Status:** design accepted in part by the owner on 2026-11-05 ("go with your recommendations"; see section 5).
Nothing is built. It touches the Inventory team (stock) and the Billing team (money), so their leads should read it
before any build.

**Backlog wording (owner, 2026-11-05):** the CA records a returned quantity and reason after completion, a different
person checks it, and the stock goes into quarantine (the STK-2 hold), not back to sellable. Any money goes through
Billing's refund flow (BIL-6).

## 1. What happens today

- When a Dispensary case is completed, each saved line's quantity leaves stock as a `dispense` movement against the
  batch and location the CA chose. The case, its lines and their batch allocations are then fixed.
- There is no way to record that a patient brought medicine back. Staff would have to use a write-off or a count
  correction, which loses the link to the patient's visit.

## 2. Two things the survey found

1. **BIL-6 cannot refund a completed visit.** `RefundService` refunds only the full amount of a receipt that was
   reversed **before** the visit was completed. Most returns happen after the patient has paid and left, when the visit
   is completed. So DSP-7 cannot hand money back through BIL-6 as it is. Money for a return needs a Billing decision
   (section 5, question 4).
2. **An STK-2 hold covers the whole batch at the branch.** Putting returned units on a hold would also stop the CA
   dispensing the good, unopened stock of that same batch until someone releases the hold. That is safe but can
   block the shelf.

## 3. Proposed flow

**Step 1: the CA records the return** (on the completed case's page, per medicine line):

- quantity returned: more than 0 and no more than was dispensed on that line, minus anything already returned;
- reason, from a fixed list: patient did not use it; wrong medicine or strength given; patient reaction; dispensing
  error; other (a short note is required for "other");
- condition seen: sealed and in date; opened or damaged; cannot tell.

Nothing happens to stock yet. The return waits for a check.

**Step 2: a different person checks it** (CA Supervisor, Finance Officer or Director, never the person who
recorded it). The checker chooses one outcome:

- **Dispose:** the returned units are destroyed. No stock goes back. This is the safe default: returned medicines
  are normally not given to another patient.
- **Quarantine:** the units go back into stock **on hold**, for a later decision (see options A and B below).
- **Refuse:** the return was wrong (for example the wrong case). Nothing changes; the record stays.

**Step 3: money**, only if the owner wants money handled in KPOne (question 4).

Every step is append-only and audited with counts and codes only, never medicine names or free text in the audit.
The dispensed case, its lines and the original `dispense` movement never change.

### Where "quarantine" puts the stock

- **Option A, as written in the backlog: STK-2 hold.** Credit the units back to the same batch at the Dispensary
  location and place an STK-2 hold on that batch. Simple and already built. Side effect: the whole batch at that
  branch is held, so good stock of that batch cannot be dispensed until a supervisor releases the hold.
- **Option B: a separate quarantine place.** Credit the units to a non-dispensing location at the branch (for
  example the branch store, marked as returns), so they can never be picked at Dispensary. The rest of the batch
  stays usable. A later supervisor decision either writes them off (the existing STK-5 write-off) or moves them back
  with a transfer. This needs a small Inventory change (a location set aside for returns).

**Recommendation:** make **Dispose** the default outcome, and use **Option B** for the few returns kept for a later
decision, so a single returned strip never blocks a whole batch. If the owner prefers to reuse what exists today,
Option A works with the side effect noted above.

## 4. Data and rules (for review)

- New append-only tables: `dispensary_returns` (case, line, quantity, reason, condition, recorded by and when) and
  `dispensary_return_checks` (outcome, checked by and when, and the stock movement it created, if any). Update,
  delete and truncate are refused by the model and PostgreSQL. The checker must differ from the recorder (domain and
  database).
- Quantities use the existing exact three-decimal stock unit rules (STK-1). The total returned on a line can never
  exceed what was dispensed.
- A stock credit (quarantine) uses one new movement type, for example `dispensary_return`, written only by the
  check step and added to the governed stock-writer allowlist test.
- Permissions: recording reuses `dispensary.update.branch` or gets its own `dispensary.returns.record.branch`
  (question 3). Checking reuses `inventory.variances.approve.branch` (CA Supervisor, Finance Officer, Director).
- Every write: permission and branch scope, `DB::transaction`, row locks on the case and line, the record's version,
  an idempotency key on the record step, and a structural audit.
- Reports: Stock movement summary shows quarantine credits under "Adjusted"; disposals change no stock. Insights and
  invoices are unchanged unless money is handled (question 4).

## 5. Owner decisions (2026-11-05) and open questions

The owner said "go with your recommendations". Decided:

1. **Default outcome: Dispose.** Returned medicine is never given to another patient by default; quarantine is
   only a checker's choice.
2. **Quarantine place: Option B,** a separate returns place at the branch (needs the Inventory change in section 6).
   Option A is not used, so a return never holds a whole batch.
3. **Money: record the return only** in the first version; any refund stays outside KPOne. A refund after a
   completed visit is a separate Billing piece, not part of DSP-7.

Still open (the note made no recommendation, so these need the owner's answer before the build):

4. **Who records:** any CA at the branch (reuse `dispensary.update.branch`), or a new narrow permission for CA and
   CA Supervisor?
5. **Controlled medicines:** should a returned controlled medicine always need a CA Supervisor or Director as the
   checker, and always be disposed? (Professional question; to add to the pharmacist review.)

## 6. Teams to involve

- **Inventory (Zulkifli):** Option B's returns place, the new movement type, the stock-writer allowlist, and the
  Stock movement summary.
- **Billing (Farah):** question 4. BIL-6 today only covers receipts reversed before visit completion.
- **Design (Iman, then Daniel):** the return form on the completed case page; it is a new section on an existing page.
