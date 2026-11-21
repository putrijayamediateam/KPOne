# BIL-30: monthly sales targets per branch and per doctor

Owner decisions: item 13 (20-21 Nov 2026), and round 2 of 21 Nov 2026:
- P6: Director and Finance Officer set targets;
- P7: a Resident Doctor sees their own target;
- 18 = A: monthly targets, and "needed per day" uses the calendar days left.

Design note for review (Kumar). Classic only. Synthetic data only; nothing here is production approval.

## 1. What it does

- **Set (Director, Finance Officer).** For each month (branch-local calendar month), the person sets:
  - a sales target for each branch;
  - optionally, a target for each doctor at that branch.
- **See:**
  - **Director and Finance Officer:** a Sales targets page. For each branch, then each doctor: target, achieved so far,
    balance, needed per day, and the share achieved as a percentage.
  - **Resident Doctor:** "My sales target" for the current month, showing only their own line at their active branch.
    No other doctor's figures, no branch figure, no patient detail.
- No money changes. Targets are read against existing sales; nothing is charged, moved or recalculated.

## 2. The numbers (the same as Insights, so they always agree)

- **Achieved** = Insights "net invoice sales" for the month at that branch:
  - the payable total of invoices finalised in the month;
  - minus refund credits approved in the month;
  - plus Panel claim adjustments confirmed in the month.

  Each item counts on its own day, so a past day never changes. This reuses `InvoiceSalesProjection::reconciliation()`
  and `netOffsets()` over the branch-local month, the same code the Insights Sales headline uses.
- **Per doctor:** the attending doctor of the consultation, from `consultation_checkouts.attending_doctor_user_id`, as
  the Insights Provider table already does. OTC (medicine-only) sales have no doctor, so they count towards the branch
  only. That's why the doctors' lines can add up to less than the branch line; the page says so in one line.
- **Balance** = target − achieved. When achieved passes the target it reads "Target reached" with the amount over,
  never a negative balance.
- **Needed per day** = balance ÷ calendar days left in the month, counting today (owner 18 = A). It is rounded up to the
  sen, so the plan is never short. In the last day, the needed amount is the whole balance. After the month ends,
  "needed per day" is not shown.
- **Figures** are computed fresh when the page loads, in the branch timezone.

## 3. Data (one additive migration)

- **`sales_targets`**: one current row per (organisation, branch, month, doctor or none). The columns:
  - `month` (first day, date);
  - `branch_id`;
  - `doctor_user_id` (null for the branch target);
  - `amount_sen` (from 1 to the ExactMoney ceiling);
  - `lock_version`;
  - created and updated user and time.

  PostgreSQL: a unique key on (organisation, branch, month, coalesce(doctor, 0)), an amount check, and a check that the
  doctor holds the Resident Doctor role at that branch (a domain check in the service; the database checks the key and
  the amount).
- **`sales_target_revisions`**: append-only, one row per set, change or clear (who, when, old amount, new amount, a
  reason up to 200 characters for a change or clear). UPDATE and DELETE are refused by a trigger, as in other
  append-only Billing evidence.
- No existing table changes.

## 4. Rules (domain service `SalesTargetService`)

- **Who sets:** holders of `sales_targets.manage.organisation`, at any branch of their organisation.
- **What can be set:** only the current or a future month. A past month is read only, so history cannot be rewritten.
  Owner question 1 covers correcting the current month on its last days.
- **Protection:** each save runs in a transaction with a row lock, an optimistic `lock_version`, an idempotency key and
  an audit record (`sales_target.set`, `sales_target.changed` or `sales_target.cleared`). The audit holds the branch,
  month, whether it is a doctor target, and the old and new amounts; it never names a patient.
- **Clearing** a target removes the current row and keeps the revision.
- **Changes after a decision:** a target can change any time within its month. Every change is a revision.

## 5. Permissions (owner approved P6 and P7, round 2)

- **`sales_targets.manage.organisation`:** Director, Finance Officer. Sets targets and sees every branch's and doctor's
  targets and progress.
- **`sales_targets.view.own`:** Resident Doctor. Sees only their own line.
- No other role gets either.
- Neither is in the people-and-access authority set. Both come in through one additive permission migration, mirrored
  in `PermissionCatalogue`.
- The Director and Finance overview (BIL-31) reads targets through the manage permission.

## 6. Screens (Daniel's look)

- **Clinic management, then Sales targets (Director, Finance Officer):**
  - a month picker (current month by default);
  - a branch table: target, achieved, balance, needed per day, % reached;
  - expand a branch for its doctors;
  - "Set target" opens a small dialog with an amount in RM, and a reason when changing.
  - At 390 px, the table becomes cards.
- **Resident Doctor:** a small "My sales target" card on the consultation queue page. It shows the month, target,
  achieved, balance, needed per day and a progress bar, with no other names. If no target is set, the card does not
  show.

## 7. Tests

- **Service:**
  - who can set and who is refused;
  - the past-month refusal;
  - idempotency replay;
  - a stale `lock_version`;
  - revision append-only (an UPDATE or DELETE is refused in PostgreSQL);
  - audit content.
- **Numbers:**
  - achieved equals the Insights Sales net figure for the same branch and month, on a fixture with an adjustment, a
    refund credit after completion and a confirmed Panel claim adjustment;
  - the doctor lines exclude OTC;
  - needed per day for the first, a middle and the last day, and after the target is reached.
- **Privacy:**
  - a doctor sees only their own line;
  - a CA, an Administrator and a Panel Officer get 403;
  - no patient field appears in any target payload.
- **Contention test:** two simultaneous sets of the same target leave one current row and two revisions, or one refusal
  for a stale version.
- **Changed existing assertions:** the Clinic management hub's exact destination map and the exact permission pins
  (catalogue and role lists) will need the new entries. They'll be listed for the owner before any edit, and left
  failing until approved.

## 8. Questions for the owner

1. Lock past months completely, or allow the Director to correct the month just ended for a few days?
2. Where should a doctor see their target? The consultation queue page is proposed. Should their "My day" page
   (Staff Experience, item 31) show it too, when that exists?
3. Should Panel sales count towards targets? Proposed: yes. Insights net sales include the Panel share.
