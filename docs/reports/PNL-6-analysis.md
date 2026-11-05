# PNL-6 analysis: correcting a wrongly recorded Panel payment

Owner answered section 5 on 2026-11-05: "use the recommendations" (option (a) for every question), and the
build is authorised as PNL-6.
Panel team (Syafiq, Lead), 2026-11-05, at `main` `322228f`.

## 1. The problem

When a Panel pays the clinic, a Panel Officer (or, since PNL-3, a Finance Officer) records the payment against
one claim, or splits one Panel payment across several claims. Today a recorded payment can never be undone:

- `panel_claim_payments` rows are refused update and delete by PostgreSQL (`kpone_panel_claim_child_immutable`).
- `panel_remittances` rows (PNL-3 split payments) are refused update and delete in the same way.
- There is no reversal record and no screen.

So a typing mistake (RM 1,500 entered as RM 15,000, the wrong claim, the wrong date, a payment recorded twice, or
a payment that later bounced) stays in KPOne for ever. It makes the claim look paid when it is not, drops it off
the Follow-up list, and puts a wrong "remitted" figure into Insights, the Panel summary and the daily agent
summary.

## 2. What KPOne already does for patient payments (the pattern to copy)

`InvoiceCorrectionService::reverse` (patient receipts):

- a separate append-only `payment_reversals` row; the original payment is kept and marked `reversed`;
- **the person who reverses must not be the person who recorded it**;
- a written reason is required, and the screen states it is a recording error only, **not a refund**;
- a separate permission, `payments.reverse.branch` (CA Supervisor, Finance Officer, Director);
- reports show original, reversed and net amounts (RPT-01b, RPT-02b).

The design below follows that pattern, so staff see one way of correcting money across KPOne.

## 3. Proposed design

### 3.1 What a correction is

**Reverse, then record again.** A wrong payment is never edited. It is reversed with a reason, and the correct
payment (if any) is recorded again through the existing screens. Every step stays visible in the claim's history.

- **Whole payment only.** A reversal cancels a whole payment, never part of one. A smaller "partial reversal"
  would be harder to understand and is not needed: reverse it and record the right amount.
- **A split Panel payment (PNL-3) is reversed as a whole.** All its claim splits are reversed together in one
  action, because the split must always equal the bank amount. Then the payment is recorded and split again
  correctly. (Reversing just one claim's share would leave the Panel payment not adding up.)
- **A single-claim payment** (recorded on the claim page) is reversed on its own.

### 3.2 What happens to the claim

- The claim's received amount becomes "payments minus reversals".
- Its status goes back by itself: `paid` → `partially_paid`, or back to `accepted` / `partially_accepted` when
  nothing is left received (the earlier status follows from the accepted amount, which does not change).
- The Panel's decision and accepted amount are **not** touched. A wrong decision is a different problem and is
  out of scope here.
- The reversal counts as a new action on the Follow-up list, so a claim that is unpaid again starts its
  follow-up clock from the reversal.

### 3.3 Records and rules

- New append-only table `panel_claim_payment_reversals`: payment, claim, branch, amount (always the whole
  payment), reason code, short note, who and when; optionally the Panel payment (remittance) it belongs to.
  Update, delete and truncate refused, as for all claim evidence.
- PostgreSQL checks: one reversal per payment at most; the amount equals the payment; the reverser is not the
  recorder; a reversal of one split exists only if every split of that Panel payment is reversed in the same
  transaction (deferred check, like the PNL-3 "split must add up" rule); the claim's status matches its net
  received amount.
- The domain service: permission and branch check, one transaction, row locks in a fixed order (Panel payment,
  then claims by id), `lock_version` on each claim, an idempotency key, and one structural audit row
  (`panel_claim.payment_reversed` / `panel_remittance.reversed`: amounts, counts and reason code only, never the
  note text).

### 3.4 Where it shows

- **Claim page, Payments block:** a "Reverse" action beside a payment for staff allowed to reverse; a reversed
  payment stays listed, struck through, with "Reversed by … on …, reason …".
- **Panel payments page (PNL-3):** the same action on a recorded Panel payment, reversing all its splits.
- **Reports and figures that read claim payments become net of reversals** (seven places: claim list, claim
  page, Panel payments page, Follow-up list, Panel summary, Insights Payments "remitted", the agent Panel claims
  summary). Insights and the Panel summary show the reduction in the period of the **original** payment date,
  as patient receipts already do, with a "reversed" figure where a column exists.

## 4. Not in this design

- Refunds: KPOne never pays a Panel back; a reversal records that the earlier entry was wrong.
- Changing a Panel decision or an accepted amount after it was recorded.
- Editing a payment's date or reference in place (reverse and record again instead).
- Any bank or Panel portal link.

## 5. Questions for the owner

Each has a recommended answer (first). The owner may answer "use the recommendations".

1. **Who may reverse a Panel payment?**
   (a) *Recommended:* Finance Officer and Director, with a new narrow permission
   `panel.claim_payments.reverse.branch`. (b) Also the Panel Officer. (c) Someone else.
2. **Must the person who reverses be different from the person who recorded it?**
   (a) *Recommended:* yes, always, as for patient payments. (b) No, the recorder may fix their own mistake.
3. **One step or two?**
   (a) *Recommended:* one step by that different person, with a reason. (b) Two steps: one person asks, a second
   person approves (like the daily cash closing). Safer, but slower.
4. **Reasons:** (a) *Recommended:* a fixed list (Wrong amount, Wrong claim, Wrong date or reference, Recorded
   twice, Payment did not arrive or bounced, Other) plus a required short note (up to 200 characters, no patient
   details). (b) Free text only.
5. **Time limit:** (a) *Recommended:* none; a mistake can be fixed whenever it is found. (b) Only within N days.
6. **Reports:** (a) *Recommended:* the correction reduces the figure in the period of the original payment date
   (past reports change, as for patient receipts). (b) It shows in the period when the reversal was made
   (past reports never change).

## 6. Size and risk

About the size of PNL-3: one migration (table, PostgreSQL checks, permission grant), one domain service, two
screen changes, seven read paths made net of reversals, and tests (including red-green proof that today's
screens double-count without the change, a PostgreSQL race between a reversal and a new payment, and every
read path's figure before and after). No existing assertion should need to change; if one does, it will be
listed for the owner first.
