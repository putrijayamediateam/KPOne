# Security

## Data classification

Phase 0A stores organisation structure and staff access metadata. Phase 0B supports operational work identity, department, role, status, and branch-assignment administration. It must not store patient details, IC/passport values, clinical content, prescriptions, billing data, real staff seed data, plaintext credentials, or operational secrets.

Test and seed records use fictional names and `.test` addresses. Planning headcounts are documentation only.

Phase 1A introduces restricted patient identity and contact data in the schema, but development and automated testing remain synthetic-only. Patient identity documents, DOB, sex, contact, and address data are confidential healthcare administrative data. Search responses are masked and minimized; full details require a separate view permission. Patient permissions never imply future clinical-record access, and technical administrators receive none.

Phase 1B introduces branch Visit administration: Visit type, administrative reason, doctor assignment, priority, provisional coverage/member reference, registration/cancellation evidence, and branch provenance. Visit and Patient permissions remain separate. The Registration Console minimizes output, member references are never listed, and sensitive Visit text is never copied into audit metadata.

Phase 1C introduces branch Queue operations. Queue and Visit permissions remain separate, resident doctors receive only their own assigned Queue, and technical administrators receive none. Polling repeats the same explicit minimized projection and authorization on every private POST request; it is not a broader data channel.

Phase 2A introduces highly restricted clinical notes, vitals, and diagnoses. Only the snapshotted attending resident doctor receives own Encounter access, and every start/save revalidates active status, resident-doctor role, clinical permission, active branch, and effective primary or temporary assignment. Directors, Queue supervisors, CAs, and technical administrators receive no direct clinical permission. The initial history list is bounded and structural only. Full read-only cross-branch history is loaded on demand only for the same organisation-level Patient while the doctor owns a currently eligible care relationship, or for a historical Encounter the doctor authored; every detail request revalidates clinical role, permission, and current branch eligibility, and there is no general Clinical History search.

## Authentication controls

- Laravel's stateful web guard and session cookies protect the staff application.
- CSRF middleware remains enabled for state-changing browser requests.
- Login is limited to five attempts per minute per normalised email/IP key.
- Google redirect/callback routes are limited to ten requests per minute.
- Password update is limited to six requests per minute and requires the current password.
- Public staff registration and self-service account deletion are disabled.
- Internal account creation uses `StaffProvisioningService`; the unused Fortify `CreateNewUser` starter action has been removed and no registration feature or route is enabled.
- `is_active` is checked during password authentication and by the shared `web` middleware group, so existing sessions are rejected consistently across KPOne, settings, and Fortify-managed authenticated routes.
- Sessions regenerate after Google login and are logged out, invalidated, and issued a new CSRF token when an inactive session is rejected.
- Deactivation does not directly delete database-session rows. That would couple the domain service to one session driver; the shared middleware provides driver-safe revocation on the inactive user's next web request. Revisit central immediate revocation only if a supported multi-driver session registry is introduced.

Production must use HTTPS, a generated `APP_KEY`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_ENCRYPT=true`, an appropriate cookie domain, and `SESSION_SAME_SITE=lax` unless a reviewed integration requires otherwise.

## Google SSO rules

- Credentials exist only in environment configuration; `.env.example` contains empty placeholders.
- Unknown Google users never auto-register.
- First-time linking requires Google's verified-email claim and an exact normalised email match to an existing active KPOne user. The stable Google subject is the sole lookup identity after linking.
- A conflicting Google subject is rejected.
- Access and refresh tokens are never persisted.

## Authorisation

Route middleware checks coarse permissions, policies check model access, and domain services constrain branch/organisation queries. Hiding a navigation item is never considered an authorisation control.

Permissions have explicit `own`, `branch`, or `organisation` scope. New permissions are not inherited by an existing role unless a reviewed seed/migration change adds them. In particular, `technical_admin` must never receive future clinical-content access automatically.

Patient Master search is organisation-wide only for explicitly authorised operational roles because returning patients may attend any branch. It is not branch ownership. Search, view, create, update, and identifier-correction permissions are separate. Existing NRIC/passport retirement or replacement requires `patients.identifiers.manage.organisation`; normal CAs may only supply an initial identifier or add the first identifier of a type.

Visits require explicit `.branch` view/create/update/cancel permissions plus active branch context and effective branch authority. The server derives Visit ownership; `expected_branch_id` detects stale forms but cannot select another branch. Resident doctors are read-only. Technical and non-operational roles receive no Visit permission. Cancelled Visits are immutable and no Visit delete/reopen route exists.

Queue access requires explicit `queue.view.own`/`.branch`, `queue.enter.branch`, or `queue.call.own`/`.branch` permissions. Queue ownership is always active-branch-derived. Own scope is forced to the actor's assigned doctor ID server-side. Queue-number probing cannot bind a record, and Patient/Visit permission does not imply Queue access.

Clinical access requires explicit `encounters.view.own`, `encounters.start.own`, `encounters.update.own`, and separately gated `encounters.history.view.organisation`. Queue Call In authority never implies clinical access. Encounter route identity remains the branch-scoped Visit number, and the browser cannot control organisation, branch, Patient, clinician, status, timestamps, child ownership, positions, or clinical record version.

Phase 0B mutations also compare authority on the target, not only the actor's coarse route permission. Current target administrative permissions must be covered by the actor; proposed role capabilities must be covered at an equal or broader explicit scope. Director is a protected governance role, so a non-director technical administrator cannot change a director's roles, branch access, or status. Self role/access/status mutation is rejected.

Runtime identity/access mutation services require an explicit non-null actor and do not treat omission as system authorization. Role synchronization supplies that actor through a scoped, synchronous audit context so Spatie attach/detach events remain correctly attributed even outside an authenticated HTTP session; the context does not add duplicate summary events.

Active provisioning and reactivation require exactly one currently effective primary assignment. In Phase 0B this is the stable home/base branch, not a temporary operational-primary concept, and it remains non-expiring while the account is active. Temporary dated assignments are additional access. An active primary assignment cannot be ended or made future/expiring until another current non-expiring assignment is promoted. This avoids active accounts with ambiguous or absent default branch context.

## Audit

The audit foundation records successful/failed login, logout, inactive-session rejection, denied permission checks, staff creation, identity/profile/department changes, activation/deactivation, role changes, permission changes, branch assignments, and branch-context changes where applicable.

Audit metadata is explicitly selected by callers and sanitised recursively and case-insensitively for credential-like key variants. Sensitive keys remain visible with a `[REDACTED]` value. Never pass request headers, OAuth tokens, credentials, or request bodies wholesale. Audit access is itself organisation-scoped and permission-protected.

Visit audits contain structural state only. They exclude Patient identity and numbers, Visit numbers, doctor identity, Visit/cancellation reasons, Panel member references, raw requests, and exception details. Priority changes use directional events. Global audit projections identify only a neutral “Visit record” and suppress its subject ID, so technical audit access cannot become a Patient/Visit disclosure channel.

Queue audit projections likewise identify only a neutral “Queue record” and suppress the subject ID. `queue.entered`, `queue.called`, and `queue.removed` contain structural transition/version metadata only, never Patient identity, Queue number, reason, doctor identity, or cancellation reason.

Clinical audits identify only a neutral “Clinical record” and suppress the subject ID. `encounter.started` and `encounter.updated` contain state/version and changed-section names only. They never contain clinical-note text, diagnosis text/codes, vital values, Patient/Visit numbers, or clinician identity. Clinical request roots are excluded from validation flash data, clinical pages use private/no-store and encrypted Inertia history, and Queue polling/Patient search never carry clinical content.

`AuditLog` rejects model update and delete operations, and no application route exposes those mutations. `SystemEvent` has a different, explicit lifecycle: its `processed_at` field is intended for a future controlled processing transition, while replacement and deletion are not ordinary application flows. Phase 0A has no system-event producer, processor, or mutation route. A future processor must encapsulate the transition in a domain service and audit it before such functionality is enabled. Where infrastructure permits, the production application database role should additionally be denied `UPDATE` and `DELETE` on `audit_logs`; database grants are intentionally deferred from Phase 0A. Production operations must also define retention, restricted database roles, backups, monitoring, and tamper-evidence appropriate to healthcare operations and Malaysian legal requirements before regulated data is introduced.

## Branch-assignment mutations

`BranchAssignmentService` is the supported boundary for create, update, end, and primary-assignment changes. Deletion is intentionally unsupported so historical assignment periods remain available. Mutations lock the parent staff-profile row inside a transaction; this serialises primary changes for the same staff member on PostgreSQL while retaining the normal SQLite test workflow. Every implicit demotion and explicit promotion receives its own before/after audit record.

This boundary applies to runtime/domain mutations. Controlled local/testing bootstrap seeders and test fixtures may establish synthetic initial state directly when necessary; those code paths must remain environment-bound, non-routable, and visibly distinct from runtime mutation APIs.

## Bootstrap credentials

Phase 0B supports Google-only pre-provisioning with a null password and an optional strong administrator-entered bootstrap password. Password input is validated, hashed by the model cast immediately, excluded from audit metadata, never returned by the provisioning service as a value, and never placed in response props or flash/session data. Forced first-login rotation is not implemented in Phase 0B; production use of password provisioning therefore requires an operationally controlled transfer and reset procedure until a separately designed invitation/rotation lifecycle exists.

## Reporting and review

Before adding regulated data or a new external integration, complete a focused threat model, data classification, retention decision, access matrix review, and recovery test. Security findings must be handled without adding real sensitive data to issues, logs, fixtures, or screenshots.

Phase 1A intentionally does not use application field encryption. Compensating requirements are strict server authorisation, response masking/minimisation, no PII duplication in audit/application logs, encrypted transport, encrypted production database/storage/backups, and restricted database/backup roles. Field encryption must be reassessed before real-patient production rollout.

Phase 2A remains a development milestone. It lacks clinical signing/finalization, addenda, handover, completion, structured allergy/condition master data, legal retention approval, and clinical recovery validation. Real-patient production approval remains not granted.

Phase 2B.0 introduces structured Allergy and Problem data but remains a development milestone. Allergy/Problem permissions are resident-doctor-only and independent of Patient, Visit, Queue, history, Director, and Technical Admin authority. Current values are available only through an eligible in-progress Encounter care relationship. Cross-branch continuity stays within the same organisation and revalidates the active branch assignment or temporary coverage on every request. Wrong-doctor, cross-branch, and cross-organisation operational probes resolve without exposing clinical existence.

Allergy Profile mutations use exact optimistic versions and an append-only version ledger. Encounter review records only the exact reviewed Profile version; future medicine save must revalidate that the current version still matches. Allergy/Problem content is excluded from general operational projections, URLs, validation flash, and audit metadata. Global Audit Logs neutralize the clinical subject and suppress its ID. Phase 2B.0 does not provide medicine safety automation, terminology governance, prescribing/finalization, retention approval, or recovery approval; real-patient production approval remains not granted.

Phase 2B Treatment Plan permissions are resident-doctor-only and independent of every operational and other clinical permission. Every save and catalogue lookup revalidates current Serving care and immutable attending-clinician ownership. Medicine mutations fail unless `AllergyReviewGate` validates the exact current Profile version inside the same transaction; this is a review-freshness gate, not automated drug-safety advice. Catalogue search is POST-only, bounded, wildcard escaped, tenant scoped, and minimized. Order content is excluded from operational/history projections, URLs, validation flash, logs, and general audit metadata. Global Audit Logs neutralize the plan and actor and suppress the subject ID. Real-patient production approval remains not granted.
