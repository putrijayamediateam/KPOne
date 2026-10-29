# KPOne agent instructions

These rules apply to the entire repository.

## Non-negotiable boundaries

- KPOne is independent of `MiniWeb_KlinikPutrijaya_Fullstack`. Do not import MiniWeb code, access its database, or add a runtime dependency on its code, files or availability. The only permitted integration is the owner-approved publication of static website snapshots to a configured storage destination and the public website's configured Staff Login navigation to KPOne. The snapshot contract must remain independent of MiniWeb implementation code. Changes to the MiniWeb website are made in the MiniWeb repository under its own AGENTS.md, never from within a KPOne change.
- Never create, read, print, or commit a real `.env`. Work from `.env.example` placeholders only.
- Never add real patient data, staff personal data, employee names, private email addresses, phone numbers, credentials, tokens, or operational secrets.
- Use fictional `.test` identities in tests and local-only seeders.
- Do not build patient, registration, queue, appointment, clinical, diagnosis, prescription, dispensary, billing, inventory, HR workflow, finance workflow, website integration, Yezza, WhatsApp, OTP, or data-migration features until their phase is explicitly authorised. Website content remains private draft and review data except for the narrowly approved snapshot publication workflow. Only an authorised Director may explicitly publish a validated public JSON-and-images snapshot derived only from an approved immutable website release and frozen reviewed routing aliases, or restore a previous eligible publication. No other role may publish or restore; adding another publishing role requires a separate owner decision. Approval alone does not publish. Public website visitors must read static snapshot hosting only; do not create anonymous KPOne content APIs or public KPOne media-serving routes. Snapshots must exclude drafts, internal notes, source, approval, consent and rights references, staff-account data, patient or clinical data, operational records, secrets and doctor registration numbers. English output is initially permitted; existing English and Bahasa Melayu completeness requirements for release approval remain unchanged. Publishing credentials must remain server-side and restricted to the configured snapshot destination. This exception does not authorise MiniWeb code or data imports, new site-settings functionality, static-renderer website replacement, public intake, booking or feedback integration, upload-workflow migration, performance or analytics integration, Search Console integration, admin-user migration, shared authentication, legacy-admin retirement, infrastructure provisioning or production deployment. Those require separate explicit approval. The owner currently authorises PR 1 documentation and guardrails only; do not begin PR 2 or any implementation until separately instructed (see docs/WEBSITE_SNAPSHOT_CONTRACT.md and docs/BASELINE.md). Existing unrelated authorisations and restrictions remain unchanged. The owner-authorised WA-01 exception (2026-10-22) permits only a staff-initiated WhatsApp click-to-chat link using a patient's saved number; KPOne sends nothing and uses no WhatsApp integration or message storage. WhatsApp messaging, OTP and all other messaging features remain unauthorised.
- Do not commit or push unless the user explicitly asks after reviewing the diff.

## Architecture

- Keep a modular monolith and normal Laravel conventions.
- Place business rules under `app/Domain/{Organisation,Identity,Access,Audit,Patient,Visit,Queue,Clinical,Shared}`.
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
- Internal account creation must use `StaffProvisioningService`; public registration must remain disabled.
- Staff role, branch-access, and status mutations must reject self-escalation and targets with greater effective administrative authority. The protected director authority may not be governed by a non-director account.
- Active staff must retain exactly one currently effective primary branch. Future-only primary access is not a Phase 0B employment lifecycle.
- Bootstrap passwords, when used, are one-time administrator inputs: hash immediately and never audit, log, return, flash, or persist them as plaintext.

## Data and migrations

- Multi-branch staff relationships belong in `staff_branch_assignments`; do not reduce them to a single `users.branch_id`.
- Runtime branch-assignment mutations must go through `BranchAssignmentService`. Controlled local/testing bootstrap seeders and test fixtures may write synthetic records directly when needed to establish initial state; that path is not an application mutation API.
- Add foreign keys and indexes deliberately. Avoid destructive cascades for organisation reference data.
- Keep audit records append-oriented; system-event state transitions must remain explicit and auditable.
- Reference staffing counts are documentation, not identities or generated user records.

## Worktrees

- The served tree at `C:\Users\User\Herd\KPOne` stays checked out on `main`, always. UAT runs from a detached
  checkout of the exact candidate SHA or a dedicated disposable UAT branch, never from the served tree switched
  onto a feature branch.
- A branch checked out in the served tree must never also be a worktree target for merge work elsewhere. Why:
  on 2026-09-28, the served tree was left on a feature branch for UAT while that same branch advanced from
  commits made in a sibling worktree; the served tree's index never refreshed, which blocked a plain
  `git checkout` and cost three rounds of investigation to characterise as safe. Nothing was lost, but the
  convention exists so it costs nothing next time.

## Quality gate

Before handoff, run backend tests, PHP formatting/static analysis, frontend type/lint/format checks, production build, migration/seed verification with dummy data, and `git diff --check`. Report failures honestly.
