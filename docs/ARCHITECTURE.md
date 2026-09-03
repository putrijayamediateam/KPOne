# Architecture

Phase 3A-Core adds `Clinical/Dispensary` and `Organisation/Inventory` inside the modular monolith. Clinical handoff and inventory remain separate aggregates joined only in the atomic completion transaction.

## Decision: modular monolith

KPOne is one Laravel deployment and one PostgreSQL database. Domain boundaries organise the code without introducing deployment boundaries:

- `app/Domain/Organisation`: organisations, branches, departments, and branch policy
- `app/Domain/Identity`: staff profiles, branch assignments, staff policy, and identity administration
- `app/Domain/Access`: permission catalogue and branch/staff scope services
- `app/Domain/Audit`: append-oriented records, recorder, and security-event listeners
- `app/Domain/Shared`: reserved for genuinely cross-domain primitives; it should not become a miscellaneous folder
- `app/Domain/Patient`: organisation-level Patient Master models, policy, number allocation, identity normalisation, directory, and administration
- `app/Domain/Visit`: branch operational Visits, Registration, doctor eligibility, Visit number allocation, directory, administration, and policy
- `app/Domain/Queue`: one-to-one branch Queue Entries, branch/day numbering, live projection, and Waiting/Serving transitions
- `app/Domain/Clinical`: one-to-one Clinical Encounters, vitals observations, diagnoses, longitudinal Allergy/Profile review and Problem List records, own-clinician policy, aggregate services, and minimized clinical projections

HTTP controllers translate requests and Inertia responses. They do not own scope rules. The application remains compatible with normal Laravel routing, service-container, Eloquent, policy, middleware, migration, and seeder conventions. No third-party modules framework is used.

## Runtime shape

The staff application uses Laravel session authentication, CSRF middleware, Inertia 3, and Vue 3. PostgreSQL 18 is the application database. Queues, event brokers, microservices, and Kubernetes are not part of Phase 0.

All persistent timestamps are written in UTC. Branches carry `Asia/Kuala_Lumpur` as their presentation timezone. Numeric primary keys are internal identifiers.

## Identity and branch context

`users` belongs to an organisation, not a branch. A user has one `staff_profile`, and that profile has zero or more dated `staff_branch_assignments`.

Each assignment records:

- branch
- primary/non-primary state
- assignment type
- `valid_from`
- optional `valid_until`

Effective assignment queries include only periods covering the current date. `BranchAccessService` is the single backend decision point for available branches, selected branch context, and branch visibility. Organisation-scoped staff may select any active branch in their organisation; branch-scoped staff may select only effectively assigned branches.

Runtime branch-assignment changes use `BranchAssignmentService`, including create, update, end, and primary-assignment operations. Synthetic local/testing seeders and test fixtures are a separate controlled bootstrap boundary: they may establish initial fictional state directly where using runtime services would add misleading operational audit history. Bootstrap access is not available through HTTP or other runtime application flows.

Runtime mutation services require a concrete authenticated actor and fail closed when one is not supplied. The development seeder is independently environment-guarded, and synthetic assignment fixtures use a test-autoloaded bootstrap helper; neither boundary is callable by an HTTP controller. For Phase 0B, a primary assignment is the staff member's stable home/base branch and is non-expiring while the account is active. Effective-dated temporary assignments are additional access only; scheduling a temporary operational primary or automatic fallback is not implemented.

Phase 0B staff administration is split across small application/domain services:

- `StaffDirectoryService` applies server-side staff visibility, filtering, and assignment projection.
- `StaffProvisioningService` owns the outer transaction for user, profile, roles, initial assignments, primary-branch establishment, and final audit evidence.
- `StaffAdministrationService` owns explicit profile, department, activation, and deactivation changes.
- `StaffRoleService` validates catalogue roles and the actor's effective authority before using Spatie role synchronisation.
- `BranchAssignmentService` remains the only runtime branch-assignment mutation boundary.

## Roles, permissions, and scope

Spatie stores roles and permissions in its standard tables. Permission names encode the protected capability and scope, for example:

- `staff.view.own`
- `staff.view.branch`
- `staff.view.organisation`

Roles are seeded bundles, not special cases in controllers. Routes use `RequireAnyPermission`; model-specific checks use policies; query visibility uses `StaffAccessService` and `BranchAccessService`. Vue receives the effective permission list only to present appropriate navigation.

Phase 0B reuses the existing `staff.manage.organisation` and `access.manage.organisation` permissions instead of creating redundant verbs. Full provisioning requires both. Profile/status changes require staff management; role and branch-access changes require access management. `StaffAuthorityService` compares effective administrative capabilities and scope coverage for both current targets and proposed roles. The director role is an explicit protected governance role because the Phase 0 catalogue intentionally gives director and technical administrator equivalent platform permissions; a non-director therefore cannot govern a director even though their permission sets otherwise match.

Future clinical permissions must be separate names and deliberate grants. `technical_admin` has platform-foundation administration permissions and no implicit clinical-content access.

## Patient Master

Patients belong to an organisation and deliberately have no branch owner. Branch context will belong to future registration and operational records, not canonical identity. Patient identifiers retain current and retired NRIC/passport history; the database reserves canonical values across both states. A row-locked organisation counter allocates immutable `KP-00000001` patient numbers inside the creation transaction.

`PatientAdministrationService` is the explicit actor, authorisation, normalisation, transaction, optimistic-locking, and structural-audit boundary. `PatientDirectoryService` performs bounded server-side search and constructs masked/minimised Inertia or JSON projections. Patient models are fully guarded and are never serialized directly to Vue.

## Patient Registration and canonical Visit

Phase 1B Registration creates a canonical branch-owned `Visit`; it does not create a separate Registration record. Patient identity remains organisation-owned, while branch provenance and operational status belong to the Visit. Organisation-wide, branch-independent `KPV-00000001` numbers use a locked counter inside the transaction.

`VisitRegistrationService` owns idempotency, stale branch-context checks, Patient locking, repeat-attendance review, doctor and Panel revalidation, number allocation, creation, and structural audit. Quick Patient creation calls the unchanged Phase 1A administration service inside the same outer transaction. `VisitAdministrationService` owns optimistic registered-only edit and final cancellation. `VisitDirectoryService` enforces active-branch and branch-timezone projections. `VisitDoctorEligibilityService` accepts active resident doctors with any currently effective assignment to the Visit branch, including temporary coverage.

Phase 1B Visit state remains only `registered` or `cancelled`. Consultation/OTC, priority, administrative reason, and provisional Self-pay/Panel intent are Visit attributes.

Phase 1C adds a one-to-one `QueueEntry` operational child for registered Consultation Visits. Queue Entry owns the branch/day numeric Queue number and `waiting`, `serving`, or `removed` state; Patient, assigned doctor, reason, coverage, and priority continue to resolve through Visit. `QueueEntryService` owns idempotent Send to Waiting and atomic Call In, `QueueDirectoryService` owns branch/own minimized snapshots and carry-over, and `QueueNumberGenerator` allocates under a branch/day counter lock. All combined mutations lock Visit before Queue Entry. Queue polling is a bounded private POST request approximately every three seconds while visible; no broker or persistent connection is introduced.

Phase 2A begins only from a registered Consultation whose Queue Entry is Serving. `ClinicalEncounterService` creates one `in_progress` Encounter per Visit and atomically saves one clinical note, one current vitals observation, and an ordered diagnosis list under one optimistic version. The attending clinician is snapshotted at Start and cannot be silently reassigned. Combined clinical mutations lock actor security state, then Visit, Queue Entry, Encounter, vitals, and diagnosis rows. Visit and Queue state remain unchanged.

Phase 2A stops before signing/finalization, addenda, handover, Treatment Plan, medication, completion, dispensing, inventory, billing, appointment, and patient-facing state.

Phase 2B.0 adds one lazy organisation-level Allergy Profile per Patient, append-only Profile versions, active/entered-in-error Allergy Records, one exact Profile-version review per current Encounter, and row-versioned active/resolved/entered-in-error Problem Records. Allergy status is never inferred as no-known from missing rows. All access is through the active attending doctor's current Serving care relationship; historical Encounter authorship does not grant current longitudinal access. Mutations extend the Phase 2A lock order from actor security state → Patient → Visit → Queue Entry → Encounter into Profile → ordered Allergy rows → Encounter review → ordered Problem rows. A future medicine aggregate may follow only after these locks and must revalidate the exact reviewed Profile version.

Phase 2B.0 still contains no Treatment Plan, medicine/service order, medication decision support, Dispensary, stock, billing, Visit completion, or Queue completion.

Phase 2B adds a separately versioned one-to-one Treatment Plan under the current Clinical Encounter, with relational medicine and service/procedure order children. Catalogue references are organisation-scoped; order identity fields are snapshotted so later catalogue changes do not rewrite history. Medicine mutation extends the existing clinical lock order through the Allergy Profile and exact Encounter review before locking the plan, order rows, and referenced catalogue rows. Persisted removals are explicit `withdrawn` transitions. Encounter, Visit, and Queue versions/states do not change merely because treatment is saved. Phase 2B has no fulfilment, stock, price, billing, signing, or completion state.

## Authentication

Fortify provides password authentication and reset flows. Public registration is disabled. Phase 0B removed the unused starter `CreateNewUser` action only after the internal `StaffProvisioningService` path, Fortify configuration, route inspection, and registration-negative tests proved that Fortify had no dependency on it. Authentication checks `users.is_active` before password validation, and authenticated requests pass through `EnsureActiveUser` to reject a session if the account is later deactivated.

Socialite provides Google OAuth architecture. The callback resolves the Google subject or normalised email to an already-created active KPOne user. Unknown, inactive, or mismatched identities are rejected; no account is created and no provider token is stored. A successful first link records only the stable Google subject.

## Audit and system events

`AuditRecorder` appends sanitised security/administration records. Model update and delete operations on `AuditLog` are blocked to keep the default application path append-oriented. Database administrators still require operational retention and tamper-monitoring controls in deployment.

Authentication and Spatie access events are observed centrally. Provisioning, identity/profile, activation, and branch-assignment services record their changes explicitly. Sensitive metadata keys such as password, token, secret, authorisation, and cookie are preserved for structural context, but their values are replaced recursively with `[REDACTED]` before persistence.

`system_events` is an integration-neutral foundation for later internal operational signals. Unlike immutable `audit_logs`, a system event has an explicit processing lifecycle represented by `processed_at`. Phase 0A has no runtime producer or processor, broker, or event-driven subsystem. Any future processing transition must use an explicit service and produce an audit record; replacing or deleting the originating event is not an ordinary application flow.

## Data model

Domain tables:

- `organisations`
- `branches`
- `departments`
- `users`
- `staff_profiles`
- `staff_branch_assignments`
- `audit_logs`
- `system_events`
- `patient_number_counters`
- `patients`
- `patient_identifiers`
- `panels`
- `visit_number_counters`
- `visits`
- `queue_number_counters`
- `queue_entries`
- `clinical_encounters`
- `encounter_vital_observations`
- `encounter_diagnoses`
- `medicine_catalogue_items`
- `clinical_service_catalogue_items`
- `treatment_plans`
- `treatment_plan_medicine_orders`
- `treatment_plan_service_orders`

RBAC tables:

- `roles`
- `permissions`
- `model_has_roles`
- `model_has_permissions`
- `role_has_permissions`

Laravel also owns framework tables for sessions, password resets, cache, jobs, failed jobs, and job batches.

## Coexistence

The current website and its website admin remain separate and production-active during migration. KPOne has no dependency on them in Phase 0A or Phase 0B. Yezza replacement and legacy data migration require later, explicitly approved architecture and reconciliation work.
