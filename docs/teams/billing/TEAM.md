# Billing & Payments builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Farah** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Kumar** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |

Both are Claude builder sessions working for the owner through the coordinator session. Names chosen by the
coordinator at the owner's request (2026-11-05).

## 2. What this area covers

**In scope (the team builds and maintains):**
- Building and finalising invoices from the dispensed lines (consultation and OTC) and governed prices.
- Payments, receipts and payment reversals; Manage payments.
- Coverage: Panel responsibility proposals and approval within branch limits (BP-01), pay-later requests and
  outstanding (patient receivable) approval; Outstanding bill blocks.
- Invoice adjustments (discount or surcharge), their branch threshold and reversals.
- Invoice notes, Bill to, the invoice activity log.
- Invoice and receipt prints (letterhead, footer, layout).
- Payment methods and their Cash / Non-cash classification; manual terminal reconciliation.
- Completing the visit (visitation) after settlement.
- Pages: `Billing/Show.vue`, `Billing/Invoice.vue`, `Billing/Payments.vue`, `Billing/Print.vue`,
  `PaymentMethods/Index.vue`, the Billing Approval Limits and Terminal Reconciliation screens.

**Shared with other teams (change only through a small agreed change):**
- Panel claims, claim message template, Panel invoices (`PanelClaim*` services live in the Billing folder but
  belong to the **Panel** team).
- Prices, price books and charge definitions: **Catalogue & settings** team.
- Dispensed lines that feed the invoice: **Dispensary & OTC** team (Aisyah and Hakim).
- Financial reports and Insights: **Reports** team (they read `FinancialLedger` and `PostedReceiptProjection`).
- Business details and the shared letterhead: **Catalogue & settings** team.
- Permissions catalogue: changed only by an owner-approved migration.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Domain services | `app/Domain/Visit/Billing/Services/` — `BillingBuilderService`, `BillingSourceService`, `PaymentService`, `ResponsibilityService`, `OutstandingBillService`, `InvoiceAdjustmentService`, `InvoiceAdjustmentLimitService`, `InvoiceNoteService`, `InvoiceBillToService`, `InvoiceActivityService`, `InvoiceCorrectionService`, `CompleteVisitationService`, `FinancialLedger`, `ExactMoney`, `PaymentMethodAdministrationService`, `PaymentMethodClassificationService`, `PaymentReconciliationService`, `BillingApprovalLimitAdministrationService`, `PostedReceiptProjection` |
| Models | `app/Domain/Visit/Billing/Models/` — invoice, lines, payments, allocations, reversals, coverage allocations, receivables, adjustments, notes, Bill to revisions, payment methods, reconciliations |
| Pages | `resources/js/pages/Billing/*.vue`, `PaymentMethods/Index.vue` |
| Tests | `tests/Feature/Billing/*` (except the Panel and pricing files), `PostgresBillingRegressionTest`, `DocumentLetterheadTest` |

## 4. Permissions (who can do what today)

Source of truth: `app/Domain/Access/PermissionCatalogue.php` and `BillingPermissions`.

| Permission | Holders |
|---|---|
| `billing.view.branch`, `billing.build.branch`, `billing.finalize.branch`, `billing.print.branch` | CA, CA Supervisor |
| `billing.summary.branch`, `billing.lines.view.branch` | Director, Panel Officer, Finance Officer |
| `payments.add.branch` | CA, CA Supervisor, Finance Officer |
| `payments.reverse.branch`, `invoices.void.branch` | Finance Officer |
| `payments.reconcile.branch` | Director, CA, CA Supervisor, Finance Officer |
| `coverage.propose.branch` | CA, CA Supervisor, Panel Officer, Finance Officer |
| `coverage.approve.branch` | Director, CA, CA Supervisor, Panel Officer, Finance Officer (CA within Director-set limits) |
| `outstanding.request.branch` / `.approve.branch` / `.view.branch` | request: CA, CA Supervisor, Finance Officer; approve: Director, CA Supervisor, Finance Officer; view: Director, CA, CA Supervisor, Finance Officer |
| `billing.adjustments.add.branch` | Director, CA, CA Supervisor, Finance Officer |
| `billing.adjustments.override_limit.branch`, `.reverse.branch` | Director, CA Supervisor, Finance Officer |
| `billing.notes.view/add.branch` / `billing.notes.retract.branch` | Director, CA, CA Supervisor, Finance Officer / Director, CA Supervisor, Finance Officer |
| `billing.bill_to.view.branch` / `.manage.branch` | adds Panel Officer for view |
| `billing.approval_limits.manage.organisation` | Director |
| `payment_methods.manage.organisation` | Director, Finance Officer, Administrator |

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| 3B | Checkout, service evidence, governed pricing, invoices, receipts, payment and responsibility, controlled Visit completion | #6 |
| I1C | Payment method governance and visit-completion safety | #24 |
| BS-02 group | Manual terminal reconciliation, BP-01 Panel approval limits, Payment Method setup screen | #45 |
| BILL-01 / BILL-02 | Payment guidance and separate amount fields; back button to a page the actor may open | #70, #95 |
| FIN-01 | Finance Officer, Panel Officer and Director see invoice lines | #75 |
| DS-01b-2 | OTC invoice anchor | #81 |
| INV-01 / INV-01b | Invoice and Patient Billing pages in the Yezza layout | #114, #115 |
| INV-02a / INV-02b | Outstanding bill groups; payment dialogs and Manage payments | #116, #117 |
| INV-03 | Read-only invoice activity log | #122 |
| INV-05a / INV-05b | Governed invoice adjustments and reversals; adjustment reporting | #123, #124 |
| DOC-01 | Letterhead and optional branch footer on prints | #128 |
| INV-04a / INV-04b | Encrypted invoice notes; Bill to | #130, #131 |
| RPT-01c-0 | Cash / Non-cash classification on payment methods | #145 |
| D02 / D03 | Footer release (no development disclaimer); OFFICIAL INVOICE / RECEIPT layout | #189, #192 |
| D002-ECHO | Ringgit shown beside sen approval limits | #194 |
| PRINT-1 | Prints stay white in dark mode | #200 |
| BIL-1 | Route-level feature tests and a browser walk (1440 and 390 px) of invoice void and reissue, adjustment reversal, later settlement of pay-later debt on a return visit, and rejected and partially accepted Panel decisions (screenshots handed to the owner, not committed). All four flows worked; no application code changed | #213 |
| BIL-1b | "Panel shortfall" warning on the Billing page when a Panel rejects or only partly accepts a claim (approved Panel share minus accepted amount). Display only; what the patient owes is unchanged | #219 |
| BIL-2 | "Recorded by <name>" on each receipt on the Billing page and Manage payments, with the activity log's name rule; prints unchanged | #222 |
| BIL-3 | "Pay later to collect" page: the branch's approved, unpaid pay-later amounts, oldest due first, with past-due totals and filter, linked from Clinic management and the Billing page's Outstanding bill box. Read-only | PR #224 |

## 6. Owner decisions already made (never ask again)

1. A CA may approve a Panel responsibility within Director-set branch limits; same-actor approval allowed
   within limits; limits are Director-only (2026-10-02).
2. Payments are reversed, never deleted (INV-02b).
3. Adjustments: discount or surcharge, fixed or percentage of the original subtotal, rounded once to sen, a
   required private reason; one active adjustment; reversal by a different person; only before any payment,
   Panel proposal or pay-later history and before completion; a missing branch threshold counts as RM0
   (2026-10-16).
4. Invoice notes: at most 500 characters, encrypted, never edited (correct or retract), excluded from prints,
   claims, audit metadata and reports (2026-10-19).
5. Bill to is document addressing only; it never changes Panel, claim or amounts (2026-10-19).
6. Invoice and receipt footer: "This is a computer-generated document. No signature is required." typed per
   branch in Business details (2026-10-30). The development disclaimer is removed (2026-10-30).
7. Print layout per D03 (OFFICIAL INVOICE / OFFICIAL RECEIPT, To block with name and KP number only) (2026-10-30).
8. Approval limits keep sen entry with a ringgit echo (2026-11-03).
9. ~~"Receiver: staff #ID" stays~~ (2026-11-03). **Replaced 2026-11-05:** the owner authorised showing the
   receiver's name on screens only, not on the printed receipt (BIL-2).
10. Cash / Non-cash classification applies to every report generated afterwards; unclassified counts as
    Non-cash (2026-10-20).
11. SET-01 Price tier skipped; BNPL e-wallet provider fees reconciled outside KPOne for now (2026-10-18).
12. Tax and e-Invoice suitability is to confirm with the clinic's accountant or tax adviser; nothing tax-
    related is built until then.
13. Receiver's name shown on screens only, not on printed receipts (2026-11-05).
14. The pay-later collection list is visible to outstanding viewers (Director, CA, CA Supervisor, Finance
    Officer) at their branches (2026-11-05).
15. Daily cash closing: counted by a CA, approved by a CA Supervisor, Finance Officer or Director who is not
    the counter (2026-11-05).
16. Panel shortfall (Option B, 2026-11-05): when a Panel rejects a claim or accepts only part of it, the Billing
    page shows a "Panel shortfall" warning with the amount. It is shown only; the patient's amount owed does not
    change, and nothing is moved to the patient (BIL-1b).
17. BIL-2 scope (2026-11-05): "Receiver: staff #ID" turned out to be on the stock receiving screen (Inventory
    team). The owner chose to show "Recorded by <name>" on each receipt on the Billing page and Manage payments
    instead, with the same name rule as the activity log ("Staff member" when the viewer may not see that
    person); printed invoices and receipts show no staff name.
18. BIL-3 links (2026-11-05): the pay-later list is opened from a "Pay later to collect" card in Clinic management
    (the owner approved adding its one entry to the hub's exact destination test) and from a link in the
    Billing page's Outstanding bill box.

## 7. Open items and known limits

- **Walked and tested in BIL-1 (2026-11-05):** invoice void and reissue, adjustment reversal, later settlement
  of pay-later debt on a return visit, rejected and partially accepted Panel decisions
  (`tests/Feature/Billing/MoneyFlowWalkTest.php`). All worked as the rules say.
- **Panel shortfall (BIL-1b, owner Option B 2026-11-05):** the Billing page now shows the shortfall as a
  warning. It is still not moved to the patient: the patient owes RM 0 in KPOne and the clinic follows up any
  shortfall outside KPOne. The warning is not on the printed invoice or the completed-visit Invoice page.
- **No void once a Panel or pay-later amount is approved:** an approved responsibility cannot be withdrawn, so
  such an invoice cannot be voided and reissued (pinned by a refusal test in BIL-1).
- **No corrections after completion:** a receipt recorded in error on a completed visit (including a later
  pay-later instalment) cannot be reversed in KPOne; reversal and void need a Registered visit (pinned by a
  refusal test in BIL-1).
- After a void, the Billing page shows only the replacement invoice; the voided invoice and its reversed
  receipt stay in the records but are not listed on that page.
- A fully settled pay-later card still reads "Pay later · Approved · Due <date>" with no settled label; the
  financial summary correctly shows RM 0.00 approved pay later.
- Reprints show live data (current letterhead, footer, Bill to), not the exact paper issued earlier.
- Physical printer test of invoice and receipt still outstanding.
- No tax or e-Invoice fields (accountant first).
- BNPL provider fee and settlement tracking parked for the finance phase.
- Receipt "Recorded by" names (BIL-2) are not on the completed-visit Invoice page, only on the Billing page and
  Manage payments. The stock receiving screen still shows "Receiver: staff #ID"; that screen belongs to the
  Inventory team.
- Staff name visibility (activity log and receipts) compares branch assignment dates with the UTC date, so
  between 00:00 and 08:00 Kuala Lumpur time an assignment starting that day is not yet visible to branch-level
  viewers (existing behaviour of `StaffAccessService`).
- The pay-later collection list (BIL-3) is not audited per view, like the Finance / Billing work list and the
  Registration board; it shows patient name and KP number only. Payments are still recorded on each patient's
  Billing page.
- The pay-later collection list (BIL-3) shows approved pay later only, not everything a patient owes. If a payment
  that paid off part of a pay-later debt is reversed, that amount moves back into the invoice's due now (pay later
  never grows back), so it shows on the Billing page but not on this list. The page says so.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Money uses `ExactMoney` (sen); never floats. Financial evidence is append-only: no edit or delete of
  invoices, payments, allocations, adjustments or their reversals; corrections are new records.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency keys on payments and any retried action, and a structural audit row with no reasons, notes or
  free text.
- Before and after every money action, tests prove the untouched financial evidence is unchanged.
- Never weaken approval limits, independent-person rules or PostgreSQL integrity checks without an owner
  decision recorded in section 6.
- Do not change any existing test assertion without the owner's approval.
- Full gate before hand-off on a task-owned PostgreSQL 18 database, including the billing contention tests
  with none skipped; screenshots at 1440 and 390 px, light and dark, for any screen or print change.
- Commit, push and PR only after the owner says "ship it"; never merge.
- Record every new owner decision in section 6 and every finished piece in section 5 (with its PR number), in
  the same PR.
- **Do not edit `docs/BASELINE.md`** (owner rule, 2026-11-05): rows added by several teams at once made every
  open PR conflict and re-run CI. Record finished work only in this file; the coordinator records all BASELINE
  updates in one docs-only PR after each batch of merges. If a piece changes an authorisation boundary (for
  example a BASELINE section 4 "not authorised" item), say so clearly in the PR description.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`.

1. **BIL-1 Untested money flows.** *(Built 2026-11-05; owner approved "ship it" the same day.)* Walk and test, with synthetic data, the flows DRY-RUN-2 did not cover: invoice
   void and reissue, adjustment reversal, later settlement of pay-later debt, rejected and partially accepted
   Panel decisions. Add feature tests for each; fix defects found (each fix listed for the owner). No change to
   money rules without an owner decision.
1b. **BIL-1b Panel shortfall warning.** *(Built 2026-11-05; owner approved "ship it" the same day.)* **Owner decision 2026-11-05 (Option B):** on the Billing page, show a
   "Panel shortfall" warning with the amount when a Panel claim is rejected or partly accepted. Shown only: the
   patient's amount owed, the invoice and the claim are unchanged, and nothing is charged to the patient.
2. **BIL-2 Receiver's name.** *(Built 2026-11-05 as "Recorded by" on receipts, see decision 17; owner approved "ship it" the same day.)* Show the staff member's name instead of "Receiver: staff #ID" (replaces decision
   9 in section 6, owner 2026-11-05). **Owner decision 2026-11-05: screens only** (Billing, Manage
   payments, activity log); the printed receipt shows no staff name.
3. **BIL-3 Pay-later collection list.** *(Done: PR #224, owner approved "ship it" 2026-11-05.)* A page listing patients with approved unpaid pay-later amounts, oldest
   first, per branch, so staff can collect when the patient returns. **Owner decision 2026-11-05: holders of
   `outstanding.view.branch` (Director, CA, CA Supervisor, Finance Officer) at their branches.**
4. **BIL-4 Daily cash closing.** End-of-day cash count per branch: expected cash from posted Cash receipts versus
   counted cash, a reason for any difference, approved by a different person; append-only. Builds on the
   existing terminal reconciliation pattern. **Owner decision 2026-11-05: a CA (or anyone with
   `payments.reconcile.branch`) counts; a CA Supervisor, Finance Officer or Director approves, never the counter.**
