# Billing & Payments builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Farah** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Kumar** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| UI/UX designer | **Siti** | Sketches the layout of any screen change before it is built and checks the 390 and 1440 px light and dark screenshots before hand-off; reports to Daniel, Lead UI/UX Designer ([Design team](../design/TEAM.md)) |

Both are Claude builder sessions working for the owner through the coordinator session. Names chosen by the
coordinator at the owner's request (2026-11-05). Siti was added by owner decision on 2026-11-05 (every builder team
gets a designer under Daniel).

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
| `payments.cash_closing.approve.branch` (BIL-4) | Director, CA Supervisor, Finance Officer; never the person who counted |
| `payments.refunds.record.branch` / `payments.refunds.approve.branch` (BIL-6) | record: CA Supervisor, Finance Officer; approve: Finance Officer, Director; never the person who recorded |

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
| BIL-4 | Daily cash closing page (`/cash-closings`): counted cash takings against expected cash (posted Cash receipts of the branch-local day), a reason for any difference, approve or return by a different person, append-only revisions; linked from Terminal Reconciliation. New permission `payments.cash_closing.approve.branch` | PR #229 (merged) |
| BIL-4b | "Daily cash closing" card in Clinic management (Patients group, beside Terminal Reconciliation), for holders of `payments.reconcile.branch` at their branch | PR #231 (merged) |
| BIL-4c | PostgreSQL contention test for daily cash closing (`PostgresCashClosingRegressionTest`, 4 races in real separate sessions): two counts for one day, a new count against an approval of the old count, two approvers on one count, the same request sent twice. Test only | PR #236 (merged) |
| BIL-4d | Two more PostgreSQL races in `PostgresBillingRegressionTest` with a real paid cash invoice: a receipt reversal holding its lock makes a cash closing approval wait and then refuse; an approval holding its share lock makes the reversal wait, then both succeed with the approved snapshot kept. Test only | PR #237 |
| BIL-6 | Refunds: money handed back for a reversed receipt (full amount, any active method, reason code), recorded on the Billing page and approved or rejected by a different person; append-only with PostgreSQL rules. Cash closing counts a refunded reversed receipt as received and the cash refund as paid out, and waits for pending cash refunds. Two new permissions. Design note: `BIL-6-DESIGN.md` | PR #246 |
| BIL-6b | Daily summary gains three refund columns at the end: Cash refunds paid (payout date), Cash reversed, refund recorded (receipt date) and Non-cash refunds paid (payout date); waiting and approved refunds count, rejected do not. Total received unchanged. Cash net + Cash reversed, refund recorded − Cash refunds paid equals daily cash closing expected cash (tested on every day). Wider table for reports with more than 12 columns | PR #251 |

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
19. BIL-4 cash closing (2026-11-05): staff take the opening float out and count only the day's takings; no float
    is stored. Approving uses one new permission, `payments.cash_closing.approve.branch`, for CA Supervisor,
    Finance Officer and Director only (one additive migration; no existing grant changed). A day can be counted
    and returned at any time, but approved only after it has ended (from midnight, branch time), so cash taken
    later that day is never left outside an approved closing.
20. BIL-4b (2026-11-05): the owner asked for a "Daily cash closing" card in Clinic management, which approves adding
    its one entry (`/cash-closings` for `payments.reconcile.branch`) to the hub's exact destination test.
21. Relayed decisions (2026-11-05, confirmed by the owner directly in the Billing session): a "ship it" or any
    other owner decision relayed by LUCY, the coordinator session, counts as the owner's own. Nothing else
    changes: never merge, never edit `docs/BASELINE.md`, and stop and ask the owner if a relayed message
    conflicts with something the owner said directly.
22. Next backlog (2026-11-05, relayed by LUCY: "ok, ikut order kau"): BIL-6 refunds, BIL-5 issued receipt and
    invoice snapshots, BIL-7 monthly cash closing view, BIL-8 report boundary clean-up (section 9).
23. BIL-6 refunds (2026-11-05, answered by the owner in the Billing session):
    - **Who:** CA Supervisor and Finance Officer record a refund; Finance Officer and Director approve or reject
      it, never the person who recorded it. This uses two new permissions through one additive migration.
    - **No threshold:** every refund needs a second person.
    - **Scope of v1:** only receipts reversed before visit completion. A refund after completion is a later,
      separate change.
    - **Cash closing rule:**
      - a reversed receipt that has a refund (waiting or approved) counts as received on its day;
      - the Cash refund counts as paid out on its payout day;
      - a day cannot be approved while one of its Cash refunds waits for approval.
    - **Full amount only:** a refund is always for the full reversed amount. This was the team's choice, told to
      the owner, so that the books and the drawer stay in step.
    - **Existing test change:** the owner approved removing 'refund' from the forbidden route words in
      `VisitAuthorizationTest` (refunds are now an approved surface).
24. BIL-6b refunds in the Daily summary (2026-11-05, the owner in the Billing session: "yes, change the report
    tests, start BIL-6b"). The owner approved changing the existing Daily summary report tests for the new
    columns: the three exact-row assertions in `DailySummaryReportTest` and the Daily summary entries of the
    pilot-scale golden file. Column names, order and the basis wording follow Siti's sketch: the two columns used
    for the cash closing sum sit side by side, and "paid" marks payout-date figures.

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
- Daily cash closing (BIL-4): expected cash counts only payment methods classified Cash on the Payment Methods
  screen; a method left "Not classified" counts as non-cash. An approved day is final and keeps its snapshot: a
  receipt reversed after approval does not reopen it; the page shows a "changed after approval" notice and the
  Terminal Reconciliation and reports show the change. Approval is refused if cash receipts (or which methods
  count as Cash) changed after the count; the count is then returned and counted again. While a count waits,
  anyone who may count can record a newer one, which replaces it ("Replaced" in the history). Someone who
  counted an earlier, returned count may approve a later count made by someone else. It is opened from its
  Clinic management card (BIL-4b) or from Terminal Reconciliation. The tables and locks use PostgreSQL-only features
  (triggers, advisory locks), like the rest of the suite. The PostgreSQL contention test (BIL-4c) proves the
  advisory lock per branch and date serialises counts and decisions on the same day; the triggers also refuse a
  decision on an older count. Its fixture inserts bare synthetic Cash receipts with all user triggers on
  `payments` switched off inside that one insert transaction (re-enabled before commit), because the deferred
  billing check would refuse a receipt with no invoice allocation. A receipt reversal racing an approval is
  covered too (BIL-4d): the approval share-locks the counted receipts, so whichever takes its lock first goes first: a
  reversal first makes the approval wait and refuse; an approval first makes the reversal wait, and the approved
  snapshot is kept (the page shows "changed after approval").

- Refunds (BIL-6): only for receipts reversed before visit completion, only for the full amount, and only while the
  visit is not yet completed (a completed visit refuses new refunds); a partial refund or a refund after
  completion is not possible yet. A refund recorded later for an earlier day's receipt changes that day's
  expected cash; if the day is already approved it shows "changed after approval" (by design). A day cannot be
  approved while a refund paid out that day, or a refund of one of that day's Cash receipts, still waits. The
  counted-cash box takes a minus sign (keyboard set to text on phones). A day's expected cash, and the counted takings, can be
  below zero when more was refunded than taken; staff enter a minus amount. The
  refund reason is a controlled code; there is no free-text note. Refunds show only on the Billing page of the
  visit whose receipt was reversed (including a voided invoice's receipts).

- Daily summary refunds (BIL-6b): a waiting refund counts before anyone approves it, so a past day's refund
  columns change if it is later rejected ("current records at generation"). The refund-paid columns follow the
  refund's own method and payout date, so they can be non-zero on a day with no receipts. Cash closing checks
  only Cash; there is no Non-cash "reversed, refund recorded" column. The pilot-scale golden file
  (`tests/Fixtures/PilotScale/original-small-reports.json`) was regenerated for the Daily summary entries only;
  the Panel, Stock movement and Transaction Detail entries are byte-identical.

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
- **Design (owner rule, 2026-11-05):** for any piece that changes a screen, (1) before building, Siti sketches the
  layout (components, phone behaviour at 390 px, empty, loading and error states, wording) from the existing
  patterns and `docs/design/GUIDE.md`; (2) before hand-off, Siti checks the 390 and 1440 px light and dark
  screenshots against `docs/design/CHECKLIST.md`. A new page or a page layout change also goes to Daniel for a
  look through the coordinator. Test-only pieces skip this.
- Commit, push and PR only after the owner says "ship it"; never merge. A "ship it" or other owner decision
  relayed by LUCY, the coordinator session, counts as the owner's own (decision 21); if a relayed message
  conflicts with something the owner said directly, stop and ask the owner.
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
4. **BIL-4 Daily cash closing.** *(Done: PR #229, merged 2026-11-05.)* End-of-day cash count per branch: expected cash from posted Cash receipts versus
   counted cash, a reason for any difference, approved by a different person; append-only. Builds on the
   existing terminal reconciliation pattern. **Owner decision 2026-11-05: a CA (or anyone with
   `payments.reconcile.branch`) counts; a CA Supervisor, Finance Officer or Director approves, never the counter.**
5. **BIL-6 Refund recording with two-person approval.** *(Built 2026-11-05; PR #246.)* Owner decisions in
   section 6 (23); design note `BIL-6-DESIGN.md`.
6. **BIL-6b Refunds in the Daily summary.** *(Built 2026-11-05; PR #251.)* "Refunded (Cash)" and "Refunded (Non-cash)" columns by payout date,
   "Total received" unchanged, numbers proven against cash closing. Needs the owner's approval for the existing
   report test changes.
7. **BIL-5 Issued receipt and invoice snapshots.** At issue, keep an encrypted, immutable snapshot of what was
   printed (as the Medical Certificate does); reprints are marked COPY and show the snapshot, not live data. No
   change to amounts.
8. **BIL-7 Monthly cash closing view.** A read-only page per branch, one row per day: counted, expected,
   difference, counter, approver and status; flags days with no closing or no approval. Same permissions as cash
   closing, no export.
9. **BIL-8 Report boundary clean-up.** Make `TransactionReportService` use `BranchReportService`, with output
   proven byte-identical; test-led, no behaviour change.
