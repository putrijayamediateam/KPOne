# Security

## Data classification for Phase 0A

Phase 0A stores organisation structure and staff access metadata only. It must not store patient details, IC/passport values, clinical content, prescriptions, billing data, real staff seed data, credentials, or operational secrets.

Test and seed records use fictional names and `.test` addresses. Planning headcounts are documentation only.

## Authentication controls

- Laravel's stateful web guard and session cookies protect the staff application.
- CSRF middleware remains enabled for state-changing browser requests.
- Login is limited to five attempts per minute per normalised email/IP key.
- Google redirect/callback routes are limited to ten requests per minute.
- Password update is limited to six requests per minute and requires the current password.
- Public staff registration and self-service account deletion are disabled.
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

## Audit

The audit foundation records successful/failed login, logout, inactive-session rejection, denied permission checks, staff activation/deactivation, role changes, permission changes, branch assignments, and branch-context changes where applicable.

Audit metadata is explicitly selected by callers and sanitised recursively and case-insensitively for credential-like key variants. Sensitive keys remain visible with a `[REDACTED]` value. Never pass request headers, OAuth tokens, credentials, or request bodies wholesale. Audit access is itself organisation-scoped and permission-protected.

Application models reject audit/system-event update and delete operations, and no application route exposes those mutations. Where infrastructure permits, the production application database role should additionally be denied `UPDATE` and `DELETE` on `audit_logs`; database grants are intentionally deferred from Phase 0A. Production operations must also define retention, restricted database roles, backups, monitoring, and tamper-evidence appropriate to healthcare operations and Malaysian legal requirements before regulated data is introduced.

## Branch-assignment mutations

`BranchAssignmentService` is the supported boundary for create, update, end, and primary-assignment changes. Deletion is intentionally unsupported so historical assignment periods remain available. Mutations lock the parent staff-profile row inside a transaction; this serialises primary changes for the same staff member on PostgreSQL while retaining the normal SQLite test workflow. Every implicit demotion and explicit promotion receives its own before/after audit record.

## Reporting and review

Before adding regulated data or a new external integration, complete a focused threat model, data classification, retention decision, access matrix review, and recovery test. Security findings must be handled without adding real sensitive data to issues, logs, fixtures, or screenshots.
