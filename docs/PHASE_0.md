# Phase 0

## Phase 0A objective

Build the trustworthy platform boundary before operational healthcare modules: organisation structure, staff identity, multi-branch context, scoped RBAC, authentication, audit, and a professional application shell.

## Delivered in this phase

- Organisation: Klinik Putrijaya
- Branches: `CHERAS`, `SUNGAI_BESI`, `PUCHONG`
- Departments: Clinical, Clinic Operations, Leadership, Panel, Finance, Business Development, Technology, HR / Management, Marketing
- Roles: `director`, `resident_doctor`, `ca`, `ca_supervisor`, `panel_officer`, `finance_officer`, `business_development`, `marketing`, `hr_manager`, `technical_admin`
- Explicit own/branch/organisation permissions
- Dated primary/non-primary staff branch assignments
- Password staff login, inactive-account rejection, and future Google SSO linkage
- Responsive application shell and requested foundation pages
- Append-oriented audit and system-event tables
- Automated security, access-scope, assignment, and audit tests

## Phase 0B identity administration closeout

Phase 0B adds an operational staff directory, transactional internal provisioning, staff detail/access review, permitted profile and department changes, audited multi-role management, effective-dated branch assignment management, and account activation/deactivation. It does not add a public registration path or any patient/clinical module.

Active staff provisioning and reactivation require exactly one currently effective primary branch. Runtime branch changes remain owned by `BranchAssignmentService`. Role, access, and status mutations compare the target's current effective administrative authority with the actor, and non-directors cannot govern protected director accounts.

See `docs/PHASE_0B.md` for the service, transaction, audit, and residual-risk details.

## Planning headcounts

These counts are planning reference data only. They do not create user records and must not be used to infer real identities:

| Location | Planning baseline |
| --- | --- |
| Cheras | 3 resident doctors and 10 CA |
| Sungai Besi | 2 resident doctors and 5 CA |
| Puchong | 2 resident doctors and 5 CA |
| Shared / HQ | 2 directors, 2 panel, 2 finance, 3 business development including 2 developers, 1 manager/HR, and 1 marketing designer |

## Explicitly out of scope

Phase 0A and Phase 0B do not implement or model:

- patient records, IC/passport storage, or patient registration
- queue, appointments, clinical records, diagnosis, or prescribing
- prescriptions, dispensary, billing, or inventory
- HR or finance workflows
- website integration or website administration replacement
- Yezza integration, Yezza replacement, or migration from Yezza
- WhatsApp, OTP, or patient messaging

The current website remains separate, and its current website admin remains in production throughout this migration stage.

## Remaining Phase 0 work

Items to address in a separately reviewed follow-up include production deployment/runbook design, production PostgreSQL role/backup policy, formal staff approval/delegation policy, forced first-login password rotation or an invitation/reset workflow, wider access-control catalogue mutation UX, Google OAuth credential onboarding, audit retention/export rules, and a formal threat model before any regulated module begins.

None of those items authorises a later-phase operational module.
