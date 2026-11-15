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
| BIL-5 | Issued invoice and receipt copies: printing is recorded; the first print freezes an encrypted, immutable copy (letterhead and logo included) labelled ORIGINAL, an unchanged reprint is COPY, a changed document prints as REVISED (version n); earlier versions reprint as COPY with a "later version exists" note, a reversed receipt's copy says so. "Issued copies" list on the Billing page; the old live print pages are marked PREVIEW. Two append-only tables with PostgreSQL guards; no new permission | PR #270 |
| BIL-5b | The visit page (`Visits/History.vue`) Documents list prints invoices and receipts through the BIL-5 recorded print ("Back to visit" returns there); the issued-copies fallback name is "Staff member", as elsewhere. Daniel's BIL-5 changes: "Issued" shows date and time on one line and "by …" below, "1 print / 3 prints", and the version number sits beside its ORIGINAL/REVISED badge so the table fits; when the column is narrower than the table (1024 and 1280 px screens, phones) the list shows cards instead (a container query). Phone touch targets: the Billing workspace's compact button style (30 px) had silently overridden the 44 px targets of the print, Reprint and BIL-6 refund buttons; buttons marked `data-touch-target` now get 44 px below 640 px | PR #286 |
| BIL-7 | Monthly cash closing view (`/cash-closings/month`): one row per day of the active branch up to today with the latest count (an approved day's approved count), status (Approved, Waiting for approval, Returned, Not counted, Open for today) and flags (No count, Not approved, Changed after approval), a month summary, previous and next month, "Open day" to the daily page and "Month view" from it. Read only; no new permission, table or migration. Design note: `BIL-7-DESIGN.md` | PR #315 |
| BIL-8 | Transaction Detail (`TransactionReportService`) now extends the shared `BranchReportService` instead of repeating its request boundary. The shared class gains optional hooks (`window`, `guardWindow`, `tooManyRowsMessage`, `extraMetadata`, `filterRules`, `filterDefaults`) whose defaults keep the other reports unchanged. Output proven byte-identical against a verbatim copy of the old service (`TransactionReportBoundaryTest`); patient identity still read only for the rows shown. no behaviour, permission or migration change | PR #338 |
| BIL-9 | Issued invoices and receipts follow the clinic's own paper: letterhead in capitals with Email and Tel (second phone when set), To block with name, IC/passport and phone, item table, seven category totals, totals with Invoice total, Panel covers and Pay later, a receipt sentence, item table and invoice number, VOID on a void invoice. Versions issued before keep their D03 layout; `myr()` prints negative amounts as −RM x. Design note: `BIL-9-DESIGN.md` | PR #388 |
| BIL-10 | Refunds after a visit is completed: part or all of one posted receipt (never more than the receipt less its refunds that are not rejected), recorded by a CA Supervisor or Finance Officer and approved or rejected by a Finance Officer or Director, never the recorder (the two BIL-6 permissions). Each carries an invoice credit of the same amount: once approved, the ledger lowers the invoice total and what was paid together, so nothing becomes due again. Billing page refund panel (amount, method, reason), "Refunded … · … kept" under the receipt and a "Refund credit" line on the Billing, Invoice and printed invoice; Insights and the Sales CSV show "Refund credits" as their own row on the approval day; Daily summary and Transaction Detail gain a "Refunded after completion" column. Additive migration with PostgreSQL guards (`invoice_credits`, `payment_refunds.kind`). Design note: `BIL-10-DESIGN.md` | PR #392 |
| BIL-11 | Each service line keeps its clinical service's category as priced (frozen at finalisation), so a later rename never moves money between the printed category rows or revises the copy. Invoices finalised before BIL-11 keep reading the live category (owner decision). Additive nullable column; no screen, amount or permission change. Design note: `BIL-11-DESIGN.md` | PR #404 |
| BIL-12 | Insights provider tables (Sales and Today) take each approved refund credit off its invoice's doctor, or the OTC row, on the approval day, so the rows add up to the headline net sales (owner decision). No permission, migration or screen change. Design note: `BIL-12-DESIGN.md` | PR #405 |
| BIL-13 | PostgreSQL race tests for refunds after completion: two refunds at once on one receipt never exceed it (one recorded, the other refused on the amount), and two that fit together are both recorded. The race test cleanup also removes invoice credits. Test only | PR #399 |
| BIL-14 | The old live invoice and receipt print addresses (PREVIEW pages) redirect to the visit's Billing page at Issued copies with "Print from here so the copy is recorded."; opening an old link prints and records nothing. Same permission and branch checks (owner decision). Tests that read the old page now read the recorded copy (15 checks, each approved by the owner 2026-11-10). No permission, migration or layout change. Design note: `BIL-14-DESIGN.md` | PR #406 |
| BIL-15 | Cash closing start date per branch: a Director or Finance Officer sets the first day the monthly cash closing (BIL-7) expects daily counts, on the cash closing month page; without one the start stays the first count. Append-only revisions, audited; a start date moved later is announced on the page and never hides counts already made. New permission `payments.cash_closing.start_date.manage.branch` (finance_officer, director; additive migration `2026_11_12_200100`). No change to counts, approvals or amounts | PR #408 |
| BIL-16 | Correct a receipt recorded in error on a completed visit, in one step: a Finance Officer who did not record it reverses it and records what was really received; only money not received becomes due (owner decisions). Never a refund. Append-only `payment_corrections` evidence (migration `2026_11_12_400100`), no new permission. Design note: `BIL-16-DESIGN.md` | PR #415 |
| BIL-17 | Billing page display fixes: a fully paid pay-later reads "Paid in full · nothing left to collect"; voided invoices stay visible (number, total, who and when, receipts; never the reason); the "Use amount due" shortcut also shows on a completed visit with money due; the pay-later list notes money due now (paid first). Display only. Design note: `BIL-17-DESIGN.md` | PR #431 |
| BIL-18 | Pay-later list on phones and summary wording: below 1024 px the pay-later collection list is cards (amount and past-due chip first, the due-now note beside it, a 44 px Open billing); the Billing, Invoice and Visit history summary row "Approved pay later" reads "Pay later still owed" (prints unchanged). Layout and wording only. Design note: `BIL-18-DESIGN.md` | PR #471 |
| BIL-19 | "Recorded by" on the completed-visit Invoice page: each receipt shows when it was received and who recorded it, by the BIL-2 name rule ("Staff member" when the viewer may not see the name); prints unchanged. Display only. Design note: `BIL-19-DESIGN.md` | PR #493 |
| BIL-20 | PostgreSQL race tests for receipt corrections (BIL-16): two people correcting one receipt at once record one correction (one reversal, one replacement, one audit; the other is refused), and a correction racing a refund on the same receipt never both win, with no deadlock (both lock the patient and visit, then the invoice and the receipt); both orders are also forced in turn, and the same request sent twice at once records one correction. The race test cleanup also removes payment corrections. Test only. | PR #474 |
| BIL-21 | Staff names by the branch day: a branch-level viewer sees a staff member's name while that person is assigned to the branch on the branch's own calendar day, as branch access already decides it. Between 00:00 and 08:00 Kuala Lumpur time the UTC date used to keep yesterday's leaver named and today's new starter hidden. One shared Access file (`StaffAccessService`), also used by the staff directory, Dispensary returns, patient history and Panel screens, and by who a branch-level viewer may open in the staff directory (`StaffPolicy`); their tests pass unchanged. | PR #491 |
| BIL-23 | "Bills to collect": the pay-later collection list now shows every completed visit that still owes money, not only approved pay later: rows show Approved, Pay later, Due now and Still owed (the Billing page's Outstanding bill amount); money due now counts from the visit day and is past due from the next day ("N days since visit"); the page and its menu card are renamed (same link). Read-only. Design note: `BIL-23-DESIGN.md` | PR #538 |
| BIL-24 | Bills to collect at scale: the list now works out only bills that can still owe money (approved pay later still to collect, or a completed visit whose invoice has a payment reversal, since a visit completes only with nothing due now and afterwards only a receipt correction can make it owe). Same output, measured on 20,000-bill fixtures. No migration. Design note: `BIL-24-DESIGN.md` | PR #556 |
| BIL-25 | Receipt status badges: on the Invoice page and the read-only Billing payments list a receipt's status is the coloured status badge (Posted green, Reversed neutral grey) on its own row under the receipt number and time, instead of plain grey text; the manage-list badges use the same local tone. Version 2 only (owner decision 2026-11-13): Classic keeps the plain status text. Frontend only | PR #636 |
| BIL-26 | "Review required" on Bills to collect: a bill whose invoice is on hold for a correction or has out-of-date source (the Outstanding bills "Review required before settlement" rule) shows a neutral "Review required" tag beside Still owed on the phone card and in the table, with a help sentence when one is on the page; amounts and Open billing unchanged. Sent as a side list of visit numbers, so the pinned row fields stay. Read-only | PR #639 |
| BIL-28 | The Outstanding bill box's link on the Billing page reads "View all bills to collect at this branch", naming the page it opens (BIL-23). Wording only. | PR #553 |
| BV2-0 | Version 2 groundwork for Patient Billing: a Version 2-only copy of the Classic workspace logic (`composables/billing/useBillingWorkspaceV2.ts`) for the coming V2 page, pinned to the Classic script by a parity test (same payloads, keys, lock versions and idempotency key). Classic is untouched; no visual change; no existing test edited | PR #650 |
| BV2-1 | Version 2 Patient Billing page (`pages/Billing/V2/Show.vue`, registered in `config/v2-pages.php`; Version 2 only). The patient name and visit number head the page; from 1280 px a right column (Due now first, then Payments, Bill to and notes), below 1280 px one column with Due now and Payments first; one main action per state (Build / refresh draft, Finalize Invoice, Record new payment or Settle Outstanding Balance, Complete Visitation), in the header from 1280 px and in a bottom bar with the amount above it below that; "More billing actions" menu (Panel, pay later, then Governed correction last as Danger, opened in view with Cancel); status words through the V2 chip; Panel shortfall on warning colours; a small "Due now" or "Pay later owed" label line above the amount (one element, never wrapping in the 1280 px rail), or "Nothing due"; after completion with pay later still owed, Settle Outstanding Balance is the main action. Same props, routes, permissions, payloads and money rules (the BV2-0 logic copy). The three entry dialogs (payment, Panel or pay later, reversal) are V2Dialogs (bottom sheet on a phone, tall with a pinned footer for the two money dialogs, discard guard on typed values) with V2MoneyInput ("Amount received", RM prefix, amount due stated) and V2Picker for the payment method and the Panel, keeping the Classic calls, payload values and disabled conditions; the bottom bar hides while any dialog is open. No existing test edited | open |
| BV2-2 | Version 2 adjustment and completion dialogs for Patient Billing V2 (`components/billing/v2/InvoiceAdjustmentDialogV2.vue`, `BillingCompletionDialogV2.vue`), so every dialog on the V2 page is a V2Dialog. Each script is a verbatim copy of the Classic dialog (same calculation, threshold check, idempotency key, payload, guards and post), pinned by a parity test; only the template differs: bottom sheet on a phone with a pinned footer, 44 px choices instead of native selects, V2MoneyInput for a fixed amount, warning tokens for the above-limit box, a reason line when the action is disabled, the visit number unbroken in the completion title. Classic's native "required" becomes the disabled Confirm; closing while saving stays refused. Classic dialogs untouched; no existing test edited | open |

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
    other owner decision relayed by B.E.R.N.I.S.S (formerly LUCY), the coordinator session, counts as the owner's own. Nothing else
    changes: never merge, never edit `docs/BASELINE.md`, and stop and ask the owner if a relayed message
    conflicts with something the owner said directly.
22. Next backlog (2026-11-05, relayed by B.E.R.N.I.S.S (formerly LUCY): "ok, ikut order kau"): BIL-6 refunds, BIL-5 issued receipt and
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

25. BIL-5 issued invoice and receipt copies (2026-11-05, answered by the owner in the Billing session): the copy is
    frozen at first print, per version (ORIGINAL, then COPY for the same content); an invoice that changed since its
    last issued copy prints as a new REVISED version, earlier versions stay readable; labels are English (ORIGINAL,
    COPY, REVISED); the "Issued copies" list is for those who can print (`billing.print.branch`), no new
    permission. Design note: `BIL-5-DESIGN.md`.
26. Backup push (2026-11-05, owner rule relayed by B.E.R.N.I.S.S (formerly LUCY): "yes, set the backup push rule for all teams"): whenever the
    team stops to wait for the owner and has work not yet on GitHub, commit it to that piece's own feature branch
    and push it (`git push -u origin <branch>`). Backup only: no PR, never `main` or another team's branch, never
    merge, never force-push. A branch with an open PR is pushed only when the change is ready (a push re-runs CI).
    The PR still opens only after "ship it", from the same branch, with the latest `main` merged first. After a
    restart on a fresh machine, fetch the branch before continuing.

27. BIL-5 print entry points (2026-11-05, answered by the owner in the Billing session): the old live print pages
    (still reachable by URL and from the patient Invoices page) stay viewable but carry a clear "PREVIEW — not an
    issued copy" line on the paper; every Billing print button records the print. The recorded print view opens in
    the same tab with a "Back to billing" button (browsers block a new tab opened after the server records it).
28. BIL-7 monthly cash closing view (2026-11-05, B.E.R.N.I.S.S for the owner: "yes, start BIL-7"; "BIL-7 stays
    approved" after the owner came back). Read only, same permission as the daily page (`payments.reconcile.branch`),
    no export or new tables. Layout follows Siti's sketch; sent to Daniel through B.E.R.N.I.S.S.
    Daniel (2026-11-06): "Not counted" stays danger; phone cards below 1024 px; flags show only what the badge does
    not already say (the warning border stays); a month with no counts never reads "Matches".
29. BIL-8 report boundary clean-up (2026-11-06, the owner in the Billing session: "start BIL-8"). No behaviour
    change: Transaction Detail must stay byte-identical, proven by a test oracle.
    The owner approved (2026-11-06) pointing three lines of the existing source check in
    `tests/Frontend/branch-reports.test.mjs` ("Transaction Detail reuses receipt evidence…") at
    `BranchReportService.php`, where they now live: REPEATABLE READ, the audit call, and the cap check before any
    patient lookup (now `guardWindow()` before `identities()`). No other line of that test changed.
30. BIL-9 invoice and receipt layout (2026-11-06, the owner shared the clinic's paper through B.E.R.N.I.S.S and
    answered in the Billing session): name, IC/passport and phone in the To block; categories from the clinical
    service's category; the sample's totals plus Invoice total, Panel covers and Pay later; earlier copies keep
    their layout; Invoice No on the receipt; VOID on a void invoice and no Total due there; the receipt's Total
    paid is its own amount; page numbers on documents longer than one page (owner, relayed); phones in Daniel's
    format through the shared `PrintedPhone` (owner: "go with Daniel's phone format"). Details in `BIL-9-DESIGN.md`
    section 4.
31. BIL-10 refunds after completion (2026-11-06, the owner in the Billing session; then B.E.R.N.I.S.S for the owner):
    an invoice credit of the same amount; partial amounts up to the receipt less its earlier refunds; the BIL-6 people
    and permissions; no time limit. Reports (B.E.R.N.I.S.S for the owner, marked "Check this"): "Refund credits" as
    their own row in Insights and the Sales CSV, on the approval day; the owner's own words "go with Farah's three for
    BIL-10" for the Daily summary "of which" column, the Transaction Detail per-receipt column and no change to the
    receipts-by-method CSV. The changed existing assertions were approved item by item by B.E.R.N.I.S.S for the owner
    (listed in the PR). Details in `BIL-10-DESIGN.md` sections 6 and 8.
32. BIL-11 frozen service category (2026-11-07, the owner in the Billing session): invoices finalised before BIL-11
    keep reading the live category; every invoice finalised after carries its frozen category.
33. BIL-12 refund credits in the Insights provider table (2026-11-07, the owner in the Billing session): each credit
    comes off its invoice's doctor (or the OTC row) on the approval day; rows add up to the headline.
34. BIL-15 cash closing start date (2026-11-07 and 2026-11-10, the owner in the Billing session): set by a Director or
    Finance Officer, on the cash closing month page; no date set means the start is the branch's first count. Moving the
    date later is allowed and audited, and the month page says "Start date changed on X (was Y)". There is no "clear"
    back to the first count: a date set by mistake is corrected by setting the first-count date by hand.
35. BIL-14 retire the PREVIEW print pages (2026-11-07 and 2026-11-10, the owner in the Billing session): the old
    addresses redirect to Issued copies on the Billing page with a note; the tests move to the recorded copy (items
    a to o approved one by one).
36. BIL-16 correct a receipt after completion (2026-11-10, the owner in the Billing session): a Finance Officer only,
    never the person who recorded it; takes effect at once; any date (an approved cash closing day shows "changed
    after approval"); replace in one step, so only money not really received becomes due.
37. BIL-18 (2026-11-10, the owner through B.E.R.N.I.S.S, "i want to start all that"): take Daniel's two Billing debt
    items, the pay-later list on phones and the "Approved pay later" summary row wording; layout and wording only.
    The owner also approved changing the existing assertion so the past-due chip reads "1 day" / "N days" (2026-11-10).
38. BIL-19 (2026-11-10, the owner in the Billing session, "start with 1"): show "Recorded by" on the completed-visit
    Invoice page, by the BIL-2 name rule.
39. BIL-23 "Bills to collect" (2026-11-11, the owner in the Billing session): list every completed visit that still
    owes money (pay later plus money due now, the Outstanding bill amount); money due now is past due from the day
    after the visit ("N days since visit"); rename the page "Bills to collect", same link; the one existing assertion
    on the old title may change.
40. BIL-24 (2026-11-11, the owner in the Billing session, "start with 1"): make Bills to collect cheaper on a long history
    without changing what it shows; no index migration in this slice (recorded as the next step if needed).
41. BIL-25 receipt status badges (2026-11-11, the owner in the Billing session, "start with 1"; Siti's design, Daniel OK 2026-11-12): Reversed stays neutral grey (a settled correction, nothing to do), set locally in Billing so the shared status map, which HR also uses for 'reversed', is unchanged. On 2026-11-13 the owner chose "Version 2 only" for it: the badges show only with "Try the new layout"; Classic keeps the plain text.
42. BIL-26 (2026-11-12, the owner in the Billing session, "start with 1" on a "Check bill" tag): Siti chose the wording "Review required" (the Billing page and Outstanding bills already use it for the same state; "Check bill" could read as "check the amount with the patient") and a neutral tone so amber stays for past due.
43. BIL-28 (2026-11-12, the owner in the Billing session): the link reads "View all bills to collect at this branch"; the owner approved changing the one existing assertion that pinned the old words (bil-3 test).
44. BV2-0 (2026-11-13, owner "start all v2" via the coordinator; plan approved with conditions): the first try moved the workspace logic into a shared composable, but 7 existing tests would break (they mount or read the Classic file), so it was stopped. The coordinator chose option A: Classic stays byte-identical, the Version 2 page gets its own copy of the logic and a parity test pins the copy to Classic; Kumar reviews line by line; BV2-0 merges alone.
45. BV2-1 (2026-11-13, Daniel's Billing look with the coordinator's conditions): the right column starts at 1280 px at the earliest (the shared V2 work layout only offers 1024 or 1536, so the page builds its own two-column grid); the patient's name sits above Due now; one cobalt action per state; the entry dialogs may keep their Classic build under a Version 2 wrapper until BV2-2 rebuilds them as V2Dialog. The page adds its own "context cleared" reset for the Version 2 page name, so the BV2-0 copy stays identical to Classic.
46. BV2-2 (2026-11-13, agreed with the coordinator as BV2-1's follow-up): the adjustment and completion dialogs follow the BV2-0 pattern: the V2 copies keep the Classic script byte-for-byte (a parity test fails on any drift) and only the template moves to V2Dialog, so Classic stays untouched and no existing test changes.

## 7. Open items and known limits

- **Debt (BV2-0):** about 260 lines of the Patient Billing workspace logic exist twice, in Classic `components/billing/BillingWorkspace.vue` and the Version 2 copy `composables/billing/useBillingWorkspaceV2.ts`. The parity test fails if they differ, so a change to one must be made to both. A shared-composable refactor needs its own approval (7 existing tests would break and need edits).
- **BV2-1 limits:** the shared Classic pieces inside the V2 page (adjustment dialog, refunds, corrections, outstanding bills, Bill to and notes) keep their Classic build under a Version 2 wrapper: while the page is open it marks the page body, and page-only CSS makes every control at least 44 px below 1024 px (also in the dialogs it opens) and shows their extra filled buttons as outline. The dialog close cross stays a small icon. The payment summary sits in the shared layout's right column from 1280 px and stays stacked above the work below it (`railBelow="stack-top"`, batch 15), rendered once, so nothing remounts when the width changes. The adjustment and completion dialogs are V2Dialogs since BV2-2. Screenshots cover the synthetic states that exist (money due, nothing due, completed with refund credit and a voided invoice, adjustments, pay later, Panel); draft, stale draft, proposal waiting and correction hold are covered by tests of the main-action helper, not screenshots. Daniel's BV2-1 review (2026-11-13): the Classic refund form inside V2 Billing (native selects for "Given back by" and "Reason") is design debt **DD-82** and moves to V2Picker once that lands; until then its controls meet 44 px below 1024 px through the wrapper. On a completed visit without corrections the Classic refunds block shows two overlapping explainers, because the Classic `v-else` pairs with the corrections pointer rather than the after-completion note; it needs a Classic change or the V2 refund form, so it travels with DD-82 (Classic stays untouched; Daniel agreed).
- **Debt (BV2-2):** the adjustment and completion dialog logic now exists twice (Classic and `components/billing/v2/`), like BV2-0; the parity test fails if they differ, so a change to one must be made to both.
- **Walked and tested in BIL-1 (2026-11-05):** invoice void and reissue, adjustment reversal, later settlement
  of pay-later debt on a return visit, rejected and partially accepted Panel decisions
  (`tests/Feature/Billing/MoneyFlowWalkTest.php`). All worked as the rules say.
- **Panel shortfall (BIL-1b, owner Option B 2026-11-05):** the Billing page now shows the shortfall as a
  warning. It is still not moved to the patient: the patient owes RM 0 in KPOne and the clinic follows up any
  shortfall outside KPOne. The warning is not on the printed invoice or the completed-visit Invoice page.
- **No void once a Panel or pay-later amount is approved:** an approved responsibility cannot be withdrawn, so
  such an invoice cannot be voided and reissued (pinned by a refusal test in BIL-1).
- **Corrections after completion:** since BIL-16 a receipt recorded in error on a completed visit is corrected on
  its Billing page (reverse and replace in one step). Voiding still needs a Registered visit (pinned by a refusal
  test in BIL-1).
- After a void, the Billing page lists the voided invoice and its receipts under "Voided invoices" (BIL-17), without
  the void reason.
- A fully settled pay-later card reads "Paid in full · nothing left to collect" (BIL-17).
- Issued copies (BIL-5): a print from the Billing or Invoice page is recorded and shows the frozen copy. The old
  live print pages still open and print, marked "PREVIEW — not an issued copy"; nothing in KPOne links to them
  any more (BIL-5b). Each version stores its own logo copy (up to
  about 3.6 MB). A change is compared with the latest version only, so content that changes back gets a new
  version. "Prints" counts presses of Print, not sheets. Issuing is refused for anyone who cannot see every printed
  part (lines and Bill to); today only CA and CA Supervisor print and they see both.
- Physical printer test of invoice and receipt still outstanding.
- No tax or e-Invoice fields (accountant first).
- BNPL provider fee and settlement tracking parked for the finance phase.
- Receipt "Recorded by" names (BIL-2) show on the Billing page, Manage payments and, since BIL-19, the completed-visit
  Invoice page; never on prints. The stock receiving screen still shows "Receiver: staff #ID"; that screen belongs to the
  Inventory team.
- Staff name visibility (`StaffAccessService`) uses the branch's own calendar day since BIL-21. Still on the UTC date,
  outside Billing: the staff directory's branch filter and its assignment state label (`StaffDirectoryService`), and
  `StaffBranchAssignment::effectiveAt()` called without a date (primary-branch checks). Raised for their owner.
- The pay-later collection list (BIL-3) is not audited per view, like the Finance / Billing work list and the
  Registration board; it shows patient name and KP number only. Payments are still recorded on each patient's
  Billing page.
- Pay-later list on phones (BIL-18): "1 records" comes from the shared pagination component. "Return to
  Workspace" is title case (sentence case per the UI guide); it is a shared string, logged for its owner, not fixed here.
- The Billing department space (DS team, `BillingSpace::payLater()`) reads the Bills to collect page: since BIL-23
  its totals include money due now while its rows show the pay-later part only (`remainingSen`), so a due-now-only
  bill reads RM 0.00 there until the space adds the `dueNow` map. Told the DS session; their file, their change.
- Bills to collect (BIL-23) lists completed visits that owe money and approved pay later (also on open visits); an
  open visit's own due-now money is not listed (it is being paid at the counter). Since BIL-28 the Billing page's
  link reads "View all bills to collect at this branch". Since BIL-26 a bill on hold for correction or with stale
  source shows a neutral "Review required" tag (a side list, so the pinned row fields stay). The Billing department
  space (DS team) builds its pay-later card and overdue answers from the rows only, so it does not show the tag yet;
  the side list `reviewRequired` is there for it to use (their file, their change). An open visit with pay later and
  unpaid money due no longer shows the "also due now" note (that money
  is being paid at the counter). If an invoice's evidence did not reconcile, the list floors its due-now money at
  zero instead of refusing, as the Billing page would. Performance (BIL-24): only bills that can still owe money are
  worked out (approved pay later still to collect, or a completed visit whose invoice has a payment reversal). On a
  20,000 paid-bill fixture the page fell from about 1.1 s to 0.56 s and the past-due view from 1.2-2.2 s to 0.22 s,
  with output byte-identical. When many bills really owe money (20,000 corrected bills) it is unchanged at about
  3.5 s: the money sums still group whole tables, because payment_allocations, payment_reversals and
  coverage_allocations have no invoice_id index (a per-bill sum was tried and was far slower). An index migration
  would be the next step if a branch ever has thousands of bills owing at once.
  The "Owed on bills past due" total counts a whole bill once any part of it is past due, including pay later not yet
  due on that bill (counting only the past-due part needs a backend change; a follow-up for the owner).
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

- Monthly cash closing (BIL-7): an ended day with no count is flagged "No count" only from the branch's first ever
  count on; days before it show "Not in use yet" with no flag, and a month wholly before it shows only "Cash closing
  started on …". So a day skipped before the first count is never flagged (there is no configured go-live date). A
  missing, malformed, future or pre-2000 `?month=` shows the current month without a message. "Changed after
  approval" recomputes each approved day's expected cash (about three queries per approved day, about 93 for a
  fully approved month); not load-tested. Today's count is in the money totals but in no day counter until the day
  ends. Staff names follow the staff-directory rule ("Staff member" otherwise), as on the daily page.

- Report boundary (BIL-8): Transaction Detail now reads the payment methods twice in the same transaction (options,
  then rows); one small extra query. The frozen pre-BIL-8 copy and its comparison test were retired by BIL-10, as
  agreed; the privacy checks remain. The `BASELINE.md` note that the service "repeats the shared report boundary" is
  out of date (Billing does not edit `BASELINE.md`).
- Invoice and receipt layout (BIL-9): see `BIL-9-DESIGN.md` section 7. In short: a service category rename revises
  the next print of an invoice issued in the new layout; the category match is English and exact ("Procedure(s)" and "Investigation(s)", CAT-11's fixed names, included; any
  other or older free-text category counts as Service); the old live
  `/print` preview is retired (BIL-14: it redirects to Issued copies); copies issued before keep theirs; IC/passport and phone on paper are a PDPA
  point before real use.
- Refunds after completion (BIL-10): see `BIL-10-DESIGN.md` section 9 (Insights Provider table nets credits since BIL-12;
  one invoice per refunded receipt; the race test is BIL-13).
- Insights provider credits (BIL-12): when the Range provider list is cut at 100 doctors, a doctor below the cut who
  has a credit in the period is not added with only that credit (their sales are not shown either); the existing
  "truncated" note explains why the rows then do not add up to the headline. Below 100 doctors the rows always add up.
- Cash closing start date (BIL-15): no way back to "no date set" (owner decision). A request key reused on two
  different branches at the same moment can fail with a server error instead of a message (the branch check normally
  catches it first). A month before the start that holds counts still shows them in full.
  The "Start date changed" note stays until the next change (follow-up from Daniel: fade it to a neutral "Start date
  was moved on …" line after 30 days). The date field shows the browser's own date order (a
  clinic PC set to English (Malaysia) or English (UK) shows dd/mm/yyyy); the saved date is always shown as "4 Nov 2026".
- Receipt corrections after completion (BIL-16): a receipt that paid off pay-later keeps that part (the amount
  received cannot be below it, because an approved pay-later balance cannot grow back); a receipt with a refund, or
  that pays more than one invoice, cannot be corrected. The replacement receipt carries the correction date, so the
  original shows as reversed on its own day and the replacement on the day of the correction. Before completion
  the existing reversal still applies. Money left due after a correction is collected on the completed visit (paid before any
  pay-later) and shows in outstanding bills and, since BIL-23, on Bills to collect (Due now column; a bill with both
  notes that a payment clears the due-now money first, BIL-17); the "Use amount due" shortcut is offered. A refund after completion
  (BIL-10) can still be recorded on the replacement receipt while money is due; the amount due does not change. A
  correction refused for a changed branch or a reused request key can also show that message at the top of Refunds.
  Two corrections, or a correction and a refund, racing on one receipt run one after the other (race tests, BIL-20).

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
  relayed by B.E.R.N.I.S.S (formerly LUCY), the coordinator session, counts as the owner's own (decision 21); if a relayed message
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
7. **BIL-5 Issued receipt and invoice snapshots.** *(Built 2026-11-05; PR #270.)* At issue, keep an encrypted, immutable snapshot of what was
   printed (as the Medical Certificate does); reprints are marked COPY and show the snapshot, not live data. No
   change to amounts.
7b. **BIL-5b Recorded print on the patient Invoices page.** *(Built 2026-11-05; PR #286.)* Convert the invoice and receipt print links in the
   Documents list of `resources/js/pages/Visits/History.vue` to the BIL-5 recorded print (they open the PREVIEW
   page today). Billing's to do, from the latest `main`, now that UX-2 (#254) has merged (B.E.R.N.I.S.S,
   2026-11-05).
8. **BIL-7 Monthly cash closing view.** *(Built 2026-11-05/06; merged in PR #315.)* A read-only page per branch, one row per day: counted, expected,
   difference, counter, approver and status; flags days with no closing or no approval. Same permissions as cash
   closing, no export.
9. **BIL-8 Report boundary clean-up.** *(Built 2026-11-06; PR #338.)* Make `TransactionReportService` use `BranchReportService`, with output
   proven byte-identical; test-led, no behaviour change.
10. **BIL-9 Invoice and receipt in the clinic's layout.** *(Built 2026-11-06; PR #388.)* Owner request with the
   clinic's paper; decisions in `BIL-9-DESIGN.md`.
11. **BIL-10 Refunds after completion and partial refunds.** *(Built 2026-11-06/07; owner "start with 1", "ship it for
   BIL-10 for farah", relayed.)* A refund of part or all of a posted receipt after the visit is completed, with an
   invoice credit of the same amount, approved by a second person. Design note `BIL-10-DESIGN.md`.
