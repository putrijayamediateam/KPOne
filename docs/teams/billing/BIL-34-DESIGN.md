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
