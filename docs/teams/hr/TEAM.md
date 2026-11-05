# HR builder team (KPOne HR)

Status: **set up on 2026-11-05 by the coordinator (B.E.R.N.I.S.S) on the owner's instruction.** This folder is
the team's memory: what is built, what was decided, the rules, and what to build next. The team reads it before
every task and updates it in the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Liyana** | Plans each piece, builds it, keeps this file and `HR-PLAN.md` current |
| Reviewer | **Suresh** | Checks every piece against the rules, the tests, privacy and the decisions below before hand-off |
| Team designer | **Hui Min** | Sketches each screen from `docs/design/GUIDE.md` before Liyana builds it, then runs `docs/design/CHECKLIST.md` on the screenshots |

All three are Claude builder roles working for the owner through the coordinator session. Names chosen by the
coordinator (2026-11-05). Hui Min works under Daniel, the Lead UI/UX Designer.

## 2. What this area covers

**KPOne HR**: the clinic's own HR system, replacing the outside HR and payroll product the clinic uses today,
built slice by slice. Staff accounts, roles and branch assignments (Phase 0B) stay where they are and are reused;
HR never creates, activates or changes an account, role or branch assignment by itself.

**In scope (the team builds and maintains):** roster and shifts, leave, attendance and overtime, employee
records and documents, staff credential expiry, staff expense claims, payroll and payslips, announcements and
memos, the HR dashboard, appraisal, letters and discipline records, and the clinic compliance items in section 9.

**Shared with other teams (change only through a small agreed change, told to the coordinator first):**
- Staff accounts, provisioning and branch assignments (`StaffProvisioningService`, `BranchAssignmentService`,
  `StaffAuthorityService`): Phase 0B code, no team owns it; HR only reads it.
- Clinician credentials (DOC-02a, professional name and MMC number): Director-only verification stays unchanged.
- Clinic management hub and the shared shell: Staff Experience team.
- Doctor room choice, Call In and registration doctor eligibility: Queue & TV and Patients & Registration. HR does
  not gate clinical work (see decision 4).
- Careers website content (W1b): website programme; HR does not collect applications through it.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Domain | `app/Domain/HR/*` (new: services, models, policies) |
| Pages | `resources/js/pages/HR/*` |
| Controllers | `app/Http/Controllers/HR/*` |
| Tests | `tests/Feature/HR/*`, `tests/Frontend/hr-*.test.mjs` |

## 4. Permissions

New permissions use the `hr.` prefix and explicit `.own` or `.organisation` scope, are added by additive migrations
(the R1-02 pattern: `firstOrCreate`, give only what is missing, a missing role logged and skipped, `down()` a
no-op) and are mirrored in `PermissionCatalogue`. None of them joins the declared people-and-access authority set
(`PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS`); HR permissions never let anyone change accounts, roles
or branch access. Default holders unless a slice decision says otherwise: Director and HR Manager manage; Director
approves; each human staff member sees only their own records. Technical Admin, Administrator, Marketing, Business
Development, Finance and the TV account get no HR data by implication. Server checks are the authority.

## 5. What is built

| Slice | What it did | PR |
|---|---|---|
| (none yet) | | |

## 6. Decisions (never ask again)

Decisions marked **B.E.R.N.I.S.S for the owner** were made by the coordinator under the owner's delegation of
2026-11-05 ("for now, BE ME") and are on the owner's board for review; the owner may change them.

1. KPOne HR is authorised as a programme, built one slice at a time; each slice gets a design note approved before
   building and a "ship it" before its PR (owner, 2026-11-05: "i want all the things inside it and can be kpone
   mysyarikat").
2. On screen it is called **KPOne HR**; it does not copy the outside product's name, logo, screens or wording
   (B.E.R.N.I.S.S for the owner).
3. Who manages: Director and HR Manager manage; the Director approves rosters and HR Manager decisions that affect
   pay; nobody approves their own request (leave, claim, overtime, correction); each staff member sees their own
   (B.E.R.N.I.S.S for the owner, following `docs/reports/ANALYSIS-HR.md`).
4. HR never blocks clinical work: a missing or wrong roster, leave or credential does not stop registration, Call
   In, consultation or document issue. A later clinical link would be warnings first, by separate decision
   (B.E.R.N.I.S.S for the owner).
5. Reminders and alerts are inside KPOne only, worked out when a permitted page is opened. No WhatsApp, SMS, email,
   OTP or push notification (AGENTS.md; B.E.R.N.I.S.S for the owner).
6. Clock-in is a button in KPOne on the staff member's own signed-in account, with manual corrections approved by
   someone else. No selfie or face photo, no continuous location. A branch geofence check may be added later only
   after a privacy (PDPA) review (B.E.R.N.I.S.S for the owner; the clinic HR proposal asked for geofence and selfie:
   **owner to review**).
7. Payroll is built inside KPOne (the owner asked for everything), late in the order, with every statutory rate
   and table (EPF, SOCSO, EIS, PCB/MTD, HRD) entered and versioned by the clinic, never hard-coded, and marked
   "to confirm with the clinic's accountant" before real use. KPOne makes no payment; it can export a reviewed
   file (B.E.R.N.I.S.S for the owner; the HR analysis had advised keeping payroll outside: **owner to review**).
8. Locums are HR-managed worker records without a KPOne login; there is no outside locum portal, OTP or WhatsApp
   broadcast (AGENTS.md: unknown identities never create users; messaging unauthorised) (B.E.R.N.I.S.S for the
   owner; **owner to review**).
9. Sensitive fields (IC or passport number, bank account, salary, emergency contact, health and discipline notes,
   uploaded documents) are encrypted at rest, kept out of the staff directory and audit records (field names only,
   never values), and shown only to permitted holders (B.E.R.N.I.S.S for the owner).
10. Overtime follows the clinic HR proposal (normal day 1.5x, rest day 2x, public holiday 3x, 45-hour week) as
    clinic-editable settings, **to confirm with the clinic's HR adviser or accountant** before real pay use
    (B.E.R.N.I.S.S for the owner).
11. Synthetic data only; nothing here approves real staff data or go-live (unchanged project rule).
12. HR-1 design note approved; Daniel gets an early look and his screenshot review still applies before the PR
    (B.E.R.N.I.S.S for the owner, 2026-11-05).
13. Roster self-approval: whoever sends a week for approval cannot approve it, even a Director. The HR Manager
    drafts and sends, the Director approves; a Director-drafted week is sent by someone else or approved by a second
    Director (B.E.R.N.I.S.S for the owner, 2026-11-05; "Check this" on the owner's board for a one-Director clinic).
14. HR-1 defaults: sessions Morning, Afternoon, Evening, Night, Full day, Other; 16-hour maximum shift; minimum
    staffing for Resident Doctor, CA and CA Supervisor; no travel buffer between branches; CA Supervisors do not see
    the whole branch roster in HR-1 (a branch view is the possible follow-up HR-1b) (B.E.R.N.I.S.S for the owner,
    2026-11-05).
15. Existing-assertion change approved: the `ClinicManagementTest` hub destination map gains `/hr/roster` for
    `hr.roster.manage.organisation`, `hr.roster.approve.organisation` and `hr.roster.view.own` (B.E.R.N.I.S.S for
    the owner, 2026-11-05).
16. A clinic compliance slice (clinic document licences, internal audit checklist, vaccine fridge temperature log)
    joins the backlog as HR-11, after HR-10 (B.E.R.N.I.S.S for the owner, 2026-11-05; "Check this" on the owner's
    board because it is clinic data, not staff data).

## 7. Open items and known limits

- Every statutory and policy question in `docs/reports/ANALYSIS-HR.md` section 8 is open and is for the clinic's
  accountant, HR adviser or lawyer, not the team.
- The clinic HR proposal's WhatsApp, OTP, locum portal and MySyarikat-integration items are not authorised.
- A privacy (PDPA) review is needed before real staff data and before any location check at clock-in.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md`, `docs/design/GUIDE.md`, this file and `HR-PLAN.md` first.
- Never open, print or edit `.env`. Synthetic `.test` identities only.
- Business rules live in `app/Domain/HR` services and policies, never only in controllers or Vue.
- Every state-changing operation: backend permission and scope, `DB::transaction`, row locks, `lock_version`,
  idempotency key where retries are possible, and an audit record (ids and field names, never values or secrets).
- Times are stored in UTC with the branch time zone recorded; dates for a branch use the branch time zone (never
  the UTC clock in tests either: BASELINE section 6 item 21).
- Append-only history for approvals, ledgers (leave balance, payroll runs) and corrections; never edit or delete an
  approved record, add a reversing one.
- Do not change any existing test assertion without approval; list each one first.
- Migrations for this team use timestamps from `2026_11_10_000100` onward (other teams own other ranges).
- Design: Hui Min sketches before any visible change and runs the checklist; every new page or changed layout goes
  to Daniel through the coordinator (`docs/design/REVIEW.md`). A "Not OK" from Daniel blocks the merge unless the
  owner accepts it as debt.
- Full gate before hand-off on a task-owned PostgreSQL 18 database (verify host, port and name first): backend
  tests, Pint, PHPStan, frontend types, lint, format, build, frontend tests, `migrate:fresh --seed`,
  `git diff --check`; screenshots at 390 and 1440 px, light and dark, for every screen touched.
- PHPStan stopgap if the session hook did not install it: `git clone -q --depth 1 --branch 2.2.8
  https://github.com/phpstan/phpstan.git /tmp/phpstan-src && mkdir -p vendor/phpstan/phpstan && cp -r
  /tmp/phpstan-src/{phpstan,phpstan.phar,bootstrap.php,conf} vendor/phpstan/phpstan/`, then
  `kphp -d memory_limit=2G vendor/phpstan/phpstan/phpstan analyse --memory-limit=2G --no-progress <changed files>`.
- Independent review by the Reviewer (a subagent given the diff, this file and the rules) before hand-off,
  including a privacy pass.
- Backup push: if you stop to wait with unpushed work, commit and push to your own feature branch (no PR, no merge,
  no force-push, never `main`). Commit, push and PR only after "ship it"; never merge. Never edit
  `docs/BASELINE.md`; the coordinator records BASELINE. Record every new decision in section 6 and every finished
  piece in section 5, in the same PR.

## 9. Backlog (in order; each needs its design note approved)

1. **HR-1 Roster planner (start now).** Weekly roster per branch for staff who have KPOne accounts: shifts with
   start and end (overnight allowed), breaks, session type and optional proposed room; HR Manager or Director
   drafts, the Director approves a version; changes after approval make a new version; overlapping shifts for one
   person across branches are refused; a shift outside the person's effective branch assignment is refused; each
   staff member sees their own approved shifts ("My roster"); minimum staffing per branch and session as a
   setting, with a shortfall warning on the planner. Read-only for clinical screens (no gating).
2. **HR-2 Leave (e-Leave).** Leave types and entitlements as clinic settings (versioned), opening balances entered
   by HR, apply, approve or reject by someone else, cancel, an append-only balance ledger, branch public-holiday
   calendars, private evidence upload (for example an MC), approved leave shown as unavailable on the roster.
3. **HR-3 Attendance and overtime.** Clock in and out on the own account, roster against attendance, forgotten
   clock-out, late and early flags, corrections approved by someone else, overtime requests and approval, the
   45-hour week warning, period summaries.
4. **HR-4 Employee records and documents.** Employment periods (start, type, probation, confirmation, end), job and
   reporting line history, emergency contact, encrypted personal and bank details, an encrypted document vault
   with approved categories, own read-only summary, onboarding and offboarding checklists that ask (never perform)
   account changes.
5. **HR-5 Credentials and compliance.** APC, BLS or ACLS, Hepatitis B and other clinic-chosen items with expiry and
   evidence reference; 60, 30 and 7-day reminders inside KPOne; resident doctor roster coverage rules and work-hour
   limits as warnings on the planner; SOP acknowledgement.
6. **HR-6 Staff claims.** Expense claims with receipt photos (sniffed, size-limited, private disk), approval by
   someone else, Finance marks paid with a reference; never mixed with patient Panel Claims.
7. **HR-7 Payroll.** Salary and allowance master (encrypted), monthly payroll runs from approved attendance,
   overtime, unpaid leave and claims, clinic-entered versioned statutory tables (EPF, SOCSO, EIS, PCB/MTD, HRD),
   review and Director approval, payslips each staff member sees in KPOne, a reviewed export file; no payment from
   KPOne; accountant confirmation required before real use.
8. **HR-8 Announcements and memos.** E-memo to all or chosen branches or roles, with read acknowledgement.
9. **HR-9 HR dashboard and reports.** Live staffing today per branch, shortfalls, leave and attendance summaries,
   monthly summary, staff cost (after HR-7); aggregates for the Director and HR Manager.
10. **HR-10 Appraisal, letters and discipline.** Appraisal cycles, letter templates (offer, confirmation, warning)
    with immutable issued copies, restricted discipline records; incident reports.
11. **HR-11 Clinic compliance.** Clinic document licences and expiry (clinic registration, X-ray, clinical waste,
    calibration), a monthly internal audit checklist with photos whose failed items become supervisor tasks, and a
    daily vaccine fridge temperature log with out-of-range alerts inside KPOne.
12. **Not authorised (needs the owner):** WhatsApp, SMS, email, OTP or push messages; an outside locum portal;
    selfie or continuous location at clock-in; integration with the outside HR product; applicant intake.
