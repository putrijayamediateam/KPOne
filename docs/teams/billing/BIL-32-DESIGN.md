# BIL-32: daily close approved by the Branch Manager, the day locked, money paused while waiting

Owner decisions:
- **Item 11** (20-21 Nov 2026): CA counts by notes, the Branch Manager approves, and the day is locked. No bill of that
  day can be changed or voided; refund only. New transactions pause while the close waits for approval.
- **Round 2** (21 Nov 2026):
  - 19 = A: only money actions pause; registration and consultation carry on.
  - 20 = A: the Finance Officer can approve the close if the Branch Manager is away.
- **ROLE-1** (#1049) has added `branch_manager` with `payments.cash_closing.approve.branch` only.
- **BIL-32 questions** (22 Nov 2026, owner's words relayed by the coordinator: "1 keep all, 2 A, 3 allow"):
  - 1: keep all approvers (CA Supervisor and Director stay, alongside the Branch Manager and Finance Officer).
  - 2 = A: only the counted day pauses; the next morning is open.
  - 3: Panel claim adjustments are allowed on a locked day; they count on their own confirmation day.
- **View permission** (22 Nov 2026, owner: "approve the Branch Manager cash closing view permission"):
  `payments.cash_closing.view.branch` for `branch_manager` only, view-only.

Design note for review (Kumar). Revision 3: revision 2 addressed Kumar's first review (verdict "rework", ten findings,
K1 to K10); revision 3 addresses his second read ("approve with changes", six items, R1 to R6). Classic only. Synthetic data only; nothing here is production approval.

## 1. How the close works today (unchanged parts)

- A count (`payments.reconcile.branch`: CA, CA Supervisor, Finance Officer, Director) records counted cash against
  expected cash for one branch-day (`cash_closings`).
- An approver (`payments.cash_closing.approve.branch`: CA Supervisor, Finance Officer, Director, and since ROLE-1 Branch
  Manager) who did not count approves or returns the latest count (`cash_closing_decisions`, append-only).
- Approval is possible only after the day ends at branch midnight, with no waiting refunds and unchanged figures.
  `CashClosingService::expected(..., lock: true)` share-locks the day's Cash receipts under a per-branch-day advisory
  lock, so a reversal cannot commit while an approval is decided.
- Nothing today stops money moving on an approved day. "Changed since approval" is only flagged.

## 2. What changes

### 2.1 Who approves

- Branch Manager, Finance Officer, CA Supervisor and Director all approve (owner answer 1). No approval permission is
  removed. The approver is never the counter (unchanged).
- **K1, a bug from ROLE-1 that this slice fixes:** `routes/web.php` puts `cash-closings.decide` inside the
  `permission:payments.reconcile.branch` group, so a Branch Manager (approve only) gets 403 on Approve today. BIL-32
  moves `decide` out of that group so it needs only `payments.cash_closing.approve.branch`.

### 2.2 View-only close pages (owner approved)

- New permission `payments.cash_closing.view.branch` for `branch_manager` only. It opens the Daily cash closing and
  Monthly cash closing pages read only.
- A Branch Manager approves but never records a count. Counting stays on `payments.reconcile.branch`.
- Routes: the two GET pages allow `reconcile` OR `cash_closing.view`; `store` keeps `reconcile`; `start-date` keeps
  `reconcile` plus `payments.cash_closing.start_date.manage.branch` (R6); `decide` needs only `approve` (K1).
- **K9, corrected in R3 and R5:** entry points that lead to the close pages widen to `reconcile` OR `cash_closing.view`:
  - the "Daily cash closing" hub entry only (`ClinicManagementNavigation`), not the Terminal Reconciliation entry;
  - a **new, separate** cash-closing nav flag in `HandleInertiaRequests`. The existing `paymentReconciliations` flag
    drives the Terminal Reconciliation link and stays on `reconcile`, so the Branch Manager never sees a link that 403s;
  - the Billing department space: whether it is offered (`DepartmentSpaceNavigation`) and its cash-closing tile
    (`BillingSpace`);
  - the "days with no cash count" space question (`SpaceQuestions`), so the Branch Manager can ask it.

### 2.3 The day lock (on approval)

When a branch-day's close is approved, that day is **locked**. **K4:** the lock always checks the **original** day of
the thing being changed, not today:

| Action | Original day | Locked day |
|---|---|---|
| Void (finalised → voided, on `invoices`) | invoice `finalized_at` | refused |
| Receipt reversal (`payment_reversals`) | receipt `received_at` | refused, unless part of a refund (see below) |
| Receipt correction (Billing `ReceiptCorrectionService`, K7: writes `payment_reversals`, a new `payments` row and `payment_corrections`) | original receipt `received_at` | refused |
| Invoice adjustment add or reverse (`invoice_adjustments`, `invoice_adjustment_reversals`) | invoice `finalized_at` | refused (in practice rare: already refused after payment or completion) |

Still allowed on a locked day ("refund only"):

- **Refunds.** **K3, owner decision needed (question 5):** a refund before completion needs the receipt reversed
  first (`RefundService`), and a reversal of a locked day's receipt is refused above. Two options:
  - **A (recommended):** a reversal of a locked day's receipt is allowed only inside a refund: one action that
    reverses and records the refund together, with the refund's approval. The locked day's figures do not change: a
    reversed receipt with a refund still counts as received on its own day (`CashClosingService::expected`, BIL-6), and
    the refund counts on its payout day.
    - **R2:** a reversed receipt counts on its day only while its refund is open, so under A a **rejected** decision on
      the refund of a locked day's receipt is refused (`payment_refund_decisions`, in `BranchDayGate` and PostgreSQL);
      the refund can still be approved. The screen says why Reject is off.
  - **B:** no reversal on a locked day at all; a locked day's receipt can only be refunded after completion (as an
    invoice credit). Before completion, the CA must first complete the visit.
- **New payments on a later day** against an older invoice: they belong to the day they are received.
- **Panel claim adjustments** (PCA-2), which count on their confirmation day (owner answer 3).
- **K6, Panel and pay-later approvals and Panel remittances:** coverage allocations and patient receivables change
  who owes, not the cash received on the locked day, so they are allowed on a locked day's invoice. Panel remittances
  (`PanelRemittanceService`), Panel claim payments (`PanelClaimService`) and their reversals are Panel money, not the
  clinic till, and count on their own day: not locked and not paused.

The lock is a row per branch-day, derived from the approval: a new table `cash_closing_locks`, written in the same
transaction as the approval decision, append-only. A PostgreSQL helper `kpone_branch_day_locked(branch, date)` is
used by guard triggers on the real tables (K2): `invoices` (update to `voided`), `payment_reversals`,
`payment_corrections`, `invoice_adjustments` and `invoice_adjustment_reversals`. A reversal inserted by a refund (option
A) is let through only when the same transaction records the refund. **R1:** a refund row can only be inserted after
its reversal exists (`payment_refunds` check, `RefundService`), so the locked-day check on `payment_reversals` is a
`CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` that runs at commit and then requires a non-rejected
`payment_refunds` row for the receipt (precedent: `2026_09_05_000300_protect_billing_reconciliation`). Under option B the
same trigger simply refuses. The other lock triggers are ordinary row triggers.

### 2.4 The pause (while a close waits for approval; owner answer 2 = A, the counted day only)

From the moment a count is recorded until it is approved or returned, no money action is accepted that **belongs to
the counted day**. **K4:** for the pause, an action belongs to the counted day if either its original day (as in 2.3)
or today (branch-local) is the counted day. In practice the count is recorded in the evening, so new actions that night
are paused; after midnight today is a new day and only actions on the counted day's receipts or invoices are paused.

Paused actions and their tables (K2): new payment (`payments` insert), finalise (`invoices` update to `finalized`),
void, receipt reversal and correction, invoice adjustment add or reverse, Panel or pay-later proposal and approval
(`coverage_allocations`, `patient_receivables`).

**K6, refunds are not paused.** A refund recorded on the counted day changes the expected figure, so the approval's
existing re-check (approval needs unchanged figures) refuses it and the CA must recount. That is safe and is already
how BIL-6 works; the screen says so.

Either way:

- **Registration, queue, consultation and dispensary carry on** (19 = A).
- **"Return for recount" lifts the pause** until the next count.
- **Refused money actions** get a plain message: "Today's cash has been counted and is waiting for the Branch Manager's
  approval. New payments are paused until it is approved or returned for a recount."
- **Enforced in the domain** (one shared guard, `BranchDayGate`, called by `PaymentService`,
  `BillingBuilderService::finalize`, `InvoiceAdjustmentService`, `ResponsibilityService`, `InvoiceCorrectionService`,
  Billing `ReceiptCorrectionService` and `RefundService` for option A's reversal) **and in PostgreSQL** (the same tables
  as above, through `kpone_branch_day_paused(branch, date)`).

### 2.5 Which day (K4)

The helper computes `(ts AT TIME ZONE branches.timezone)::date`, the same branch timezone the close already uses. The
original day comes from `received_at` (receipts) or `finalized_at` (invoices). Today comes from `now()`.

## 3. Data (one additive migration, plus one permission migration)

- **`cash_closing_locks`:** one row per (organisation, branch, business_date), with the approving decision id, created
  at, and an append-only trigger.
- **PostgreSQL functions:** `kpone_branch_day_locked(branch_id, date)`; `kpone_branch_day_paused(branch_id, date)`, true
  when the latest count of that day has no decision. Guard triggers call them on the tables named in 2.3 and 2.4.
- **Permission migration:** `payments.cash_closing.view.branch` for `branch_manager` only (no approver removed),
  mirrored in `PermissionCatalogue`.

## 4. Contention (K5)

There is no branch-day row before approval, so the gate uses the **same per-branch-day advisory lock** the close
already takes (`CashClosingService`), in one order:

1. `BillingContextService::lock` as today (actor, then patient, then visit, in one call);
2. read the original day without a row lock (`received_at` and `finalized_at` never change once set);
3. take the branch-day advisory lock(s): the original day and today, **in date order**, deduplicated;
4. only then the invoice and ledger row locks (`FinancialLedger::lock`).

**R4:** the gate runs right after `context->lock` and before any invoice or payment lock, the same way in every gated
service. The close never locks patients, visits or invoices for update, so either side of step 1 is deadlock-free.

Count and approval take the same advisory lock before the receipts' share lock, as today. So:

- A payment and an approval racing: the payment commits before the approval takes the lock (and is counted, because
  approval re-reads expected cash under the lock), or it waits and is then refused by the lock.
- A count and a payment racing: the payment commits before the count (in its expected figure) or is refused by the
  pause.
- No path takes an invoice or payment row lock and then the advisory lock, so a reversal and an approval cannot
  deadlock.

## 5. Screens (Daniel's look)

- **Daily cash closing:** "Waiting for approval: new payments for this day are paused" while a count waits; "Locked" on
  an approved day; the Branch Manager sees Approve and Return but no Count form.
- **Patient Billing and Manage payments (K8, the counted day only):** a banner when today is the counted day and its
  count is waiting; money buttons off with the reason. On a locked day's invoice or receipt: Void, receipt reversal and
  correction off, with "This day's cash is closed and locked." Refund stays available (option A) or points to refund
  after completion (option B).

## 6. Tests

- **Lock:** void, reversal, correction and adjustment refused on a locked day, in the domain and by direct SQL; a refund
  still allowed (per question 5); a later-day payment allowed; a Panel approval and a Panel remittance allowed.
- **Pause:** each paused action refused while a count waits, in the domain and by direct SQL; registration and
  consultation not refused; a return lifts the pause; the next day is not paused; a refund on the counted day is
  accepted and the approval then asks for a recount.
- **Option A refund:** reverse and refund together on a locked day commits; a reversal alone is refused at commit; a
  rejection of that refund is refused.
- **Approvers:** Branch Manager approves (K1, today a 403); Finance Officer, CA Supervisor and Director approve; the
  counter can never approve.
- **View-only:** the Branch Manager opens both close pages, the Daily cash closing hub entry and the Billing space, cannot
  count or set the start date (403 on POST), sees no Count form, and sees no Terminal Reconciliation link.
- **PostgreSQL contention tests** (task-owned database, `Contention` suite): payment against approval; count against
  payment; reversal against approval (the deadlock case); two approvals.
- **Changed existing assertions (K10)**, to be listed line by line for the owner before any edit and left failing until
  approved. Files expected to be touched:
  - `tests/Feature/RoleOneRolesTest.php` (exact `branch_manager` permission list);
  - `tests/Frontend/fixtures/access-control-roles.json`;
  - `tests/Feature/Billing/CashClosingTest.php`, `CashClosingMonthTest.php`, `CashClosingStartDateTest.php`;
  - `tests/Feature/ClinicManagementTest.php`, `tests/Feature/BillingSpaceTest.php`;
  - `tests/Feature/SpaceAskTest.php`, `tests/Unit/SpaceQuestionsRegistryTest.php`;
  - `tests/Frontend/bil-4-cash-closing.test.mjs`, `bil-7-cash-closing-month.test.mjs`, `nav-02-top-bar.test.mjs`;
  - `tests/Feature/PostgresCashClosingRegressionTest.php`, `PostgresBillingRegressionTest.php` and their workers
    (table snapshots and protocols see the new guards);
  - the permission catalogue pins.

## 7. Owner answers (22 Nov 2026)

1. Approvers: keep all (CA Supervisor, Director, Branch Manager, Finance Officer).
2. Pause: A, the counted day only.
3. Panel claim adjustments on a locked day's invoice: allowed.
4. New `payments.cash_closing.view.branch` for `branch_manager` only, view-only: approved.

## 8. New question for the owner (from Kumar's review)

5. Refund of a locked day's receipt before the visit is completed: **A** (recommended) reverse and refund together in
   one approved action, the locked day's figures unchanged; or **B** no reversal on a locked day, refund only after the
   visit is completed?
