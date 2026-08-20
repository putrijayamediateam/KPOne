# Architecture

## Decision: modular monolith

KPOne is one Laravel deployment and one PostgreSQL database. Domain boundaries organise the code without introducing deployment boundaries:

- `app/Domain/Organisation`: organisations, branches, departments, and branch policy
- `app/Domain/Identity`: staff profiles, branch assignments, staff policy, and identity administration
- `app/Domain/Access`: permission catalogue and branch/staff scope services
- `app/Domain/Audit`: append-oriented records, recorder, and security-event listeners
- `app/Domain/Shared`: reserved for genuinely cross-domain primitives; it should not become a miscellaneous folder

HTTP controllers translate requests and Inertia responses. They do not own scope rules. The application remains compatible with normal Laravel routing, service-container, Eloquent, policy, middleware, migration, and seeder conventions. No third-party modules framework is used.

## Runtime shape

The staff application uses Laravel session authentication, CSRF middleware, Inertia 3, and Vue 3. PostgreSQL 18 is the application database. Queues, event brokers, microservices, and Kubernetes are not part of Phase 0A.

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

## Roles, permissions, and scope

Spatie stores roles and permissions in its standard tables. Permission names encode the protected capability and scope, for example:

- `staff.view.own`
- `staff.view.branch`
- `staff.view.organisation`

Roles are seeded bundles, not special cases in controllers. Routes use `RequireAnyPermission`; model-specific checks use policies; query visibility uses `StaffAccessService` and `BranchAccessService`. Vue receives the effective permission list only to present appropriate navigation.

Future clinical permissions must be separate names and deliberate grants. `technical_admin` has platform-foundation administration permissions and no implicit clinical-content access.

## Authentication

Fortify provides password authentication and reset flows. Public registration is disabled. `CreateNewUser` remains unwired starter-kit residue: registration is absent from Fortify's enabled features and no create-user action is registered. It must not be treated as a staff provisioning path or wired without a reviewed provisioning design. Authentication checks `users.is_active` before password validation, and authenticated requests pass through `EnsureActiveUser` to reject a session if the account is later deactivated.

Socialite provides Google OAuth architecture. The callback resolves the Google subject or normalised email to an already-created active KPOne user. Unknown, inactive, or mismatched identities are rejected; no account is created and no provider token is stored. A successful first link records only the stable Google subject.

## Audit and system events

`AuditRecorder` appends sanitised security/administration records. Model update and delete operations on `AuditLog` are blocked to keep the default application path append-oriented. Database administrators still require operational retention and tamper-monitoring controls in deployment.

Authentication and Spatie access events are observed centrally. Activation and branch-assignment services record their changes explicitly. Sensitive metadata keys such as password, token, secret, authorisation, and cookie are preserved for structural context, but their values are replaced recursively with `[REDACTED]` before persistence.

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

RBAC tables:

- `roles`
- `permissions`
- `model_has_roles`
- `model_has_permissions`
- `role_has_permissions`

Laravel also owns framework tables for sessions, password resets, cache, jobs, failed jobs, and job batches.

## Coexistence

The current website and its website admin remain separate and production-active during migration. KPOne has no dependency on them in Phase 0A. Yezza replacement and legacy data migration require later, explicitly approved architecture and reconciliation work.
