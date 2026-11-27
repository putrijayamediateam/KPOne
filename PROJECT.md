# KPOne project

## Current delivery

- Merged: phases 0A to 3B (staff, patients, registration, queue, clinical, dispensary, billing) and later slices
  (Panel Claims, TV, Insights and reports, documents, website drafts, inventory, KPOne HR, agents, triage).
- Status and what is authorised: [docs/BASELINE.md](docs/BASELINE.md). Merge list, phase detail and roadmap:
  [docs/PROJECT-HISTORY.md](docs/PROJECT-HISTORY.md).
- Nothing is production-approved; all data is synthetic. Yezza stays outside KPOne.
- Roadmap placement is not authorisation to build or store data for a later phase.

## Vision

KPOne will become Klinik Putrijaya's cohesive digital operating system. Its long-term purpose is to connect safe patient administration, clinic operations, clinical work, medication, revenue, stock, people operations, corporate panels, marketing, digital channels, patient services, and management intelligence without fragmenting the business into unrelated systems.

The replacement will be incremental. Safety, continuity, clear ownership, and auditable access take priority over feature velocity.

## Delivery principles

- Modular monolith over distributed infrastructure.
- Explicit policies and small domain services over hidden convention.
- Server-side scope enforcement over UI-only controls.
- Minimal dependencies and first-party Laravel capabilities.
- Synthetic development data only.
- Reviewable diffs and automated proof for security boundaries.
