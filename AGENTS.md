# KPOne agent instructions

These rules apply to the entire repository.

## Non-negotiable boundaries

- KPOne is independent. Do not modify, import code from, or add a runtime dependency on `MiniWeb_KlinikPutrijaya_Fullstack`.
- Never create, read, print, or commit a real `.env`. Work from `.env.example` placeholders only.
- Never add real patient data, staff personal data, employee names, private email addresses, phone numbers, credentials, tokens, or operational secrets.
- Use fictional `.test` identities in tests and local-only seeders.
- Do not build patient, registration, queue, appointment, clinical, diagnosis, prescription, dispensary, billing, inventory, HR workflow, finance workflow, website integration, Yezza, WhatsApp, OTP, or data-migration features until their phase is explicitly authorised.
- Do not commit or push unless the user explicitly asks after reviewing the diff.

## Architecture

- Keep a modular monolith and normal Laravel conventions.
- Place business rules under `app/Domain/{Organisation,Identity,Access,Audit,Shared}`.
- Do not add a modules framework, microservice, broker, Kubernetes configuration, or speculative infrastructure.
- Prefer Laravel first-party features. Add a dependency only when its value and security posture are clear.
- Use internal numeric primary keys. Add public identifiers separately only when an external contract requires them.
- Store timestamps in UTC; localise only at presentation boundaries.

## Access and security

- Treat roles and permissions as separate concepts.
- Name scoped permissions explicitly with `.own`, `.branch`, or `.organisation` suffixes.
- Enforce permissions and branch access in backend middleware, policies, and domain services. Frontend visibility is only a usability aid.
- Never grant a technical administrator clinical-content permission by implication. Future clinical permissions require an explicit role decision.
- Unknown Google identities must never create users. Social login may authenticate only an existing active account.
- Audit identity, access, activation, and branch-assignment changes without recording credentials, tokens, cookies, or secret fields.
- Preserve CSRF protection, session regeneration, rate limits, secure-cookie production settings, and inactive-session rejection.

## Data and migrations

- Multi-branch staff relationships belong in `staff_branch_assignments`; do not reduce them to a single `users.branch_id`.
- Runtime branch-assignment mutations must go through `BranchAssignmentService`. Controlled local/testing bootstrap seeders and test fixtures may write synthetic records directly when needed to establish initial state; that path is not an application mutation API.
- Add foreign keys and indexes deliberately. Avoid destructive cascades for organisation reference data.
- Keep audit records append-oriented; system-event state transitions must remain explicit and auditable.
- Reference staffing counts are documentation, not identities or generated user records.

## Quality gate

Before handoff, run backend tests, PHP formatting/static analysis, frontend type/lint/format checks, production build, migration/seed verification with dummy data, and `git diff --check`. Report failures honestly.
