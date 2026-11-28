# BIL-34: refunds are approved by the Branch Manager; above RM 300 the Finance Officer reviews first (design note)

Owner decision, 25 Nov 2026, by tick box in the coordinator session (item 10): refunds are approved by the Branch
Manager, and a refund above RM 300 is reviewed by the Finance Officer first. To be built before the Monday 30 Nov
Puchong test, after the Billing restyle and BIL-33. Synthetic data only.

## 1. Today (BIL-1, BIL-10)

- **Record:**
  - a refund of a reversed receipt, or an after-completion refund (BIL-10), is recorded by `payments.refunds.record.branch`
    (`ca_supervisor`, `finance_officer`);
  - it writes an immutable `payment_refunds` row (amount, method, reason).
- **Decide:**
  - `payments.refunds.approve.branch` (`finance_officer`, `director`) approves or rejects with a reason code
    (`RefundService::decide`), writing one immutable `payment_refund_decisions` row;
  - the recorder can never decide;
  - BIL-32: a refund whose receipt day is locked can be approved but not rejected.
- An approved refund counts as money paid out in the cash closing and lowers the invoice's figures.

## 2. Proposal

- **Two steps for amounts above RM 300.00** (above `30000` sen; exactly RM 300.00 needs no review):
  1. **Finance review** (new `payments.refunds.review.branch`, `finance_officer`):
     - "Reviewed, send to the Branch Manager", or "Reject" with a reason code;
     - it writes a `payment_refund_reviews` row (immutable: reviewer, time, outcome, reason code);
     - the reviewer may not be the recorder;
     - audit `billing.refund_reviewed`.
  2. **Branch Manager decision:** today's `decide()`, which now also requires a positive review when the amount is above
     RM 300.
- **Who decides:** `payments.refunds.approve.branch` is granted to `branch_manager`. Whether the Finance Officer and the
  Director keep it is question 1.
- **People:**
  - at most one person per role in the chain: the recorder, the reviewer and the approver must all be different people;
  - this is checked in the service and by PostgreSQL (the decision row refers to the review row and checks the three
    user ids differ).
- **States** (shown on Manage payments and in the waiting counts):
  - "Waiting for Finance review" (above RM 300, not reviewed);
  - "Waiting for the Branch Manager";
  - "Approved" or "Rejected".
  - New BIL-31 waiting kinds: `refund_review` for Finance and `refund` for the Branch Manager, which already exists.
- **Locks and rules, as today:**
  - the actor and branch lock; the refund row, payment and invoice locks (BIL-10 order);
  - `lock_version`, idempotency key and audit;
  - the BIL-32 locked-day rule, unchanged;
  - a review on a locked day is allowed (it changes no money).
- **The threshold:** fixed at RM 300 in code, or a setting under Approval Limits (question 2).

## 3. Permissions (each goes to the owner as a tick box)

- `payments.refunds.approve.branch` to `branch_manager`.
- New `payments.refunds.review.branch` to `finance_officer`.
- Possibly removing `payments.refunds.approve.branch` from `finance_officer` and `director` (question 1). That would be the
  first revocation in Billing, so it needs an explicit owner tick, and a migration that revokes (not additive).

## 4. Existing assertions that change (owner approval needed one by one)

- Refund approvals made by a Finance Officer or Director in tests:
  - `RefundTest`, `RefundAfterCompletionTest`, `DailyCloseLockTest`, `CashClosingTest`, `DirectorOverviewTest`;
  - `PostgresBillingRegressionTest` and its worker.
- If question 1 is A, their approver becomes a Branch Manager. If B, only the role pin tests change.
- Any test with a refund above RM 300 gains a review step.
- The full run will give the exact list before I build.

## 5. Tests

- RM 300.00 exactly: one step. RM 300.01: approval is refused until reviewed.
- The reviewer differs from the recorder, and the approver differs from both (service and PostgreSQL).
- A rejection at review ends the refund; a Branch Manager of another branch gets 404.
- The locked-day rule is unchanged; idempotent retry.
- The cash closing counts only approved refunds, as today.
- Contention: two Branch Managers deciding at once, and a review racing a decision.
- Migration tests.

## 6. Questions for the owner

1. **Do the Finance Officer and the Director keep approving refunds?**
   - **A (recommended for Monday):** yes, as a fallback when the branch has no Branch Manager on shift. The Branch
     Manager is added and nothing is revoked.
   - **B:** no; only the Branch Manager approves. This revokes from Finance and the Director.
2. **The RM 300 threshold:**
   - **A (recommended):** fixed at RM 300 for now.
   - **B:** a per-branch setting on Approval Limits (more work before Monday).
3. **Does the RM 300 review also apply to refunds after completion (BIL-10)?**
   - **A (recommended):** yes, every refund.
   - **B:** only refunds of a reversed receipt.

## 7. The owner's answers (25 Nov 2026, tick box in the coordinator session)

- Q1 = **A**: the Finance Officer and the Director keep approving as a fallback; nothing is revoked.
- Q2 = **B**: the review threshold is a **per-branch setting on Approval Limits** (default RM 300.00; an amount exactly at
  the threshold needs no review). Versioned and audited like the other Approval Limits settings. A missing setting
  counts as RM 300.00.
- Q3 = **A**: every refund, including refunds after completion (BIL-10).
- Q4: approved. `payments.refunds.approve.branch` goes to `branch_manager`; new `payments.refunds.review.branch` goes to
  `finance_officer`.
- Kumar's review comes first (the owner chose to wait), and the exact changed-assertion list goes to the owner before
  the build.

## 8. Kumar's review (28 Nov 2026)

Reviewed at `ca57a7b6`. Verdict: OK to build after these changes; the coordinator holds the build until after the
Monday 30 Nov test (it changes the refund money flow, adds permissions and changes existing tests). One point (P2) is
for the owner.

- **P1 Lock order.** The review step locks as `RefundService::decide` does today: `BillingContextService::lock`
  (patient, then visit), the invoice and its ledger for an after-completion refund (BIL-10), the refunded receipt, then
  the refund row. The decision keeps today's order and then reads the review row. A review changes no money, so it takes
  no day gate (a review on a locked day is allowed, as section 2 says).
- **P2 "Fallback" for Finance and the Director (owner).** Q1 = A keeps `payments.refunds.approve.branch` for
  `finance_officer` and `director`. As written they could approve at any time, not only when the branch has no Branch
  Manager. Choose: (a) unconditional, the simplest and what "nothing is revoked" gives today; or (b) only when the branch
  has no active Branch Manager with a current assignment there, checked in the decide transaction (the BIL-33 K2 rule).
  Recommendation: (a) for now, so Monday's counter keeps working, and (b) as a later slice if the owner wants it.
- **P3 The threshold is a new per-branch setting.** Approval Limits today stores per-person limits
  (`billing_approval_limits`: user, capability, amount), so a per-branch review threshold is a different shape. Follow
  INV-05a's per-branch adjustment threshold (`invoice_adjustment_limits` with `InvoiceAdjustmentLimitService`):
  one versioned row per branch, Director-managed on the Approval Limits page, audited, missing = RM 300.00. One additive
  migration.
- **P4 Snapshot the threshold on the refund.** Record the threshold in force (amount and version) on the refund when it
  is recorded, and decide against that snapshot, so changing the setting never moves a refund that is already waiting.
  Refunds recorded before this ships carry no snapshot and need no review (grandfathered). The refund row is immutable,
  so the snapshot columns are written at insert (one additive migration on `payment_refunds`).
- **P5 Three different people in PostgreSQL.** A CHECK constraint cannot read another table, so "recorder, reviewer and
  approver all differ" is a trigger on `payment_refund_reviews` (reviewer ≠ recorder) and on `payment_refund_decisions`
  (approver ≠ recorder and ≠ reviewer when a review exists; a decision above the snapshot needs a positive review).
- **P6 Review idempotency.** A review sends the refund's `lock_version` and an idempotency key, with a payload hash like
  the decision: the same review returns it, a different one is refused, and one review per refund (unique index).
- **P7 Waiting counts.** BIL-31 has no code yet; Manage payments shows the two waiting states, and the Branch Manager and
  Finance get "Refunds waiting" lists instead of BIL-31 kinds.
- **Effect at a small branch:** above the threshold a refund needs three different people (recorder, Finance reviewer,
  approver). With one Finance Officer, the approver must then be the Branch Manager or the Director.
- **Existing tests that change** (the full run gives the exact list before any build): refunds above RM 300 in
  `RefundTest`, `RefundAfterCompletionTest`, `DailyCloseLockTest`, `CashClosingTest`, `DirectorOverviewTest`,
  `PostgresBillingRegressionTest` and its worker gain a review step; with P2 (a), no approver changes and no role pin test
  changes beyond the two additive grants.
