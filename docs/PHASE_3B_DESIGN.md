# Phase 3B design lock — Completed Patient v1

Status: architecture recommendation for implementation authorization; no Phase 3B runtime is implemented by this document.

Reviewed on 2026-09-03 against `main`, `origin/main`, and `phase-3a-core`, all resolving locally to `6cb764f3f11edb5f73869cba6b1b6947ab19e3bc`. The initial working tree was clean. This document is the only intended repository change. Remote refs were not refreshed in this design-only run.

## 1. Objective and existing boundaries

Deliver **Completed Patient v1** using synthetic data: Registration → Waiting → Serving → Complete Consultation → Dispensary where medicine is ordered → Billing/payment or governed receivable → Complete Visitation → Completed.

Retain the owner's compact Patient workspace mental model: Patient/Visit context on the left, items/services/documents in the centre, finance summary/actions on the right. Do not require a separate Billing-module detour for a CA. This is familiarity with the supplied clinic workflow, not a claim to have independently inspected or copied Yezza.

Released-source findings:

- `Visit` and its PostgreSQL status constraint currently permit only `registered` and `cancelled`. Completion is a real Phase 3B extension, not an existing flag to activate.
- `ClinicalEncounter` currently has only `in_progress`. Phase 3B operational checkout must not pretend to implement legal clinical signing/finalization.
- `TreatmentPlan` has `in_progress` and `ready_for_dispensing`. Sending records the **post-transition** version; returning alone changes a handed-off Plan back to `in_progress`.
- `DispensaryHandoffService::send()` requires active medicine. It cannot currently complete a no-medicine consultation.
- Dispensary completion is the authoritative physical-fulfilment transaction. Completed items have actual quantities; allocation-linked movements record stock deduction. Returned attempts are not billable fulfilment.
- Service Orders have active/withdrawn intent, but no performed evidence. Active intent alone is insufficient proof of delivery.
- Registration coverage is `self_pay` or `panel`, with Panel identity/name snapshot and optional member reference. It does not establish an approved financial allocation.
- Visit editing/cancellation stops after Call In; Queue remains waiting/serving/removed. Completed is currently a planned zero-state in Registration.
- There is no released Billing, Payment, commercial pricing or financial permission catalogue. Finance/Panel roles currently receive general organisation permissions, not financial authority.
- Visit numbering is transactional, organisation-scoped `KPV-%08d`; Patient numbering uses `KP-`. Preserve this pattern rather than reusing Visit numbers as invoice identifiers.

Inspection covered the Visit model/migration/numbering/administration/directory, Queue projection and constraints, current-care/Encounter services, Plan and Service Order models, handoff/completion/directory services, access catalogue, Registration/PatientBoard presentation, routes, Phase 3A/security documentation, Treatment Plan/Inventory and PostgreSQL regression test contracts, and the established PostgreSQL CI workflow. This is targeted architecture analysis, not a new exhaustive security scan.

## 2. Scope decisions

### Core

- Explicit doctor checkout for medicine, service-only and consultation-only Visits.
- Minimal service-performance confirmation; no automatic billing of unperformed services.
- Governed commercial charge definitions and versioned prices, independent of clinical and stock identities.
- Draft/finalized invoice snapshots, deterministic money calculations and numbering.
- Multiple self-pay payments, split Panel responsibility, approved patient outstanding and later settlement of the originating invoice.
- Complete Visitation, Completed board, invoice and receipt printing.
- Minimal governed correction/reversal evidence, strict authorization, privacy and PostgreSQL concurrency/constraint proof.

### Deferred

Quick Treatment Sets; OTC completion; procurement/receiving/stocktake/supplier workflows; real catalogue/import work; discounts/packages/promotions; refunds and partial credit notes; prepayments/wallets/overpayment; multiple currencies; Panel Claims submission, adjudication and remittance processing; GL/accounting exports and bank/cash reconciliation; e-Invoice integration; clinical signing/addenda; automatic debt collection; production deployment/cutover.

No stock transaction belongs to Billing. No Billing command may call inventory debit/transfer/opening-balance APIs. Existing physical fulfilment is not reopened because an invoice or payment needs correction.

## 3. Operational checkout and no-medicine decision

Introduce a small `ConsultationCheckout` evidence record, not a new Encounter/Plan status. It records organisation/branch/Visit/Encounter, immutable attending doctor, checkout time, exact Encounter version, nullable Plan identity/version, route (`dispensary` or `billing`), optional handoff identity, and current/superseded evidence. One current checkout per Visit; earlier attempts remain retained. It contains no duplicated clinical note or Allergy content.

For medicine, the existing handoff transaction remains authoritative. Checkout evidence is recorded in the **same transaction**, referencing the new handoff and post-transition Plan version. Return to Doctor supersedes that checkout atomically; re-send creates new evidence. Existing completed pre-3B handoffs can serve as the legacy checkout source, identified explicitly, without fabricating historical doctor attestations or rewriting released records.

For zero active medicines, `CompleteConsultationService` verifies immutable attending resident-doctor authority, exact Visit/Queue/Encounter and nullable Plan version, current Serving consultation, and no open/completed physical handoff inconsistent with bypass. It records explicit no-medicine checkout and moves Queue serving → removed with a distinct structural `consultation_completed` reason. It creates **no Case, Handoff, stock movement or dummy Medicine Order**. A Plan need not be created for consultation-only care; an existing service-only Plan remains `in_progress` as a clinical-plan state. Its ordinary mutation still requires Serving current care. UI additionally says consultation closed; it must not display “Sent to Dispensary” for bypass.

All relevant current-care writes must respect checkout/Queue/Visit closure. Doctor checkout is an operational declaration, not legal signing. No new Encounter status is needed in Core.

Allow one narrowly defined no-medicine correction route: the attending doctor, with explicit reopen authority, may supersede a bypass checkout and restore Serving **only before invoice finalization, payments, financial allocations or Visit completion**, with exact versions. This is not generic removed → serving and cannot operate on a medicine handoff. A draft is marked stale atomically. After financial finalization, no clinical reopening in Core. Existing Dispensary Return remains the only route for a medicine handoff.

| Visit content                   | Doctor checkout                              | Dispensary                                                         | Invoice source                                                                      | Visit completion                                     |
| ------------------------------- | -------------------------------------------- | ------------------------------------------------------------------ | ----------------------------------------------------------------------------------- | ---------------------------------------------------- |
| Consultation + active medicines | Existing handoff plus checkout evidence      | Required, even if all medicine is later acknowledged not-dispensed | Current completed handoff actuals + confirmed services + consultation charge        | Physical case complete and financial gates satisfied |
| Consultation + services only    | Explicit no-medicine checkout                | Bypass                                                             | Confirmed performed services + consultation charge                                  | Checkout and financial gates satisfied               |
| Consultation only               | Explicit no-medicine checkout; Plan nullable | Bypass                                                             | Governed consultation charge                                                        | Checkout and financial gates satisfied               |
| OTC                             | No new completion authority in Core          | No invented clinical/stock bypass                                  | Invoice schema does not require Encounter, but Core builder rejects unsupported OTC | Explicitly deferred                                  |

If an old handoff was returned and the doctor withdraws all medicines, bypass may proceed only with all attempts terminal-returned and **no committed dispense movement**. It must reference the current Plan; it must not reuse the returned attempt's quantities.

## 4. Authoritative billable sources

### Medicine

Bill only `quantity_dispensed > 0` from the current **completed** handoff of the completed Case. Use its immutable medicine identity/display/unit snapshots and actual quantity. Exclude pending, withdrawn, zero/not-dispensed, superseded and returned-attempt items. A partial 10 of ordered 20 bills 10, not 20. Multiple batch allocations for the same item do not become duplicate invoice lines.

`BillableFulfilmentProjection` is a server-internal read-only contract: tenant/branch/Visit, source Case/Handoff/Item identifiers and versions, source identity/snapshots, exact actual quantity/unit, completed time and reconciliation evidence. The customer-facing DTO excludes internal IDs, batches, stock balances, Allergy data, dosage, notes and diagnoses. Billing reads under the established clinical/Case ordering; it does not acquire inventory balances for ordinary financial writes.

Do not re-run current Allergy validation to collect money for already committed historical fulfilment. Allergy validation belongs to the physical transaction. A later Allergy change neither changes the billable actual nor permits new stock movement. The builder verifies the completed source and handoff/Plan correspondence, not a newly invented clinical gate.

### Services

**Decision: active order plus explicit performance evidence**, not active order alone. Add minimal `ServiceDelivery` evidence under Clinical, tied to exact Service Order identity/content and the checkout snapshot. The attending doctor confirms performed quantity and date, or explicitly not-performed (zero). CA cannot attest clinical delivery or alter the order. Quantity must be nonnegative and no greater than ordered; only positive confirmed quantity is billable. Every active service must have a disposition before checkout/finalization; there is no silent default to performed.

The doctor can confirm services compactly during Complete Consultation, without choosing a price. A service change or Return/re-checkout requires current confirmation; stale evidence is retained but cannot authorize a new bill. Bind evidence to the checkout's final Plan version, including the send increment, not an obsolete pre-send version. When order contents genuinely did not change, reconfirmation may reference prior evidence explicitly rather than pretend the service was performed twice. One logical delivered service cannot be billed twice across attempts.

### Consultation fee

One configured commercial consultation charge per checkout/Visit, quantity one. Do not insert a fake Service Order. Do not also map a clinical service to that same consultation source. Missing price fails clearly; an explicitly governed zero price is permitted and remains auditable. No blanket zero-price fallback.

## 5. Commercial identity and pricing

Use `ChargeDefinition` (medicine, service, consultation) plus `PriceBook` and immutable/versioned `PriceEntry`. This is a small price list, not a rules engine.

Clinical catalogue identity → explicit charge mapping → commercial price entry.

Physical Inventory SKU → fulfilment evidence only. SKU acquisition cost, batch cost and supplier price do not select the selling price. Different permitted SKUs fulfilling the same mapped medicine use the same governed charge unless a separately approved commercial rule is introduced later. Billing never asserts SKU interchangeability or broadens the existing clinical mapping.

Core: one MYR organisation default book, optional explicit branch book, one applicable entry per charge at a given effective instant. Branch entry takes precedence; organisation fallback is explicit and visible. No Panel-specific tier engine, manual CA price override or browser-supplied unit price. Prices use the charge's exact clinical selling unit; no implicit pack/tablet/mL conversion. A unit mismatch blocks pricing.

Draft generation displays chosen book/entry/version. Finalization uses a server-recorded pricing instant and re-resolves effective entries; a change since preview fails stale and requires review. After finalization, price/name changes cannot change an invoice. Published price rows are retained; new revisions supersede rather than overwrite history. Finance publishes prices with explicit organisation authority; Director approval can be part of governance without conferring clinical detail access.

## 6. Minimal financial entities

Place the financial subdomain under `app/Domain/Visit/Billing/{Models,Services,Policies}` to fit the existing allowed domain roots. Keep clinical checkout and service confirmation in Clinical; `CompleteVisitationService` is a Visit-level coordinator. No new framework, broker or microservice.

| Entity                                    | Purpose / essential relationships                                                                                                                                                                                                             |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Invoice                                   | Internal ID + public UUID; tenant, branch, Patient, Visit; nullable final invoice number; lifecycle, currency, subtotal/total, exact lock version, source manifest/version, created/finalized/void provenance, optional replaces-invoice link |
| InvoiceLine                               | Invoice/tenant/branch; typed source FK; ChargeDefinition and immutable PriceEntry reference; display/code/unit snapshot; exact billable quantity, unit price, rounded line total; immutable after finalization                                |
| PaymentMethod                             | Governed active method code/name and reference requirement; initial cash/card/QR-transfer options, no executable gateway configuration                                                                                                        |
| Payment                                   | Public ID/receipt number, tenant/branch/Patient, exact amount/currency, method snapshot, occurred/recorded times, actor, restricted reference, idempotency identity; retained posted/reversed evidence                                        |
| PaymentAllocation                         | Payment → original Invoice, amount; one allocation per Payment in Core, extensible to several later; same Patient/tenant/branch and currency                                                                                                  |
| CoverageAllocation                        | Invoice → Panel, accepted responsibility amount, verification/evidence/provenance/version and member reference where needed; this row is the Panel receivable source, not a Payment                                                           |
| PatientReceivable                         | Invoice/Patient/Visit, approved deferred amount and remaining amount projection, reason/due date, approving actor/time, approval version, settlement linkage; one current deferment per invoice                                               |
| FinancialReversal / InvoiceCorrection     | Append-only reference to original record, exact value/versions, reason and independent approver; not a generic amount editor                                                                                                                  |
| PriceBook / PriceEntry / ChargeDefinition | Governed selling-price authority and source mappings, separate from stock and prescribing                                                                                                                                                     |
| ConsultationCheckout / ServiceDelivery    | Minimal authoritative checkout and performed-service evidence, not financial or stock transactions                                                                                                                                            |

`paid_amount`, `outstanding_amount` and receivable balances are **derived** from valid immutable allocations/reversals. They may be transactionally materialized for reads but are never public editable attributes. A separate PanelReceivable table is unnecessary in Core: accepted CoverageAllocation plus remaining-balance projection supplies that subledger responsibility.

Invoice cardinality: **one current non-void Invoice per Visit**, not one invoice ever. Enforce a unique active-Visit guard. Governed full void/reissue must retain the original invoice and number; a replacement references it. This avoids an absolute unique Visit FK that makes safe correction impossible. Cross-invoice payment splitting is deferred; multiple payment records can settle one invoice.

## 7. Invoice lifecycle, numbering and money

Persist only `draft → finalized → voided`; draft may also be abandoned/voided. No payments or accepted debt on a draft. Finalized line/source/price snapshots are immutable immediately, not only after Visit completion. Exact versions apply to all financial mutations; no automatic stale merge.

Settlement is a separate derived display: unpaid, partially paid, paid; additionally show Panel receivable and patient deferred/due-now balances. “Approved for checkout” is not “paid”. A zero-total governed invoice is settled with no zero-valued Payment record.

Recommend `KPI-%08d` for final invoice numbers and `KPR-%08d` for receipt numbers, using organisation-scoped locked counters consistent with `KPV-`. Drafts use UUIDs, not provisional invoice numbers. Allocate numbers in the finalization/payment transaction; retain voided numbers; never recycle a committed number. Resolve public routes with organisation authorization; sequential numbers are not secrets. Separate document types/prefixes avoid collisions. Future legal numbering rules remain a production decision.

**Money: BIGINT integer sen, MYR only.** No floats, JavaScript floating calculations, PostgreSQL `money`, or implicit PHP decimal-string arithmetic. Price per selling unit is integer sen in Core; sub-sen unit pricing is deferred. Existing clinical quantity is a validated decimal string with at most three places; convert losslessly to integer thousandths for calculation.

For nonnegative quantities: `line_total_sen = intdiv(quantity_milli * unit_price_sen + 500, 1000)` (half-up per line). Sum the rounded lines for subtotal/total. Bound operands and invoice totals before multiplication/addition to prevent PHP overflow; maximum accepted amounts must fit both server integer operations and the chosen JSON contract. Send amounts/quantities as canonical strings to Vue and display locally without treating client totals as authority. Reject exponent notation, nonfinite values, excess precision, negatives, overflow and locale-ambiguous input; do not silently round invalid submitted amounts.

No discounts, tax engine or cash-rounding adjustment in Core. Synthetic prices use exact payable sen; do not present this as a production tax/cash-rounding policy. PostgreSQL exact integer/NUMERIC semantics support the approach; floating types are inexact: [PostgreSQL 18 numeric types](https://www.postgresql.org/docs/18/datatype-numeric.html).

## 8. Payments, Panel responsibility and outstanding

Payments record money actually received/confirmed through an approved method, not a browser's success assertion or a Panel promise. No card numbers, CVV, credentials, terminal secrets or bank API integration. Restricted bounded references are never flashed/logged/general-audited. A configurable method catalogue can require a reference without introducing payment processing.

Accept multiple payments such as cash RM20 + QR RM20 against one invoice. Core allocates each payment fully to one invoice; no unallocated wallet balance, overpayment or change ledger. For cash, record the retained amount, not the note tendered; any change display is non-ledger arithmetic. Unique request idempotency plus canonical payload digest prevents retries duplicating money; replay with different payload fails. Idempotency lookup is still authorized. A posted payment and its allocation/audit commit together.

Panel is **approved responsibility/receivable**, not money received. Registration Panel selection is only a suggestion. Core uses one Panel per invoice (enough for the confirmed Panel + self-pay split), same organisation, active at acceptance, snapshot of Panel identity and restricted member reference. CA can propose coverage; only a separate exact approval permission can accept amount and verification evidence. “Verified” means evidence reviewed under clinic policy, not a claim that a live Panel API approved it. Unverified coverage cannot clear checkout. Keep original evidence and later correction history.

Define, in sen:

- `T`: final invoice total.
- `S`: net valid self-pay payment allocations.
- `C`: accepted Panel responsibility (unpaid in Core; future remittances reduce its receivable, not its original responsibility).
- `D`: remaining specifically approved patient deferment.
- `U = T - S - C - D`: unapproved amount due now.

Core invariants: `T,S,C,D,U >= 0`; `S + C <= T`; `D <= T - S - C`; `T = S + C + D + U`. Total money outstanding is `T - S = C + D + U`, **not** `U`. Do not sum allocation and receivable representations of the same amount twice. Panel-only invoice can have `U=0` and `paid=0`.

Example: RM448 total, accepted Panel RM408, self-pay receipt RM40 → due now zero, patient outstanding zero, Panel receivable RM408, actual money received RM40. This permits operational completion without falsely calling RM448 paid.

Pay-later is Core: CA requests it; CA Supervisor may approve at the currently authorized branch. Finance and Director can receive explicit organisation approval authority. Require a reason, due date, exact invoice/responsibility version, approving active staff and a governed amount limit. Fail closed if a required authority limit is not configured. Proposer cannot approve their own request; ordinary CA cannot create approved debt. No clinician is made cashier or debt approver by seniority.

Future payment settles the **old invoice** and reduces that invoice's PatientReceivable atomically. No new charge or automatic consolidation into a future Visit. Core later-settlement entry is restricted to the original invoice branch; cross-branch cash collection is deferred. An explicitly authorized organisation finance view can route work to that branch without transferring ownership. Future Visit UI shows a bounded amount/date summary to authorized finance/CA actors only; restricted cross-branch rows are not exposed through patient search.

When a new payment arrives before checkout, apply it to unapproved patient due first, then reduce the remaining approved patient deferment; never consume Panel responsibility as self-pay accidentally. If allocation intent differs, require explicit reviewed correction rather than an implicit reshuffle. After checkout U is zero, so later patient receipts reduce the original deferred balance. An exact-version approval racing a receipt must reload/reapprove the actual remainder.

An approved deferment applies only to its approved financial revision. Reversing a payment must not silently expand an earlier deferment authorization. New debt needs fresh approval. Panel reductions/reversals similarly cannot silently transfer liability to the Patient or mark cash refunded; those require governed correction evidence.

## 9. Complete Visitation and financial correction

Complete Visitation locks/revalidates:

1. Active eligible actor, exact `visits.complete.branch`, effective branch assignment and current expected branch.
2. Same-tenant/current-branch registered consultation Visit, immutable Patient link and exact Visit version; not cancelled/completed.
3. Current doctor checkout evidence; matching Encounter/Plan source versions; no active Serving/Waiting care or pending Return/clinical amendment.
4. For medicine path: the current Case and handoff are completed and correspond to the checkout/Plan. All actuals and source reconciliation are final, even when actual is zero. No open attempt remains.
5. For no-medicine path: explicit bypass evidence and no conflicting open/dispensed handoff. All active services have current performed/not-performed disposition.
6. Exactly one current finalized invoice, matching authoritative sources and versions, valid prices/currency, no unresolved correction hold.
7. `U=0`: fully received self-pay, accepted Panel receivable, approved patient deferment, or a reconciled combination. Positive unapproved remainder blocks completion.

Then atomically set Visit `completed`, completed time/actor, increment Visit version, retain exact completion invoice/version and settlement disposition evidence, and write neutral audit. Queue stays removed; Plan/Encounter state, Allergy versions and stock are untouched. A later payment changes receivable state, not the completed Visit history.

Add `completed_at`, `completed_by_user_id` and completion financial provenance through **new** migrations. Extend Visit status/cancellation/completion field constraints together: registered has neither terminal timestamp, cancelled has cancellation evidence only, completed has completion evidence only. Terminal Visit identity/details are immutable; ordinary edit/cancel routes reject completed Visits. Retain existing Called-In cancellation restrictions.

### Minimal correction required now

- Unsaved browser payment entry: discard locally. Draft invoice: explicit versioned rebuild, no committed payment deletion.
- Mistaken recorded payment: independently approved append-only reversal of the original receipt/allocation, with reason/evidence and one reversal per original. This corrects a recording error, **not a real refund**. If real money was received and must be returned, Core refuses to fake a reversal/refund and flags a governed unresolved correction.
- Wrong finalized invoice: full void/reissue only by exact correction authority, before Visit completion and after all posted payment/coverage/deferment records have been validly unwound. Reject if genuine money remains held and refund support is needed. Preserve original number/snapshots; create a new numbered replacement; no original-source deletion or stock movement.
- After Visit completion: no ordinary invoice void/reissue or clinical reopening. Finance may record a correction case/hold and a proven erroneous-payment reversal; any resulting receivable discrepancy is explicit and cannot reuse old debt approval. Completed Visit evidence remains historical. Full post-completion credit/refund correction is deferred; unresolved cases must remain visible, not silently “paid”.

These limits are acceptable for synthetic Completed Patient v1, not sufficient production accounting/returns policy. The UI must explain unsupported corrections rather than offer a destructive workaround.

## 10. Authorization defaults

All grants below are **new explicit proposed permissions**, not inherited from current role hierarchy. Branch mutations require actual effective assignment and expected branch, including for organisation-level finance approvers. Organisation read/approval variants do not grant clinical or stock access.

| Role                | Billing authority proposed for Core                                                                                                                                                                           |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CA                  | Branch operational invoice view/build/finalize, add patient payment, view bounded patient due, propose Panel/deferment, print, Complete Visitation; no approval/price override/void/reversal                  |
| CA Supervisor       | CA operations plus explicit branch Panel/deferment approval within configured limits; independent approval rule; no organisation price publishing by default                                                  |
| Resident doctor     | Own checkout/reopen-before-finalization and service confirmation only; no finance/payment/debt privileges                                                                                                     |
| Finance officer     | Explicit organisation financial summary, price publishing, Panel/deferment approval and financial corrections; branch payment settlement if explicitly granted and assigned; no clinical note/stock authority |
| Panel officer       | Assigned-branch Panel proposal/verification/approval and narrow coverage summaries; no cashier/deferment/Visit completion or automatic medicine detail                                                        |
| Director-only       | Explicit financial summary/governance and deferment approval; no cashier/clinical detail by implication; exceptional correction approval only with exact grant                                                |
| Technical Admin     | No financial, Patient billing, checkout or clinical authority                                                                                                                                                 |
| HR / Marketing / BD | None                                                                                                                                                                                                          |

Permission vocabulary:

- `billing.view.branch` (minimum itemized cashier invoice), `billing.build.branch`, `billing.finalize.branch`, `billing.print.branch`.
- `billing.summary.view.organisation` (financial summary, not item detail); `billing.detail.view.organisation` only by separately justified grant, not a default Finance/Director grant.
- `payments.add.branch`, `payments.reverse.organisation`, `invoices.void.organisation`.
- `coverage.propose.branch`, `coverage.approve.branch`, `coverage.approve.organisation`.
- `outstanding.view.branch`, `outstanding.view.organisation`, `outstanding.request.branch`, `outstanding.approve.branch`, `outstanding.approve.organisation`.
- `prices.manage.organisation`, `visits.complete.branch`.
- `consultations.complete.own`, `consultations.reopen.own`, `services.confirm.own` require explicit resident-doctor role and immutable ownership as well as permission.

Do not use ambiguous `invoice.complete` for Visit completion. Existing handoff permission remains enforced on the medicine route. Existing Dispensary-only/clinical routes are not broadened to financial roles. Multi-role unions never bypass scope, ownership, exact permission or self-approval rules. All direct requests reauthorize inside mutation transactions; UI hints confer no authority.

## 11. Transactions and canonical lock order

Canonical relative order (only lock applicable resources):

`Actor User → StaffProfile → roles/permissions → assignments → Patient → Visit(s) by ID → Queue → Encounter → Allergy Profile/rows/review → TreatmentPlan → Medicine/Service Orders by ID → ServiceDelivery/Checkout evidence → Dispensary Case → Handoff → Items/Exceptions/allocations → inventory Locations → SKUs/mappings → Batches → Balances → existing StockMovements → commercial definitions/price-book anchors/entries → Invoice(s) by ID → InvoiceLines → CoverageAllocations → PatientReceivables → Payment(s)/allocations/reversals → document counters/idempotency records → Audit`.

Checkout insertion may reference an already locked handoff; lock the Visit parent first as its stable creation mutex and never acquire a new earlier-row lock late. Multiple existing rows always lock deterministically. Actor security mutation/revocation must share the released serialization anchor; merely loading cached permissions is not permission-loss proof. Historical actor/approver FKs are not invitations to lock another actor late.

Billing skips inventory locks/mutations because completed fulfilment is immutable. Payment-only commands start with actor/Patient/Visit locks and then Invoice/finance locks; they never call back into clinical services after locking Invoice. Clinical completion can append evidence and audit without taking finance locks. Bypass reopen may inspect/invalidate draft finance only after its clinical locks. Price publication takes actor + commercial locks and never subsequently locks clinical or invoices. No Inventory → Clinical or Billing → Clinical reverse acquisition.

Atomic boundaries:

- **Build/refresh draft:** authorize, lock source roots, validate current readiness, resolve price snapshot, create/upsert exact typed source lines and source manifest, increment once, audit. Repetition with same inputs is idempotent, not duplicate generation. A GET never builds an invoice.
- **Finalize:** revalidate all source/price/version evidence, totals and service confirmation, allocate invoice number, freeze lines, audit in one transaction. No payment accepted before this.
- **Add Payment:** lock remaining balance and current responsibility/deferment, validate positive amount/method/version/idempotency and no overpayment, insert Payment/allocation, adjust derived patient receivable, insert audit. All or nothing. No remote terminal calls inside locks.
- **Accept Panel / approve outstanding:** serialize on Invoice, verify exact remaining amount/evidence/approver limits and separation of duties, append responsibility/approval evidence, reconcile and audit atomically.
- **Complete Visitation:** all preconditions plus Visit completion evidence and audit in one transaction. The dedicated Add Payment button is a separately committed real receipt and may remain if a later separate completion attempt fails; show “payment recorded, Visit not completed”. If a combined “Pay and complete” command is introduced, it must call shared locked operations in **one outer transaction**, not two HTTP requests; required audit/completion failure rolls the entire combined command back. Core can keep Add Payment and Complete Visitation separate and explicit.

Use transaction retries only for retryable DB deadlocks/serialization failures, not arbitrary financial validation errors. Re-authorize/re-read on retry. Do not perform printer/network side effects before commit. No auto-merge of stale operator edits.

## 12. PostgreSQL constraints and migration plan

Use new additive Phase 3B migrations; never edit released Phase 0–3A migrations. Replace the existing Visit status and terminal-field checks deliberately to admit completed. Down/refresh behavior must not silently discard completed financial data; rollback with live Phase 3B rows is refused, while fresh disposable recreation is tested.

- Composite tenant/branch/Patient/Visit ownership FKs across Invoice and financial children; same-currency payment allocation; typed nullable source FKs with exactly-one-source checks instead of an unconstrained polymorphic numeric ID.
- One current non-void invoice per Visit; retained voided predecessors; no replacement cycle/cross-Visit replacement. One current checkout and service disposition per source version.
- Unique source line per invoice: completed DispensaryItem, ServiceDelivery or consultation checkout. Prevent duplicate active billing for the same logical service/order through source identity plus invoice replacement constraints.
- Quantities positive/exact on lines; zero/not-dispensed not charged. Monetary BIGINT amounts bounded/nonnegative; Payment > 0; source quantity and deterministic line rounding reconcile.
- Invoice subtotal = sum lines; total = subtotal in Core; paid/responsibility/deferment/due formulas above; no overpayment or unallocated posted Payment; reversal cannot exceed or duplicate original.
- Immutable numbered/finalized snapshots and posted payment core fields; correction inserts evidence rather than changing amounts. Version/lifecycle and provenance pairs checked.
- Deferred aggregate constraint triggers validate multi-row invoice/payment/coverage/receivable/completion consistency at COMMIT. Lock the parent Invoice in child-mutating paths/trigger strategy so independent insertions cannot bypass aggregate checks by write skew. Cross-table sums are not ordinary PostgreSQL CHECK constraints.
- Visit completion requires a current finalized invoice and zero unapproved due at that completion revision. Later legitimate receipt/reversal changes do not rewrite historical completion evidence; a correction hold makes later discrepancies visible.
- Test direct SQL ownership violations and committed invalid aggregates, not only application validation. Test fresh install, repeated migrate:fresh and deferred trigger execution with no stale function collisions. Do not disable existing Phase 3A triggers.

SQLite feature tests supplement these rules; only real disposable PostgreSQL tests prove its constraints/concurrency. No schema is created in this design run.

## 13. Workspace, routes and projections

Keep existing ClinicLayout, top navigation, filters/polling safeguards and Clinical 65/35 layout. Extend the Patient workspace presentation with separate authorized panels, not a union raw-model payload:

- Left: minimum Patient/Visit/coverage and authorized outstanding summary.
- Centre: Items, Services, Documents; fulfilled item snapshots read-only in Billing. Clinical note, diagnoses, Allergy and batch/stock fields are absent from financial DTOs. A CA with both grants can see the independently authorized existing Dispensary panel.
- Right: subtotal, accepted Panel responsibility, self-pay received, deferred amount, due now, Add Payment and Complete Visitation. Do not label approved Panel credit as received money.
- After physical completion, retain a discoverable `Awaiting billing` row/action in the operational board; a completed Case must not make an unfinished Visit disappear. Add a compact Billing board filter for no-medicine checkout rather than pretending it entered physical Dispensary. Same Patient workspace, not a detached admin module.
- Completed tab selects `Visit.status=completed`, never Queue removed or Case completed alone. Project Patient/Visit/doctor/coverage/completed time/actions; invoice status and amounts only with explicit financial summary authority. No medication, member identifiers, references or invoice-line preload. “All” active mode excludes completed; history filters are explicit.
- Desktop uses the compact three-region layout; tablet/mobile stacks finance after items with reachable actions and local table overflow. Navigation remains keyboard-accessible, statuses textual, focused inputs visible.

Proposed routes (public IDs, existing auth/active-user/branch middleware, private no-store):

| Method / route                                            | Meaning                                                          |
| --------------------------------------------------------- | ---------------------------------------------------------------- |
| GET `/visits/{visit}/billing`                             | Read-only workspace; no automatic draft mutation                 |
| POST `/visits/{visit}/billing/draft`                      | Build or explicitly refresh draft with exact version/idempotency |
| POST `/invoices/{invoice}/finalize`                       | Freeze reviewed source/price snapshot                            |
| POST `/invoices/{invoice}/payments`                       | Record and allocate confirmed payment                            |
| POST `/invoices/{invoice}/coverage`                       | Propose coverage                                                 |
| POST `/coverage-allocations/{allocation}/approve`         | Independently approve exact coverage                             |
| POST `/invoices/{invoice}/outstanding`                    | Request exact deferment                                          |
| POST `/patient-receivables/{receivable}/approve`          | Independently approve deferment                                  |
| POST `/payments/{payment}/reverse`                        | Privileged recorded-error reversal, not refund                   |
| POST `/invoices/{invoice}/void`                           | Guarded full void/reissue pre-completion                         |
| POST `/visits/{visit}/complete`                           | Complete Visitation, not consultation                            |
| GET `/invoices/{invoice}` and `/invoices/{invoice}/print` | Authorized retained invoice/print                                |
| GET `/payments/{payment}/receipt`                         | Authorized immutable receipt/print                               |
| POST `/visits/{visit}/encounter/complete-consultation`    | Explicit doctor checkout dispatcher                              |
| POST `/visits/{visit}/encounter/reopen-checkout`          | Restricted no-medicine pre-finalization correction               |

Keep existing send-to-dispensary URL compatible and secured; both medicine entry paths must share the same transaction/checkout evidence. No duplicate independent implementation. Performance confirmation can be an exact-version part of doctor checkout, not a separate broad clinical API. Old outstanding payments use the originating invoice payment route. No GET side effects or delete-payment route.

Invoice print shows immutable charged snapshots, allocations and outstanding (not falsely “paid”). Receipts show actual net receipt/method, originating invoice and retained reversal annotation. Both are distinct from medicine labels and non-mutating. UAT documents prominently say synthetic/not production accounting. No e-Invoice QR/validation claim.

## 14. Security, privacy and production boundary

Financial item descriptions can reveal healthcare information. Protect them as sensitive rather than assuming finance permission grants a full chart. Separate aggregate financial oversight from itemized cashier access. Patient search, Queue polling, global Inertia props and general audit must not receive InvoiceLines, payment references, Panel member identity, diagnoses, notes or medication directions.

Every mutation revalidates active actor, exact permission, effective assignment, tenant/branch/Patient source ownership, expected versions and idempotency. Unauthorized existence-sensitive requests return privacy-preserving denial. Client filters cannot select scope. Technical Admin does not gain access through layout, role-management authority or printed URLs. Review administrative assignment of new financial permissions so self-escalation remains prohibited.

Private/no-store HTTP responses; no financial/clinical browser storage; clear data on Patient/branch changes; stale responses cannot replace a new context. CSRF/rate limits, server allowlists, overposting rejection and bounded search retained. Add all nested payment/coverage/member/reference/reason roots to sensitive-input no-flash protections. General audit records event/state/version/reason category with neutral subject/actor projection, not Patient identity, public invoice number, amounts, medicine detail or free text. Restricted financial evidence stores necessary references, amounts and approval reasons separately.

**e-Invoice: defer implementation entirely in Core.** No unused integration tables, secrets, connector or completion dependency. The owner's optional patient-request workflow is a UX requirement, not a legal exemption: LHDN's healthcare FAQ distinguishes individual requests from consolidated reporting. Applicability, thresholds, exemptions, tax and cash-rounding obligations require a separate current production assessment. See [LHDN healthcare FAQ, questions 2–3](https://www.hasil.gov.my/media/atse5ojz/lhdnm_healthcare_faqs.pdf). Do not declare e-Invoice legally optional based on patient preference.

Yezza remains the operational source of truth. No real cash reconciliation, claims, imports, Patient records or stock opening balances. **REAL-PATIENT PRODUCTION APPROVAL: NOT GRANTED.** Production still needs legal/privacy review, governed prices and catalogues, correction/refund policy, retained-record/recovery validation, infrastructure controls and cutover authorization.

## 15. Services and implementation responsibilities

- `CompleteConsultationService`: actor-owned checkout dispatcher, safe no-medicine bypass, composition with existing handoff and source evidence.
- `ServiceDeliveryService`: exact-version performed/not-performed attestations; no price selection or stock mutation.
- `BillingSourceService`: minimized authoritative completed fulfilment/checkout/service projection; fail on unsupported/mismatched source.
- `PriceResolutionService`: governed effective price/unit selection and provenance; no clinical equivalence.
- `BillingBuilderService`: draft generation and immutable finalization with exact source/version/rounding; no receipts.
- `PaymentService`: idempotent confirmed receipt/allocation and approved reversal evidence; no clinical or stock side effects.
- `CoverageAllocationService`: proposal/verification/acceptance and reconciliation, not claims/remittance.
- `OutstandingService`: separate approval, due summary and original-invoice settlement projections.
- `InvoiceCorrectionService`: guarded void/reissue and unresolved correction holds; no refunds/clinical edits.
- `CompleteVisitationService`: Visit-level atomic readiness and financial gate/terminal transition.
- `BillingDirectoryService` and explicit financial policies: per-role read models/print, no raw serialization.

Expected future implementation touches new Billing classes and migrations, small checkout/service-evidence additions under Clinical, Visit model/completion/policy/constraints, directory/board status projections, explicit PermissionCatalogue additions, controllers/requests/routes, and existing Patient/Dispensary presentation composition. These are future authorized changes, not files edited now.

## 16. Test and PostgreSQL race plan

Feature/auth/privacy tests must cover every permission matrix role, dual-role precedence, active/inactive staff, temporary assignments, wrong branch/tenant/Patient, forged sources/prices/totals/statuses, CSRF, no-flash/no-store, print purity, immutable snapshots and rounding boundaries. Preserve released 53 pre-3A PostgreSQL regressions plus all 17 Phase 3A groups; discover/report actual counts on the future exact SHA.

Proposed Phase 3B suite: `PostgresBillingCompletionRegressionTest` with repository-consistent separate-process worker. At least these 18 grouped scenarios:

| #   | Race                                                 | Required result                                                                                                           |
| --- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| 1   | Two CA payments, same displayed version              | One commits; other stale. Reload may then accept any legitimate remainder; no silent merge                                |
| 2   | Duplicate Complete Visitation                        | One transition/audit; authorized exact idempotent retry returns prior result                                              |
| 3   | Payment vs Complete Visitation                       | Payment first may satisfy gate; completion first with uncovered due rejects; combined-command late failure rolls all back |
| 4   | Deferment approval vs payment                        | Exact approved remainder only; no double-covered patient amount                                                           |
| 5   | Panel acceptance vs self-pay                         | No overlap or over-allocation; proposal cannot turn into cash                                                             |
| 6   | Duplicate invoice build/finalize                     | One active invoice, one copy of each source, one issued number                                                            |
| 7   | Dispensary completion vs invoice build               | Incomplete source rejected, or committed final actuals billed once; no second movement                                    |
| 8   | Exact payment permission loss                        | Revocation-first fails closed; transaction-first uses still-authorized locked state                                       |
| 9   | Branch assignment loss                               | Same serialized authority outcome; no cross-branch payment                                                                |
| 10  | Visit cancellation vs financial completion           | Existing cancellation eligibility respected; no cancelled-and-completed Visit or financial completion of invalid care     |
| 11  | Two payments consume last amount due                 | Never overpaid; include same idempotency key, distinct keys and changed-payload replay                                    |
| 12  | Late audit/constraint failure                        | Full rollback of invoices/lines/payments/allocations/receivables/Visit/counters where changed                             |
| 13  | Price publication vs finalization                    | One applicable version; changed preview is stale, finalized snapshot unchanged                                            |
| 14  | No-medicine checkout vs Plan/Encounter edit          | Exact current snapshot or stale rejection; no fake Dispensary or unlocked clinical edit                                   |
| 15  | Return/reopen vs checkout-source generation          | Old checkout/source cannot be billed; invoice-finalized bypass cannot reopen                                              |
| 16  | Payment reversal vs payment/completion               | No duplicate reversal, unexplained debt approval or lost receipt                                                          |
| 17  | Void/reissue vs payment                              | No receipt allocated to a newly void invoice; one current replacement only                                                |
| 18  | Two Visits settling old outstanding / privacy probes | Original invoice credited once; no merging debt into new charges; unauthorized probes change nothing                      |

Add deactivation, service-attestation supersession, completion vs wrong Panel evidence, direct SQL deferred constraint failure and identical-price-unit edge cases within these groups or additional tests. Counts are a design target, not claimed execution evidence.

Require independent PHP OS processes/connections, verified backend PIDs, READY/GO, CRLF-safe protocol, independent observer, `pg_blocking_pids()` and recursive blocker-chain proof at actual production lock surfaces. Required contention not observed is a failure. For impossible-state/authorization probes, assert denial rather than fabricate a lock the production path never takes. Log safe exception classes/SQLSTATE/protocol outcomes, not payloads/secrets. Disposable PostgreSQL 18 guards remain mandatory; never substitute SQLite or accept PG skips in candidate CI.

Browser acceptance: self-pay normal journey; Panel only (receivable not paid); Panel + self-pay; cash + QR; approved pay-later and denial without approval; later settlement of original debt without duplicating charge; no-medicine; service-only performed/not-performed; partial actual medicine billing; duplicate completion; wrong branch/roles; price change; incorrect payment controlled correction; non-mutating invoice/receipt; Completed permissions; zero-total governed price; terminal UI and no second stock deduction.

Future quality gates: full isolated Laravel suite, Pint, PHPStan/Larastan, TypeScript, ESLint, Prettier, production build, git diff check; clean disposable PostgreSQL migration/recreation and all old/new races; independent financial/security/concurrency review, exact-candidate CI and synthetic browser UAT. This design-only run did not execute implementation tests or access a database.

## 17. Explicit decisions 1–20

| #   | Question                            | Decision                                                                                                                         |
| --- | ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| 1   | One Invoice per Visit?              | One current non-void invoice; retained void/replacement chain, not one ever                                                      |
| 2   | Actual dispensed medicine quantity? | Yes, completed current handoff actual only                                                                                       |
| 3   | Service billability?                | Active order AND doctor-confirmed performed quantity                                                                             |
| 4   | Panel Payment or receivable?        | Accepted responsibility / Panel receivable, never cash Payment                                                                   |
| 5   | Panel + self-pay?                   | Yes, one Panel plus patient share in Core                                                                                        |
| 6   | Multiple self-pay receipts?         | Yes; each fully allocated to one original invoice                                                                                |
| 7   | Overpayment?                        | No; no wallet, unallocated deposit or refund workaround                                                                          |
| 8   | Pay-later in Core?                  | Yes, specific version-bound approval                                                                                             |
| 9   | Who approves?                       | Branch Supervisor; explicit Finance/Director organisation approval; configured limits and independent approval                   |
| 10  | e-Invoice Core?                     | No integration/status tables; production compliance separate                                                                     |
| 11  | No-medicine bypass?                 | Yes, explicit doctor checkout evidence; no dummy Dispensary                                                                      |
| 12  | Complete Visitation finance gate?   | Current finalized reconciled invoice, no unresolved correction, U=0 through receipts/approved receivables                        |
| 13  | Invoice immutable?                  | Lines/prices/sources immutable from finalization; retained completion provenance                                                 |
| 14  | Correction?                         | Approved recording-error reversal; pre-completion full void/reissue; post-completion correction hold, no destructive edit/refund |
| 15  | Price authority?                    | Separate ChargeDefinition + versioned PriceBook/PriceEntry, not doctor/SKU cost                                                  |
| 16  | Money?                              | BIGINT sen, MYR, exact quantity thousandths, bounded integer half-up calculations                                                |
| 17  | Completed projection?               | Structural by default; finance fields permission-gated, no clinical detail                                                       |
| 18  | Quick Treatment Sets deferred?      | Yes, after Completed Patient v1                                                                                                  |
| 19  | Billing deducts stock?              | Never                                                                                                                            |
| 20  | Real-patient production approval?   | Not granted                                                                                                                      |

## 18. Risks, verdict and implementation milestones

Largest risks: false service delivery, confusing Panel responsibility with payment, double-counted receivables, no-medicine checkout without a safe clinical freeze, price/unit mismatch, missing idempotency, payment/void write skew, and financial projections exposing medication history to management.

Recommended defaults are explicit above. Before implementation authorization, the owner should accept the service-attestation rule, one-current-invoice replacement rule, same-branch old-debt collection, limited correction policy, no-medicine reopen guard, and proposed approval grants. Actual price values, approval caps, due-date policy and approved methods require governed synthetic configuration; absent configuration fails closed. Production tax, cash rounding, e-Invoice applicability and full refund/correction rules remain unresolved **production** gates, not claims of readiness.

Architecture verdicts, conditional on implementing this design and preserving its gates:

- Phase 3B scope: **GO**.
- Billing domain: **GO**.
- Payment/split allocation: **GO**.
- Outstanding/pay-later: **GO**.
- Complete Visitation: **GO**, including the explicit no-medicine and Visit-status extensions.
- Security/privacy design: **GO**, not a completed security review of unwritten code.
- Concurrency plan: **GO**, dynamic proof still required.
- Ready for a separately authorized synthetic Phase 3B implementation: **YES**. This document itself is not implementation/release/deployment authorization.

Shortest safe sequence:

1. Characterize released boundaries; implement doctor checkout/service confirmation/no-medicine bypass and guarded pre-financial reopen. Preserve all Phase 3A races.
2. Exact money primitives, commercial mappings/prices, invoice source projection and idempotent draft/finalization/numbering constraints.
3. Payments, Panel responsibility, outstanding approvals/settlement and the limited correction evidence, with feature/authority tests first.
4. Atomic Complete Visitation and new Visit terminal constraints; Patient workspace Billing panel, discoverable awaiting-billing state, Completed board and print outputs.
5. Independent financial/safety/security review; disposable PostgreSQL constraints and race suite plus all released regressions; full quality gates.
6. Freeze exact reviewed candidate, exact-SHA PostgreSQL 18 CI, synthetic browser UAT; release only under separate authorization. No Quick Treatment Sets or production cutover.

Complexity: moderate-to-high domain work, not a cosmetic panel addition. Implement in these bounded milestones rather than billing, debt and Visit closure as one unreviewable patch.
