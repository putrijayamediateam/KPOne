# Phase 1C — Queue & CA Console

## Objective and canonical relationship

Phase 1C places a registered Consultation Visit into the clinic waiting workflow. `queue_entries` is a one-to-one operational child of the canonical Visit: Patient → Visit → Queue Entry. It does not duplicate Patient ownership, doctor assignment, Visit reason, priority, clinical data, or billing data. OTC Visits do not enter the Consultation Queue.

## Queue number and operational day

Queue numbers are branch-local integers allocated from `queue_number_counters` and displayed with a minimum of three digits (`001`). The sequence resets for each branch-local operational date, expands naturally after 999, and is never a Patient number, Visit number, database ID, or route identity. Counter initialization, row locking, increment, Queue Entry creation, and audit occur in one transaction. Removed numbers are not reused.

## State ownership

Visit retains the broad Phase 1B states `registered` and `cancelled`. Queue Entry exclusively owns `waiting`, `serving`, and `removed`. A Waiting or Serving Visit remains registered. Call In atomically changes Queue Entry from Waiting to Serving; it does not create a Clinical Encounter.

Ordinary Visit edit and cancellation are permitted when there is no Queue Entry or while its Queue Entry is Waiting. A queued Consultation cannot become OTC. Waiting cancellation atomically cancels the Visit and marks the Queue Entry Removed. Once Serving, ordinary Visit edit, reassignment, and cancellation are rejected; Phase 2 must define clinical abandonment, disposition, and completion semantics.

## Send to Waiting and ordering

CA, CA Supervisor, and Director may Send to Waiting with `queue.enter.branch`. The service derives active branch ownership, validates stale branch and Visit version guards, re-locks actor security state and Visit, resolves any existing Queue Entry idempotently, revalidates the assigned doctor, allocates the branch/day Queue number, creates one Waiting entry, and records `queue.entered`. Unique `visit_id` is the final duplicate authority.

Priority remains canonical on Visit. Waiting queries order Urgent before Normal, then `queued_at`, then Queue Entry ID. Browser input cannot choose Queue position, and there is no drag-and-drop ordering. Existing directional Visit audit events remain the urgency accountability record.

## Queue views and Call In

Director, CA, and CA Supervisor receive branch Queue view; resident doctors receive only their own currently assigned Queue. Doctor scope is forced server-side. Resident doctors may Call In their own assigned Patient. CA Supervisor and Director have branch Call In override; CA does not.

Call In locks Visit before Queue Entry, checks Visit and Queue optimistic versions, requires a registered Visit and Waiting Queue Entry, revalidates the assigned doctor's active role and branch eligibility, sets Called evidence, increments Queue version, and records one structural `queue.called` event. Temporary branch coverage is eligible. An ineligible doctor remains visible as an operational exception but Call In is blocked until reassignment.

## Carry-over, duration, and polling

Waiting duration is computed from UTC `queued_at`; it is never stored as a continuously changing column. Previous-day unresolved Waiting entries remain in a separated carry-over section so midnight does not hide a Patient; their operational date is shown because numeric Queue numbers can repeat on later days.

The initial Queue arrives in the normal Inertia response. A private POST snapshot refreshes approximately every three seconds while the tab is visible, prevents overlapping requests, aborts stale requests, pauses when hidden, resumes immediately, and uses bounded backoff after errors. Filters remain server-side. Phase 1C adds no SSE, Reverb, WebSocket, or broker.

Background snapshots use a Queue-only refreshing state and do not submit or visually disturb the filter toolbar. Manual filter application has a separate applying state, so the Apply action remains stable during polling while current filters, focus, pagination, and branch context are preserved.

## Privacy and audit

Queue projections contain only Queue/Patient/Visit numbers, Patient name, bounded administrative reason excerpt, doctor display, coverage label, priority, Queue state, operational date, timestamps, and concurrency versions needed for authorized actions. They exclude identity documents, raw contact/address data, Panel member reference, database IDs, normalized fields, and clinical data. Queue routes remain authenticated, private/no-store, encrypted for Inertia history, POST-based for search/polling, and protected by explicit branch/own policies.

Audit events are limited to `queue.entered`, `queue.called`, and `queue.removed`. Metadata contains structural states and record versions only. Global Audit Logs render a neutral “Queue record” with no subject ID or link.

## Locking and concurrency

When both records participate, all Phase 1C paths lock Visit before Queue Entry. The broader order is actor/security state → Patient where an existing Visit mutation requires it → Visit → Queue Entry → doctor security state → Panel when relevant → Queue counter. PostgreSQL separate-process regressions cover duplicate Send, first/existing counter allocation, branch/day independence, midnight reset, Call contention, priority/reassignment/security races, and cancellation races.

## Deferrals and production boundary

Hold/Resume is deferred because return-state, timing, and authority semantics are not approved. Phase 1C contains no `on_hold`, held timestamp, resume action, Queue delete, normal completion, Encounter, vitals, notes, diagnosis, treatment, prescription, dispensing, inventory, or billing.

Phase 1C ends when the Patient has been Called In and Queue Entry is Serving. Phase 2 begins with any clinical data or action. This synthetic development milestone is not approval for real-patient production; existing legal/privacy/infrastructure gates, operational training, isolated PostgreSQL CI, recovery workflow, polling load validation, and independent security review remain mandatory.

## Release verification

The isolated manual browser UAT completed 16 of 16 scenarios successfully. The accepted operator ratings were Yezza familiarity 4/5, Queue clarity 5/5, realtime smoothness 4/5, and operational speed 4/5, with two confusing steps noted. The operator assessed substantial-retraining readiness as “Partially” and smoothness against Yezza as “About the same”. The final Apply-button polling flicker was corrected and its manual recheck passed; no further Queue redesign was included in the release gate.

The release-gate PostgreSQL 18 run used a freshly migrated disposable database. The complete suite passed with 240 tests, 1,196 assertions, and two intentional Fortify feature skips; the dedicated Phase 1C separate-process Queue regression passed all 7 tests and 44 assertions with no skips. Independent Phase 1C security review and the local static/frontend quality gates are release requirements. These results establish a development milestone only: real-patient production approval remains explicitly not granted.
