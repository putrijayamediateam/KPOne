# BIL-32: daily close approved by the Branch Manager, the day locked, money paused while waiting

Owner decisions:
- **Item 11** (20-21 Nov 2026): CA counts by notes, the Branch Manager approves, and the day is locked. No bill of that
  day can be changed or voided; refund only. New transactions pause while the close waits for approval.
- **Round 2** (21 Nov 2026):
  - 19 = A: only money actions pause; registration and consultation carry on.
  - 20 = A: the Finance Officer can approve the close if the Branch Manager is away.
- **ROLE-1** (#1049) has added `branch_manager` with `payments.cash_closing.approve.branch` only.

Design note for review (Kumar). Classic only. Synthetic data only; nothing here is production approval.

## 1. How the close works today (unchanged parts)

- A count (`payments.reconcile.branch`: CA, CA Supervisor, Finance Officer, Director) records counted cash against
  expected cash for one branch-day (`cash_closings`).
- An approver (`payments.cash_closing.approve.branch`: CA Supervisor, Finance Officer, Director, and since ROLE-1 Branch
  Manager) who did not count approves or returns the latest count (`cash_closing_decisions`, append-only).
- Approval is possible only after the day ends at branch midnight, with no waiting refunds and unchanged figures.
- Nothing today stops money moving on an approved day. "Changed since approval" is only flagged.

## 2. What changes

### 2.1 Who approves

- **Branch Manager:** approves at their branch.
- **Finance Officer:** keeps approval, for when the manager is away (20 = A).
- **Owner question 1:** CA Supervisor and Director still hold approval today. Keep them (no change), or remove them so
  that only the Branch Manager and Finance Officer approve? Removing is a permission change, and changes existing
  assertions listed in section 6.
- The approver is never the counter (unchanged).

### 2.2 View-only close pages (agreed with Kavitha)

- New permission `payments.cash_closing.view.branch` for `branch_manager`. It opens the Daily cash closing and Monthly
  cash closing pages read only.
- A Branch Manager approves but never records a count. Counting stays on `payments.reconcile.branch`, which they don't
  hold.
- Routes move from `reconcile` to `reconcile OR cash_closing.view` for the GET pages only. The POST routes keep their
  existing permissions.

### 2.3 The day lock (on approval)

When a branch-day's close is approved, that day is **locked**. A locked day refuses, in the domain service and in
PostgreSQL:

- **Void** of an invoice finalised on that branch-local day (BIL-33 adds its own approval on top; the lock refuses first).
- **Receipt reversal and receipt correction** (BIL-16) of a receipt received on that day.
- **Invoice adjustment add or reverse** on an invoice finalised that day. Today these are already refused after
  payment or completion, so in practice this only covers a rare open, unpaid, finalised invoice from that day.
- **Any change to a payment of that day.** Payments are append-only already; this is about reversals.

Still allowed on a locked day ("refund only"):

- **Refunds**, before and after completion, with their own approval (BIL-34 adds the Branch Manager and the
  above-RM300 Finance review). A refund's money is counted on its payout day, so a locked day's figures never change.
- **New payments on a later day** against an older invoice. They belong to the day they are received.
- **Panel claim adjustments** (PCA-2), which count on their confirmation day. Owner question 3.

The lock is a row per branch-day, derived from the approval: a new table `cash_closing_locks`, written in the same
transaction as the approval decision, append-only. A PostgreSQL helper `kpone_branch_day_locked(branch, date)` is used
by the guard triggers on `payment_reversals`, `invoice_corrections` (void) and `invoice_adjustments`, so a direct write
cannot get round it. Refunds are not guarded.

### 2.4 The pause (while a close waits for approval)

**Owner question 2. This needs a choice, because approval is only possible after midnight.**

- **Option A: pause the counted day only.**
  - From the moment a count is recorded until it is approved or returned, no new money action is accepted that would
    belong to the counted day: payments, finalise, adjustments, Panel or pay-later proposals and approvals, receipt
    reversals and voids.
  - After midnight the new day is open as normal, so the clinic can work the next morning even if the manager has not
    approved yet.
  - In practice: once the CA has counted at night, the till is closed for that day.
- **Option B: pause the whole branch until approval.**
  - From the count until the approval, no money action at all at that branch, including the next morning.
  - The clinic cannot take payments the next day until the Branch Manager (or the Finance Officer) approves.
  - This is stricter, but it can stop the counter in the morning.

I recommend **A**.

Either way:

- **Registration, queue, consultation and dispensary carry on** (19 = A).
- **"Return for recount" lifts the pause** until the next count.
- **Refused money actions** get a plain message: "Today's cash has been counted and is waiting for the Branch Manager's
  approval. New payments are paused until it is approved or returned for a recount."
- **The pause is enforced in the domain** (one shared guard, `BranchDayGate`, called by `PaymentService`,
  `BillingBuilderService::finalize`, `InvoiceAdjustmentService`, `ResponsibilityService`, `InvoiceCorrectionService` and
  `ReceiptCorrectionService`) **and in PostgreSQL** (the same helper checks for a waiting count). A request that slips
  past the page still fails.

### 2.5 Which day a money action belongs to

The branch-local calendar date of the action's own time (`received_at` for a payment, `finalized_at` for finalise, now
for a reversal or void), using the branch timezone the close already uses.

## 3. Data (one additive migration)

- **`cash_closing_locks`:** one row per (organisation, branch, business_date), with the approving decision id, created
  at, and an append-only trigger.
- **PostgreSQL functions:**
  - `kpone_branch_day_locked(branch_id, date)`;
  - `kpone_branch_day_paused(branch_id, date)`, true when the latest count of that day has no decision.

  Guard triggers call them on the tables in 2.3 and 2.4.
- **Permission migration:** `payments.cash_closing.view.branch` for `branch_manager`, plus question 1's change if
  chosen.

## 4. Contention

The count, approval, and every guarded money write lock the branch-day row in one order (branch-day first), so:

- A payment and an approval racing: either the payment commits before the approval (and is counted, because approval
  re-checks expected cash under the same lock, as today), or it is refused.
- A count and a payment racing: the payment either commits before the count (in its expected figure) or is refused by
  the pause.

## 5. Screens (Daniel's look)

- **Daily cash closing:**
  - shows "Waiting for approval: new payments are paused" while a count waits;
  - shows "Locked" on an approved day;
  - the Branch Manager sees Approve and Return but no Count form.
- **Patient Billing and Manage payments:** a banner while the branch is paused, and money buttons off with the reason.
  On a locked day's invoice: Void and receipt reversal off, with "This day's cash is closed and locked."

## 6. Tests

- **Lock:**
  - void, reversal, correction and adjustment refused on a locked day, both in the domain and by direct SQL;
  - a refund is still allowed;
  - a later-day payment is still allowed.
- **Pause:**
  - each money action refused while a count waits;
  - registration and consultation not refused;
  - a return lifts the pause;
  - Option A: the next day is not paused.
- **Approvers:** the Branch Manager approves; the Finance Officer approves; the counter can never approve.
- **View-only:** the Branch Manager opens both close pages, cannot count (403 on POST), and sees no Count form.
- **PostgreSQL contention tests** (task-owned database, in the `Contention` suite):
  - payment against approval;
  - count against payment;
  - two approvals.
- **Changed existing assertions** (listed exactly for the owner before any edit, left failing until approved):
  - cash closing route and permission tests (GET pages now also allow the view permission);
  - the permission catalogue and role pins (new permission; question 1 if chosen);
  - `ClinicManagementTest`'s destination map and the Billing department-space list (Kavitha's note).

## 7. Questions for the owner

1. Approvers: keep the CA Supervisor and Director as approvers too, or only the Branch Manager and Finance Officer?
2. Pause: A (only the counted day pauses; the next morning is open; recommended) or B (the whole branch pauses until
   approval)?
3. Panel claim adjustments on a locked day's invoice: allow them (they count on their own confirmation day, so the
   locked day's figures do not change; recommended), or block them?
