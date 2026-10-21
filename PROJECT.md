# KPOne project

Latest merged delivery: PR #149 — RPT-02b patient Transaction Detail report, merged as `39161b6`. Recent merges, newest first
(documentation-only PRs omitted):

- PR #149 (`39161b6`) — RPT-02b: Transaction Detail page and CSV at `/reports/transactions` (one row per receipt with KPOne patient number, name, visit and invoice numbers, method, Cash / Non-cash classification and original, reversed and net amounts; no IC number), audited before release, for Finance Officer and Director only.
- PR #148 (`2b56ce3`) — RPT-01e: Stock movement summary page and CSV, quantities only (opening, received, dispensed, adjusted, transferred, closing per SKU and unit; no cost or valuation), for Director, Finance Officer and Administrator.
- PR #147 (`71b8361`) — RPT-01d: Panel summary page and CSV (approved, claimed, unclaimed, accepted, rejected, remitted and outstanding per Panel, with 0-30, 31-60, 61-90 and over 90 day ageing), aggregates only, for Director, Finance Officer and Panel Officer.
- PR #146 (`bfd136f`) — RPT-01c: Daily summary page and CSV (Cash and Non-cash receipts by receipt date; Panel and approved pay-later Due by invoice finalisation date; Total is Cash + Non-cash received only) for Director, Finance Officer and Administrator.
- PR #145 (`87df9b1`) — RPT-01c-0: Cash / Non-cash classification on payment methods, applied to reports from then on. Also made two clock-dependent tests deterministic.
- PR #143 (`ca9d03b`) — RPT-02a: Billing Detail page and CSV at `/reports/billing-detail` (one row per finalised invoice with KPOne patient number, name and settlement figures; no IC number), audited before release, for Finance Officer and Director only. Transaction Detail and Cash / Non-cash classification are deferred.
- PR #142 (`89d10bb`) — RPT-01b: daily net sales (with distinct patients per day) and receipts-by-payment-method CSVs, aggregates only, with reversals shown and a receipt projection shared with terminal reconciliation.
- PR #141 (`83877a4`) — RPT-01a: aggregate Sales summary CSV for Director, Finance Officer and Administrator.
- PR #139 (`695c0c2`) — DOC-03: Drug label printing from saved CA-final dispensing lines with server-side field budgets (over-budget labels are blocked, never truncated), a new `dispensary.labels.print.branch` permission for CA and CA Supervisor, and no stored label content. Provisional 100 × 150 mm size; printer and sticker stock to be confirmed.
- PR #137 (`ff9479b`) — DOC-02b follow-up: the MC history shows issue times in the branch timezone (UTC evidence unchanged) and explains that a void closes the MC chain.
- PR #135 (`f1518c1`) — DOC-02b: Medical Certificate issuance by the attending doctor with encrypted immutable pre-signature snapshots, numbering, replace and void by the original issuer, and no diagnosis by default, no charge. EN/BM wording is a draft pending doctor approval.
- PR #134 (`d3cac4d`) — DOC-02a: Director-managed clinician credentials (professional name, MMC number) and fixed draft EN/BM MC template previews.
- PR #133 (`e06a3d3`) — UI-FIX: Quick create Register Visit card no longer overlaps the form on narrow screens.
- PR #131 (`458ac11`) — INV-04b: Bill to (document addressing only) with append-only revisions, an invoice print block and generic Logs entries.
- PR #130 (`024a42a`) — INV-04a: encrypted internal invoice notes (correct or retract, never edited), with an invoice-level Add note in the Logs drawer.
- PR #128 (`916219c`) — DOC-01: Business-details letterhead and an optional branch footer on the invoice and receipt prints. Clinical documents and issued-document snapshots are deferred.
- PR #127 (`0a949c5`) — SET-04b: Email and Address inputs and requirement switches in QR, Quick create and Patient creation.
- PR #126 (`d930244`) — SET-04: Registration settings (`/registration-settings`), with Date of birth and Nationality switches.
- PR #124 (`bcfde20`) — INV-05b: Insights net of invoice adjustments with gross item rankings, adjustment rows on new Panel claims and the print view.
- PR #123 (`ef96f1e`) — INV-05a: governed invoice adjustments (discount or surcharge) and reversals, with a branch threshold, new narrow permissions and PostgreSQL integrity checks.
- PR #122 (`4ab954c`) — INV-03: read-only invoice activity log drawer.
- PR #121 (`b5f4b29`) — NAV-02: shorter role-based top bar; everything else through Clinic management.
- PR #119 (`1101dd8`) — BIZ-01: Business details, shared logo and branch letterhead service.
- PR #118 (`f4ad214`) — Clinic management hub (`/clinic-management`), filtered by existing permissions.
- PR #117 (`cb1d102`) — INV-02b: payment dialogs and Manage payments page.
- PR #116 (`13701db`) — INV-02a: Outstanding bill groups.
- PR #114 and #115 (`d6a533d`, `e0a267d`) — INV-01 and INV-01b: Invoice and Patient Billing pages in the Yezza layout.
- PR #113 (`35c02dc`) — PANEL-NOTE-01: per-visit Panel notes and a Payment column.
- PR #112 (`375d541`) — CONSULT-02: patients waiting counts on the consultation page.
- PR #110 and #111 (`39f38d9`, `56e4a67`) — CONSULT-01 two-column consultation workspace; test-only timezone fix.
- PR #106 to #109 (`dbd7dc5`, `93b8f3d`, `b8a4cce`, `b904a7e`) — settings pages: Suppliers, Medicine details, Preset diagnoses and the diagnosis preset picker.
- PR #105 (`6b0cbba`) — ADM-01: Administrator role and `catalogue.settings.manage.organisation`.
- PR #103 (`5aa60d6`) — PS-03: Panel invoices page (`/panel-invoices`) with date, time, Panel, doctor, visit type and
  treatment filters, and a CSV download for Panel Officer, Finance Officer and Director. First export in KPOne,
  audited by filters and row count only. New permission `panel.invoices.export.branch`.
- PR #102 (`2860ea4`) — PS-02: claim message template (plain text with smart fields) edited in Checkout settings and a
  Copy message button on the claim page.
- PR #100 (`bfba0ef`) — PS-01: Checkout settings (`/checkout-settings`): Panel list with tabs, search, status switch,
  Edit and Archive; Add/Edit panel page (type, SSM number, contact, address, verification link or remark, TIN) and a
  per-Panel claim due rule (N days after the visit, or daily, weekly or monthly by a time). New permission
  `panels.manage.organisation` for Director, Finance Officer and Panel Officer.
- PR #99 (`03c6a9c`) — PC-02: Create claim page for Panel Officers (invoice details, submitted date, remarks, photos);
  claims start in Submitted with scoped idempotency.
- PR #98 (`b51161a`) — Yezza-style claim page with documents, notes, remarks and a patient identity view for Panel
  Officers.
- PR #97 (`e40d627`) — Yezza-style Panel Claims main page: Pending and Overdue invoices, filters and per-Panel due days.
- PR #95 (`946e573`) — Billing back button goes to Registration, Panel Responsibility or Finance / Billing depending on
  what the account may open (a Panel Officer no longer lands on a forbidden Registration page).
- PR #94 (`fdca613`) — OTC visits can be sent to waiting and show in the Registration Waiting tab; a doctor's
  previous-visit View opens the Invoices page with a safe return link.
- PR #93 (`4a23486`) — PC-01: manual Panel Claims for Panel Officers (claim pack from an approved Panel
  responsibility, manual portal submission, query and decision tracking, remittances, Payments Insights measures).
- PR #91 (`8547ef7`) — REF-01: staff pages send `Referrer-Policy: same-origin` so `back()` and refused-action redirects
  know the source page; the public QR pages keep `no-referrer`.
- PR #89 (`d03c03e`) — DS-01d: the Dispensary page uses the Invoices three-column layout (patient and allergies |
  editable medicines and services | summary and actions). Layout only.
- PR #87 (`de4e496`) — DS-02c: the QR form offers "Buy medicine only"; accepting joins the OTC list and the patient's
  phone shows the B number and the pharmacy call.
- PR #86 (`10f94b6`) — DS-02b: per-branch setting to call patients by queue number (default) or full registered name,
  on the TV list and spoken call.
- PR #85 (`12f2f6b`) — DS-02a: OTC waiting list with its own B numbers (consultation numbers now `A-001`), called to the
  dispensary on the TV.
- PR #84 (`b80f491`) — DS-02 design decisions (documentation only).
- PR #83 (`5643adf`) — DS-01c: Invoices page showing ordered beside dispensed.
- PR #82 (`c23b65f`) — DS-01b-3: Dispense from the Registration board.
- PR #81 (`fe1a959`) — DS-01b-2: OTC billing.
- PR #80 (`fa68e58`) — DS-01b-1: OTC dispensary case.
- PR #78 (`1cdd1a7`) — DS-01a-services: the CA edits, adds, removes and confirms services at Dispensary; the doctor's
  service order stays as the original and billing uses the CA-confirmed lines.
- PR #77 (`55594bf`) — DS-01a: the CA edits, adds and removes medicines at Dispensary with the doctor's original
  order kept beside it, and completing is the CA's own verification (no CA allergy tick).
- PR #75 (`2252f3d`) — finance officers, panel officers and directors see invoice lines.
- PR #74 (`843e61c`) — refused Dispensary and Billing actions stay on their page; TV Screen menu link; header cleanup.
- PR #70 (`8fd95c5`) — billing page guidance: separate "Amount received" and Panel or pay later amount fields, a
  "How to settle this invoice" note, a six-step Panel guide, and hints under a waiting proposal and a disabled
  Add Payment. Wording and layout only; amounts, approval limits and permissions are unchanged.
- PR #69 (`915733d`) — QR intake review forms keep the current lock version, so Accept is no longer refused after
  Save corrections.
- PR #67 (`c547578`) — QR intake review actions (start, correct, correction required, reject, accept) stay on the
  intake's own review page instead of the patient status page.
- PR #66 (`49a335e`) — a refused Call In returns to the Registration board or queue it was pressed on.
- PR #65 (`af0ee62`) — TV-4: soft two-note ding-dong call chime (owner choice).
- PR #64 (`3c16156`) — TV-3: earlier calls stay on the TV list, one row per patient, service and room.
- PR #62 (`8344037`) — TV display polish: separate Full screen button, light/dark theme button, Klinik Putrijaya
  logo, English on-screen text, wrapping room names.
- PR #60 (`cf8574d`) — TV-2: dispensary staff press Panggil to call a patient to the dispensary room, and the
  doctor or a supervisor calls a patient being served to a treatment room. Calls only announce on the TV; the
  queue, consultation and dispensary case are unchanged.
- PR #57 (`3a32c1a`) — TV-1 queue display (consultation calls): branch rooms, each doctor's room for the day
  (Call In needs it once a branch has rooms), immutable call records with no patient data, Call Again /
  Panggil semula, and a `queue_display` role whose branch TV shows and announces called numbers and rooms
  alongside posters, a YouTube video and scrolling text. TV accounts stay signed in.
- PR #54 (`c93af4a`) — cloud session start hook: Claude Code cloud sessions get PHP 8.4 via Docker (`kphp`),
  Composer and Node packages and generated routes, so tests and linters run there. Development tooling only.
- PR #52 (`36c6078`) — QR status page overflow fix: the public status page no longer scrolls sideways on phones.
  Found by the agent-run QR UAT on 2026-10-05, which passed 27 of 27 cases after the fix.
- PR #49 (`aba4ab9`) — QR intake form polish (Phase C): per-step checks mirroring server validation before each
  step, a review-before-submit summary with edit links, and a visible 15-minute session countdown. Frontend only;
  the server remains the validation authority and nothing is kept in browser storage.
- PR #47 (`92decc3`) — QR status Phase B: patients ahead, a labelled wait range from recent branch history,
  aggregate waiting counts per branch, public branch address/map links, and optional browser-only nearby-branch
  sorting.
- PR #46 (`dae30a1`) — QR status Phase A: status polling, first name and queue number after CA acceptance,
  progress stepper, call-in banner, opt-in chime and vibration.
- PR #45 (`99c5c93`) — BS-02 first-Director Artisan command, QR coverage handoff, manual terminal
  reconciliation, BP-01 Panel approval limits, and the Payment Method setup screen.
- PR #44 (`c566530`) — HC-01: at most three held consultations per doctor, with a 30-minute held warning.
- PR #43 (`a08d099`) — authorised Insights reports and Unified Catalogue Setup.

Phase 3B — Completed Patient v1 and Phase 3A-Core Dispensary/minimal inventory are also merged. Quick Treatment
Sets remain deferred. Insights behavior, metric definitions, and limitations are documented in
[docs/INSIGHTS.md](docs/INSIGHTS.md). See [docs/BASELINE.md](docs/BASELINE.md) for authoritative delivery status,
owner UAT, and remaining release gates. No production approval is implied.

## Vision

KPOne will become Klinik Putrijaya's cohesive digital operating system. Its long-term purpose is to connect safe patient administration, clinic operations, clinical work, medication, revenue, stock, people operations, corporate panels, marketing, digital channels, patient services, and management intelligence without fragmenting the business into unrelated systems.

The replacement will be incremental. Safety, continuity, clear ownership, and auditable access take priority over feature velocity.

## Current delivery

Phase 0A established the platform contract: one organisation, three branches, nine departments, staff identity, multi-branch assignment periods, RBAC, authentication, audit, and the application shell.

Phase 0B adds internal operational staff provisioning and identity administration. Authorised organisation-scoped administrators can create staff accounts transactionally, maintain permitted identity and employment fields, manage effective-dated branch assignments through the domain service, sync approved roles, activate or deactivate accounts, and review a staff access summary. Every security-relevant mutation remains server-authorised and audited.

Phase 1A adds the organisation-level Patient Master foundation: stable KPOne patient numbers, controlled NRIC/passport history, masked search projections, explicit patient permissions, demographic administration, and structural audit evidence. It does not create a registration, visit, clinical, billing, or patient-facing workflow. Synthetic implementation and tests do not constitute approval to store real patient data.

Phase 1B adds branch-scoped Patient Registration. Registration creates the canonical Visit that later Queue, clinical, dispensing, and billing domains must extend. It provides Consultation/OTC classification, eligible doctor assignment, administrative reason, priority, provisional Self-pay/Panel intent, idempotency, repeat-attendance review, registered-only editing/cancellation, and a privacy-minimised Registration Console. Queue Entry and every clinical, medication, financial, appointment, messaging, portal, and external-integration workflow remain outside this delivery.

Phase 1C adds the Consultation Queue as a one-to-one operational child of Visit. It provides branch/day numeric Queue numbers, Waiting, Urgent-first deterministic ordering, own-doctor Queue, Call In to Serving, previous-day carry-over, Queue-aware Visit cancellation through Waiting, and an authorised live CA/doctor console. It ends at Serving; Hold/Resume, Clinical Encounter content, completion, dispensing, and billing remain outside this delivery.

Phase 2A adds the first clinical aggregate after a Consultation reaches Serving: one in-progress Clinical Encounter per Visit, a single current vitals observation, one clinical note, ordered structured diagnoses, own-clinician authorization, bounded care-related history summaries, structural audit, and optimistic concurrency. It does not finalize or complete an Encounter and contains no treatment, prescription, order, dispensing, billing, or patient-facing workflow. Real-patient production approval remains not granted.

Phase 2B.0 adds the clinical safety prerequisite for later medicine ordering: a longitudinal organisation-level Allergy Profile with explicit unknown/no-known/has-allergies semantics, active structured Allergy Records, an immutable version ledger, explicit Encounter review of an exact Profile version, and a longitudinal Problem List. It adds no Treatment Plan, medicine/service order, automatic contraindication/interaction logic, Dispensary, inventory, or billing behavior. Real-patient production approval remains not granted.

Phase 2B adds one in-progress Treatment Plan per current Clinical Encounter. The attending resident doctor may atomically maintain governed catalogue-backed medicine and clinical service/procedure orders under a separate optimistic version. Medicine mutations require the exact current Encounter Allergy review. Orders retain immutable identity snapshots and persisted removals are withdrawn rather than deleted. There is no stock, fulfilment, performed-service state, pricing, billing, signing, finalisation, Visit/Queue completion, or Phase 3 workflow. Real-patient production approval remains not granted.

Phase 3A-Core adds a version-bound doctor-to-Dispensary handoff, branch fulfilment, safe stock allocation, and immutable inventory movements. Phase 3B adds checkout, service performance evidence, governed pricing, invoices, receipts, payment and responsibility handling, and controlled Visit completion. These milestones are merged, but their delivery does not by itself authorise production use or go-live.

Panel Claims (PC-01, PR #93) is merged as a separate manual-portal workflow. Its scope is recorded in [docs/BASELINE.md](docs/BASELINE.md); it does not integrate with Panel systems or change Invoice allocations.

The waiting-room TV (TV-1, PR #57; TV-2, PR #60) shows each branch's consultation, dispensary and treatment-room
calls. The TV account sees only called numbers, rooms and times for its own branch, never patient, clinical or
financial data, and does not receive Insights.

The current Insights work provides aggregate-only operational and financial reports; invoice sales are net of invoice adjustments (INV-05b) while item rankings stay gross. It does not introduce appointments, packages, patient-level financial rankings, exports, or new business transactions. Unsupported figures are identified rather than estimated.

The current public website remains a separate system. Its current website administration remains the production administration path during migration. KPOne Phase 0A contains no website integration.

Yezza remains outside this delivery. No replacement, integration, or migration from Yezza is being implemented yet.

## Roadmap

The order below is directional and requires a separately approved scope for each phase:

1. Keep the first-Director bootstrap path governed without weakening staff-authority rules.
2. Close the remaining feature-level owner UAT recorded in `docs/BASELINE.md` (QR status Phase B browser GPS
   flow, QR intake form polish (Phase C), Payment Method setup screen, TV-1/TV-2 queue display on a real branch TV;
   BP-01 deferred at the owner's direction).
3. Complete production deployment, recovery, access review, staff-governance hardening, security/privacy/PDPA
   and legal gates before any go-live decision.
4. Design and separately authorise Appointments and any later Queue refinements.
5. Expand inventory operations (including procurement, receiving, and stocktake) only after their own safety,
   reconciliation, and authorisation decisions.
6. Advanced finance, HR workflows and staff roster, each under an approved scope.
7. Website management, marketing, controlled external integrations, patient portal, and patient messaging.
8. Deliberate legacy/Yezza migration planning after data, safety, and reconciliation design.

Roadmap placement is not authorisation to build or store data for a later phase.

## Delivery principles

- Modular monolith over distributed infrastructure.
- Explicit policies and small domain services over hidden convention.
- Server-side scope enforcement over UI-only controls.
- Minimal dependencies and first-party Laravel capabilities.
- Synthetic development data only.
- Reviewable diffs and automated proof for security boundaries.
