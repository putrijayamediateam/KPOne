# KPOne project

## Vision

KPOne will become Klinik Putrijaya's cohesive digital operating system. Its long-term purpose is to connect safe patient administration, clinic operations, clinical work, medication, revenue, stock, people operations, corporate panels, marketing, digital channels, patient services, and management intelligence without fragmenting the business into unrelated systems.

The replacement will be incremental. Safety, continuity, clear ownership, and auditable access take priority over feature velocity.

## Current delivery

Phase 0A established the platform contract: one organisation, three branches, nine departments, staff identity, multi-branch assignment periods, RBAC, authentication, audit, and the application shell.

Phase 0B adds internal operational staff provisioning and identity administration. Authorised organisation-scoped administrators can create staff accounts transactionally, maintain permitted identity and employment fields, manage effective-dated branch assignments through the domain service, sync approved roles, activate or deactivate accounts, and review a staff access summary. Every security-relevant mutation remains server-authorised and audited.

Phase 1A adds the organisation-level Patient Master foundation: stable KPOne patient numbers, controlled NRIC/passport history, masked search projections, explicit patient permissions, demographic administration, and structural audit evidence. It does not create a registration, visit, clinical, billing, or patient-facing workflow. Synthetic implementation and tests do not constitute approval to store real patient data.

The current public website remains a separate system. Its current website administration remains the production administration path during migration. KPOne Phase 0A contains no website integration.

Yezza remains outside this delivery. No replacement, integration, or migration from Yezza is being implemented yet.

## Roadmap

The order below is directional and requires a separately approved scope for each phase:

1. Production deployment, recovery, access review, and staff-governance hardening.
2. Patient registration, appointments, and queue operations after a separately approved regulated-data phase.
3. Clinical encounters, diagnosis, prescribing, and dispensary workflows.
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
