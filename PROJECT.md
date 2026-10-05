# KPOne project

Latest merged delivery: PR #43 — authorised Insights reports and Unified Catalogue Setup, merged as `a08d099`.
Phase 3B — Completed Patient v1 and Phase 3A-Core Dispensary/minimal inventory are also merged. Quick Treatment
Sets remain deferred.

Current in-progress worktree: BS-02's controlled interactive Artisan invocation for the first-Director bootstrap
service and the fresh-database UI acceptance walk are complete. The walk used one synthetic visit and verified
non-zero data across all six Insights reports. Payment Method setup is now available through an audited,
permissioned administration screen. The current worktree passed the full PostgreSQL 18.6 suite (751 tests, 749
passed, 2 expected skips); final independent review of the added Payment Method screen remains. Insights behavior,
metric definitions, and limitations are documented in
[docs/INSIGHTS.md](docs/INSIGHTS.md). See [docs/BASELINE.md](docs/BASELINE.md) for authoritative delivery status
and remaining release gates. No production approval is implied.

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

The current Insights work provides aggregate-only operational and financial reports. It does not introduce appointments, packages, patient-level financial rankings, exports, or new business transactions. Unsupported figures are identified rather than estimated.

The current public website remains a separate system. Its current website administration remains the production administration path during migration. KPOne Phase 0A contains no website integration.

Yezza remains outside this delivery. No replacement, integration, or migration from Yezza is being implemented yet.

## Roadmap

The order below is directional and requires a separately approved scope for each phase:

1. Keep the first-Director bootstrap path governed without weakening staff-authority rules; verify the new
   permissioned Payment Method setup screen and complete its final-candidate release gates.
2. Complete remaining release evidence for Insights, catalogue, and Payment Method changes. The owner walkthrough
   with non-zero synthetic transactions and the PostgreSQL 18.6 run for the current worktree are complete; final
   independent review remains.
3. Complete production deployment, recovery, access review, staff-governance hardening, security/privacy/PDPA
   and legal gates before any go-live decision.
4. Design and separately authorise Appointments and any later Queue refinements.
5. Expand inventory operations (including procurement, receiving, and stocktake) only after their own safety,
   reconciliation, and authorisation decisions.
6. Panel claims/advanced finance, HR workflows and staff roster, each under an approved scope.
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
