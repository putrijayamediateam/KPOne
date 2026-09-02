# KPOne project

Current unreleased milestone: Phase 3A-Core Dispensary and Minimal Inventory Foundation. Quick Treatment Sets and Phase 3B remain deferred.

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

The current public website remains a separate system. Its current website administration remains the production administration path during migration. KPOne Phase 0A contains no website integration.

Yezza remains outside this delivery. No replacement, integration, or migration from Yezza is being implemented yet.

## Roadmap

The order below is directional and requires a separately approved scope for each phase:

1. Production deployment, recovery, access review, and staff-governance hardening.
2. Appointment design and later Queue refinements after separately approved phases.
3. Treatment planning, prescribing, completion, and dispensary workflows after the Phase 2A clinical foundation receives its production safety gates.
4. Billing, panel/corporate, and inventory operations.
5. HR and finance operations.
6. Website management, marketing, and controlled external integrations.
7. Patient portal, messaging, and management analytics.
8. Deliberate legacy/Yezza migration planning after data, safety, and reconciliation design.

Roadmap placement is not authorisation to build or store data for a later phase.

## Delivery principles

- Modular monolith over distributed infrastructure.
- Explicit policies and small domain services over hidden convention.
- Server-side scope enforcement over UI-only controls.
- Minimal dependencies and first-party Laravel capabilities.
- Synthetic development data only.
- Reviewable diffs and automated proof for security boundaries.
