# Phase 3B — Completed Patient v1

Implementation working tree based on Phase 3A-Core (`6cb764f3f11edb5f73869cba6b1b6947ab19e3bc`). This is not a release or production approval. Yezza remains the operational source of truth. See `PHASE_3B_DESIGN.md` for the approved design.

## Operational path

Complete Consultation now records immutable, version-bound `ConsultationCheckout` evidence. An active medicine uses the existing atomic Dispensary handoff and records the **post-transition** Plan version. Return supersedes checkout; re-handoff creates new evidence. Every active Service Order requires an explicit attending-doctor performed quantity or not-performed disposition; an order alone is never billable.

Without active medicines, the attending doctor checks out directly to Billing. Queue becomes removed with `sent_to_billing`; no fake Case, Handoff, stock movement or Plan is created. A service-only Plan retains its clinical `in_progress` status, with checkout closure shown separately. Dedicated doctor reopen is available only before financial finalization or any payment/responsibility evidence. Draft invoices become stale; ordinary Queue removed-to-serving remains unavailable.

Awaiting Billing and Completed are structural Registration-board modes under existing branch scope. Complete Visitation requires current checkout/clinical source versions, completed physical fulfilment where applicable, an exact finalized Invoice revision, no correction hold and zero unapproved due now. It changes only Visit completion evidence/version and neutral audit. Queue remains removed. It never changes Allergy, Treatment Plan, Dispensary, balances or movements.

## Pricing and invoice truth

Commercial `ChargeDefinition` is separate from clinical catalogue and inventory SKU. `PriceBook` supplies organisation defaults or an explicit branch override. Immutable `PriceEntry` revisions contain effective MYR unit prices. Publication uses exact `prices.publish.organisation` authority, not `prices.manage.organisation`: the latter would be classified as staff-administration authority by released security rules.

All money is bounded integer sen (maximum 999,999,999,999 sen). Quantity uses exact thousandths. Each line rounds half up with integer arithmetic; no float money calculation. Missing or unit-mismatched prices fail closed; an explicit zero price is valid. No CA override, discount, tax engine, packages or inferred medicine/SKU equivalence.

One current non-void Invoice per Visit. Draft build is source-idempotent; an explicit supplied stale/future version is rejected. Changed sources/prices require an explicit reviewed rebuild. Finalization assigns an organisation-scoped transactional `KPI-########` number and freezes snapshots. A receipt has an independently sequenced `KPR-########` number. Numbers are not recycled.

Invoice lines are typed and unique by source: one consultation charge, positive completed actual medicine quantity per Dispensary item (not ordered quantity or one line per batch), and positive current performed ServiceDelivery. Returned attempts, zero/not-dispensed items and unperformed services do not generate charges. Selling prices cannot change historical finalized snapshots. Billing never deducts or reserves stock.

## Payments, responsibility and corrections

Payments are retained receipts fully allocated to one originating Invoice. Multiple governed methods/receipts are supported, with per-request idempotency, exact Invoice versions, required safe references, and overpayment rejection. No deposits or card credentials.

Financial reconciliation is `T = S + C + D + U`: total, valid net self-pay, accepted Panel responsibility, remaining independently approved deferment, unapproved due now. Panel is not Payment and registration coverage does not approve financial responsibility. Requests carry reasons, versions and necessary Panel/member or due-date evidence. Approval requires a different active authorized actor and an explicit branch/capability amount limit; absent limits fail closed. Panel must still be active at acceptance.

New receipts first cover due now, then reduce existing approved deferment. Future Visit views show at most ten authorized same-branch old debts; they are never copied into a new Invoice. Later collections remain allocated to the original Invoice and leave historical Visit completion evidence unchanged. Cross-branch collection is excluded.

Corrections are independently authorized **recording-error reversals**, not refunds. Receipt/allocation evidence remains. Reversal supersedes any old deferment so it cannot silently authorize increased debt. Full void/reissue is pre-completion only and requires no retained net payment/Panel/deferred obligation. Original numbers, lines and reversal/void provenance remain. The latest implementation request explicitly excludes post-completion accounting corrections, overriding the broader optional correction-hold discussion in the design; completed Visits are not ordinarily reopened.

## Authorization and privacy

| Role                               | Explicit Core access                                                                                                                                       |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CA                                 | Branch invoice build/finalize/view/print, receipts, responsibility requests, old debt view, Complete Visitation                                            |
| CA Supervisor                      | CA capabilities plus independent configured branch responsibility approval                                                                                 |
| Resident doctor                    | Own checkout, service confirmation and guarded no-medicine reopen; no cashier role                                                                         |
| Finance                            | Assigned-branch financial summary/receipts/approval/correction, organisation price publication; no medicine lines or operational completion by implication |
| Panel officer                      | Assigned-branch coverage summary/proposal/approval only                                                                                                    |
| Director                           | Explicit assigned-branch summary/responsibility oversight; no cashier/clinical grant by seniority                                                          |
| Technical Admin, HR, Marketing, BD | No Billing/Payment authority                                                                                                                               |

Requests reauthorize under actor/assignment/aggregate locks. Public route IDs are scoped again to Visit and branch. Navigation is only a hint. Finance-only projections omit medication line descriptions; operational boards contain no invoice amounts or clinical content. Private/no-store and encrypted Inertia history protections apply. Sensitive request roots are not flashed; general audit events carry only structural versions and neutral subject/actor projections, never amounts, Patient identity, medicine, invoice contents or payment/member references.

## Workspace and routes

The existing clinical/operational shell is retained. Billing uses a compact Patient/Visit/old-debt column, All/Items/Services/Documents centre, and financial summary/payment/responsibility/completion column. Dispensary completion offers Billing only to actors with the existing new Billing authority. No-medicine checkout uses the same Billing workspace without physical dispensing.

New route families are `POST /visits/{visit}/encounter/checkout`, dedicated checkout reopen, and `/visits/{visit}/billing` with explicit build/finalize/payment/responsibility/correction/completion subroutes (see `routes/web.php` for exact names). Invoice and receipt print routes are private GET views and non-mutating, separate from medicine labels. Completed views are read-only except authorized settlement of original old debt.

## Database and transaction protections

Three new additive migrations introduce checkout/service evidence, financial aggregates, then PostgreSQL reconciliation guards. Released migrations remain untouched. Tenant/branch composite FKs, source uniqueness, one-current guards, bounded money/quantity checks, typed line sources, approval shape, document identity, and immutable snapshots are protected. Deferred PostgreSQL checks reconcile Invoice lines and allocations/reversals/responsibilities at commit; parent Invoice anchors prevent child write skew. Completed evidence is checked at the transition and remains historical thereafter. Migration functions use replace-safe definitions; fresh teardown retains released trigger enforcement and deletes coherent synthetic aggregates in one transaction.

Lock order follows actor User → StaffProfile → roles/grants → assignment → Patient → Visit → Queue → Encounter → required clinical/Plan/order sources → Checkout/ServiceDelivery → Case/Handoff/items → prices → Invoice/lines → responsibility/debt → receipts/allocations/reversals → numbering → Audit. Read-only physical billing sources do not repeat Allergy validation or acquire inventory for financial writes. Payment-only operations never acquire clinical locks after financial locks. Approval configuration and Panel eligibility are acquired before Invoice locks.

## Concurrency proof and validation boundary

`PostgresBillingRegressionTest` / `PostgresBillingWorker` contain 18 grouped scenarios:

1. Two cashiers adding payment.
2. Duplicate Complete Visitation.
3. Payment versus completion revision.
4. Deferment approval versus payment.
5. Panel acceptance versus self-pay.
6. Duplicate invoice build/finalization, plus direct-SQL snapshot/quantity/ownership guards.
7. Dispensary completion versus invoice generation from actual quantity.
8. Exact payment permission loss.
9. Branch assignment expiry.
10. Visit state loss versus completion.
11. Last remaining amount and idempotent retry.
12. Late payment/completion audit rollback.
13. Price publication versus finalization.
14. No-medicine checkout versus clinical edit.
15. Reopen versus Billing source generation.
16. Receipt reversal versus completion.
17. Void/reissue versus payment.
18. Old debt settlement preserving history plus wrong-branch/cross-tenant/unrelated actor denial.

The existing independent PHP OS-process harness pattern uses separate connections, READY/GO, backend PID verification, observer `pg_blocking_pids()` and recursive blocker-chain proof at production lock surfaces. Required contention missing its checkpoint fails. Guards require an explicitly disposable PostgreSQL testing database. Local SQLite execution skips these groups; it does **not** prove PostgreSQL behavior. All 53 released and 17 Phase 3A groups remain required alongside these 18 in later exact-SHA PostgreSQL 18 CI.

## Configuration / rollout limits

Actual prices, accepted payment methods and approval limits require governed configuration; this implementation does not invent production values or add a broad price/configuration administration UI. Synthetic tests/bootstrap may establish references. Missing data fails closed.

The implementation requires explicit current checkout evidence as locked by the implementation request. A pre-3B handoff without it is not silently assigned a fabricated historical doctor attestation; legacy handoff onboarding remains a separately governed compatibility decision before any real-data rollout. No data migration/import was performed.

Browser acceptance and independent financial/security/concurrency review remain separate gates. Real-patient production approval is **not granted**. No deployment, Quick Treatment Sets, refunds, e-Invoice, general ledger, Procurement, Receiving, Stocktake, real prices/inventory/Drug Master import or OTC completion workflow is included.

## Implementation file inventory

The existing untracked `docs/PHASE_3B_DESIGN.md` was preserved, not authored or changed in this implementation. Generated assets and the isolated build helper remain ignored.

### Created

- `app/Domain/Access/BillingPermissions.php`
- `app/Domain/Access/TransactionalActorAuthority.php`
- `app/Domain/Clinical/Models/ConsultationCheckout.php`
- `app/Domain/Clinical/Models/ServiceDelivery.php`
- `app/Domain/Clinical/Services/CheckoutEvidenceService.php`
- `app/Domain/Clinical/Services/CheckoutReopenEligibility.php`
- `app/Domain/Clinical/Services/CompleteConsultationService.php`
- `app/Domain/Clinical/Services/ReopenConsultationCheckoutService.php`
- `app/Domain/Visit/Billing/Models/ChargeDefinition.php`
- `app/Domain/Visit/Billing/Models/CoverageAllocation.php`
- `app/Domain/Visit/Billing/Models/Invoice.php`
- `app/Domain/Visit/Billing/Models/InvoiceLine.php`
- `app/Domain/Visit/Billing/Models/PatientReceivable.php`
- `app/Domain/Visit/Billing/Models/Payment.php`
- `app/Domain/Visit/Billing/Models/PaymentAllocation.php`
- `app/Domain/Visit/Billing/Models/PaymentMethod.php`
- `app/Domain/Visit/Billing/Models/PaymentReversal.php`
- `app/Domain/Visit/Billing/Models/PriceBook.php`
- `app/Domain/Visit/Billing/Models/PriceEntry.php`
- `app/Domain/Visit/Billing/Models/RetainsResponsibility.php`
- `app/Domain/Visit/Billing/Services/BillingBuilderService.php`
- `app/Domain/Visit/Billing/Services/BillingContextService.php`
- `app/Domain/Visit/Billing/Services/BillingDirectoryService.php`
- `app/Domain/Visit/Billing/Services/BillingNumberGenerator.php`
- `app/Domain/Visit/Billing/Services/BillingSourceService.php`
- `app/Domain/Visit/Billing/Services/CompleteVisitationService.php`
- `app/Domain/Visit/Billing/Services/ExactMoney.php`
- `app/Domain/Visit/Billing/Services/FinancialLedger.php`
- `app/Domain/Visit/Billing/Services/InvoiceCorrectionService.php`
- `app/Domain/Visit/Billing/Services/PaymentService.php`
- `app/Domain/Visit/Billing/Services/PricePublicationService.php`
- `app/Domain/Visit/Billing/Services/PriceResolutionService.php`
- `app/Domain/Visit/Billing/Services/ResponsibilityService.php`
- `app/Http/Controllers/BillingController.php`
- `app/Http/Controllers/ConsultationCheckoutController.php`
- `database/migrations/2026_09_05_000100_create_consultation_checkout_evidence.php`
- `database/migrations/2026_09_05_000200_create_billing_foundation.php`
- `database/migrations/2026_09_05_000300_protect_billing_reconciliation.php`
- `docs/PHASE_3B.md`
- `resources/js/pages/Billing/Print.vue`
- `resources/js/pages/Billing/Show.vue`
- `resources/js/types/billing.ts`
- `tests/Feature/Billing/BillingBoundaryTest.php`
- `tests/Feature/Billing/BillingTestCase.php`
- `tests/Feature/Billing/CompletedPatientTest.php`
- `tests/Feature/Billing/FinancialSettlementTest.php`
- `tests/Feature/Billing/InvoiceFoundationTest.php`
- `tests/Feature/Billing/ServiceBillingTest.php`
- `tests/Feature/Clinical/ConsultationCheckoutTest.php`
- `tests/Feature/PostgresBillingRegressionTest.php`
- `tests/Frontend/billing-money.test.mjs`
- `tests/Support/PostgresBillingWorker.php`

### Modified

- `app/Domain/Access/PermissionCatalogue.php`
- `app/Domain/Clinical/Dispensary/Models/DispensaryItem.php`
- `app/Domain/Clinical/Dispensary/Models/DispensaryItemException.php`
- `app/Domain/Clinical/Dispensary/Services/DispensaryHandoffService.php`
- `app/Domain/Clinical/Dispensary/Services/DispensaryService.php`
- `app/Domain/Clinical/Services/TreatmentPlanDirectoryService.php`
- `app/Domain/Queue/Services/QueueDirectoryService.php`
- `app/Domain/Visit/Models/Visit.php`
- `app/Domain/Visit/Services/VisitDirectoryService.php`
- `app/Http/Controllers/AuditLogController.php`
- `app/Http/Controllers/DispensaryController.php`
- `app/Http/Requests/SearchVisitsRequest.php`
- `app/Http/Requests/SendTreatmentPlanToDispensaryRequest.php`
- `bootstrap/app.php`
- `resources/js/app.ts`
- `resources/js/components/patient-board/PatientBoard.vue`
- `resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue`
- `resources/js/pages/Clinical/Show.vue`
- `resources/js/pages/Dispensary/Completed.vue`
- `resources/js/pages/Registration/Index.vue`
- `resources/js/types/clinical.ts`
- `resources/js/types/patientBoard.ts`
- `resources/js/types/visit.ts`
- `routes/web.php`
- `tests/Feature/Clinical/TreatmentPlanTest.php`
- `tests/Feature/PostgresDispensaryInventoryRegressionTest.php`
- `tests/Feature/Queue/QueueAuthorizationTest.php`
- `tests/Feature/Visit/VisitAuthorizationTest.php`

Debt settlement allocations retain both the exact receivable reference and applied sen. The materialized remaining deferment must reconcile to these immutable receipts; it is not an independently editable balance.

## Local implementation validation

- Focused Phase 3B feature tests: **27 passed, 183 assertions**.
- Full Laravel suite: **468 discovered, 378 passed, 2,264 assertions, 90 skipped, zero failures/errors**. Skips are 88 PostgreSQL-only groups and two established public-registration tests (registration is disabled).
- Frontend unit tests: **6 passed** (exact money conversion and existing Treatment Plan composers).
- Pint, PHPStan/Larastan, TypeScript, ESLint, Prettier, production build and `git diff --check`: passed.
- Fresh migration plus `KPOneReferenceSeeder`: passed on isolated in-memory SQLite only.
- PostgreSQL Billing suite: **18 discovered, 18 skipped locally, zero executed**. No dynamic PostgreSQL PASS is claimed; the 53 + 17 + 18 groups require exact-candidate PostgreSQL 18 CI.
- No browser UAT or independent Phase 3B security/financial review was performed in this implementation run. These remain required before release consideration.
- Branch remains `main` at the released baseline. Nothing was staged, committed, pushed or tagged. No production/developer database, real Patient/financial data, production configuration or dependency upgrade was used.
