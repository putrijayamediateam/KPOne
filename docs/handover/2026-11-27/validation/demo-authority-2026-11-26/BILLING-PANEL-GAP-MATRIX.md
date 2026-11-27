# Billing, cash closing and Panel — full-demo parity audit

Audit date: 2026-11-26. Scope: effective HTML wrapper navigation and extracted demo assets versus current-main snapshot c938a9724a0de82d3bc08700442cffe7cff58f8c and `/workspace/KPOne-ca-integration`. This is a source/content audit, not a claim of complete visual or interaction acceptance. Earlier CL1 review covered bounded styling only.

Authority: `/workspace/attachments/e9bc43ff-8ddf-4760-a2e6-8ad93df3e127/KPOne-Clean-Look-Demo.html`; extracted files in `project/`; machine-readable tab/action/filter contracts in `billing-panel-demo-contract.json`. Demo role grants and local fake-state mutations are illustrative; approved server permissions, issued documents, monetary calculations, versions, locks and audit rules remain authoritative.

## Effective wrapper navigation

| Wrapper destination | Effective target | Implementation implication |
|---|---|---|
| CashClosing / ManagePayments / BillsToCollect / TerminalCheck / VisitHistory | Billing tabs close / receipts / collect / terminal / history | Legacy standalone HTML is not the primary navigation contract. Assemble actual route-backed views into the effective Billing workspace. |
| PanelClaims / ClaimsFollowUp / PanelPayments | Panel tabs sub / follow / pay | Evaluate the combined Panel board, not just individual old demo files. |
| BillingDetail | Reports, branch view, bd tab | This is a report, not patient billing. |
| PanelSummary / PanelInvoices | Reports ps / pi tabs | Keep reporting scope separate from operational Panel claims. |
| PatientBilling / InvoiceView / PanelPriceChanges | Independent screens | Match their own content and interactions as well as parent navigation. |

The wrapper removes CA/MA/CA Supervisor from Panel boards and directs share decisions through Billing. It also marks resident-doctor Billing/PatientBilling/Triage access as a demo proposal. Neither is permission approval. Generic navigation/action guards and fallback redirects must be considered; no specific Billing TAB_GUARD was found in the inspected explicit guard configuration.

## Gap matrix

P = presentation/composition using existing authorized behavior. R = additional scoped read projection/aggregate required; not automatically a new permission. D = business decision or conflict requiring explicit reconciliation before implementing.

| Effective demo surface | Actual route/data/action bindings | Main + CA gap | Classification and acceptance work |
|---|---|---|---|
| Billing nine-tab board: To pay, Panel/pay later, Bills to collect, Paid today, Cash closing, Cash closing month, Terminal check, Receipts/refunds, Invoice history | `/financial-work`, `/panel-responsibility` → BillingWorkController and BillingWorkDirectoryService; `/pay-later-collections`; existing cash, reconciliation, visit history/payment routes below | Both snapshots use separate pages rather than the effective nine-tab assembly. CA surface changes do not supply this composition. | P/R: scoped tab navigation, metrics, search/filter chips, row expansion and selected-row state, side accordions and related links. Bind real DTOs, pagination and permission-filtered actions; no fake cross-tab state transitions. |
| To pay / Paid today | FinancialWork/Index.vue and visit billing links; real invoice/payment records | No complete demo payable queue / paid-today board composition demonstrated. | R/P: establish existing queue scope and real paid-time/method/receipt projection. Respect branch/own visibility and server document routes. |
| Panel and pay later | Billing responsibility proposal/approve endpoints and `/panel-responsibility` | Existing actions available but split across workspace/directory. | P: carry action versions, approval separation and refusals into demo layout. D: do not infer broader share authority from demo roles. |
| Bills to collect | `/pay-later-collections` → Billing/PayLaterCollections.vue; outstanding.view.branch | Separate page; demo age/due filters, oldest-due and summary tools need reconciliation. | P/R: map real due dates/status and totals; retain genuine paging and scope. |
| Cash closing | `/cash-closings`, POST count, closing decision endpoints → Billing/CashClosings.vue | Existing daily count/history and authority rules; CA restyles header/controls only. Demo unified-tab table, expandable count form and explanatory side panels absent. | P: bind day.businessDate/today/status, expected.cashSen, receiptCount, refunds, closings, locked, changedSinceApproval and can.count/can.approve. Preserve cross-person, after-midnight and locked-day rules. D: demo approval copy omits approved BranchManager role in places; reconcile copy, do not revoke authority. |
| Cash closing month | `/cash-closings/month` → Billing/CashClosingMonth.vue | Same in main/CA; separate route, not tab. | P/R: month navigation, latest/approved count distinctions, status/flag filters and summaries against actual response. |
| Terminal check | `/payment-reconciliations` GET/POST → PaymentReconciliations/Index.vue | Same in main/CA; separate route rather than Billing tab. | P: method ledger/terminal/difference/status presentation and manual close action. Preserve comparison-only nature; never mutate payments to make a demo difference zero. |
| Receipts and refunds / invoice history | Billing/Payments.vue; payment reverse, refund-with-reversal, correct endpoints; visit history, issued invoice/receipt endpoints | Existing financial actions scattered across workspace/routes; complete board not demonstrated. | P/R: bind actual immutable issue/reversal history and permission-filtered action availability. D: any new refund/finalize shortcut requires explicit financial design. |
| PatientBilling | `/visits/{visit}/billing` → Billing/Show.vue + BillingWorkspace.vue; manage-payments variant; invoice activity GET routes/InvoiceActivityDrawer | Actual tabs all/items/services/documents differ from demo Items/Documents/Activity; no matching left payable queue, action drawer composition or complete method-pill layout. CA scripts preserve existing behavior; styling is not content parity. | P/R: reconstruct content and route bindings, patient header, item breakdown, due summary, activity and document tabs, adjustment/Bill-to/internal-note tools. Preserve current service/version/idempotency semantics. D: demo family marker is not FamilyBill authorization; instant draft payment/finalization semantics must not be invented. |
| InvoiceView | Server-issued invoice/receipt routes and print templates | Demo original/copy/document-switch/coverage expansion needs explicit screen-to-route comparison. | P: match safe print/view navigation and expandable payment/coverage details. Preserve server-issued snapshots and privacy; no client reconstruction of official monetary documents. |
| Panel six-tab board: To claim, Submitted, Follow up, Waiting for payment, Paid, Queried | `/panel-claims` → Index/Create/Show/FollowUp/Pack; `/panel-remittances`; `/panel-payment-corrections` | Both snapshots retain separate pages; no full combined board/sidebar/metrics composition. | P/R: real status filters and rows, tab navigation, summaries and related links. Preserve claim/payment permissions and server bounds. |
| To claim / Submitted / Queried | Existing claim create, answer, decision and pack services/routes | Demo ticked batch actions/prepare-missing/pack UI require precise existing-service mapping. | P when available: retain same-panel constraints and atomic behavior. Demo limits (20 claims, 50 pack) must be checked against backend; D if new behavior is needed. |
| Follow up | PanelClaims/FollowUp.vue and service | Separate page; demo examples conflict about Prepared versus submitted-only follow-up. | P/R: use authoritative real overdue/submitted semantics; document demo inconsistency instead of silently widening scope. |
| Waiting for payment / Paid | PanelRemittances/Index.vue; statement upload/delete; correction page | Demo payment splitter, optional CSV matching, totals and status grouping need content comparison to existing functionality. | P: bind real split/owed validation, versions, matching and correction routes. Demo CSV limits/oldest-200 rules are not authorization for new service rules; verify before implementation. |
| PanelPriceChanges | `/panel-price-changes` → PanelPriceChanges/Index.vue, proposal/approve/reject/withdraw routes | Actual Propose/Waiting/Decided differs from demo Waiting/Approved/Rejected-or-withdrawn. CA includes approved PNL30 bulk feature. | P: reconcile tabs, summaries and actions while retaining approved bulk selection, partial skip feedback and current permissions. Do not remove accepted functionality merely because demo omits it. |
| Panel/Billing reports | `/panel-invoices` + CSV; `/reports/billing-detail`; actual report controllers | Operational boards must not replace or accidentally expose reports through similarly named wrapper links. | P/R: confirm report navigation, filter and export bindings under existing report permissions. |

## Shared acceptance requirements

- Match meaningful content, ordering, layout and interactions, not palette alone: headers, tab labels/counts, metric cards, filters, row details, forms, empty/error/loading/refusal states, side accordions and related links.
- Reuse actual action availability, fresh authority, versions/idempotency, financial locks, audit and immutable documents. Demo optimistic toast/local-array mutation is not a transaction specification.
- Test light/dark at 390, 768 and 1440, keyboard/focus, control target sizes, overflow, tab/filter preservation and actual action results using synthetic records. No such comprehensive pass is claimed here.
- Main and CA were identical for CashClosingMonth, PayLaterCollections, PaymentReconciliations, PanelClaims Index/FollowUp, PanelRemittances and FinancialWork at inspection. CA differences in BillingWorkspace/CashClosings are CL1 presentation changes; PanelPriceChanges contains PNL30. Neither snapshot is full-demo complete.
- Next implementation should freeze a route-by-route content contract and scoped read DTO list, then implement presentation slices. Separate proposed new financial rules/visibility from missing UI; obtain decisions only for the former.

## Existing publication separation

PNL30's nine synthetic screenshots establish bounded bulk-approval presentation/partial-skip correctness, not full Panel parity. BIL36/37 are authority/readonly features; their publication gates do not certify the new full-demo UI contract. Existing original-slice normal-main refreshes and CI continue separately from this audit.

## Latest-main reconciliation

After the initial audit, remote main advanced to c1e5fc05c0d231a28eeb3f2367ccc8adee0565c5. Fetched and inspected the complete c938→c1e5 diff: exactly the ten PNL30 files (bulk service/controller/page, route, new tests and docs). Approved PNL30 is now in main as well as CA; the overall composition gaps above are unchanged. No Billing/daily-close/full-Panel restyle was introduced by this advancement.
