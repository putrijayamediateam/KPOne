# Phase 1B — Patient Registration

## Objective and operational workflow

Phase 1B turns an organisation-level Patient Master identity into a branch-owned operational Visit. Registration is the operation that creates the Visit; there is no separate one-to-one `registrations` table. The canonical relationship is Patient → Visit, and the same Visit is intended to remain the backbone for later Queue Entry, Clinical Encounter, orders, dispensing, and billing without duplicating attendance identity.

The implemented Klinik Putrijaya workflow is patient-search-first: open Registration, search and select an existing Patient Master record or quick-create one through the Phase 1A service, complete the compact Visit form, and register. The console defaults to the active branch and current branch-local day.

## Operational Familiarity First

The Registration work area retains familiar Yezza concepts—Registration, Consultation/OTC, Doctor, Visit reason, Self-pay/Panel, urgency, a prominent Register action, dense rows, and clear status badges—without copying Yezza. KPOne reduces transitions and repeated entry, uses server-backed search, conditional fields, defaults to Consultation/Normal/Self-pay, and supports keyboard-friendly native controls. Speed and clarity take precedence over decorative dashboard presentation.

## Patient and Visit ownership

Patients remain organisation-level canonical identities and have no branch owner. Visits are branch operational records. The browser cannot choose `organisation_id` or `branch_id`; `BranchAccessService` derives the active branch from the authenticated actor and effective authority. `expected_branch_id` is only a stale-context guard and never selects or authorises another branch.

Visit numbers are immutable, organisation-wide, branch-independent `KPV-00000001` identifiers. A conflict-safe counter insert followed by a row lock allocates each number inside the registration transaction. Visit numbers are not Patient numbers, Queue numbers, or database IDs.

## Visit rules

Phase 1B has two extendable string statuses: `registered` and `cancelled`. Registered Visits may be edited or cancelled with optimistic `lock_version` checks. Cancelled Visits are immutable, cannot be reopened, and cannot be deleted.

Visit types:

- Consultation requires a currently eligible doctor and a non-empty administrative Visit reason.
- OTC permits a null doctor and null reason. OTC records no medication, prescribing, dispensing, inventory, or future clinical authority.

Visit reason and cancellation reason are capped at 500 characters. They are operational administration text, not clinical documentation. The console receives at most an authorised bounded Visit-reason excerpt.

Priority is `normal` or `urgent` and is independent of status. Phase 1B only records and audits it. Phase 1C will define Queue ordering; no pinning, FIFO, waiting, call-in, serving, or hold state exists here.

## Doctor eligibility

`VisitDoctorEligibilityService` requires the doctor to be active, in the same organisation, have a StaffProfile, hold `resident_doctor`, and have a currently effective assignment to the Visit branch. Primary and temporary coverage assignments are equally eligible. Registration and edit revalidate the doctor inside the transaction while locking the doctor user, StaffProfile, and assignment rows so concurrent deactivation, role, or branch-assignment changes produce a serialized valid outcome.

## Provisional coverage and Panels

Coverage is provisional intent: `self_pay` or `panel`. Self-pay stores no Panel or member reference. Panel coverage requires an active same-organisation Panel, snapshots its name, and may store a member/staff reference. Phase 1B has no Panel CRUD UI, eligibility integration, claims, `patients.panel_id`, or patient-panel membership. Normal operation works with zero configured Panels because Self-pay always remains available. Production reference Panels require controlled setup outside this phase.

Future billing and dispensing must reconfirm coverage/payment and may not treat Phase 1B intent as a final financial decision.

## Idempotency and repeat attendance

Each registration form receives a server-generated UUID. The database uniquely reserves `(organisation_id, idempotency_key)`. A committed-key retry resolves to the same Visit and creates no extra Patient, Visit, number allocation, or `visit.created` audit evidence.

Idempotency is separate from legitimate repeat attendance. The system does not constrain Patient/date uniqueness. For the same Patient, branch, branch-local day, and an existing non-cancelled Visit, registration returns a soft warning and requires explicit confirmation. Locking the Patient serializes this decision across concurrent CAs. The same Patient may attend different branches without exposing one branch's Visit detail to another active branch context.

## Transaction and lock ordering

Quick Patient creation calls the unchanged `PatientAdministrationService` inside the outer registration transaction. Laravel nested transactions share the connection boundary, so a late Visit failure rolls back the Patient, identifiers, both number counters, Visits, and Patient/Visit audits.

Phase 1B uses this deterministic order where the resource exists: actor user, StaffProfile, assignments, and role/security state → existing Patient → prior Visit rows or target Visit → doctor user → doctor StaffProfile and assignments → Panel → organisation Visit counter → Visit insert. The quick-Patient path has no Patient row to lock initially, so the unchanged Phase 1A service allocates its Patient counter and inserts the new Patient immediately after actor state; Phase 1B then follows the same Patient/Visit order. Update/cancel use actor state → Patient → Visit, then optional doctor/Panel. This aligns with existing staff mutations and avoids contradictory Visit-path ordering.

## Branch timezone and directory

Persistent timestamps remain UTC. Current-day console scope, repeat warning, eligibility date, and operational date bounds use the branch timezone (`Asia/Kuala_Lumpur`). Tests cover the Malaysia midnight boundary. Registration search is POST-only, server-filtered, limited to 25 rows per page and a maximum inclusive 31-day range, and escapes LIKE metacharacters.

The console projection includes only Visit/Patient numbers, Patient name, Visit type, branch-local registration time, bounded reason excerpt, doctor name, coverage label, priority, and status. It excludes identity documents, phone, email, address, Panel member reference, normalized fields, database IDs, and clinical data.

## Authorisation, privacy, and audit

Permissions are `visits.view.branch`, `visits.create.branch`, `visits.update.branch`, and `visits.cancel.branch`. Director, CA, and CA Supervisor receive all four; resident doctor receives view only; technical and non-operational roles receive none. Patient permissions never imply Visit permissions.

Visit binding and policy checks require the same organisation, exact permission, active branch context, branch authority, and registered status for mutation. Cross-branch probes return 404. Frontend visibility is only a usability aid.

Sensitive responses retain private/no-store headers and encrypted Inertia history. Laravel `dontFlash` protects patient search, quick Patient fields, Visit/cancellation reasons, and Panel member references. Audit events are structural: `visit.created`, `visit.details.updated`, `visit.doctor.changed`, directional priority events, `visit.coverage.changed`, and `visit.cancelled`. Metadata never contains Patient identity, Patient/Visit number, doctor identity, Visit/cancellation reason, member reference, raw requests, or exception details. Global Audit Logs render Visit subjects neutrally as “Visit record” with no subject ID, preventing Technical Admin disclosure.

## Phase boundary and production readiness

Phase 1B contains no Queue Entry/number/state, appointment, vitals, clinical note, diagnosis, treatment plan, prescription, medication authorisation, dispensing, inventory, billing, invoice, payment, claim, family/dependent, QR, OTP, patient portal, My KP, or Yezza integration. Phase 1C starts the Queue domain.

OTC is an attendance classification only. Any future OTC medication workflow must introduce explicit medication/prescribing/dispensing authority and may not infer it from Visit type or technical administration.

Phase 1B browser UAT accepted Registration with Yezza familiarity 4/5, visual clarity 4/5, overall perceived speed 4/5, and Patient search perceived speed 3/5. Patient search latency is non-blocking for this development milestone and must be re-measured in a staging or production-like PostgreSQL/runtime environment before further optimisation is considered.

Synthetic implementation is not approval for live patient operations. Before production, complete the Phase 1A privacy/legal gate plus Registration access review, Panel reference governance, downtime/recovery workflow, audit retention and monitoring, operational training, concurrency execution on isolated PostgreSQL CI, and a focused independent security review.
