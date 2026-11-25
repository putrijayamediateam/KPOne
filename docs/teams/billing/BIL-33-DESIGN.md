# BIL-33: an invoice void is approved by the Branch Manager (design note)

Owner decision, 25 Nov 2026, by tick box in the coordinator session (item 9): a void is approved by the Branch Manager,
and never after the day is locked. To be built before the Monday 30 Nov Puchong test, after the Billing restyle.
Synthetic data only. Classic and Clean Look share the same backend.

## 1. Today

- `POST visits/{visit}/billing/{invoice}/void` (`invoices.void.branch`, held by `finance_officer` only) runs
  `InvoiceCorrectionService::void()` at once. It runs these steps:
  1. it takes the actor and branch lock and the BIL-32 day gate (`guard(..., lockable: true)`), which refuses a locked or
     paused day;
  2. it takes the invoice row lock and checks `requireMutable` (version);
  3. it refuses unless the visit is registered, with nothing paid, no Panel share, no pay-later share and no approved
     Panel share;
  4. it supersedes open proposals, voids the invoice and writes the audit `billing.invoice_voided`.
- One person decides. The Branch Manager holds only `payments.cash_closing.view.branch` today.

## 2. Proposal: request, then approval by a different person

- **New table `invoice_void_requests`:**
  - one row per request: organisation, branch, invoice, visit;
  - `requested_by_user_id`, `reason` (as today, at most 500 characters);
  - `invoice_version` (the invoice's `lock_version` when asked);
  - `status`: `pending`, `approved`, `rejected`, `withdrawn` or `stale`;
  - `decided_by_user_id`, `decided_at`, `decision_reason` (required to reject);
  - `idempotency_key` and `payload_hash`, `lock_version`.
- **PostgreSQL rules:**
  - at most one `pending` request per invoice (partial unique index);
  - a decided row never changes again;
  - no delete or truncate;
  - the decider is never the requester (a check constraint);
  - a decided row must have its decider and decision time.
- **Request (`InvoiceVoidService::request`):**
  - who: the current void holders and the roles in question 1;
  - the same pre-checks as today (actor and branch lock, day gate, invoice lock, version, nothing paid or shared);
  - it writes the pending row and the audit `billing.invoice_void_requested` (ids and version only, no reason text).
  - The invoice stays finalised and unchanged. The Billing page shows "Void waiting for the Branch Manager" with
    Withdraw (requester only).
- **Approve (`InvoiceVoidService::decide`, approved):**
  - who: the Branch Manager of that branch (new permission `invoices.void.approve.branch`; question 2);
  - it takes the actor lock, the request row lock, the day gate (a **locked day is refused, always**) and the invoice row
    lock;
  - it refuses if the requester is the approver;
  - if the invoice's `lock_version` differs from `invoice_version`, it refuses and marks the request `stale` ("The bill
    changed after the void was asked. Ask again.");
  - it re-runs every void condition under the locks, then performs today's void body unchanged (supersede proposals,
    `voided_by_user_id` = **the approver**, `void_reason` = the request's reason);
  - audit `billing.invoice_void_approved`.
- **Reject:** a reason is required; audit `billing.invoice_void_rejected`. The invoice is unchanged.
- **A pending void does not block taking payment.** A payment raises the invoice version, so approving later refuses as
  stale. That keeps the counter working; question 3 asks whether the owner wants payments blocked instead.
- **Where:**
  - the Billing page (request, status, Withdraw);
  - for the Branch Manager, a "Voids waiting" list on their home and the BIL-31 waiting counts (a new kind `void`).
- **Locked day:** both the request and the decision use the BIL-32 day gate. If the day locks while a request is
  pending, approving it is refused and the request can only be rejected or withdrawn.

## 3. Permissions (each goes to the owner as a tick box)

- New `invoices.void.approve.branch` for `branch_manager` (and see question 2).
- `invoices.void.branch` stays as the "may ask for a void" permission (`finance_officer` today).
- Neither is people-or-access authority. Nothing goes to `ca`, so the `medical_assistant` rule does not apply.
- One additive migration in my range.

## 4. Existing assertions that change (owner approval needed one by one)

`POST billing.void` now creates a request instead of voiding. Every test that voids through it, or through the service,
must either approve as a Branch Manager afterwards or assert the request. The full run will give the exact list.
From a first search, these files void directly:
- `DailyCloseLockTest`, `PanelInvoicePrintingTest`, `PayLaterCollectionTest`, `OutstandingBillsTest`;
- `InvoiceBillToTest`, `BillingDisplayFixesTest`, `MoneyFlowWalkTest`, `InvoiceActivityTest`;
- `FinancialSettlementTest`, `TransactionReportTest`, `PatientBillingReportTest`, `DailyReportExportTest`;
- `MedicineSalesReportTest`, and the PostgreSQL worker.

Proposal: a test helper `voidApproved()` that requests and then approves. Each changed assertion is listed for the owner.

## 5. Tests

- Request, then approve by the Branch Manager: the invoice is voided, its proposals superseded and both audits written.
- The requester cannot approve; any non-holder gets 403; a Branch Manager of another branch gets 404.
- Locked day: the request is refused, and approving is refused after the day locks.
- Stale after a payment; reject needs a reason; withdraw by the requester only; one pending per invoice (PostgreSQL).
- Idempotent retry.
- Contention: two Branch Managers approving at once, and approval racing a payment.
- Migration: additive and idempotent.

## 6. Questions for the owner

1. **Who may ask for a void?**
   - **A (recommended):** the Finance Officer and the CA Supervisor. The CA Supervisor runs the counter.
   - **B:** the Finance Officer only, as today.
   - **C:** also the CA.
2. **Who approves?**
   - **A (recommended):** the Branch Manager of that branch, or the Director as a fallback when the branch has no
     Branch Manager.
   - **B:** the Branch Manager only.
3. **Does a pending void block taking payment?**
   - **A (recommended):** no; a payment makes the request stale.
   - **B:** yes; payments are refused until it is decided.

## 7. The owner's answers (25 Nov 2026, tick box in the coordinator session)

- Q1 = **A**: the Finance Officer and the CA Supervisor may ask for a void. So `invoices.void.branch` is also granted to
  `ca_supervisor` (additive), as the "ask" permission. `ca` is unchanged, so the `medical_assistant` rule does not apply.
- Q2 = **A**: the branch's Branch Manager approves; the Director is the fallback when the branch has none.
- Q3 = **A**: a pending void does not block payment; a payment makes the request stale.
- Q4: new `invoices.void.approve.branch` approved for `branch_manager` and `director`.
- Kumar's review comes first (the owner chose to wait), and the exact changed-assertion list goes to the owner before
  the build.
