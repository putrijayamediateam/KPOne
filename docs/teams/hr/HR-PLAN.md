# KPOne HR programme plan

Written by the HR builder team (Liyana, Lead; Suresh, Reviewer; Hui Min, designer) on 2026-11-05, from
`docs/teams/hr/TEAM.md`, `docs/reports/ANALYSIS-HR.md`, the clinic HR proposal (`docs/inputs/hr/`) and the
repository at `0b6e006`. **This plan authorises nothing by itself.** Each slice still needs its own design note
approved and a "ship it" before its PR (TEAM.md decision 1). Items marked **[TO CONFIRM]** are questions only the
clinic can answer; none of them blocks HR-1 unless it says so.

Words used below:
- **Staff member**: a person with a KPOne account (Phase 0B). HR never creates, activates or changes an account,
  role or branch assignment.
- **Manager**: Director or HR Manager (TEAM.md decision 3).
- **Own**: the signed-in person's own records, decided by the server, never by an id sent from the browser.
- **Encrypted**: Laravel's encrypted cast on the column (TEAM.md decision 9). Audit rows hold ids and field names
  only.

## 1. The programme at a glance

| # | Slice | What the clinic gets | Size | Depends on |
|---|---|---|---|---|
| HR-1 | Roster planner | Weekly roster per branch, Director approval, "My roster", minimum staffing warning | M | nothing |
| HR-2 | Leave (e-Leave) | Leave types, balances, apply and approve, public holidays, MC upload | L (split in two) | HR-1 (shows leave on the roster) |
| HR-3 | Attendance and overtime | Clock in and out, late and missed flags, corrections, overtime approval, 45-hour warning | L (split in two) | HR-1, HR-2 |
| HR-4 | Employee records and documents | Employment periods, job history, emergency contact, encrypted personal and bank details, document vault, onboarding and offboarding checklists | L (split in two) | nothing (can run beside HR-2) |
| HR-5 | Credentials and compliance | APC, BLS/ACLS, Hep B expiry with 60/30/7-day reminders, doctor coverage and work-hour warnings, SOP acknowledgement | M | HR-1 |
| HR-6 | Staff claims | Expense claims with receipt photos, approval, Finance marks paid | M | HR-4 (worker record) |
| HR-7 | Payroll | Salary master, monthly runs, clinic-entered statutory tables, payslips, export file | L (split in three) | HR-2, HR-3, HR-4, HR-6 |
| HR-8 | Announcements and memos | E-memo to branches or roles with "I have read this" | S | nothing |
| HR-9 | HR dashboard and reports | Who is on duty now, shortfalls, leave and attendance summaries, monthly summary, staff cost | M | HR-1 to HR-3 (cost after HR-7) |
| HR-10 | Appraisal, letters, discipline, incidents | Appraisal cycles, letters with issued copies, restricted discipline file, incident reports | L (split) | HR-4 |
| HR-11 | Clinic compliance | Clinic document licences and expiry, internal audit checklist with photos, vaccine fridge temperature log | M | nothing |

Rules that hold for every slice (from TEAM.md sections 4, 6 and 8):
- New permissions start with `hr.`, end in `.own` or `.organisation`, come in by additive migration and never join
  `PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS`.
- Technical Admin, Administrator, Marketing, Business Development, Finance and the TV account get no other person's
  HR data unless a slice says so on purpose (Finance in HR-6 and HR-7 only, for exactly the fields listed there).
- Every change: permission and scope checked on the server, `DB::transaction`, row locks, `lock_version`, an
  idempotency key where a retry is possible, and an audit row with ids and field names only.
- History is append-only. An approved record is never edited; a correction is a new record pointing at the old one.
- HR never blocks clinical work (decision 4). Reminders are inside KPOne only (decision 5).
- The existing `audit.view.organisation` (Technical Admin, HR Manager, Director) must not show HR values; HR audit
  rows carry ids and field names, so the existing Audit logs page shows nothing sensitive.

## 2. Slice by slice

### HR-1 Roster planner (first; design note in section 4)

**What the clinic gets.** One place to plan each branch's week: who works which shift, with breaks, the kind of
session and an optional proposed room. The HR Manager or Director drafts the week and sends it for approval; the
Director approves it. After approval a change makes a new version, so the approved week stays visible until the new
one is approved. Each staff member sees their own approved shifts under "My roster". A minimum staffing setting per
branch, session and role shows a "Short" warning on the planner.

**Screens.** One page, `/hr/roster`, with tabs: **My roster** (every human staff member), **Planner** (managers),
**Minimum staffing** (managers). One link in Clinic management, group "Staff & branches": "KPOne HR roster".

**Tables (sketch).**
- `hr_roster_weeks`: organisation, branch, `week_start` (a Monday, branch-local date), the current approved version
  id (nullable), the open draft version id (nullable), `lock_version`. Unique per branch and week.
- `hr_roster_versions`: roster week, version number, status (`draft`, `submitted`, `approved`, `superseded`,
  `discarded`), created by, submitted by and at, decided by and at, return reason code, `lock_version`. At most one
  open (draft or submitted) and one approved version per week (PostgreSQL partial unique indexes). An approved,
  superseded or discarded version never changes (trigger).
- `hr_roster_shifts`: version, organisation, branch, staff user, `starts_at` and `ends_at` (UTC, end after start, at
  most 16 hours [TO CONFIRM]), branch time zone at save time, `break_minutes` (0 to 180, less than the shift),
  `session_type` (controlled list), proposed `branch_room_id` (nullable, same branch, foreign key). Shifts of a
  non-draft version cannot change (trigger).
- `hr_roster_actions`: append-only event list (created, shift added, changed, removed, copied, submitted, returned,
  approved, discarded) with actor and time; update, delete and truncate refused.
- `hr_roster_requests`: idempotency key, payload hash, result id (one row per retried action).
- `hr_minimum_staffing`: organisation, branch, session type, staff role, minimum count (0 to 20), `lock_version`;
  plus append-only `hr_minimum_staffing_changes`.

**Permissions.**

| Permission | Holders |
|---|---|
| `hr.roster.manage.organisation` (draft, edit, submit, copy, discard, minimum staffing) | Director, HR Manager |
| `hr.roster.approve.organisation` (approve or return a submitted week) | Director |
| `hr.roster.view.own` (My roster) | every human role: Director, Resident Doctor, CA, CA Supervisor, Panel Officer, Finance Officer, Administrator, Business Development, Marketing, HR Manager, Technical Admin. **Not** the TV account. |

Only managers see other people's shifts. CA Supervisors do not see the whole branch roster in HR-1 (a branch view
would be a separate `hr.roster.view.branch` decision).

**Rules the server checks (HR-1).**
1. A shift's person must be an active staff account in the organisation, not the TV account.
2. The person must hold an effective assignment to that branch for every branch-local date the shift touches
   (`staff_branch_assignments`, `valid_from` to `valid_until`). Otherwise the shift is refused with "Name is not
   assigned to Cheras on 9 Nov 2026." HR does not create or extend assignments.
3. One person cannot have two overlapping shifts, in any branch, among: the same draft, and the current approved
   version of every other week and branch. Intervals are start-inclusive, end-exclusive, so 08:00-14:00 and
   14:00-20:00 do not overlap. Overlap with another branch's *draft* is a warning, not a refusal (that draft may
   change). Approval re-checks everything under lock and refuses if anything became invalid.
4. A shift belongs to the week of its branch-local start date. Overnight is allowed (end the next day).
5. A proposed room must be an active room of the same branch. Two people proposed in the same room at the same time
   is a warning ("Room 2 is proposed for two people at 9:00 AM"), because dispensary and treatment rooms are shared.
6. The approver cannot be the person who submitted that version (decision 3: nobody approves their own request).
   The HR Manager drafts and sends, the Director approves; a Director-drafted week is sent by someone else or
   approved by a second Director (TEAM.md decision 13).
7. Approving supersedes the previous approved version of that week in the same transaction.
8. Minimum staffing: for each branch-local day, session type and role, the planner counts shifts in the version
   being viewed and shows "Short: 1 CA" if below the minimum. Warning only; it never blocks submission or approval
   (TEAM.md backlog: "a shortfall warning on the planner").

**Possible small follow-up HR-1b (owner to choose):** the CA Supervisor sees the approved roster of their
branches (a read-only `hr.roster.view.branch`, names, times and sessions only); shift swaps between two staff
approved by a manager.

**What stays out of HR-1.** Clock-in, attendance, overtime, pay rates, leave, shift swaps between staff, recurring
patterns (only a manual "Copy last approved week"), locums without accounts, Google Calendar sync, any change to
doctor room choice, Call In or registration eligibility, any message or notification, any write to accounts,
roles or branch assignments.

**Questions only the clinic can answer.**
- [TO CONFIRM] The clinic's session names and usual times. Default list: Morning, Afternoon, Evening, Night,
  Full day, Other (labels only; times are typed per shift).
- [TO CONFIRM] The longest allowed shift. Default 16 hours.
- [TO CONFIRM] Minimum staffing per branch and session (the proposal's example: 1 doctor and 2 CAs). Default: no
  minimums until HR enters them.
- [TO CONFIRM] Travel buffer between branches. Default none (back-to-back shifts at different branches are allowed).
- [TO CONFIRM] Which roles are rostered. Default: anyone with an account may be rostered; the minimum staffing
  setting covers Resident Doctor, CA and CA Supervisor only.
- [TO CONFIRM] Who is the named person who checks the roster page (no messages are sent).

### HR-2 Leave (e-Leave)

**What the clinic gets.** Staff apply for leave in KPOne; someone else approves; balances are always right because
every change is a ledger line; public holidays are per branch (state); approved leave shows on the roster as
"On leave" and blocks new shifts on those dates for that person (warning on existing approved shifts).

**Screens.** "My leave" (balances, apply, cancel, history); "Leave approvals" (pending list, approve or reject with
reason code); "Leave settings" (types, entitlements by policy version, public holidays per branch and year,
opening balances entry).

**Tables (sketch).** `hr_leave_types`, `hr_leave_policy_versions` (entitlement days per type, by role or service
band, carry-forward rule, unit: whole or half day), `hr_public_holidays` (branch, date, name), `hr_leave_requests`
(person, type, start and end dates and half-day flags, days charged, status, `lock_version`),
`hr_leave_request_actions` (append-only), `hr_leave_ledger` (append-only: opening, accrual, taken, reversal,
adjustment with reason code), `hr_leave_evidence` (encrypted private file, sniffed JPG/PNG/PDF, size-limited).

**Permissions.** `hr.leave.request.own` (all human roles), `hr.leave.approve.organisation` (Director, HR Manager;
never their own request; the Director's and HR Manager's own requests go to the other one),
`hr.leave.manage.organisation` (Director, HR Manager: settings, opening balances, adjustments),
`hr.leave.evidence.view.organisation` (Director, HR Manager only; the roster shows "On leave", never the type or
reason).

**Stays out.** Automatic "cuti ganti" (replacement leave) until the clinic writes the rule; no leave reason or MC on
the roster or any dashboard; no message to the staff member.

**[TO CONFIRM]** Leave types and days per year, by role or years of service; carry-forward; half days; who approves
whom (one level or supervisor then HR); opening balances and who signs them off; public holiday list per state
(Selangor and Kuala Lumpur); replacement-leave rule; MC limits. All law questions are to confirm with the clinic's
HR adviser or lawyer.

### HR-3 Attendance and overtime

**What the clinic gets.** A "Clock in" and "Clock out" button on the staff member's own account (decision 6: no
selfie, no continuous location), compared with their approved roster: on time, late (minutes), left early, no
clock-in, forgotten clock-out. Forgotten clock-out appears in the person's own list after the shift end plus one
hour [TO CONFIRM], and they enter the time as a correction that someone else approves. Overtime requests and
approval; a warning as a person nears 45 hours in a week (decision 10, settings); period summaries.

**Screens.** "My attendance" (today's button, my week, corrections); "Attendance review" (by branch and day: roster
against clock events, flags, pending corrections and overtime); "Attendance settings" (grace minutes, overtime
multipliers 1.5x / 2x / 3x, weekly hour limit, rest-day pattern).

**Tables (sketch).** `hr_clock_events` (append-only: person, branch, kind in/out, server time, method `button`,
device note), `hr_attendance_corrections` (proposed time, reason code, decided by someone else),
`hr_overtime_requests` (minutes, day type normal / rest / public holiday, status), `hr_attendance_settings`
(versioned), `hr_attendance_days` (derived summary per person per day, rebuilt, never the source of truth).

**Permissions.** `hr.attendance.clock.own` (all human roles), `hr.attendance.review.organisation` (Director,
HR Manager), `hr.overtime.approve.organisation` (Director, HR Manager; never own).

**Stays out.** Geofence and selfie (owner to review; PDPA review first), login times or queue timestamps as
attendance, pay calculation (HR-7), messages to staff.

**[TO CONFIRM]** Grace period for late; how a split shift is clocked; who approves corrections at a branch;
overtime eligibility by role; rest day per person; a shared clinic PC or own phones (the button works on both).

### HR-4 Employee records and documents

**What the clinic gets.** The staff file: employment periods (start, type permanent / contract / part-time /
locum, probation end, confirmation, end and reason code), job title and reporting line history, emergency contact,
encrypted IC or passport number, address, bank name and account; a document vault (contract, IC copy,
certificates) with approved categories; an own read-only summary; onboarding and offboarding checklists with a
probation alert 14 days before the end (in KPOne). A checklist item such as "Create KPOne account" or "Deactivate
account" is a request shown to the account administrator; HR never performs it.

Locums (decision 8): an HR worker record **without** a KPOne login (`hr_workers.user_id` nullable). They can be
rostered only after a later decision extends HR-1 to non-login workers.

**Screens.** "Staff files" (list with name, branch, employment type, status, probation due), "Staff file" detail
(tabs: Employment, Personal, Bank, Documents, Checklist), "My HR details".

**Tables (sketch).** `hr_workers` (organisation, nullable user, display name), `hr_employment_periods`,
`hr_job_history`, `hr_personal_details` (encrypted columns, `lock_version`, append-only change rows with field
names), `hr_documents` (encrypted file on the private disk, hash, category, uploaded by), `hr_checklists` and
`hr_checklist_items`.

**Permissions.** `hr.records.manage.organisation` and `hr.records.view.organisation` (Director, HR Manager),
`hr.records.view.own` (all human roles; personal and bank shown masked except to the person and managers),
`hr.documents.view.organisation` (Director, HR Manager; each document view audited before release). The protected
Director's file can be changed only by a Director.

**Stays out.** Self-editing of legal name, IC or bank (a correction request instead), import from the outside
product (needs a separate decision), any account change.

**[TO CONFIRM]** Document categories; who keeps paper originals; retention per category (adviser); whether HR may
see salary here (HR-7 decides).

### HR-5 Credentials and compliance

**What the clinic gets.** A register of each person's APC, BLS or ACLS, Hepatitis B status and other items the clinic
chooses, with issue and expiry dates and an evidence reference; status "Valid / Expiring / Expired / Not recorded"
and reminders at 60, 30 and 7 days, shown in KPOne to the person and to managers. Planner warnings: an operating
hour with no resident doctor rostered (coverage rule), too many hours in a row or no rest day (work-hour rule), and
a weekend / public holiday / night count per doctor (fair rotation). SOP documents with "I have read and understood"
and a list of who has not.

**Screens.** "Credentials" (register, filters by status), "My credentials", "SOPs" (publish, acknowledge, who has
not), and new warning chips on the HR-1 planner.

**Tables (sketch).** `hr_credential_types`, `hr_credential_records` (versioned; unknown vs no-expiry vs expiry date),
`hr_coverage_rules` (branch, opening hours by weekday, minimum doctors), `hr_work_hour_rules`, `hr_sops`,
`hr_sop_acknowledgements` (append-only).

**Permissions.** `hr.credentials.manage.organisation` (Director, HR Manager), `hr.credentials.view.own` (all human
roles), `hr.sops.manage.organisation` (Director, HR Manager), `hr.sops.acknowledge.own` (all human roles).

**Stays out.** Any change to DOC-02a verification (Director-only, unchanged); no regulator lookup; no clinical
gate (decision 4); vaccination health details beyond "Hep B: done / not done / date" [TO CONFIRM]; the KKM audit
file export (needs its own export decision); clinic document licences, internal audit checklists and the vaccine
fridge temperature log (proposal part B: these are clinic compliance, not HR; now HR-11, after HR-10, see section 3).

**[TO CONFIRM]** The full list of credentials per role; reminder windows (default 60/30/7); clinic opening hours
per branch for the coverage rule; work-hour limits (adviser).

### HR-6 Staff claims

**What the clinic gets.** Staff submit expense claims (category, date, amount in sen, receipt photos); someone else
approves or rejects with a reason code; a Finance Officer marks an approved claim paid with a payment reference.
Kept entirely apart from patient Panel Claims (different tables, pages and words: "Staff claims").

**Tables (sketch).** `hr_claim_categories`, `hr_staff_claims`, `hr_staff_claim_receipts` (sniffed JPG/PNG/PDF,
5 MB, private disk), `hr_staff_claim_actions` (append-only), `hr_staff_claim_payments` (reference, date; append-only;
reversal row only).

**Permissions.** `hr.claims.submit.own` (all human roles), `hr.claims.approve.organisation` (Director, HR Manager;
never own), `hr.claims.pay.organisation` (Finance Officer, Director: sees approved claims with name, category,
amount and receipts, nothing else from HR).

**Stays out.** Paying money from KPOne; mileage or per-diem calculation [TO CONFIRM]; linking to the patient
billing ledger.

**[TO CONFIRM]** Claim categories and limits; approval levels by amount; receipt rules.

### HR-7 Payroll

Decision 7: built inside KPOne, late, with every statutory rate entered and versioned by the clinic, and marked "to
confirm with the clinic's accountant" before real use. KPOne makes no payment.

**What the clinic gets.** A salary and allowance master (encrypted); a monthly payroll run per pay group built from
approved attendance and overtime, unpaid leave and approved staff claims; clinic-entered tables for EPF, SOCSO,
EIS, PCB/MTD and HRD levy (each a versioned table with an effective date, entered by a manager and checked by a
second person); review, Director approval and lock; payslips each person sees in KPOne; a reviewed export file
(bank upload or accountant format) with the HR-wide CSV safety rules.

Split: **HR-7a** salary master and statutory tables; **HR-7b** payroll run, review and approval; **HR-7c** payslips
and export.

**Tables (sketch).** `hr_pay_profiles` (encrypted basic salary, allowances, bank reference to HR-4),
`hr_statutory_tables` and `hr_statutory_table_rows` (versioned, effective dates, entered and verified by different
people), `hr_payroll_runs` (period, status, `lock_version`), `hr_payroll_lines` (immutable once approved; a
correction is a new adjustment run), `hr_payslips` (encrypted snapshot), `hr_payroll_exports` (audited, no stored
file).

**Permissions.** `hr.payroll.prepare.organisation` (HR Manager, Director), `hr.payroll.approve.organisation`
(Director; never the preparer), `hr.payroll.export.organisation` (Director, Finance Officer), `hr.payslips.view.own`
(all human roles).

**Stays out.** Payment, bank API, LHDN / KWSP / PERKESO e-filing, accounting ledger entries, any hard-coded rate.

**[TO CONFIRM]** (all with the clinic's accountant): pay groups and pay day, every statutory table and its source,
treatment of locums and part-timers, allowances, the export format, who receives payslips on paper.

### HR-8 Announcements and memos

**What the clinic gets.** A manager writes a memo (title, plain text, optional PDF), chooses all branches or some
branches and roles, and publishes it; staff see it in "Memos" and press "I have read this"; the manager sees who has
not read it. A memo is never edited after publishing; a correction is a new memo.

**Tables.** `hr_memos`, `hr_memo_audiences`, `hr_memo_acknowledgements` (append-only).
**Permissions.** `hr.memos.publish.organisation` (Director, HR Manager), `hr.memos.read.own` (all human roles).
**Stays out.** Any push, WhatsApp or email; "remind those who have not read" is a list in KPOne only.
**[TO CONFIRM]** Whether the CA Supervisor may publish branch memos.

### HR-9 HR dashboard and reports

**What the clinic gets.** "HR today": per branch, who is rostered now and who has clocked in, Enough / Short
against minimum staffing, no-doctor alert (shown in KPOne, decision 5), leave today (as "On leave" only); monthly
summary page (attendance, lateness, leave taken, turnover, compliance, and staff cost after HR-7) for the Director
and HR Manager; twice-daily "shift report" as a page section at the times the clinic chooses, not a WhatsApp
message.

**Permissions.** `hr.dashboard.view.organisation` (Director, HR Manager). A branch view for CA Supervisors needs a
separate decision.
**Stays out.** Any score of a person from clinical throughput or sales; messages.
**[TO CONFIRM]** Which figures go in the monthly summary; whether "turnover" counts locums.

### HR-10 Appraisal, letters, discipline and incidents

**What the clinic gets.** Appraisal cycles (attendance summary pulled in automatically, supervisor rating against
clinic criteria, the staff member's comment, Director sign-off); letter templates in BM and EN (offer,
confirmation, warning) issued as immutable numbered copies with a printable page; a restricted discipline record
per person; incident reports from any staff member to the manager, with actions and closing.

**Permissions.** `hr.appraisal.manage.organisation`, `hr.letters.issue.organisation`,
`hr.discipline.manage.organisation` (Director, HR Manager; discipline separately restricted),
`hr.incidents.report.own` (all human roles), `hr.incidents.manage.organisation` (Director, HR Manager).
**Stays out.** Automatic ranking; letters sent by email; applicant intake (not authorised).
**[TO CONFIRM]** Appraisal criteria and frequency; letter wording (adviser); who may see discipline records;
whether incident reports are an HR or a clinical-governance process (patient incidents may need clinical review).

## 3. The clinic HR proposal, item by item

**Part A: Side HR**

| Proposal item | Where it goes | Note |
|---|---|---|
| Roster shift (set shift, assign, publish) | HR-1 | "Publish" = Director approval |
| Shift swap (A and B agree, supervisor approves) | HR-1 follow-up "HR-1b swaps" | Not in HR-1; a swap is a request by both people and a new roster version approved by a manager |
| Minimum staffing alert | HR-1 (warning on planner), HR-9 (dashboard) | In KPOne only |
| Personal roster, Google Calendar sync | HR-1 "My roster" | **Calendar sync not authorised** (external integration) |
| Clock-in with geofence and selfie | HR-3 (button only) | Geofence: owner to review after PDPA review. **Selfie not authorised** (decision 6) |
| Roster against clock-in (present, late, absent) | HR-3 | |
| Forgotten clock-out | HR-3 | In-KPOne list instead of a notification |
| Attendance patterns (late, MC over limit) | HR-3 (late), HR-2 (MC count), HR-9 | Flags to managers only |
| Shift report twice daily by WhatsApp | HR-9 page section | **WhatsApp not authorised** |
| Report content (HR full list, Director summary) | HR-9 | |
| Urgent alert (branch without doctor) | HR-5 coverage warning, HR-9 | In KPOne only |
| Live dashboard (on duty now, Enough / Short) | HR-9 | |
| Role views (Director all, HR all and edit, Supervisor own branch) | HR-9 | Supervisor branch view needs a decision |
| Cost and budget (salary, OT, locum, budget alert) | HR-9 after HR-7 | Budget % [TO CONFIRM] |
| Monthly summary on day 1 | HR-9 | A page, not a message |
| Phone approvals (raise, new staff, OT over limit) | HR-3 (OT), HR-4 (new staff checklist), HR-7 (pay change) | Approved in KPOne on any device |
| Probation and onboarding | HR-4 | 14-day alert in KPOne |
| Letters and discipline (BM/EN templates, PDF) | HR-10 | Printable page; PDF via the browser |
| OT calculation 1.5x / 2x / 3x | HR-3 (minutes), HR-7 (money) | Settings, decision 10 |
| 45-hour limit warning | HR-3, and HR-1 planner warning in HR-5 | |
| Leave (replacement leave, public holidays by state) | HR-2 | Replacement rule [TO CONFIRM] |
| Payroll export to the outside product | HR-7c export file | **Integration with the outside product not authorised**; a file the clinic uploads by hand is |
| E-memo with read marks | HR-8 | |
| Appraisal with attendance data | HR-10 | |
| Recruitment (online form, shortlist, interview, offer) | Offer letter in HR-10 | **Applicant intake not authorised** (needs PDPA decision) |
| Turnover, exit interview, PDPA access log | HR-9 (turnover), HR-4 (exit checklist), all slices (audit) | Every HR view of sensitive data is audited |

**Part B: Clinic compliance**

| Proposal item | Where it goes | Note |
|---|---|---|
| Resident doctor mandatory coverage (open locum slot on a gap) | HR-5 coverage warning | Opening a locum slot: see part C |
| Work-hour limits | HR-5 | Warning only |
| Fair rotation (weekend, holiday, night counts) | HR-5 | |
| Doctor shift swap with compliance manager approval | HR-1b swaps | Approver role [TO CONFIRM] |
| Staff licence status APC, BLS/ACLS, Hep B, 60/30/7 days | HR-5 | |
| Clinic documents (registration, X-ray, clinical waste, calibration expiry) | HR-11 Clinic compliance | Not HR data; needs its own authorisation |
| SOP acknowledgement | HR-5 | |
| KKM audit file export (one button PDF) | Later, after HR-5 | Needs an export decision |
| Incident reports | HR-10 | Clinical incidents may need clinical review |
| Internal audit checklist with photos | HR-11 Clinic compliance | Needs its own authorisation |
| Vaccine fridge temperature log | HR-11 Clinic compliance | Clinic operations, not HR; needs its own authorisation |

**Part C: Locum**

| Proposal item | Where it goes | Note |
|---|---|---|
| Admin opens slots (branch, time, rate) | Later, after HR-4 locum worker records | Rate is pay data (HR-7) |
| Locum books a slot, OTP, valid APC only | **Not authorised** | Outside portal, OTP and new logins (AGENTS.md; decision 8) |
| WhatsApp reminders 48h / 24h, reconfirm | **Not authorised** | Messaging |
| Waiting list, first to press wins | **Not authorised** | Needs the outside portal |
| Emergency broadcast with premium rate | **Not authorised** | Messaging |
| Cancel policy 72h / 24h | Later, inside KPOne for HR to record cancellations | Recording only |
| Scoring and rewards | **Not authorised for now** | A score of a person needs owner and adviser review |
| WhatsApp access link with OTP | **Not authorised** | |

**Other proposal items.** Multi-branch: built in. Roles Director, HR, Compliance, Supervisor, Staff, Locum:
Director and HR Manager exist; a "Compliance manager" role and a supervisor branch view need owner decisions.
Phone OTP login and WhatsApp/email notifications: not authorised. Mobile-first: every HR screen works at 390 px.
PDPA with audit log: every slice. "Import MySyarikat data, pilot one branch": data import is not authorised (needs a
separate decision); a pilot uses synthetic data only (decision 11).

## 4. HR-1 design note (Hui Min)

Text sketch for Daniel through B.E.R.N.I.S.S (`docs/design/REVIEW.md` step 1). Follows `docs/design/GUIDE.md`.
Language: English (staff screen). Tokens and shared components only; no new colours.

### 4.1 Where it sits

- Route `/hr/roster`, page `resources/js/pages/HR/Roster.vue`. Form width `max-w-6xl`; the planner grid uses the
  work-board width `max-w-[1600px]` because it is a board (GUIDE 3).
- Link in Clinic management, group "Staff & branches": **"KPOne HR roster"**, description "Plan branch shifts and
  see your own roster." Shown to anyone with any of the three roster permissions (one new hub entry). No top-bar
  change. The word "Scheduling" is not used (the hub test forbids it).
- `PageHeader`: eyebrow "KPOne HR", title (`h1`) **"Roster"**, description "Weekly shifts for each branch. The
  Director approves each week before staff see it." No primary button in the header.
- Tabs under the header (`overflow-x-auto`, one row, `aria-current` on the active tab, tab state in the URL as
  `?tab=`): **My roster** (all holders), **Planner** and **Minimum staffing** (managers only). A staff member with
  only `hr.roster.view.own` sees no tab bar, just My roster.

### 4.2 My roster

- Section title `h2` "My shifts", help line "Approved shifts for the next 4 weeks. Times are in each branch's
  time."
- Week switcher: "Previous week" / "This week" / "Next week" outline buttons and the range label "9 – 15 Nov 2026".
- List grouped by day: day heading "Mon 9 Nov"; each shift row: time "8:00 AM – 2:00 PM", branch "Cheras", session
  badge (`Badge`, neutral) "Morning", break "Break 30 min", proposed room "Room 2" if any. Overnight shows
  "10:00 PM – 6:00 AM (next day)".
- 1440 px: the same list in a card, two columns (day heading left, shifts right). 390 px: stacked; each shift a row
  inside the day card; no table, no sideways scroll.
- Empty: `EmptyState` "No approved shifts this week." with help "Your roster appears here after the Director
  approves it."

### 4.3 Planner (managers)

**Toolbar** (wraps on phone, `flex flex-wrap gap-2`): Branch select (label "Branch", the three branches),
week switcher as above, and the version status `StatusBadge`:
- Draft → tone warning, word "Draft"
- Submitted → tone accent, word "Waiting for approval"
- Approved → tone success, word "Approved"
- Superseded → tone neutral, word "Replaced"
New status keys `roster_draft`, `roster_submitted`, `roster_approved`, `roster_superseded` added to
`lib/presentation.ts`.

**Version line** under the toolbar (`text-xs text-muted-foreground`): "Version 3 · approved by Director Demo on
5 Nov 2026, 4:10 PM" or "Version 4 draft · last changed by HR Demo 5 Nov 2026, 3:02 PM". When an approved version
and a newer draft both exist: a two-button switch "Approved (v3)" / "Draft (v4)".

**Actions area** (bottom of the toolbar on desktop, right side; full width stacked on phone):
- No roster for the week: primary **"Start draft"**, outline **"Copy last approved week"**.
- Approved, no draft: outline **"Change this week"** (creates draft v+1 from the approved shifts).
- Draft: primary **"Send for approval"**, outline **"Add shift"**, ghost destructive **"Discard draft"**.
- Submitted, viewer can approve and did not submit: primary **"Approve week"**, outline **"Return to draft"**.
- Submitted, viewer submitted: muted text "Waiting for another Director to approve." (or for HR Manager: "Waiting
  for the Director to approve.")
Every action except Add shift uses `OperationalConfirmDialog`:
- "Send this week for approval?" / "The Director checks it before staff see it. You cannot change it while it waits."
- "Approve this week?" / "Staff will see these shifts in My roster. Any earlier approved version is replaced."
- "Return this week to draft?" with a required reason select: "Needs changes", "Wrong staff", "Wrong times", "Other".
- "Discard this draft?" (destructive) / "The draft and its changes are removed from the plan. The approved week, if
  any, stays."
- "Copy last approved week?" / "Shifts from 2 – 8 Nov are copied into a new draft. Shifts that are no longer
  allowed are left out and listed."

**Warnings panel** (only when there are any; `role="status"`, card with `border-warning` left bar, items as a list):
- "Short: Mon 9 Nov, Morning: 1 CA (minimum 2)."
- "Room 2 is proposed for two people on Tue 10 Nov at 9:00 AM."
- "Aina CA also has a draft shift at Puchong on Wed 11 Nov, 2:00 PM – 8:00 PM."
- After copy: "Not copied: Dr Demo on Thu 12 Nov (not assigned to Cheras on that date)."

**Grid, 1440 px** (`OperationalTable`, caption "Roster for Cheras, 9 – 15 Nov 2026"): first column "Staff" (name,
role in `text-xs` muted), then seven day columns "Mon 9" … "Sun 15", last row "Total on shift" per day. Each cell
holds zero or more shift chips: a small bordered `rounded-md bg-card` button with "8:00 AM – 2:00 PM" and the session
word under it in `text-xs`; in a draft the chip is a button that opens the Edit shift sheet (accessible name
"Edit shift: Aina CA, Mon 9 Nov, 8:00 AM to 2:00 PM"). Rows list everyone with a shift that week plus anyone added;
"Add shift" chooses the person. Under the grid, "Short" days carry a warning `StatusBadge` "Short" in the total row.

**Grid, 390 px**: day cards instead of the table (GUIDE 6 pattern 2: `md:hidden` card list, table `hidden md:block`).
Each day card: heading "Mon 9 Nov", "Short" badge if short, then one row per shift "Aina CA · CA · 8:00 AM –
2:00 PM · Morning · Room 2" with a 44 px "Edit" button (draft only). Everything stacks; no sideways page scroll.

**Add or edit shift** (`Sheet` from the right on desktop, full-height on phone; title "Add shift" / "Edit shift"):
fields stacked, labels connected, required `*`:
- "Staff member *" (select of active staff assigned to this branch for the week, label "Name · Role")
- "Date *" (date input, within the week)
- "Start time *", "End time *" (time inputs; help "If the end is earlier than the start, the shift ends the next
  day.")
- "Break (minutes)" (number, default 0; help "Unpaid break inside the shift.")
- "Session *" (select: Morning, Afternoon, Evening, Night, Full day, Other)
- "Proposed room" (select of the branch's active rooms, optional; help "A plan only. The doctor still chooses their
  room on the day.")
Buttons bottom right: outline "Cancel", primary "Save shift"; in edit mode a destructive ghost "Remove shift" with a
confirm dialog "Remove this shift?". Errors under each field via `InputError`:
- "Choose a staff member."
- "Aina CA is not assigned to Cheras on 9 Nov 2026."
- "Aina CA already has a shift from 8:00 AM to 2:00 PM at Puchong on 9 Nov 2026."
- "The shift is longer than 16 hours."
- "The break must be shorter than the shift."
- "Choose a room at this branch."
Busy: the Save button shows a spinner and "Saving…".

**Toasts** (past tense, shared placement): "Shift added.", "Shift updated.", "Shift removed.", "Week sent for
approval.", "Week approved.", "Week returned to draft.", "Draft discarded.", "Draft started from 2 – 8 Nov."
**Stale version** (someone else changed it): page error `ErrorState` "This week was changed by someone else.
Reload to see the latest version." with a "Reload" button.

**Empty planner**: `EmptyState` "No roster for Cheras this week." help "Start a draft, or copy the last approved
week." with the two actions.

### 4.4 Minimum staffing (managers)

- `h2` "Minimum staffing", help "Shown as a warning on the planner. It never blocks approval."
- Branch select. Then an `OperationalTable` with rows = sessions (Morning … Other) and columns = Resident Doctor, CA,
  CA Supervisor, each cell a labelled number input (accessible label "Minimum CAs, Morning, Cheras"), 0 to 20.
  390 px: one card per session with three stacked labelled inputs.
- Primary "Save minimums" at the end; toast "Minimum staffing saved."; stale error as above.
- Empty means all zeros ("No minimums set" muted line above the table).

### 4.5 Refused and other states

- Without any roster permission: the hub has no link and `/hr/roster` returns the standard 403 page.
- An HR Manager never sees "Approve week"; the server also refuses a direct approve request from them (403).
- Loading (branch or week change): the grid shows `OperationalTable`'s loading row; the phone list shows
  `LoadingState` skeleton rows.
- Dark mode: tokens only; date and time inputs checked for visible icons in dark.

### 4.6 Checklist items to watch

One `h1` via `PageHeader`; tabs with `aria-current`; `OperationalTable` for the grid and minimums; card lists below
768 px; 44 px targets on phone; `StatusBadge` with words; `OperationalConfirmDialog` for every confirmation; no
palette colours; sentence case; no internal words.

## 5. HR-1 technical summary (Liyana, for the review)

- Domain: `app/Domain/HR/Roster/` with `RosterPermissions`, models for the six tables, `RosterPlanningService`
  (start, copy, add, update, remove, discard), `RosterApprovalService` (submit, approve, return),
  `RosterRules` (assignment coverage, overlap, room warnings, shortfall), `MinimumStaffingService`, and a read
  service for the planner and My roster.
- Controllers `app/Http/Controllers/HR/RosterController.php` (thin), routes under `/hr/roster`, throttled.
- Migrations from `2026_11_10_000100`: tables with PostgreSQL checks, partial unique indexes, immutability triggers
  and organisation-bound composite foreign keys; one additive grant migration.
- Tests: grants and denials for every role; foreign organisation and branch; assignment coverage on each touched
  date; overnight and New Year shifts; branch time zone; cross-branch overlap refusal and draft-overlap warning;
  back-to-back allowed; submitter cannot approve; approve supersedes; stale `lock_version`; idempotent retries and
  payload conflict; audit rows without values; no write to accounts, roles, assignments, rooms, queue or visits;
  PostgreSQL triggers refuse changes to approved shifts; a contention test for two approvals and an approval racing
  a shift edit (in the serial Contention suite).
- Expected existing-assertion change (needs approval): `ClinicManagementTest::test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role`
  gains `'/hr/roster' => ['hr.roster.manage.organisation', 'hr.roster.approve.organisation', 'hr.roster.view.own']`.
  Any other existing assertion that breaks will be stopped and listed, not changed.

## 6. HR-2 leave (e-Leave): design note

Written 2026-11-05 after HR-1 merged (PR #295, `dca171e`). It replaces the short HR-2 sketch in section 2 wherever
the two differ. **Every leave rule is a clinic-entered, versioned setting. KPOne ships no leave types, no
entitlement figures, no carry-forward rule and no public holiday list.** Until a manager enters them, staff see
"Leave is not set up yet. Ask HR." and nothing can be applied for.

### 6.1 Split

- **HR-2a (this note):** leave settings (types, entitlements, working days, public holidays), opening balances
  with a second-person check, the balance ledger, apply, approve or reject, cancel, a team leave calendar, and the
  roster link.
- **HR-2b (later, its own note):** evidence upload (for example a medical certificate from another clinic). Until
  then a request can carry an optional **evidence reference** (short text such as "MC from Klinik X, serial 123",
  at most 100 characters, encrypted), and a leave type can be marked "evidence needed". HR checks the paper and
  ticks "Evidence seen" on approval. That is enough to start, and keeps health documents out of KPOne until the
  clinic decides how they are kept [TO CONFIRM].

### 6.2 What the clinic gets (HR-2a)

- **My leave:** each staff member sees their balance for each leave type this year (entitled, taken, booked,
  left), applies for leave, follows the request, and cancels it.
- **Approvals:** the Director and the HR Manager see requests waiting for them and approve or reject with a reason
  code. Nobody decides their own request: an HR Manager's own request goes to the Director, and a Director's own
  request goes to the HR Manager or another Director (TEAM.md decision 3; the same rule as HR-1 decision 13).
- **Team calendar:** managers see who is on leave per branch and day. It shows **"On leave"** only, never the type
  or the reason, so a sick leave is not visible as such.
- **Leave settings (managers):**
  - leave types;
  - entitlements per year (versioned);
  - working days per branch (which weekdays count as working days for leave);
  - public holidays per branch and year;
  - opening balances.
- **Roster link (changes HR-1):**
  - approved leave shows on the planner as an "On leave" chip on those days;
  - a new shift on an approved-leave day is refused ("Aina CA is on approved leave on 9 Nov 2026.");
  - a shift that already existed shows a warning;
  - pending leave shows as a warning only ("Aina CA has asked for leave on 9 Nov 2026.").
  Approving leave never deletes or changes a shift. The approver sees a warning listing the person's approved
  shifts in that period, so the roster can be changed through the normal HR-1 flow.

### 6.3 Rules

1. **Leave types** (clinic-entered): code, name, paid or unpaid, counts against a balance or not, unit (whole days
   or half days allowed), evidence needed or not, active. Archived, never deleted. A type in use cannot change its
   unit or balance setting; a new version of the type is made instead.
2. **Entitlements** (clinic-entered, versioned by year): days per type, given by role and optionally by years of
   service bands [TO CONFIRM], or set per person. A new version applies from its effective date and never rewrites
   earlier ledger lines. Each year's entitlement is added to the ledger as one **grant** line per person and type
   when a manager presses "Give this year's entitlement" (shown with the number of people it will affect). It is
   not added automatically.
3. **Carry-forward** (clinic-entered per type): none, up to N days, and an optional expiry date for carried days.
   Applied by a manager action at year end ("Carry forward from 2026"), which writes **carry-forward** and, later,
   **expiry** lines. Nothing happens by schedule.
4. **Opening balances:** a manager enters, per person and type, the days already left when the clinic starts using
   KPOne leave. A **different** manager confirms each one before it counts (the same pattern as the STK-1 opening
   sign-off). Unconfirmed openings show as "Waiting for a check" and give no balance.
5. **Counting days:** a request covers whole dates from a start date to an end date (branch-local), with half-day
   AM or PM allowed on the first and last day where the type allows half days. Charged days are the working days
   of the person's **primary branch** in that range, minus that branch's public holidays. Working days and holidays
   must be set for the branch, or the request is refused with "Working days are not set up for Cheras yet." The
   figure is computed on the server and shown before sending.
6. **Balance:** left = grants + carry-forward + confirmed opening + adjustments − taken − expired. "Booked" is
   pending and approved leave not yet taken. A request that would take the balance below zero is refused unless the
   type allows a negative balance [TO CONFIRM; default not allowed]. Unpaid or non-balance types have no balance
   check.
7. **Overlap:** one person cannot have two pending or approved requests on the same day (half days AM and PM may
   share a day).
8. **Approve:** the approver must hold `hr.leave.approve.organisation`, must not be the applicant, and the balance
   is re-checked under lock. Approval writes one **taken** ledger line (negative days) for balance types.
9. **Reject:** needs a reason code (Not enough cover, Dates not allowed, Wrong type, Other). No ledger line.
10. **Cancel:**
    - the applicant cancels a pending request at any time;
    - the applicant cancels an approved request before its start date;
    - after the start date, only a manager may cancel it ("Cancel approved leave"), with a reason code.
    Cancelling approved leave writes a **reversal** line that returns the days. Nothing is deleted.
11. **Adjustments:** a manager may add or remove days for one person and type with a reason code (Correction, Leave
    given in lieu, Other) and a short note (at most 200 characters, encrypted). A different manager confirms it
    before it counts, as with openings.
12. **Ledger:** append-only (PostgreSQL refuses update, delete and truncate). Each line points at its request,
    opening, adjustment or grant. Days are stored in halves (an integer count of half days) so that 1.5 days is
    exact.
13. **Every write:**
    - a fresh permission check under lock;
    - `DB::transaction`;
    - locks on the people involved in id order (the HR-1 `lockPeople` pattern), then the request row;
    - `lock_version`;
    - an idempotency key on apply, approve, reject, cancel and grant;
    - an audit row with ids, type codes and day counts only, never reasons, notes or evidence references.
14. HR never blocks clinical work (TEAM.md decision 4). Leave does not change registration, doctor eligibility,
    Call In or room choice.

### 6.4 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_leave_types` | organisation, code, name, paid, uses balance, unit, evidence needed, allow negative, active, `lock_version` |
| `hr_leave_entitlement_versions` | organisation, leave type, effective year, rules (role or person, service band, days in halves), created and confirmed by |
| `hr_branch_working_days` | branch, seven weekday flags, `lock_version` |
| `hr_public_holidays` | branch, date, name; unique per branch and date |
| `hr_leave_openings` | person, type, year, days in halves, entered by, confirmed by (must differ), status |
| `hr_leave_adjustments` | person, type, days in halves, reason code, encrypted note, entered by, confirmed by (must differ), status |
| `hr_leave_requests` | public id, person, type, start and end date, half-day flags, charged days in halves, status (pending, approved, rejected, cancelled), encrypted reason and evidence reference, decided by and at, reason code, evidence seen, `lock_version` |
| `hr_leave_request_actions` | append-only: applied, approved, rejected, cancelled, with actor and time |
| `hr_leave_ledger` | append-only: person, type, year, kind (grant, carry_forward, opening, adjustment, taken, reversal, expiry), days in halves (signed), source row id |
| `hr_leave_requests_retry` | idempotency key, payload hash, result |

PostgreSQL checks the rules that must hold whatever the code does:
- the decider is never the applicant;
- the confirmer is never the enterer;
- the ledger is append-only;
- days are a multiple of a half;
- at most one pending or approved request per person per day and half (an exclusion constraint on a date range
  with the half flags).

### 6.5 Permissions

| Permission | Holders |
|---|---|
| `hr.leave.request.own` (my balance, apply, cancel my own) | every human role (as `hr.roster.view.own`); not the TV account |
| `hr.leave.approve.organisation` (approve, reject, cancel approved leave) | Director, HR Manager |
| `hr.leave.manage.organisation` (settings, holidays, working days, openings, adjustments, grants, team calendar) | Director, HR Manager |

None is in the people-and-access authority set. They come in by an additive grant migration from
`2026_11_10_000300` onward. HR-2b adds `hr.leave.evidence.view.organisation` later.

### 6.6 What stays out of HR-2a

- Evidence files (HR-2b).
- Automatic replacement leave (cuti ganti); it can be given by hand as an adjustment until the clinic writes the
  rule [TO CONFIRM].
- Accrual month by month [TO CONFIRM; default yearly grant].
- Leave by the hour.
- Any message or reminder outside KPOne.
- Payroll effects (HR-7 reads approved unpaid leave later).
- Import from the outside HR product.
- Any change to clinical work.
- A CA Supervisor branch view (the HR-1b decision applies).

### 6.7 Questions only the clinic can answer [TO CONFIRM]

1. The leave types the clinic uses, and for each one: paid or unpaid, counted against a balance or not, half days
   allowed, evidence needed. Examples to ask about: annual, sick (MC), hospitalisation, emergency, unpaid,
   maternity, paternity, compassionate, replacement.
2. Days per year for each type, by role, by years of service, or per person. All law questions go to the clinic's
   HR adviser or lawyer.
3. Carry-forward per type (none or up to how many days) and when carried days expire.
4. Whether any type may go below zero.
5. Each branch's working days (for example Monday to Saturday) and whether a half day on Saturday counts as half.
6. Public holidays per branch and year (Selangor for Cheras and Puchong? Kuala Lumpur for Sungai Besi?), and who
   enters them each year.
7. Replacement leave for working on a public holiday: what earns it and how long it lasts.
8. Opening balances on the first day: who enters them, and who checks them.
9. Medical certificates and other evidence: whether the clinic wants files in KPOne at all (HR-2b), who may see
   them, and how long they are kept.
10. How early staff must apply for each type, and whether a late application is refused or only flagged.

### 6.8 Screens (Hui Min, for Daniel)

Follows `docs/design/GUIDE.md`; English staff screen; tokens and shared components only; no new colours.

- **Where:**
  - route `/hr/leave`, page `resources/js/pages/HR/Leave.vue`, list width `max-w-7xl`;
  - one new hub link in "Staff & branches": **"KPOne HR leave"**, description "Apply for leave, approve requests
    and see who is away.";
  - no top-bar change.
- **Header:** `PageHeader` with eyebrow "KPOne HR", title (`h1`) **"Leave"**, description "Apply for leave and see
  your balance. Someone else approves each request."
- **Tabs** (`OperationalTabs`, `?tab=`): **My leave** (all), **Approvals** (approvers; the tab shows a count),
  **Team calendar** (managers), **Settings** (managers). Staff with only the own permission see no tab bar.

**My leave**
- **Balances:** one card per leave type in a grid (`grid gap-4 sm:grid-cols-2 lg:grid-cols-3`). Each card shows
  the type name (`h3`) and four labelled figures in `tabular-nums`: "Entitled 14", "Taken 3", "Booked 1.5",
  "Left 9.5". A type with no balance shows "Not counted".
- **Apply:** primary **"Apply for leave"** opens a `Sheet`. The fields, stacked, with connected labels:
  - Leave type *;
  - Start date * and End date *;
  - "Half day on the first day" (none, morning, afternoon);
  - "Half day on the last day" (none, morning, afternoon);
  - Reason (optional, at most 300 characters, help text "Do not write medical details here.");
  - Evidence reference (shown only for types that need it, help text "For example, the MC serial number. HR checks
    the paper copy.").
  A live summary line under the dates reads "3 working days (public holiday on 10 Nov not counted). 9.5 left
  after this." Buttons: outline "Cancel", primary "Send request".
- **Requests list:** `OperationalTable` at 1024 px and up; cards below that. Columns: dates, type, days, status
  (`StatusBadge`: Waiting for approval = warning, Approved = success, Rejected = danger, Cancelled = neutral), and
  one action ("Cancel request" in outline, or nothing).
- **Empty states:**
  - no requests: "No leave requests yet." / "Apply for leave to see it here.";
  - leave not set up: an `EmptyState` reading "Leave is not set up yet." / "Ask HR to add the leave types for the
    clinic."

**Approvals**
- A table, or cards below 1024 px. Columns:
  - person and role;
  - type;
  - dates and days;
  - "Left after this";
  - warnings, one line each: "Has 2 approved shifts in this period. Change the roster after approving.", "Evidence
    needed", "Sent 1 day before the start";
  - actions: "Approve" (primary) and "Reject" (outline).
- Approve opens `OperationalConfirmDialog` "Approve this leave?". It has an "Evidence seen" tick when the type
  needs evidence, and the confirm button stays disabled until it is ticked.
- Reject uses a confirm dialog with a required reason select.
- An approver's own requests are never listed for them. The empty state reads "Nothing waiting for you."

**Team calendar**
- **1024 px and up:** branch select and month switcher; a month grid with one row per person and a column per day.
  Each cell is "On leave" (a neutral chip), "Half day" or empty; public holidays are shaded with their name in the
  column header; non-working days are muted.
- **Below 1024 px:** a day list ("Mon 9 Nov: Aina CA, Dr Demo (half day)").
- Never shows the leave type or reason.

**Settings** (sections, each a titled card with its own Save):
1. **Leave types:** a table with Add, Edit and Archive; the form fields are as in rule 1.
2. **Entitlements:** a year select, a table of type × role in days with half-day steps, plus "Set for one person";
   Save makes a new version; "Give this year's entitlement" opens a confirm dialog that states the count of people.
3. **Working days:** branch select and seven labelled checkboxes.
4. **Public holidays:** branch and year select; a list of date and name; Add and Remove (removal is allowed only
   before any request counts on that day, otherwise "Used by 3 requests. It cannot be removed.").
5. **Opening balances and adjustments:** a person and type table; an entry form; a "Waiting for a check" list
   with "Confirm" (another manager) and "Reject".

**Planner change (HR-1 screen):** an "On leave" neutral chip in the day cell, and a warning line in the existing
"Check before approval" panel.

**Errors under fields:**
- "End date must be on or after the start date."
- "This leave would leave −1 day. You have 0.5 left."
- "You already have leave on 9 Nov 2026."
- "Working days are not set up for Cheras yet."
- "Choose a leave type."

**Toasts:** "Leave request sent.", "Leave approved.", "Leave rejected.", "Leave request cancelled.", "Leave types
saved.", "Opening balance confirmed."

**Phone:** everything stacks; cards replace tables below 1024 px; 44 px targets; no sideways page scroll at 320,
390 or 768 px.

## 7. HR-2a technical summary (Liyana)

- **Code:** domain `app/Domain/HR/Leave/` with `LeavePermissions`, `LeaveSettingsService`,
  `LeaveRequestService` (apply, approve, reject, cancel), `LeaveBalanceService` (ledger, balance, day count),
  `LeaveOpeningService` and `LeavePageService`; a thin controller `app/Http/Controllers/HR/LeaveController.php`;
  routes under `/hr/leave`, throttled.
- **Roster change:** `RosterRules::assertShiftAllowed` gains the approved-leave refusal, and `RosterPageService`
  gains the "On leave" chips and warnings. The existing HR-1 tests stay unchanged; new tests are added.
- **Tests:**
  - grants and denials for every role;
  - day counting across holidays, weekends, half days, New Year and the branch time zone (never the UTC clock);
  - balance limits and negative-balance refusal;
  - overlap refusal;
  - two-person openings and adjustments;
  - the approver is never the applicant (service and PostgreSQL);
  - cancel before and after the start date with reversal lines;
  - the ledger is append-only;
  - stale `lock_version`, retries and reused keys;
  - audit without reasons or notes;
  - roster refusal and warnings;
  - no writes to accounts, roles, assignments or clinical tables;
  - one two-process contention test: two approvals spending the same last balance.
- **Expected existing-assertion change (needs approval):** the `ClinicManagementTest` hub destination map gains
  `'/hr/leave' => ['hr.leave.request.own', 'hr.leave.approve.organisation', 'hr.leave.manage.organisation']`.

## 8. HR-2b leave evidence files: design note

Written 2026-11-06 while HR-2a (PR #302) waits in the merge queue. It builds on HR-2a and changes none of its rules.
**Status: approved with its defaults by the owner on 2026-11-06 (TEAM.md decisions 24 and 26); building.** The
clinic wants MC files in KPOne. Questions 2 to 6 in 8.9 take the defaults written in this note until the clinic says
otherwise.

### 8.1 Why and what

A staff member on sick leave usually holds a medical certificate (MC) from another clinic. Today HR-2a lets them type
a short reference ("MC from Klinik X, serial 123") and HR ticks "Evidence seen" after looking at the paper. HR-2b
lets the staff member, or HR on their behalf, attach a photo or PDF of that paper to the leave request, so HR can
check it without the paper being carried to the HR office.

**This is health data about staff.** An MC names the clinic, the doctor and the dates, and may carry a diagnosis.
It needs the PDPA review (TEAM.md section 7) before any real file is uploaded. Until then: synthetic files only, as
for the rest of KPOne.

### 8.2 What the clinic gets

- **Apply sheet:** for a leave type marked "evidence needed", an optional "Add a photo or PDF" field under the
  evidence reference. The request can be sent without a file; the file can be added later.
- **My leave:** each request row shows "Evidence: 1 file" and an "Add file" action while the request may still take
  files. The staff member can open their own files and remove one while the request is waiting for approval.
- **Approvals:** the row shows "1 file · View". "View" opens a panel: a photo is shown on the page; a PDF is offered
  as "Download PDF" only (never shown inside KPOne, see 8.4). The "Evidence seen" tick in the approve dialog is
  unchanged and can be ticked with or without a file.
- **HR on behalf:** HR can attach a scan of the paper copy to someone's request ("Paper copy scanned by HR"). The
  row shows who added each file.
- **Settings, per leave type:** "Accept evidence files" (off by default) and "Keep files for N days after the leave
  ends". Files cannot be added to a type until both are set, so nothing is stored by default.

### 8.3 Rules

1. **Who may add a file:** the applicant, on their own request, while it is waiting for approval, or after approval
   until 14 days after the leave ends [TO CONFIRM: the clinic's deadline for handing in an MC]; a holder of
   `hr.leave.manage.organisation`, on anyone's request, until the file retention ends. Never on a rejected or
   cancelled request.
2. **Who may open a file:** the applicant (their own files only) and holders of the new
   `hr.leave.evidence.view.organisation` (Director and HR Manager, as planned in section 2). Nobody else: not the
   roster, the team calendar, a CA Supervisor or any report. **Every open or download is recorded** (who, which file,
   when; never the content), the same pattern as the PC-01 identity view.
3. **Files accepted:** JPG, PNG and PDF, checked by their content, not their name (the Panel claim photo pattern).
   At most 5 MB each and 3 files per request. A photo is re-encoded on upload, which also strips its EXIF data
   (camera, GPS location, time). A PDF is stored as uploaded and only ever downloaded.
4. **Storage:** encrypted with the app key on the private disk (the PNL-7 statement pattern), under a generated name.
   The original file name is not kept (it often holds a name or a diagnosis); the screen shows "MC 1 · PDF · 240 KB ·
   added 6 Nov 2026 by Aina CA". A SHA-256 of the content refuses the same file twice on one request.
5. **Removing a file:**
   - the applicant removes their own file while the request is waiting;
   - a manager removes a file with a reason code (Wrong file, Wrong person, Not readable);
   - removal deletes the stored file at once and keeps an append-only record that a file existed, who removed it,
     when and why.
6. **Retention and deletion:** a daily command (`hr-leave-evidence:purge`, scheduled in `routes/console.php`, like
   `panel-statements:purge`) deletes each file once its type's retention after the leave's end date has passed.
   Files on a rejected or cancelled request are deleted 30 days after that decision. Both rules delete on the day
   after the period ends, in the branch time zone, and the days cannot be cleared while files remain. The request, the "Evidence
   seen" tick and the record that a file existed stay. The number of days is the clinic's decision [TO CONFIRM with
   the clinic's HR adviser: how long staff medical evidence must or may be kept]; KPOne ships no number.
7. **What never happens:** no reading of the file's content (no OCR, no AI), no automatic approval, no sending of a
   file anywhere, no file in an export, a print or an audit row.
8. **Every write:** fresh permission check under lock; `DB::transaction`; the applicant's account, then the request,
   then the file row locked, in that order (the HR-2a lock order); `lock_version` on the request; an idempotency key
   on add and remove; the file is written to disk only after the row checks pass, and deleted again if the
   transaction rolls back (the Panel claim photo compensation pattern); audit with ids, type code, file count and
   size only.
9. HR never blocks clinical work (TEAM.md decision 4); a missing file never blocks a shift or anything clinical.

### 8.4 Serving a file safely

- One route per file, `GET /hr/leave/evidence/{file}`, behind `hr.leave.request.own` or the view permission, with the
  owner or permission check in the service, an organisation check on the file and its request, and a throttle.
- The response is streamed after decryption with `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` and
  `Content-Security-Policy: sandbox`. Photos are served `inline` as `image/jpeg` or `image/png` (re-encoded, so their
  bytes are known); a PDF is always `attachment`, so a crafted PDF cannot run inside KPOne's origin.
- The open is recorded before the bytes are sent; if recording fails, nothing is sent.

### 8.5 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_leave_evidence_files` | public id, organisation, leave request, uploaded by, uploaded on behalf (yes/no), kind (jpeg, png, pdf), size, SHA-256, stored path, status (active, removed, purged), removed by and at, removal reason code, delete after (date), `lock_version` |
| `hr_leave_evidence_events` | append-only: added, opened, removed, purged, with actor (null for the scheduled purge) and time |
| `hr_leave_types` (change) | two new columns: accept evidence files (boolean, default off), keep files for N days (nullable) |

PostgreSQL checks: one active file per request and SHA-256; at most 3 active files per request (trigger); a file
belongs to a request of the same organisation; status moves only active → removed or active → purged; events are
append-only; a removed or purged file row never changes again.

Migration from `2026_11_10_000500`; the permission grant in `2026_11_10_000600` (additive, literal names, like
000200 and 000400).

### 8.6 Permissions

| Permission | Holders |
|---|---|
| `hr.leave.evidence.view.organisation` (open anyone's evidence files) | Director, HR Manager |

Adding your own file uses the existing `hr.leave.request.own`; adding or removing on someone's behalf uses the
existing `hr.leave.manage.organisation`. None is in the people-and-access authority set.

### 8.7 Screens (Hui Min; Daniel's early look folded in)

Same page, `/hr/leave`; no new tab, hub link or top-bar change. Shared components only. Daniel's early look
(2026-11-06): **OK with changes**, all folded in below.
- **Apply sheet:** an outline button "Add a photo or PDF" (never the bare browser file control), with the chosen
  file's name and size shown under it and a "Remove" link before sending. `accept="image/jpeg,image/png,application/pdf"`
  and no `capture`, so staff can pick an existing photo. Help text "JPG, PNG or PDF, up to 5 MB. Take a clear photo of
  the whole MC." Shown only for evidence types that accept files. 44 px on phones.
- **Sending:** while a file uploads the button reads "Uploading…" and is disabled; on a failure the request stays as
  it was and the error sits under the field.
- **My leave / Approvals:** a small "Evidence" line in the row or card: "No file yet" (muted, never red; a needed
  file already has the "Evidence needed" warning), "1 file" or "2 files", with "Add file" (outline) and "View"
  (outline). Their screen-reader names say which request ("View files for Annual leave, 16 Nov"). No new badge.
- **Evidence panel** (`Sheet`): one card per file with its label, size, who added it and when; the photo on a
  neutral surface with a thin border (no edge-to-edge glare in dark mode), alt text "Photo 1 of 2, added by Demo CA
  on 16 Nov" and never any medical text; or a "Download PDF" button. Empty state: "No files yet."
- **Remove:** a ghost "Remove" for everyone who may remove, always through a confirm dialog with a destructive
  confirm button; the reason field shows only for managers.
- **Settings → Leave types form:** a checkbox "Accept evidence files" and a number field "Keep files for (days after
  the leave ends)", required when the checkbox is on, with help text "Files are deleted after this. Requests and
  their history stay." The applicant's side shows a muted line "Files are kept for N days after your leave."
- **Errors under the field:** "Use a JPG, PNG or PDF file.", "This file is over 5 MB.", "This request already has 3
  files.", "This file is already attached.", "Files can no longer be added to this request."
- **Toasts:** "File added.", "File removed."
- **Phone:** the panel is full width; photos fit the width; 44 px targets; no sideways page scroll at 320, 390 or
  768 px.
- **Screenshots when built** (Daniel): the apply sheet with a file chosen and with an error, the evidence panel with
  a photo and with a PDF, and the remove dialog, at 390 and 1440 px, light and dark.

### 8.8 Tests (planned)

- Grants: the new permission is held by exactly Director and HR Manager, not the TV account, and is not authority.
- Who may add, open and remove, for every role, including another organisation's request and file ids (404).
- Content checks: a PNG renamed `.pdf`, a script renamed `.jpg`, a 5 MB + 1 byte file, a fourth file and a duplicate
  are refused; a JPEG with GPS EXIF comes back without it.
- The stored file is encrypted (its bytes do not contain a known marker from the upload); the original name is not
  stored anywhere.
- Every open writes an event; a failed event sends nothing; a PDF is always an attachment with `nosniff` and the
  sandbox header.
- Purge: a file past its type's retention and a file on a request cancelled 31 days ago are deleted, the rows stay;
  nothing else is touched. Branch-local dates, never the UTC clock.
- A rolled-back add leaves no file on disk; a retried add with the same key stores one file.
- PostgreSQL: the 3-file limit, the status moves and append-only events hold whatever the code does.
- The roster, team calendar and audit rows never carry a file, a file name or the type of the evidence.
- No existing assertion is expected to change.

### 8.9 Questions only the clinic can answer [TO CONFIRM]

1. ~~Does the clinic want evidence files in KPOne at all?~~ **Yes** (owner, 2026-11-06).
2. Which leave types take files (sick leave, hospitalisation, compassionate, others)?
3. How long may or must the files be kept after the leave ends? (For the clinic's HR adviser; KPOne ships no number.)
4. The deadline for handing in an MC after the leave (default in this note: 14 days after the leave ends).
5. Should anyone besides the Director and the HR Manager open the files (for example a CA Supervisor for their
   branch)? The default is no.
6. Is HR scanning the paper copy on the staff member's behalf wanted, or only the staff member's own upload?

### 8.10 What stays out of HR-2b

- Reading or checking the MC's content automatically, or checking it against the issuing clinic.
- Files for anything other than leave requests (credentials and staff documents are HR-3 and HR-4).
- Showing a PDF inside KPOne (download only), editing or annotating a file, virus scanning (no scanner is available;
  noted as a known limit for the PDPA and security review).
- Any message, reminder or export carrying a file.

## 9. HR-3 attendance and overtime: design note

Written 2026-11-06 while HR-2b (PR #311) waits for CI. It replaces the short HR-3 sketch in section 2 wherever the
two differ, and changes no HR-1 or HR-2 rule. **Status: approved with its defaults by the owner on 2026-11-06
(TEAM.md decision 29); building HR-3a.** Questions in 9.9 take the defaults written here until the clinic says
otherwise.

**KPOne ships no attendance rule.** Grace minutes, overtime multipliers, the weekly hour limit and who may claim
overtime are clinic-entered, versioned settings. The settings form is pre-filled with the clinic HR proposal's
values (normal day 1.5x, rest day 2x, public holiday 3x, 45-hour week; TEAM.md decision 10) but nothing counts
until a manager presses Save. Until then the button still records times, and the flags read "Attendance is not set
up yet."

### 9.1 Split

- **HR-3a (build first):** the Clock in and Clock out button, roster against attendance (on time, late, left early,
  no clock-in, forgotten clock-out, not on the roster), corrections approved by someone else, the review page and
  attendance settings.
- **HR-3b (after HR-3a merges, under this same note):** overtime requests and approval, the weekly hours warning and
  period summaries. HR-3b needs HR-3a's worked minutes, so it cannot come first.

### 9.2 What the clinic gets

- **My attendance** (every human role, not the TV account): one large button, "Clock in" or "Clock out", with
  today's date, the server time and today's approved shift ("Today: Morning, 8:00 to 14:00, Cheras"). After a press:
  a toast "Clocked in at 7:56 AM." and a line "Signed in as Aina CA. Sign out if this is a shared computer."
  Below: my week (each day's shift, clock times and flags) and my corrections.
- **Attendance review** (Director, HR Manager): one branch and one day (or a week), each rostered person and anyone
  who clocked in without a shift, with the roster beside the clock times and the flags; corrections waiting for a
  decision.
- **Corrections:** a staff member who forgot to clock in or out, or pressed at the wrong time, proposes the right
  time with a reason code; someone else approves or rejects it. The original press stays on record.
- **Attendance settings** (Director, HR Manager): grace minutes, the forgotten clock-out window, how many days back
  a correction may go, and (HR-3b) overtime multipliers, the weekly hour limit and the roles that may claim overtime.
- **HR-3b, overtime:** a staff member asks for overtime minutes on a day they worked (or a manager records it on
  their behalf); someone else approves, changes down or rejects it. The day type (normal, rest day, public holiday)
  is worked out by KPOne and shown. Minutes and day type only: money is HR-7.
- **HR-3b, weekly hours:** a warning when a person's worked hours in a week (Monday to Sunday, branch-local) reach
  the "nearing" level (default 40) and when they pass the limit (default 45), on My attendance, the review page and
  the overtime approval.
- **HR-3b, period summaries:** per person for a week or a month: rostered hours, worked hours, late count and
  minutes, left-early count, no clock-in count, forgotten clock-outs, approved overtime minutes by day type.
  On screen only (no CSV: exports need their own authorisation).

### 9.3 Rules

1. **The button is the only source.** Login times, queue or consultation timestamps and device clocks are never used
   as attendance. The time is the server's. No location, no IP address, no photo and no device fingerprint is stored
   (TEAM.md decision 6). Method is recorded as "button" or "correction".
2. **Branch:** the press is for the active branch in the top bar, which must be one of the person's effective branch
   assignments on that date (refused otherwise). If today's approved shift is at another branch, the press is
   allowed and flagged "Different branch from the roster".
3. **Sessions:** a clock-in opens a session; the next clock-out closes it. A person has at most one open session
   (PostgreSQL). A second press within 60 seconds of the first does nothing (a double tap), and a retried request
   with the same key returns the same press. A session belongs to the branch-local date of its clock-in, so a night
   shift that ends after midnight stays on its start day.
4. **Matching the roster:** each clock session is matched to the approved shift (HR-1, current approved version) of
   that person and branch that it overlaps most. Flags, worked out when a page is opened, never stored as the truth:
   - **Late N min:** clock-in later than shift start plus grace minutes.
   - **Left early N min:** clock-out earlier than shift end minus grace minutes.
   - **No clock-in:** an approved shift that ended more than the grace minutes ago with no session.
   - **Forgotten clock-out:** an open session more than the forgotten window (default 60 minutes) after its shift's
     end, or 16 hours after clock-in when there is no shift. The session shows in the person's own list with "Add
     your clock-out time"; it is never closed automatically. **Split shift (Suresh's review, decided by Liyana):**
     when a clock-in's shift ended more than the forgotten window ago and the person's next approved shift that day
     starts within two hours, the next Clock in marks the morning "missing its clock-out" instead of refusing. Without a
     later shift, someone still on after their shift simply clocks out (it may be overtime, HR-3b).
   - **Not on the roster:** a session with no approved shift that day. Shown, never refused.
   - **On approved leave:** a session on a day of approved leave (HR-2a) is flagged for the reviewer.
   Breaks come from the shift's break minutes (HR-1); worked minutes = session length minus those break minutes
   when the session covers the shift, otherwise the raw length.
5. **Corrections:** kinds "add a missing clock-in", "add a missing clock-out", "change a time"; reason codes Forgot,
   Device or system problem, Pressed at the wrong time, Worked away from the branch, Other (with a short encrypted
   note, at most 200 characters, never audited). The proposed time must be within the last N days (default 7),
   not in the future, and keep the session's times in order. A staff member proposes for themselves; a manager may
   propose on someone's behalf. **The decider is never the subject and never the proposer** (service and
   PostgreSQL). An HR Manager's own correction goes to the Director, a Director's to the HR Manager or another
   Director (TEAM.md decision 3). An approved correction adds a correction event that takes the place of the press
   it corrects; the original press stays, marked "corrected". A rejected one changes nothing.
6. **Settings are versioned:** a save makes a new version from a chosen date; flags for days before it use the old
   version, so a change never rewrites past flags.
7. **HR-3b overtime:** a request names a date the person has a closed session on, the minutes (whole minutes, at
   most the session's worked minutes beyond the shift, or all of it on a rest day or public holiday), and a reason
   code. Day type: a public holiday of that branch (HR-2a calendar), else a rest day (a weekday that is not a working
   day of that branch, until per-person rest days exist [TO CONFIRM]), else normal. Approval stores the minutes, the
   day type and the settings version (so HR-7 can price it later); the approver may lower the minutes, never raise
   them. Same two-person rule as corrections. Only roles the settings allow may request; a request for another role
   is refused. Within the correction window by default.
8. **HR never blocks clinical work** (TEAM.md decision 4): no clock-in is needed to sign in, Call In, consult or
   dispense, and no clinical screen reads attendance.
9. **Every write:** fresh permission check under lock; `DB::transaction`; the acting account and the subject locked
   once in id order (the HR-2a pattern), then the session or request row; `lock_version` on sessions, corrections
   and overtime requests; an idempotency key on clock, propose, decide and cancel; audit with ids, kinds, codes and
   minutes only (never notes); throttles on the clock route (10 a minute).

### 9.4 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_attendance_settings` | append-only versions per organisation: effective from, grace minutes, forgotten window minutes, correction days, (HR-3b) normal, rest-day and public-holiday multipliers, nearing and limit hours, overtime roles |
| `hr_clock_sessions` | person, branch, local date, clock-in event, clock-out event (nullable), status open/closed, `lock_version` |
| `hr_clock_events` | append-only: session, kind in/out, time, method button/correction, recorded by, corrects event (nullable), idempotency key |
| `hr_attendance_corrections` | subject, proposed by, kind, session (nullable for a missing clock-in), proposed time, reason code, encrypted note, status pending/approved/rejected/cancelled, decided by and at, `lock_version` |
| `hr_overtime_requests` (HR-3b) | subject, requested by, session, date, minutes requested and approved, day type, settings version, reason code, encrypted note, status, decided by and at, `lock_version` |
| `hr_attendance_retries` | idempotency records (the `hr_leave_retries` pattern) |

PostgreSQL: one open session per person (partial unique index); a session's events alternate in then out and a
closed session never reopens; events, settings versions and decisions are append-only (no update of a decided row,
no delete, no truncate); the decider differs from the subject and the proposer (CHECK); every row's person, branch
and recorder belong to the same organisation; a clock-in on a branch the person is not assigned to is refused
(trigger, backing the service).

Migrations from `2026_11_10_000700`; the permission grants in their own additive migration.

### 9.5 Permissions

| Permission | Holders |
|---|---|
| `hr.attendance.clock.own` (clock, own list, own corrections, own overtime requests) | every human role, not the TV account |
| `hr.attendance.review.organisation` (review page, decide corrections, propose on someone's behalf, settings) | Director, HR Manager |
| `hr.overtime.approve.organisation` (HR-3b: decide overtime) | Director, HR Manager |

None is in the people-and-access authority set.

### 9.6 Screens (Hui Min; Daniel's early look folded in)

Daniel's early look (2026-11-06): **OK with changes**, all folded in below. One page, `/hr/attendance`, with tabs My
attendance, Review, Settings (and Overtime in HR-3b), the HR-2a tab pattern (DD-30 strip on phones). One hub link
"KPOne HR attendance" under Staff & branches; a "Clock in" **outline** link on My roster (never a second primary).
**The top bar is unchanged** unless the owner asks (see 9.9).
- **One button, three states** (the only primary on the page; full width on phones, at least 56 px tall):
  - before clocking in: "Clock in", no dialog;
  - after: a line "Clocked in at 8:02 AM" and a toast, and the button becomes "Clock out", which asks "Clock out
    now?" in a confirm dialog so a stray tap does not end the day;
  - after clocking out: "Done for today: 8:02 AM – 5:04 PM, 9 h 2 min", with no button (a second shift that day
    shows the button again).
  While sending it reads "Clocking in…" or "Clocking out…" and is disabled. Refused states say why under the button
  ("Choose a branch you work at in the top bar.").
- **Server time line:** "Server time 8:02:15 AM" under the button, updated every second from the server's offset,
  never the device clock, and **never in a live region**. One polite live region announces state changes only
  (clocked in, clocked out, refused). Today's shift under it ("Today: Morning, 8:00 AM to 2:00 PM, Cheras").
- **Privacy line:** one muted line under the button: "KPOne records the time only. No location or photo." After a
  press on a shared computer: "Signed in as Aina CA. Sign out if this is a shared computer."
- **Formats:** KPOne's existing time and date formats everywhere ("8:02 AM", "6 Nov 2026"); worked time as
  "9 h 2 min", never a decimal.
- **My week:** one row per day (cards below 768 px): shift, clock times, worked time, flags as **warning** chips with
  text ("Late 12 min", "Left early 20 min", "No clock-out"); never red for these, and colour is never the only
  signal. Danger is used for nothing here except a refused correction. A forgotten clock-out row has an outline
  "Add clock-out time" button that opens the correction sheet.
- **Correction sheet:** kind, the recorded time beside the new one ("Recorded: none · New: 5:30 PM"), reason,
  optional note ("Do not write medical details here."). Toast "Correction sent for approval." The row then shows a
  neutral "Correction waiting" chip until it is decided.
- **Review:** branch and day pickers, a short summary line above the table ("3 flags · 1 correction waiting"), a
  table from 1024 px (person, shift, in, out, worked, flags) and cards below; waiting corrections in their own card
  with outline Approve and Reject, and the primary action inside the confirm dialog (the HR-2a pattern).
- **Settings:** number fields with units in the label ("Grace minutes"), help text for each, "Applies from" date.
- **Phone:** no sideways scroll at 320, 390 or 768 px; 44 px targets.
- **Screenshots when built:** My attendance before clocking in, clocked in, "Done for today", the Clock out confirm
  and a forgotten clock-out; the correction sheet with an error; the review day with flags; and settings, at 390
  and 1440 px, light and dark.

### 9.7 Tests (planned)

- Grants for every role, the TV account refused, none is authority; the hub map gains `/hr/attendance` (an approved
  existing-assertion change will be needed in `ClinicManagementTest`, listed for approval when built).
- Clock in and out, a double tap, a retry, a second open session refused (service and PostgreSQL), an unassigned
  branch refused, a night shift across midnight on its start day, branch-local dates with a frozen clock (never the
  UTC date: BASELINE section 6 item 21).
- Every flag against an approved roster, the grace minutes, settings versions applied by date, approved leave days.
- Corrections: the two-person rule for self, on-behalf, HR Manager and Director cases (service and PostgreSQL), the
  window, order of times, the original press kept, notes never in audit.
- PostgreSQL contention: two presses at once give one session; two deciders on one correction give one decision.
- No write to roster, leave, accounts, visits, the queue or anything clinical.
- HR-3b: day types from holidays and working days, minutes capped, approver lowers only, role eligibility, weekly
  hours at 40 and 45, summaries that add up to the day rows.

### 9.8 What stays out

- Geofence, location, selfie or any photo at clock-in (TEAM.md decision 6; PDPA review first).
- Using login, queue or consultation times as attendance; a fixed kiosk or QR clock-in.
- Pay, deductions or allowances (HR-7); exports; messages or reminders of any kind.
- Per-person rest days and split-shift rules beyond one session per shift [TO CONFIRM].
- A CA Supervisor branch view (as HR-1 decision 14).

### 9.9 Questions only the clinic can answer [TO CONFIRM]

1. Grace minutes for late and early (the form suggests 5)?
2. The forgotten clock-out window (default 60 minutes after the shift ends)?
3. How far back a correction may go (default 7 days)?
4. Do staff clock on a shared clinic PC, their own phones, or both? (Both work; a shared PC means signing out.)
5. Who may claim overtime (by role), and must overtime be asked for before it is worked?
6. Rest days: the branch's non-working weekdays for everyone, or a rest day per person?
7. A split shift (morning and evening on one day): two sessions, one per shift (the default)?
8. Should "Clock in" also be in the top bar for every role? (Default: no; a hub link and a link on My roster.)

## 10. HR-3b overtime, weekly hours and summaries: design note

Written 2026-11-06 at the owner's request ("start the HR-3b design note") while HR-3a (PR #327) waits for HR-2b
(PR #311) to merge. It works out section 9.1's HR-3b line in detail. It changes no HR-1, HR-2 or HR-3a rule, and
where it differs from section 9 it replaces it. **Status: approved with its defaults by the owner on 2026-11-06
(TEAM.md decision 32); Daniel's early look pending.** Questions in 10.9 take the defaults written here until the clinic says otherwise.

**KPOne ships no overtime rule.** Who may claim overtime, the multipliers and the weekly hour levels are
clinic-entered, versioned settings. The form is pre-filled with the clinic HR proposal's values (normal day 1.5x,
rest day 2x, public holiday 3x, nearing 40 h, limit 45 h; TEAM.md decision 10) and ticks no role, but nothing counts
until a manager presses Save. Until then the Overtime tab reads "Overtime is not set up yet." and the weekly hours
line shows the total with no warning.

### 10.1 What the clinic gets

- **Ask for overtime** (every human role the settings allow): on a day in My week where the person worked more
  than their roster, an outline "Ask for overtime" button opens a sheet with the session, the worked time beside the
  rostered time ("Worked 10 h 15 min · Rostered 8 h"), KPOne's day type ("Normal day", "Rest day" or "Public
  holiday: Deepavali"), the minutes (pre-filled with the most allowed) and a reason code (Busy clinic, Covered for
  someone absent, Stayed to finish work, Asked by a manager, Other). A manager may ask on someone's behalf from that
  person's closed day on Review (built that way: Review already shows the day's sessions).
- **Overtime tab** (Director, HR Manager): requests waiting for a decision, each with the person, date, branch, day
  type, minutes, reason and the person's hours that week; outline Approve, Approve fewer minutes and Reject (the
  primary action in the dialog). Below: decided requests for the chosen month.
- **Weekly hours:** one line on My attendance ("This week: 38 h 20 min"), and a warning chip at the nearing level
  ("Nearing 45 h this week") and past the limit ("Over 45 h this week: 46 h 10 min"); the same chip on Review and on
  each overtime request. A warning only: it never refuses a clock-in, a correction or an overtime request.
- **Summary tab** (Director, HR Manager): one branch and one week or month, up to today; one row per person who is
  assigned to that branch or worked there in the period, and a total row: rostered hours, worked hours, late (count and minutes), left early
  (count), no clock-in (count), forgotten clock-outs (count), approved overtime minutes by day type. Totals across
  all of that person's branches, with a note when some time was at another branch. Each staff member sees their
  own month on My attendance ("My month"). On screen only: no CSV (exports need their own authorisation).
- **Settings:** a second card "Overtime" on the Settings tab: roles that may claim, the three multipliers, the
  nearing and limit hours, "Applies from".

### 10.2 Rules

1. **A request is for one closed session** of the subject, within the correction window (HR-3a setting, default
   7 days back), not in the future. A session with a correction waiting cannot be asked for until it is decided.
   One pending or approved request per session (service and PostgreSQL); a split shift's two sessions can each have
   one.
2. **Day type** is worked out by KPOne from the session's branch and local date: a public holiday of that branch
   (HR-2a calendar), else a rest day (a weekday that is not a working day of that branch, until per-person rest days
   exist, 10.9), else normal. It is shown when asking and worked out again at the decision; the decision stores the
   day type it saw.
3. **Most minutes allowed:** on a normal day, the minutes outside the matched shift's window (arrived before it
   starts plus stayed after it ends), never more than the worked minutes; on a rest day or public holiday, all of the
   session's worked minutes. (Amended after Suresh's review: "worked less rostered" let a late arrival gain the
   shift's break.) A session with no shift on a normal day ("Not on the roster") allows all its worked minutes, and the
   reviewer sees that flag. Whole minutes, at least 1. Checked when asking and again at the decision.
4. **Eligibility:** only the roles ticked in the overtime settings in force on the session's date may ask, or be
   asked for. A person with several roles qualifies if any of them is ticked.
5. **The decider is never the subject and never the requester** (service and PostgreSQL CHECK), and holds
   `hr.overtime.approve.organisation`. An HR Manager's request goes to the Director; a Director's to the HR Manager or
   another Director (TEAM.md decision 3).
6. **Approve fewer minutes** lowers, never raises, and needs a reason code (Not all of it was needed, More than was
   agreed, Other). Reject needs a reason code (Not agreed in advance, Not overtime, Wrong day, Other). Notes are
   encrypted, at most 200 characters and never audited.
7. **Approval stores** the approved minutes, the day type, and the overtime settings version with its multiplier, so
   HR-7 can price it later. **No money is worked out or shown in HR-3b.**
8. **Withdraw and reverse:** the requester withdraws a pending request. A manager (not the subject) may reverse an
   approved one with a reason code (Approved by mistake, Correction changed the hours, Other); the row becomes
   "reversed" and the session may be asked for again. Nothing is deleted.
9. **A later correction** to a session with approved overtime does not change the approved minutes; Review and the
   Overtime tab show "Hours changed after approval" until a manager reverses or leaves it.
10. **Weekly hours:** worked minutes of the person's sessions whose local date falls in the branch-local Monday to
   Sunday week, at every branch; an open session counts up to now and the line says "so far"; a forgotten clock-out
   (older than 16 hours, or before the next shift of a split day) or a session missing its clock-out counts nothing
   until it is corrected; corrections waiting are not counted. Worked out when the page is
   opened, never stored.
11. **Summaries** reuse the HR-3a day rows and flags, so a summary always adds up to the day rows on Review. Late and
   left-early minutes use the HR-3a settings version of each day.
12. **HR never blocks clinical work** (TEAM.md decision 4); nothing here is read by a clinical screen.
13. **Every write:** fresh permission check under lock; `DB::transaction`; accounts locked once in id order, then the
   session and request rows; `lock_version`; idempotency keys on ask, decide, withdraw and reverse; audit with ids,
   codes, minutes and day type only.

### 10.3 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_overtime_settings` | append-only versions per organisation: effective from, normal, rest-day and public-holiday multipliers (two decimals, 1.00 to 5.00), nearing and limit hours, created by |
| `hr_overtime_setting_roles` | the roles ticked in each version (append-only) |
| `hr_overtime_requests` | subject, requested by, on behalf, session, branch, local date, minutes asked, reason code, encrypted note, status pending/approved/rejected/withdrawn/reversed, day type, minutes approved, settings version, decided by and at, decision reason, reversed by and at, reverse reason, `lock_version` |
| `hr_attendance_retries` | reused for the idempotency records |

A separate settings table keeps HR-3a's append-only `hr_attendance_settings` untouched.

PostgreSQL: one pending or approved request per session (partial unique index); the session belongs to the subject
and is closed; approved minutes at most the minutes asked and at least 1; the decider and the reverser differ from
the subject and the decider from the requester (CHECK); a decided row changes only from approved to reversed, once,
and a request's identity never changes; settings versions are append-only; no delete or truncate; every row's
people, branch and session belong to one organisation.

Migrations `2026_11_10_000900_create_hr_overtime_tables` and `2026_11_10_001000_grant_hr_overtime_permission`
(additive).

### 10.4 Permissions

| Permission | Holders |
|---|---|
| `hr.attendance.clock.own` (existing): ask for own overtime, withdraw, see own weekly hours and month | every human role, not the TV account |
| `hr.attendance.review.organisation` (existing): Summary tab, ask on someone's behalf, overtime settings | Director, HR Manager |
| `hr.overtime.approve.organisation` (new): decide and reverse overtime | Director, HR Manager |

None is in the people-and-access authority set. No new page or hub link, so no change to the Clinic management
destination map is expected.

### 10.5 Screens (Hui Min, for Daniel's early look)

Same page, `/hr/attendance`; two new tabs, **Overtime** and **Summary**, for managers (the strip scrolls on phones,
DD-30). The Clock button stays the only primary on My attendance.
- **My attendance:** the weekly hours line under the week heading with its chip (warning, never red, never colour
  alone); an outline "Ask for overtime" on each eligible day row; "My overtime" (status chips: Waiting neutral,
  Approved success, Fewer minutes approved success with the minutes, Rejected neutral with the reason, Reversed
  neutral); "My month" with the summary figures as a short definition list.
- **Ask sheet:** worked beside rostered, the day type as text, minutes as a number field with "Most allowed: 135 min
  (2 h 15 min)", reason, optional note ("Do not write medical details here."). Toast "Overtime request sent."
- **Overtime tab:** a summary line ("2 waiting · 1 hours changed after approval"), waiting cards with outline
  buttons and the decision in a dialog (Approve fewer minutes shows the minutes field and reason in the dialog);
  decided list by month as a table from 1024 px and cards below.
- **Summary tab:** branch and period pickers (Week / Month), a table from 1024 px with sticky person column, cards
  below; hours as "38 h 20 min"; a muted "Includes time at another branch" under a name where it applies.
- **Formats and phones:** KPOne time and date formats; no sideways scroll at 320, 390 and 768 px; 44 px targets.
- **Screenshots when built:** the ask sheet with an error, My attendance with a weekly warning, the Overtime tab with
  a waiting request and the fewer-minutes dialog, the Summary tab, and overtime settings, at 390 and 1440 px, light
  and dark.

### 10.6 Tests (planned)

- Grants: the new permission for Director and HR Manager only, TV account refused, not authority.
- Day types from holidays and working days at the session's branch; most minutes on each day type, with and without
  a shift and with a break; eligibility by role and settings version; the window; a waiting correction blocks.
- Two-person rule for self, on-behalf, HR Manager and Director cases (service and PostgreSQL); lower only; reasons
  required; withdraw by the requester only; reverse once by someone other than the subject; one active request per
  session (service and PostgreSQL); "hours changed after approval".
- Weekly hours at 40 and 45 across two branches, an open session "so far", a missing clock-out not counted, Monday
  to Sunday in the branch time zone with a frozen clock.
- Summaries add up to the day rows; another organisation's ids refused; notes never in audit; nothing written to
  roster, leave, sessions, accounts or anything clinical.
- PostgreSQL contention: two people deciding one request record one decision; two asks for one session record one
  request.
- Frontend: the tabs, the ask sheet, the chips and the live region.

### 10.7 What stays out

- Pay, rates, allowances or any money (HR-7); exports; messages or reminders.
- Overtime asked for before it is worked (a pre-approval) [TO CONFIRM]; per-person rest days; time off in lieu.
- A CA Supervisor branch view (as HR-1 decision 14).

### 10.8 Known risks

- Summaries for a month read every session and shift of the branch's people for that month; not load-tested.
- A role ticked or unticked later affects only sessions from that settings version's date.

### 10.9 Questions only the clinic can answer [TO CONFIRM]

1. Which roles may claim overtime? (Default: none ticked until HR ticks them.)
2. Must overtime be agreed before it is worked? (Default: no; asked for after, within 7 days.)
3. Multipliers 1.5x, 2x and 3x, and weekly levels 40 h and 45 h, as the clinic HR proposal says?
4. Rest days: the branch's non-working weekdays for everyone (default), or a rest day per person later?
5. Should a manager be able to reverse an approved request? (Default: yes, with a reason, never their own.)
6. Should staff see their own month (default yes) as well as their week?

## 11. HR-4 employee records and documents: design note

Written 2026-11-06 at the owner's request ("start the HR-4 design note") while HR-3a (PR #327) and HR-3b wait to
merge. It works out the HR-4 sketch in section 2 in detail and replaces it where the two differ. It changes no HR-1,
HR-2 or HR-3 rule. **Status: approved with its defaults by the owner on 2026-11-06 (TEAM.md decision 36); Daniel's
early look folded into 11.6 (decision 37).** The questions in 11.10 take the defaults written here until the clinic says otherwise.

**This is the most sensitive slice so far.** It holds identity numbers, bank details, addresses and emergency
contacts (TEAM.md decision 9). **Synthetic `.test` data only.** No real staff record or document is stored before the
PDPA review (decision 11, BASELINE section 6 item 1). Every page shows that in a muted line until the owner records
the review.

**What KPOne already has stays where it is.** Accounts, roles, branch assignments, the staff number, the department
and the directory job title belong to the Identity and Access code (`StaffProvisioningService`,
`BranchAssignmentService`, Phase 0B). HR-4 reads them and never writes them. A staff file shows them read-only, with
"Changed in Staff and access".

### 11.1 Split

- **HR-4a (build first): the staff file.** It holds:
  - the worker record;
  - employment periods;
  - position history;
  - personal details (identity number, address, emergency contact);
  - bank details, with a second-person confirmation;
  - correction requests from staff;
  - the Staff files list;
  - My HR details.
- **HR-4b (after HR-4a merges, under this same note): documents and checklists.** It holds:
  - the document vault, reusing HR-2b's checked, re-encoded and encrypted file pipeline;
  - onboarding and offboarding checklists;
  - the probation alert.

### 11.2 What the clinic gets

- **Staff files** (Director, HR Manager): one list of everyone with a staff file. It shows:
  - name and primary branch (from Identity);
  - employment type and status (Active, On probation, Ended);
  - probation due date;
  - a "Needs attention" chip for:
    - probation ending within 14 days;
    - bank details waiting for confirmation;
    - a correction waiting.
  - Search by name, with filters for branch, type and status.
  - It is a table from 1024 px and cards below.
- **Open a staff file** for an existing account, from the list. A file is made only on request and never in bulk.
  - **Add a locum** makes a worker record with **no KPOne login** (decision 8). The locum has a display name, contact phone and employment periods only, and cannot be rostered until a later decision extends HR-1.
- **A staff file** (Director, HR Manager) has five tabs:
  - **Employment:** periods and position history.
  - **Personal:** legal name, preferred name, identity number, date of birth, nationality, address, personal phone and emergency contact.
  - **Bank:** bank name, account holder and account number.
  - **Documents** and **Checklist** (HR-4b).
  - Sensitive values are masked by default, for example "IC •••• •••• 1234" and "Account ••••5678". A **Show** button reveals one field for this page view and writes an audit row first (field name only), as the Panel identity view does.
- **My HR details** (every human role, not the TV account): your own file, read-only, with your own values unmasked.
  - You edit your address, personal phone and emergency contact yourself.
  - For your legal name, identity number, date of birth, nationality or bank details, you **ask for a correction**, and someone else applies it.
- **Employment periods:**
  - Fields: start date, type, probation end (optional), confirmed on, and end date with an end reason.
  - Types: Permanent, Contract, Part-time, Locum, Intern.
  - End reasons: Resigned, Contract ended, Dismissed, Retired, Other with a note.
  - Ending a period changes **nothing** on the account. HR-4b's offboarding checklist reminds HR to ask the account administrator to deactivate it.
- **Position history:** title, department (from the existing list), reports to (another worker) and the date it applies from. The directory job title in Identity is not changed.

### 11.3 Rules

1. **One worker record per account** (service and PostgreSQL). A locum record has no account. A record is never
   deleted; a worker whose last period has ended shows as Ended.
2. **Employment periods never overlap** for one worker (service and PostgreSQL). An ended period's dates and reason
   change only by a recorded edit with a reason code (Wrong date, Wrong type, Other). Every edit is an append-only
   change row with field names only.
3. **Nobody manages their own file.** The Director's file is changed only by another Director, and an HR Manager's
   file only by the Director or another HR Manager (TEAM.md decision 3, and AGENTS.md "the protected director
   authority may not be governed by a non-director account"). The self-service fields on My HR details are the
   only exception.
4. **Bank details need two people.** A new or changed account is saved as **Waiting for confirmation**, and someone
   other than the person who entered it and other than the staff member confirms it (service and PostgreSQL CHECK).
   Until then the earlier confirmed account stays in force. A rejected change is kept, marked rejected.
5. **Identity number:** an IC (12 digits, with or without dashes) or a passport (letters and digits, with the issuing
   country). It is encrypted and stored with a keyed hash, so a second worker with the same number is refused
   without decrypting anyone. It is never shown in lists, search, audit or any other page.
6. **Corrections from staff:** kinds Legal name, Identity number, Date of birth, Nationality and Bank details. The new
   value is encrypted, and the request has a reason (Wrong in KPOne, Changed, Other). A manager who is not the
   person applies or rejects it; an applied bank correction then goes through rule 4.
7. **Masking:** lists, search and the Staff files list show only name, branch, type and status. Masked fields show
   the last four characters. **Show** releases one field after its audit row commits; if the audit fails, nothing is
   shown.
8. **Every write:** a fresh permission check under lock; `DB::transaction`; the worker row locked; `lock_version`; an
   idempotency key; and audit with ids and field names only, never values. Encrypted columns use Laravel's encrypted
   casts; free-text notes are encrypted and never audited.
9. **HR never blocks clinical work** (decision 4), and no clinical, queue, billing or website page reads these tables.
   The website's doctor profiles (W1b) stay separate and are never filled from a staff file.
10. **HR-4b documents:**
    - Categories: Contract, Offer letter, Identity copy, Certificate, Resignation letter and Other. Each category has a clinic-entered retention in days, and KPOne ships no number.
    - Files are PDF, JPG or PNG up to 10 MB, checked by content and re-encoded as in HR-2b, encrypted on the private disk, with no original file name kept.
    - Each document view is audited before any byte is sent.
    - Staff see and open their own documents on My HR details; managers see everyone's.
    - A file is removed with a reason (Wrong file, Wrong person, Not readable); the row stays and only the bytes go. The purge follows retention.
11. **HR-4b checklists:**
    - Onboarding and offboarding templates are clinic-entered lists of items. KPOne ships no template.
    - Starting a period offers the onboarding list, and ending one offers the offboarding list.
    - Ticking an item records who ticked it and when.
    - Items about the account ("Create the KPOne account", "Deactivate the KPOne account") show "Ask the account administrator", and ticking them records only that HR asked. HR never changes an account.
    - The probation alert appears 14 days before the probation end, on Staff files and the staff file, in KPOne only.

### 11.4 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_workers` | organisation, nullable user (unique), display name, locum contact phone (encrypted), status, `lock_version` |
| `hr_employment_periods` | worker, type, starts on, probation ends on, confirmed on, ends on, end reason, encrypted note, `lock_version` |
| `hr_positions` | worker, title, department, reports to (worker), from date |
| `hr_personal_details` | worker (unique), encrypted legal name, preferred name, identity kind, encrypted identity number, identity hash, passport country, encrypted date of birth, nationality, encrypted address, encrypted personal phone, encrypted emergency contact (name, relationship, phone), `lock_version` |
| `hr_bank_details` | worker, encrypted bank name, holder and account number, last four, status waiting/confirmed/rejected/replaced, entered by, decided by and at |
| `hr_record_corrections` | worker, kind, encrypted new value, reason, status, asked by, decided by and at, `lock_version` |
| `hr_record_changes` | append-only: worker, table, field names, changed by, reason code, time |
| `hr_record_retries` | idempotency records |
| HR-4b: `hr_documents`, `hr_document_events`, `hr_document_categories`, `hr_checklist_templates`, `hr_checklist_items`, `hr_checklist_ticks` | the HR-2b file pattern and clinic-entered templates |

PostgreSQL:
- one worker per user (partial unique index);
- no overlapping employment periods per worker (trigger);
- one waiting and one confirmed bank account per worker (partial unique indexes);
- the bank decider differs from the person who entered it and from the worker's user (CHECK);
- one identity hash per organisation (unique);
- change rows, retries and decided corrections are append-only;
- nothing is deleted or truncated;
- every row's people belong to one organisation.

Migrations from `2026_11_10_001100`, with the permission grants in their own additive migration.

### 11.5 Permissions

| Permission | Holders |
|---|---|
| `hr.records.view.organisation` (Staff files, staff files masked, Show with audit) | Director, HR Manager |
| `hr.records.manage.organisation` (open files, add locums, periods, positions, personal and bank entries, confirm bank, decide corrections) | Director, HR Manager |
| `hr.records.view.own` (My HR details, own self-service fields, ask for corrections; HR-4b own documents) | every human role, not the TV account |
| `hr.documents.view.organisation` (HR-4b: open anyone's documents, each view audited) | Director, HR Manager |

None is in the people-and-access authority set. A new hub link, "KPOne HR staff files" under Staff & branches, adds
`/hr/records` to the Clinic management destination map, which is an existing-assertion change to approve when built.
My HR details is a tab on the same page, linked from My roster.

### 11.6 Screens (Hui Min; Daniel's early look folded in)

- One page, `/hr/records`, with tabs My HR details (everyone) and Staff files (managers). A staff file opens at
  `/hr/records/{worker}`, with its five tabs (Documents and Checklist arrive with HR-4b).
- **Masked values** use a consistent pattern, "IC •••• •••• 1234", with an outline **Show** button that names the
  field ("Show identity number"). Pressing it shows the value for this view only, with a muted line "Shown just now,
  recorded". Nothing is copied to the clipboard automatically.
- **Bank tab:** the confirmed account as the main card. A change waiting shows as a second card with a warning chip
  "Waiting for confirmation", and **Confirm** and **Reject** as outline buttons, with the primary action in the
  dialog. The person who entered the change sees "Someone else confirms this".
- **My HR details:** a read-only definition list. The self-service fields have an outline **Edit**, and the other
  fields have an outline **Ask for a correction**. The sheet shows the current value beside the new one, with "Do not
  write medical details here." under the note.
- **Staff files list:** a "Needs attention" chip in warning tone with its reason in words, never colour alone.
- A muted line at the top of each page: "Synthetic data only until the PDPA review."
- **Daniel's early look (2026-11-06): OK with changes, folded in here:**
  - **Masked values:** one pattern everywhere: "IC •••• •••• 1234", "Passport •••• 5678", "Account •••• 4321". Screen readers hear words, for example "Identity number, ending 1234, hidden".
  - **Show:** once pressed, the button becomes "Hide identity number". The value hides again when the person leaves the tab or the page, and is never kept across visits. The muted line reads "Shown at 2:14 PM. This view is recorded."
  - **Bank:** both cards mask the account number to its last four, with the bank name. The waiting card puts the old and new values side by side ("Recorded: Maybank •••• 4321 · New: CIMB •••• 8765"). The person who entered it sees "Someone else confirms this" and an outline **Withdraw**, never Confirm or Reject.
  - **Staff file header:** name, role, branch and a status chip (Active, On probation, Left), with a small back link "← Staff files". The five tabs use the shared OperationalTabs as they are.
  - **Staff files list:** a table from 1024 px, cards below, and a search box. "Needs attention" carries its reason in words ("Probation ends in 7 days", "Bank change waiting"). With more than one reason it reads "2 things need attention", with each reason listed on the row or card, never in a tooltip. Probation uses a warning tone, never red.
  - **The synthetic-data line** is one shared muted line under the page header on every HR-4 page, with the same component and words each time. It is never a coloured banner.
  - **The correction sheet** reuses the HR-3a pattern exactly: "Recorded: … · New: …", the reason, the note warning, and the error under the field.
  - **Adding a locum:** visible labels, and a neutral chip "No KPOne sign-in" on the locum's file.
  - **Empty states:** for the Staff files list (no matches) and for each tab. A field on My HR details that is not filled in yet reads "Not given", never blank.
  - **HR-4b, ahead of time:** a document has a type, an issue date and an expiry chip in warning tone with words ("Expires in 30 days", "Expired 3 days ago"). Opening a document follows the same "This view is recorded" rule as Show.
- Phones: no sideways scroll at 320, 390 and 768 px; 44 px targets.
- **Screenshots when built** (Daniel added two: a value after Show with its "This view is recorded" line, and a staff file with two "needs attention" reasons):
  - the Staff files list;
  - a staff file on each tab, masked and after Show;
  - a bank change waiting, as seen by the person who entered it and by another manager;
  - My HR details with the correction sheet and an error;
  - adding a locum;
  - all at 390 and 1440 px, light and dark.

### 11.7 Tests (planned)

- Grants for every role, the TV account refused, and none in the authority set. The hub map line goes to the owner
  for approval.
- One worker per account; locums with no account; periods that overlap are refused (service and PostgreSQL); an end
  changes nothing on the account (the accounts, roles and assignments tables are compared before and after).
- Nobody manages their own file; the Director's file is changed only by another Director; an HR Manager's file only
  by the Director or another HR Manager.
- Bank: two people (service and PostgreSQL); the earlier account stays in force until a change is confirmed; a
  rejection is kept.
- Identity numbers: format, the duplicate hash refused without decrypting, and never present in lists, search,
  audit or other pages (a payload scan).
- Show writes its audit row before the value is released; a failed audit releases nothing.
- Corrections: the two-person rule, encrypted values, and notes never in audit.
- Another organisation's ids refused; nothing written to accounts, branches or anything clinical.
- HR-4b: the HR-2b file checks, view audit, retention purge, checklist ticks and the account items that change nothing.

### 11.8 What stays out

- Any account, role or branch change (Identity and Access only).
- Salary, allowances and statutory numbers (EPF, SOCSO, income tax), which are for HR-7 with its own note.
- Credentials such as APC and MMC (HR-5); appraisal, letters and discipline (HR-10).
- Import from the outside product; exports; messages or reminders of any kind.
- Rostering locums (a later decision for HR-1).
- Any real staff data before the PDPA review.

### 11.9 Known risks

- Masking narrows the view but is not anonymisation: a name with a branch still identifies a person.
- Encrypted columns cannot be searched; searching is by name only.
- The keyed identity hash protects duplicates only within one organisation, and the key must stay secret like the
  app key.

### 11.10 Questions only the clinic can answer [TO CONFIRM]

1. Who may see a full identity number or bank account with **Show**? (Default: Director and HR Manager, each view
   recorded.)
2. Must a bank change be confirmed by a second person? (Default: yes.)
3. Which fields may staff change themselves? (Default: address, personal phone and emergency contact; the rest by a
   correction.)
4. Employment types and end reasons as listed in 11.2? (Default: yes.)
5. HR-4b document categories and the retention days for each (the adviser's call); and who keeps the paper originals?
6. Should staff see their own documents? (Default: yes, view only.)
7. Is a probation alert 14 days before the end right? (Default: yes; KPOne ships no probation length.)

## 12. HR-5 credentials and compliance: design note

Written 2026-11-06 at the owner's request ("start the HR-5 design note"). It works out the HR-5 sketch in section 2
in detail and replaces it where the two differ. It changes no HR-1 to HR-4 rule. **Status: approved with its defaults by
the owner on 2026-11-06 (TEAM.md decision 38); Daniel's early look folded into 12.6 (decision 39).** The questions in 12.9 take the defaults written here until the clinic
says otherwise.

**KPOne ships no credential list and no limit.** Which credentials each role needs, when a warning starts, opening
hours, the doctor coverage rule and the work-hour limits are all clinic-entered, versioned settings. Until HR saves
them, the register reads "Credentials are not set up yet." and the planner shows no HR-5 warnings.

**What stays where it is.** The doctor's MMC registration and its verification stay in DOC-02a ("Clinician
credentials", Director only). HR-5 shows that status read-only ("MMC: verified by the Director") and never changes
it. **Nothing in HR-5 blocks clinical work** (TEAM.md decision 4): an expired APC or a missing BLS shows a warning
to HR and to the person, and never stops sign-in, Call In, a consultation, an MC or dispensing.

**Synthetic data only** until the PDPA review (decision 11). Health items such as Hepatitis B vaccination are
sensitive (decision 9).

### 12.1 Split

- **HR-5a (build first, after HR-4b): the credential register.** It holds:
  - clinic-entered credential types;
  - each person's records, with versions;
  - staff send a renewal, and someone else checks it;
  - statuses and in-KPOne reminders;
  - My credentials and the Credentials register.
  - Evidence is a document in the HR-4b vault (category Certificate), linked to the record.
- **HR-5b: roster compliance warnings on the HR-1 planner.** It holds:
  - the doctor coverage rule (opening hours and the minimum number of resident doctors);
  - work-hour rules;
  - fair-rotation counts.
  - These are warnings only, never a refusal.
- **HR-5c: SOPs.** It holds:
  - SOP documents with versions;
  - publishing to chosen roles;
  - "I have read and understood" acknowledgements;
  - a list of who has not acknowledged yet.

### 12.2 What the clinic gets

- **Credential types** (Director, HR Manager, in Settings). Each type has:
  - a name, for example "Annual Practising Certificate (APC)", "BLS", "ACLS", "Hepatitis B vaccination" or "Radiation safety";
  - whether it expires;
  - the roles that need it;
  - a "warn from" number of days (the form suggests 60);
  - whether it is a health item (shown only to the person and managers).
  - The form suggests the clinic HR proposal's list and ticks nothing; nothing counts until a manager saves.
- **My credentials** (every human role, not the TV account): one card per type my roles need, plus any I hold anyway. Each card shows:
  - the status chip: Valid, Expiring in N days, Expired N days ago, Waiting for check, Not recorded or No expiry;
  - the issue and expiry dates and the issuing body;
  - the reference number (masked to the last four), and the linked document.
  - **Add or renew** opens a sheet with the dates, the reference and an evidence file. The file goes to the HR-4b vault as a Certificate.
- **Credentials register** (Director, HR Manager): everyone against every type their roles need.
  - A summary line: "3 expiring · 1 expired · 2 not recorded · 1 waiting for check".
  - Filters by branch, role, type and status.
  - It is a table from 1024 px, cards below.
  - Waiting records have outline **Accept** and **Reject**, with the primary action in the dialog.
- **Reminders, inside KPOne only** (decision 5):
  - the chips on My credentials and the register;
  - one line on My roster and My attendance: "Your BLS expires in 21 days";
  - the 60, 30 and 7 day steps change the words ("Expires in 30 days"), never a message.
- **HR-5b on the planner:** warning chips beside the week, each with its reason in words:
  - "No resident doctor Tue 2:00 PM – 5:00 PM (Cheras)";
  - "Dr Aina: 7 days in a row";
  - "Dr Aina: 9 h rest before Wed (11 h set)";
  - "Planned 52 h this week (limit 48 h)".
  - A **Fair rotation** panel counts weekend, public holiday and night shifts per doctor for the month.
- **HR-5c SOPs** (Director, HR Manager publish; everyone acknowledges):
  - an SOP list with title, version, roles and "N of M acknowledged";
  - each person sees "SOPs to read" on My HR details, opens the PDF (the view is recorded) and presses **I have read and understood**;
  - a new version needs a new acknowledgement.

### 12.3 Rules

1. **A credential record is versioned.** A renewal adds a version; earlier versions stay, and only the newest
   accepted version is in force.
2. **Someone else checks.** A record a staff member sends is "Waiting for check" until a manager who is not that
   person accepts it (service and PostgreSQL CHECK). Until then the earlier accepted version stays in force. A record
   a manager enters for someone else is accepted by another manager, never by the person who entered it. A rejection
   needs a reason (Wrong dates, Not readable, Wrong person, Other) and is kept.
3. **Status** is worked out when a page opens, never stored:
   - **Not recorded:** a type the person's roles need with no accepted version.
   - **Expired:** an expiry date before today (branch-local).
   - **Expiring:** within the type's warn-from days.
   - **Valid** or **No expiry** otherwise.
   - "Waiting for check" is shown beside the status of the version in force.
4. **Dates:** the issue date is not in the future, and the expiry date is after the issue date. A type with "no
   expiry" refuses an expiry date.
5. **Health items** (Hepatitis B and any type marked as health) record only Done or Not done and the date: no test
   results, no titres, no notes about health. They are shown only to the person and to Director and HR Manager, and
   never in lists other people see.
6. **Reference numbers** are encrypted and masked to the last four everywhere except on Show (the HR-4 rule:
   audited before release).
7. **HR-5b warnings come only from approved and draft weeks, and never refuse.**
   - **Coverage:** the branch's opening hours by weekday, and the minimum number of resident doctors (the form suggests 1). A gap of 15 minutes or more is shown.
   - **Work hours:** the most days in a row (the form suggests 6), the least rest between two shifts (11 h), and the most planned hours a week (48 h).
   - **Fair rotation:** counts only.
   - Approved leave (HR-2a) counts as a day off.
8. **HR-5c:** an SOP version is a PDF (the HR-2b checks, encrypted, view recorded). An acknowledgement is
   append-only: who, which version, when. A version is never edited, only replaced by a new one. Withdrawing an SOP
   keeps every acknowledgement.
9. **Every write:** a fresh permission check under lock; `DB::transaction`; the worker and record rows locked;
   `lock_version`; an idempotency key; and audit with ids, type codes and dates only. Reference numbers and notes
   never reach audit.

### 12.4 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_credential_types` | organisation, name, code, expires, warn-from days, health item, active, `lock_version` |
| `hr_credential_type_roles` | type, role (versioned with the type's settings) |
| `hr_credential_records` | worker, type, version, issued on, expires on (nullable), issuing body, encrypted reference, last four, linked document (HR-4b), status waiting/accepted/rejected/replaced, entered by, decided by and at, decision reason |
| HR-5b: `hr_branch_opening_hours`, `hr_coverage_rules`, `hr_work_hour_rules` | clinic-entered versions per branch or organisation |
| HR-5c: `hr_sops`, `hr_sop_versions`, `hr_sop_audiences`, `hr_sop_acknowledgements` | an SOP, its PDF versions, roles and append-only acknowledgements |
| `hr_compliance_retries` | idempotency records |

PostgreSQL:
- one accepted version in force per worker and type (partial unique index);
- the decider is never the worker's user and never the person who entered it (CHECK);
- the expiry date is after the issue date, and empty when the type does not expire;
- decided records, settings versions and acknowledgements are append-only;
- nothing is deleted or truncated;
- every row's people belong to one organisation.

Migrations from `2026_11_10_001500`, with the grants in their own additive migration.

### 12.5 Permissions

| Permission | Holders |
|---|---|
| `hr.credentials.view.own` (My credentials, send own renewals) | every human role, not the TV account |
| `hr.credentials.manage.organisation` (register, types, enter for others, accept or reject; HR-5b settings) | Director, HR Manager |
| `hr.sops.manage.organisation` (HR-5c: publish, replace, withdraw, see who has not acknowledged) | Director, HR Manager |
| `hr.sops.acknowledge.own` (HR-5c: read and acknowledge SOPs for your roles) | every human role, not the TV account |

None is in the people-and-access authority set, and none touches DOC-02a's `clinician_credentials.manage.organisation`.
New hub links ("KPOne HR credentials", and "KPOne HR SOPs" with HR-5c) add `/hr/credentials` and `/hr/sops` to the
Clinic management destination map, which are existing-assertion changes to approve when built.

### 12.6 Screens (Hui Min; Daniel's early look folded in)

- **My credentials:**
  - one card per type, with the status chip (warning tone for Expiring and Expired, neutral for Waiting for check, Not recorded and No expiry; never red);
  - dates in KPOne formats ("30 Nov 2026");
  - an outline **Add** or **Renew** on each card, with the primary action in the sheet;
  - the masked reference, read to screen readers as "Reference, ending 4321, hidden".
- **Credentials register:**
  - the summary line, then a matrix of person × required type, with a chip in each cell, as a table from 1024 px;
  - below 1024 px, one card per person listing only what needs attention, then "All others valid";
  - a waiting record shows the recorded and new values side by side, with outline Accept and Reject.
- **Settings card for credential types:** name, expires (Yes or No), warn from (days, suggests 60), the roles that need it (ticks, using the app's role labels) and Health item (Yes or No), with saved versions listed below.
- **Planner warnings (HR-5b):**
  - a "Compliance" chip on the week header with the count;
  - its list opens in the existing planner side panel;
  - each line names the person, the day and the rule in words.
- **SOPs (HR-5c):**
  - a list with version and "N of M acknowledged";
  - on each person's side, an "SOPs to read" card with an outline **Open** and, once opened, the primary **I have read and understood** (one primary on the card);
  - "Acknowledged on 6 Nov 2026" afterwards.
- The shared synthetic-data line from HR-4, and the HR-4 Show pattern for references.
- **Daniel's early look (2026-11-06): OK with changes, folded in here:**
  - **Status words:** the time is in the words, with the date beside it ("Expires in 21 days · 27 Nov 2026", "Expired 3 days ago"). Expired gets stronger warning text and a small alert icon so it never reads the same as Expiring, and still never red. Waiting for check, Not recorded and No expiry stay neutral.
  - **Register matrix:** the person column and the header row stay sticky. Cells hold short words ("Valid", "21 days", "Expired", "Waiting"), and screen readers hear the full sentence ("APC, expires in 21 days, 27 Nov 2026"). A type the person does not need shows "—", read as "Not required". There is no colour legend.
  - **Waiting records** show who entered them and when. The person whose record it is sees "Someone else checks this" and no Accept or Reject (the HR-4 bank rule).
  - **Planner warnings (HR-5b):** a worded chip ("3 compliance warnings") in warning tone. Each line in the side panel names the person, the day and the rule. One plain line at the top: "Warnings only. They don't stop you saving or sending the roster."
  - **SOPs (HR-5c):** "I have read and understood" is disabled until the SOP has been opened, with a hint "Open the SOP first". When a new version is published, the card says "Version 3 is new. Please read it again." and lists the old acknowledgement as being for version 2. "Who hasn't acknowledged" lists name, role, branch and "published 6 Nov 2026" as plain text, with no red and no ranking.
  - **MMC number:** shown read-only with a muted source line, "From Clinician credentials. The Director updates this."
  - **Settings:** "Health item" has help text saying what it changes: only Done or Not done and a date, seen only by the person and managers.
  - **Empty states:** My credentials with nothing required ("Your role needs no credentials"), the register with no types set up (pointing to Settings), and SOPs with none published.
  - **One more screenshot:** a register row showing each status, at 1440 px dark.
- Phones: no sideways scroll at 320, 390 and 768 px; 44 px targets.
- **Screenshots when built:** My credentials with each status; the renew sheet with an error; the register at 1440 and 390 px; a waiting record decided by someone else; the type settings; a planner week with each HR-5b warning (HR-5b); an SOP being acknowledged and the "who has not" list (HR-5c); light and dark.

### 12.7 Tests (planned)

- Grants for every role, the TV account refused, none in the authority set, and the hub map lines to approve.
- Versions and the version in force; someone else checks (service and PostgreSQL); a rejection is kept; the earlier
  version stays in force while one is waiting.
- Statuses at the warn-from boundary, on the expiry day and the day after, with a frozen branch-local clock; types
  with no expiry; Not recorded only for the roles that need it.
- Health items: only Done or Not done and the date accepted; shown only to the person and managers (payload scan).
- References encrypted, masked and never in audit; Show audited first.
- Nothing blocks clinical work: a person with an expired APC still signs in, calls in and issues an MC (the
  DOC-02a and queue tests stay green, plus one direct check).
- DOC-02a's clinician credentials are untouched (the table is compared before and after).
- HR-5b: a coverage gap, days in a row, short rest and planned hours, with approved leave counted; warnings never
  refuse a save or an approval.
- HR-5c: a new version needs a new acknowledgement; acknowledgements are append-only; withdrawal keeps them; each
  PDF view is recorded.
- PostgreSQL refusals; another organisation's ids; nothing written to accounts, rosters or anything clinical.

### 12.8 What stays out

- Any change to DOC-02a (MMC number and verification stay Director only), and any regulator lookup or API.
- Any clinical gate (decision 4).
- Health details beyond Done or Not done and a date.
- The KKM audit file export, which needs its own export decision.
- Shift swaps (HR-1b); opening locum slots (needs locum rostering first).
- Clinic document licences, the internal audit checklist and the vaccine fridge log (HR-11).
- Messages or reminders outside KPOne of any kind.

### 12.9 Questions only the clinic can answer [TO CONFIRM]

1. Which credentials does each role need? (Default: none ticked; the form suggests APC and BLS for Resident Doctor,
   BLS for CA and CA Supervisor, and Hepatitis B vaccination for clinical roles.)
2. When should a warning start? (Default: 60 days before expiry, with the words changing at 30 and 7.)
3. Hepatitis B: only Done or Not done and a date? (Default: yes; anything more is health data for the PDPA review.)
4. Who checks a renewal? (Default: Director or HR Manager, never the person themselves.)
5. HR-5b: each branch's opening hours, and the minimum number of resident doctors? (Default: none until entered; the
   form suggests 1.)
6. HR-5b work-hour limits? (Default: suggested 6 days in a row, 11 h rest and 48 h a week, to confirm with the HR
   adviser.)
7. HR-5c: who must acknowledge which SOPs? (Default: chosen per SOP by role.)

## 13. HR-6 staff claims: design note

Written 2026-11-06 at the owner's request ("start the HR-6 design note"). It works out the HR-6 sketch in section 2
in detail and replaces it where the two differ. It changes no HR-1 to HR-5 rule. **Status: approved with its defaults by
the owner on 2026-11-06 (TEAM.md decision 40); Daniel's early look folded into 13.6 (decision 41).** The questions in 13.9 take the defaults written here until the clinic
says otherwise.

**Staff claims are not Panel Claims.** They use different tables, pages, permissions and words ("Staff claims",
"Claim a refund of what I paid"), and share nothing with Panel Claims, invoices, receipts, payments, refunds, daily
cash closing or any report of clinic income. **KPOne pays nothing:** it records that someone paid a claim outside
KPOne, with a reference.

**KPOne ships no category, limit or rule.** Categories, their limits, the amount above which the Director must
approve and how far back a claim may go are clinic-entered, versioned settings. Until HR saves at least one category,
My claims reads "Staff claims are not set up yet."

**Synthetic data only** until the PDPA review (decision 11). Receipts can carry names, addresses and card digits.

### 13.1 One slice

HR-6 is one slice, built after HR-5c in the current order. HR-6 does not need HR-4, so the owner may move it earlier.
It covers:
- settings (categories and the Director level);
- submitting a claim with receipts;
- approval by someone else;
- recording payment;
- a monthly list for Finance.

Mileage, per diem, advances and paying through payroll are out (13.8).

### 13.2 What the clinic gets

- **My claims** (every human role, not the TV account):
  - **New claim** opens a sheet with category, date spent, amount in RM, what it was for (up to 200 characters, encrypted) and receipts (JPG, PNG or PDF, up to 5 MB each, up to 5 per claim, through the HR-2b checks), for the active branch.
  - My list shows each claim's status: Waiting for approval, Approved, Rejected, Paid, Withdrawn.
  - The person who sent a claim withdraws it while it waits.
- **Approvals** (Director, HR Manager): claims waiting, each card showing:
  - the person, branch, category, date and amount;
  - the receipts (opening one is recorded);
  - the category's limit and the person's total in that category this month;
  - any warning in words: "Over the RM 200.00 limit for Meals", "This receipt was also attached to claim SC-0012", "Needs the Director: over RM 500.00".
  - Outline **Approve** and **Reject** buttons, with the primary action in the dialog.
- **Pay** (Finance Officer, Director): approved claims not yet paid. **Record payment** asks for:
  - the method (Bank transfer, Cash, Cheque);
  - the paid date;
  - a reference (encrypted, masked to the last four).
  - A mistake is corrected with **Reverse payment** and a reason; the claim goes back to Approved, and both rows stay.
- **Monthly list** (Finance Officer, Director, HR Manager): approved and paid claims for a month, by branch and category, with totals. On screen only: no CSV (exports need their own authorisation).
- **Settings** (Director, HR Manager). Each category has:
  - a name;
  - whether a receipt is needed;
  - an optional limit per claim and per person per month;
  - whether it is active.
  - The settings also hold the Director level (claims above it need the Director) and how many days back a claim may go (the form suggests 30).
  - KPOne ships none of these.

### 13.3 Rules

1. **A claim** belongs to one person, one branch (the active branch when it is sent) and one category. It has:
   - an amount in whole sen, from 1 to 99,999,999;
   - a date spent that is not in the future and not further back than the settings allow.
   - Claim numbers are `SC-` plus 6 digits, per organisation, never reused.
2. **Receipts:**
   - A category that needs a receipt refuses a claim without one.
   - A receipt is checked by content, re-encoded and encrypted, with no file name kept (the HR-2b pipeline).
   - The same file (by hash) on another active claim of the organisation is a warning to the approver, never a refusal (two people can share one bill).
3. **Two people at each step** (service and PostgreSQL CHECK):
   - the approver is never the claimant;
   - the payer is never the claimant and never the approver of that claim.
   - An HR Manager's own claim goes to the Director, and a Director's to the HR Manager or another Director (TEAM.md decision 3).
4. **The Director level:** a claim above the saved amount can be approved only by a Director. Below it, a Director or
   HR Manager approves.
5. **Limits warn, never refuse.** Over a category's per-claim or monthly limit, the approver sees it in words and may
   still approve. The approval stores the settings version it saw.
6. **Approve fewer** is not offered. A wrong amount is rejected with the reason Wrong amount, and the person sends a
   new claim. A claim is decided as sent, and never edited after it is sent.
7. **States:**
   - waiting → approved, rejected or withdrawn;
   - approved → paid;
   - paid → approved again only through a payment reversal row.
   - Every step is an append-only action row.
   - Reject reasons: No receipt, Not a work expense, Over the limit, Wrong amount, Duplicate, Other with a note.
   - A payment reversal reason: Wrong claim, Wrong amount, Payment failed, Other.
8. **Nothing here touches clinic money:** no invoice, receipt, payment method, refund, cash closing, Insights or
   report of clinic income reads or writes staff claims. A test compares those tables before and after.
9. **Every write:** a fresh permission check under lock; `DB::transaction`; the claim row locked; `lock_version`; an
   idempotency key on send, decide, withdraw, pay and reverse; and audit with ids, category, amount and codes only.
   The purpose text, notes and payment references are encrypted and never audited.

### 13.4 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_claim_settings` | append-only versions: Director level (sen, nullable), days back |
| `hr_claim_categories` | organisation, name, needs receipt, per-claim limit, monthly limit, active, `lock_version` (changes recorded as versions) |
| `hr_claim_counters` | per organisation, for `SC-` numbers |
| `hr_staff_claims` | number, person, branch, category, date spent, amount sen, encrypted purpose, status, settings version, `lock_version` |
| `hr_staff_claim_receipts` | claim, encrypted file path, hash, size, kind, status active/removed |
| `hr_staff_claim_actions` | append-only: claim, action, actor, reason code, encrypted note, time |
| `hr_staff_claim_payments` | append-only: claim, method, paid on, encrypted reference, last four, paid by; reversal rows point to the payment they reverse |
| `hr_claim_retries` | idempotency records |

PostgreSQL:
- a claim's amount, person, branch, category and date never change after it is sent;
- the approver differs from the claimant, and the payer from the claimant and the approver (CHECK);
- one live payment per claim (partial unique index), and a reversal row points to exactly one payment;
- actions, payments, settings versions and counters are append-only, or increment-only for counters;
- nothing is deleted or truncated;
- every row's people and branch belong to one organisation.

Migrations from `2026_11_10_002000` (HR-5c took `001900`; TEAM.md decision 52), with the grants in their own additive migration.

### 13.5 Permissions

| Permission | Holders |
|---|---|
| `hr.claims.submit.own` (My claims: send, withdraw, see own receipts) | every human role, not the TV account |
| `hr.claims.approve.organisation` (Approvals: open receipts, approve, reject) | Director, HR Manager |
| `hr.claims.pay.organisation` (Pay: approved claims with name, branch, category, amount and receipts only; record and reverse payments) | Finance Officer, Director |
| `hr.claims.manage.organisation` (Settings; the monthly list with HR's view) | Director, HR Manager |

Finance Officer also sees the monthly list through `hr.claims.pay.organisation`. None is in the people-and-access
authority set, and none is a Panel or billing permission. A new hub link, "KPOne HR staff claims", adds `/hr/claims`
to the Clinic management destination map, which is an existing-assertion change to approve when built.

### 13.6 Screens (Hui Min; Daniel's early look folded in)

- One page, `/hr/claims`, with tabs My claims, Approvals, Pay, Monthly and Settings, each shown only to its holders, using the shared OperationalTabs.
- **Amounts:**
  - in KPOne's money format ("RM 45.60"), right-aligned with tabular-nums;
  - typed in RM in the sheet, converted to sen with whole-number arithmetic (the STK-10B rule), never floating point.
- **The New claim sheet:**
  - visible labels and help text: "Up to 5 receipts. JPG, PNG or PDF, 5 MB each.";
  - the HR-2b upload button and "Uploading…";
  - "Do not write medical details here." under the purpose;
  - errors under each field, all in one submit.
- **Status chips:** Waiting for approval neutral, Approved success, Paid success with the date, Rejected neutral with the reason, Withdrawn neutral. None is red.
- **Approvals cards:**
  - warnings as warning chips with words;
  - the receipts as thumbnails on a neutral bordered surface (the HR-2b pattern), with each opening recorded.
- **Pay:** a table from 1024 px, cards below. A paid row shows "Paid 6 Nov 2026 · Bank transfer · Ref •••• 4321".
- **Monthly:** totals per category and branch, with a total row (the HR-3b Summary pattern).
- **Daniel's early look (2026-11-06): OK, with these to build in:**
  - **Approved and Paid** are both in success tone, so the words tell them apart: "Approved" and "Paid 6 Nov 2026".
  - **The claimant** never sees Approve or Reject on their own claim, only "Someone else approves this" (the HR-4 bank rule). On the HR Manager's view, a claim above the Director level reads "Waiting for the Director".
  - **A reversed payment** shows "Payment reversed" in neutral tone with its reason, and the claim is back to Approved.
  - **The amount field** has a visible "RM" prefix, `inputmode="decimal"` and the help text "For example 45.60".
  - **Receipt thumbnails** have alt text ("Receipt 1 of 3, JPG"), and a PDF shows a file tile, never a broken image.
  - **Not built (owner, 2026-11-06: "keep rule 6"):** Daniel also suggested "Approved RM 30.00 of RM 45.60" for a partly approved claim. Rule 6 stands: there is no partial approval, so a wrong amount is rejected with the reason Wrong amount and sent again.
  - **Daniel's option, kept:** a claim rejected for Wrong amount shows the reason in words on the claimant's card, with an outline **Send a new claim** that opens the New claim sheet with the purpose and receipts copied in. Receipts copied from the claimant's own rejected claim do not count as a duplicate on the approval card. The new claim is a separate claim with its own number, and the rejected one is kept.
- The shared synthetic-data line from HR-4. Empty states for each tab, each saying what to do next. 44 px targets, and no sideways scroll at 320, 390 and 768 px.
- **Screenshots when built:** My claims with each status; the New claim sheet with an error; an approval card with each warning; the Director-level case seen by an HR Manager; a payment recorded and reversed; the monthly list; the settings; at 390 and 1440 px, light and dark.

### 13.7 Tests (planned)

- Grants for every role, the TV account refused, none in the authority set, and the hub map line to approve.
- Amounts in sen with whole-number conversion; date limits with a frozen branch-local clock; claim numbers unique
  and gapless per organisation under a race (a two-process PostgreSQL test).
- Receipts: content checks and limits; a needed receipt refused when missing; the duplicate warning.
- The two-person rule at approval and payment, for the self, HR Manager and Director cases (service and PostgreSQL);
  the Director level; limits that warn and never refuse.
- States, withdrawal by the sender only, the payment reversal back to Approved with both rows kept, and one live
  payment per claim (a two-process race between two payers).
- Purpose, notes and references encrypted and never in audit; references masked.
- Clinic money untouched: invoices, receipts, payments, refunds, cash closings and Panel claims compared before and
  after.
- Another organisation's ids refused; nothing written to accounts or anything clinical.

### 13.8 What stays out

- Paying money from KPOne, bank files and any payment integration.
- Mileage, per diem, cash advances and paying claims through payroll (HR-7 may add "paid through payroll" later).
- Exports; messages or reminders of any kind.
- Any link to patient billing, Panel Claims, daily cash closing or clinic income reports.
- Tax treatment, which is for the clinic's accountant.

### 13.9 Questions only the clinic can answer [TO CONFIRM]

1. Which claim categories, and does each need a receipt? (Default: none shipped; the form suggests Meals, Travel and
   parking, Training and courses, Supplies bought for the clinic, and Other, each needing a receipt.)
2. Limits per claim and per month for each category? (Default: none; limits warn only.)
3. Above what amount must the Director approve? (Default: none until entered.)
4. How far back may a claim go? (Default: the form suggests 30 days.)
5. Must the payer be someone other than the approver? (Default: yes.)
6. Are claims ever paid in cash from the till? (Default: no. KPOne records a cash payment but never links it to daily
   cash closing, so till cash for staff claims must be handled outside KPOne. Tell us if that is wrong.)

## 14. HR-7 payroll: design note

Written 2026-11-06 at the owner's request ("start the HR-7 design note"). It works out the HR-7 sketch in section 2
and TEAM.md decision 7 in detail and replaces the sketch where the two differ. It changes no HR-1 to HR-6 rule.
**Status: approved with its defaults by B.E.R.N.I.S.S for the owner on 2026-11-06, during the owner's 20-minute "BE ME" (TEAM.md decision 43, marked "Check this" on the owner's board); Daniel's early look folded into 14.6.** The questions in 14.10 take the
defaults written here until the clinic says otherwise. **Most of them are for the clinic's accountant.**

**This is the riskiest slice, and it is built to be safe before it is built to be clever.**
- **Every rate, band, threshold, divisor and rounding rule is clinic-entered data.** That includes EPF, SOCSO, EIS, MTD (PCB) and the HRD levy, entered by one person and checked by a second (decision 7). **KPOne ships no statutory number** and computes nothing from a rate written in code.
- **Every payroll page shows one plain line:** "To confirm with the clinic's accountant before real use. Synthetic data only." This stays until the owner records the accountant's sign-off and the PDPA review.
- **KPOne makes no payment, files nothing with KWSP, PERKESO or LHDN, and posts nothing to an accounting ledger.** It prepares figures, has them approved, shows payslips and gives a reviewed export file (decision 7).
- **All money is whole sen.** There is no floating point anywhere; percentages are stored as basis points, and every rounding step follows the rule written on its table.

### 14.1 Split

- **HR-7a: pay profiles and statutory tables.** Each person's pay profile (salary, fixed allowances, pay type, which statutory schemes apply), effective-dated and confirmed by a second person. The clinic-entered statutory tables and the pay item types. Nothing is calculated yet.
- **HR-7b: the payroll run.** A monthly run per pay group, built from the HR-7a profiles and from approved HR records:
  - approved overtime (HR-3b);
  - approved unpaid leave (HR-2a);
  - worked hours for hourly staff (HR-3a);
  - approved staff claims (HR-6);
  - manual lines.
  - The run goes Draft, then Sent for approval (frozen), then Approved by someone else, then Locked. A correction is a new adjustment run, never an edit.
- **HR-7c: payslips and exports.** An encrypted payslip snapshot per person per approved run, shown in KPOne and printable. A reviewed bank-upload file and an accountant summary, each audited and never stored. A statutory summary on screen, for the clinic to enter by hand on the KWSP, PERKESO and LHDN portals.

### 14.2 What the clinic gets

- **Pay profiles** (preparers), opened from the HR-4 staff file. Each profile has:
  - **pay type:** Monthly salary, or Hourly (part-time, paid on HR-3a worked hours). Locums are paid outside KPOne in v1 (14.8).
  - **basic monthly salary** or **hourly rate**, in RM;
  - **fixed allowances**, each a clinic-entered pay item type and an amount;
  - **the schemes that apply:** EPF (with its category, for example "Malaysian below 60"), SOCSO (its category), EIS, MTD and HRD levy, each Yes or No;
  - **the pay group;**
  - **the date it applies from.**
  - A new or changed profile is **Waiting for confirmation** until someone other than the person who entered it, and other than the worker, confirms it (the HR-4 bank rule). The earlier profile stays in force until then.
  - Bank details come from the confirmed HR-4 bank record and are never copied here.
- **Pay item types** (clinic-entered): for example Basic salary, Fixed allowance, Overtime, Unpaid leave, Staff claim refund, Bonus, Other addition, Other deduction. Each has:
  - whether it is earnings, a deduction or a refund;
  - a Yes or No for each of EPF, SOCSO, EIS, MTD and HRD wages, because the clinic decides, for example, whether overtime counts for EPF.
- **Statutory tables** (preparers enter, a second person checks). There is one kind of table, a **banded table**, which is enough for EPF, SOCSO, EIS and the HRD levy:
  - each table has a name, a scheme, a category (for example SOCSO Category 1), the date it applies from and a source note (where the figures came from);
  - its rows are wage bands (from and to, in sen) with an employee and an employer part, each either a fixed amount in sen or a rate in basis points;
  - each table has a rounding rule: none, to the nearest sen, or up to the next ringgit.
  - A table counts only once it is **Checked** by someone other than the person who entered it, and a checked version never changes; a correction is a new version.
- **MTD (PCB) in v1:** the monthly MTD amount per person is **entered on the run, from the LHDN calculator or the accountant**, and checked with the run. KPOne does not compute MTD in v1: the formula needs reliefs, TP1 and TP3 forms and year-to-date figures that the clinic's accountant must design with us first (14.8).
- **Rate settings** (clinic-entered, versioned): how a daily and an hourly rate are derived from a monthly salary (for example "basic ÷ 26 days" and "daily ÷ 8 hours"), used for overtime and unpaid leave. KPOne ships no divisor.
- **Payroll runs** (HR-7b): pick a pay group and a month, and press **Prepare**. The draft lists each person's lines and totals, with every figure's source in words:
  - "Overtime: 3 h 30 min × RM 12.50 × 1.5 (normal day, approved 4 Nov)";
  - "Unpaid leave: 1 day × RM 96.15";
  - "Staff claim SC-000012 refund";
  - "EPF employee 11% of RM 2,500.00, rounded up".
  - Warnings in words: "No confirmed pay profile", "No checked EPF table for this category", "MTD not entered", "Bank details waiting for confirmation".
  - **Prepare again** recalculates the whole draft. **Send for approval** freezes it. The **approver**, who is never the preparer, reads the frozen run and presses **Approve**, or **Return** with a reason. **Lock** follows approval, and only a locked run gives payslips and exports.
- **Payslips** (HR-7c): every person sees their own on My HR details. Each payslip shows:
  - the clinic letterhead;
  - the period;
  - earnings, deductions and refunds;
  - the employer contributions as information;
  - the net pay, and the bank account masked to the last four.
  - Printing uses the browser, white in every theme (PRINT-1). Opening a payslip is recorded.
- **Exports** (HR-7c), from a locked run only, by an exporter. Each download writes its audit row first, and the file is never stored:
  - the **bank file**, a clinic-chosen layout from a small list (generic CSV of name, bank, account, amount, reference; more layouts on request);
  - the **accountant summary** CSV (totals by person and pay item type);
  - both with the CSV safety rules: UTF-8 BOM, formula-neutralised cells, and the row cap and throttle of the reports.
  - The **statutory summary** is on screen only, with totals per scheme to type into the portals by hand.

### 14.3 Rules

1. **Money:** integers in sen; rates in basis points (1100 = 11.00%); multipliers as hundredths (150 = 1.5x). Every
   multiplication rounds once, at the step its table or setting names, and the rounding is shown on the line.
2. **Statutory tables and pay profiles need two people.** The person who enters is never the one who checks or
   confirms, and nobody confirms their own pay profile (service and PostgreSQL CHECK). Only checked tables and
   confirmed profiles are used.
3. **Effective dates:** a run uses the profile, table and setting versions in force on the last day of its month,
   and the approval stores every version id it used, so a later version never changes an approved run.
4. **Sources are only approved records:**
   - overtime approved in HR-3b (minutes × hourly rate × the multiplier stored on the approval);
   - unpaid leave approved in HR-2a, on leave types the clinic marks as unpaid;
   - HR-3a worked minutes for hourly staff;
   - staff claims approved in HR-6 and not yet paid.
   - A staff claim paid through payroll is recorded as paid by the payroll run when the run is locked, through HR-6's own payment row (method Payroll), so it can never be paid twice.
5. **Manual lines:** an addition or deduction with a pay item type, an amount and a reason code. They belong to the
   draft and are frozen with it.
6. **Approval:** the approver is never the preparer. A run prepared by the Director needs another Director, or the
   HR Manager if the clinic allows it (14.10). A returned run goes back to Draft, and an approved run is locked and
   never edited.
7. **Corrections:** an adjustment run for the same month references the locked run. It holds only the differences
   and goes through the same two-person approval. Payslips show the original and the adjustment separately.
8. **Who sees what:**
   - preparers and approvers see the salaries and lines of the runs they handle;
   - exporters see the locked run's net pay per person, and the bank file;
   - everyone sees only their own payslip.
   - Salary never appears in Staff files lists, HR-4 tabs for other holders, audit or any other page.
9. **Every write:** a fresh permission check under lock; `DB::transaction`; the run row locked (and the worker rows,
   in id order); `lock_version`; an idempotency key; and audit with ids, codes, counts and version ids only, never
   amounts. Payslip snapshots and amounts are encrypted at rest.
10. **HR never blocks clinical work**, and no clinical, billing, Panel, cash-closing or Insights page reads payroll.

### 14.4 Tables (sketch)

| Table | Holds |
|---|---|
| `hr_pay_groups` | organisation, name, pay day, active |
| `hr_pay_item_types` | organisation, name, kind earnings/deduction/refund, counts for EPF, SOCSO, EIS, MTD and HRD (Yes or No each), active |
| `hr_pay_profiles` | worker, pay group, pay type, encrypted basic or hourly rate, schemes and categories, effective from, status waiting/confirmed/rejected/replaced, entered by, confirmed by and at |
| `hr_pay_profile_items` | profile, pay item type, encrypted amount |
| `hr_rate_settings` | append-only versions: daily and hourly divisors |
| `hr_statutory_tables` | organisation, scheme, category, effective from, rounding rule, source note, status entered/checked/replaced, entered by, checked by |
| `hr_statutory_table_rows` | table, wage from and to (sen), employee and employer part as fixed sen or basis points |
| `hr_payroll_runs` | pay group, month, kind regular/adjustment, adjusts run, status draft/sent/approved/locked, prepared by, approved by, version ids used, `lock_version` |
| `hr_payroll_lines` | run, worker, pay item type or scheme, encrypted amount, source kind and id, encrypted explanation |
| `hr_payslips` | run, worker, encrypted snapshot, hash |
| `hr_payroll_exports` | append-only audit of each download: run, layout, row count, by, time (no file) |
| `hr_payroll_retries` | idempotency records |

PostgreSQL:
- the decider differs from the entrant for tables and profiles, and the approver from the preparer for runs (CHECK);
- a checked table, a confirmed profile, a sent or approved run and its lines never change;
- one regular run per pay group and month (unique);
- one confirmed profile in force per worker and date;
- payslips, exports, settings and table rows are append-only;
- nothing is deleted or truncated;
- every row belongs to one organisation.

Migrations from `2026_11_10_002300`, with the grants in their own additive migration.

### 14.5 Permissions

| Permission | Holders |
|---|---|
| `hr.payroll.prepare.organisation` (profiles, tables, pay item types and settings, prepare runs; see salaries) | HR Manager, Director |
| `hr.payroll.approve.organisation` (confirm profiles and check tables entered by someone else; approve, return and lock runs) | Director |
| `hr.payroll.export.organisation` (bank file and accountant summary from locked runs; the statutory summary) | Director, Finance Officer |
| `hr.payslips.view.own` (my payslips) | every human role, not the TV account |

None is in the people-and-access authority set. Under rule 2, an HR Manager can enter a table that the Director
checks, and the Director can enter one that the HR Manager checks only if the clinic allows it (14.10). A new hub
link, "KPOne HR payroll", adds `/hr/payroll` to the Clinic management destination map, which is an
existing-assertion change to approve when built. **Exports** are authorised by decision 7 ("a reviewed file") and
follow the RPT-01 safety rules.

### 14.6 Screens (Hui Min; Daniel's early look folded in)

- One page, `/hr/payroll`, with tabs Runs, Pay profiles, Tables and Settings for preparers, and Exports for exporters. Payslips sit on My HR details for everyone.
- **The accountant line** under the header on every payroll page: the shared muted component from HR-4, with these words.
- **Runs:**
  - one card per run, with its status chip: Draft neutral; Waiting for approval neutral; Approved success; Locked success with the date; Returned neutral with the reason.
  - The run page is a table from 1024 px (person, earnings, deductions, refunds, employee contributions, net, warnings), with a total row, and cards below.
  - Each person's row opens a side panel with every line and its source sentence.
- **Warnings** are warning chips with words, listed at the top ("4 things to check before sending") and on each row.
- **Tables:** a banded table is edited as rows (from, to, employee, employer) with right-aligned tabular-nums, a rounding select with help text, and the source note. A checked table is read-only, with "Checked by … on …".
- **Pay profile:** amounts masked to "RM ••••" for anyone outside payroll, with no Show for them. For preparers the full amounts show, and a waiting change appears side by side ("Recorded: RM 2,500.00 · New: RM 2,700.00").
- **Payslip:** one A4 layout, white in every theme, letterhead, period, earnings, deductions and refunds as three short tables, net pay in bold, the bank masked.
- **Approve, Return and Lock** are outline buttons with the primary action in the dialog. "Lock" says what it means: "Locked runs make payslips and exports and can't be changed. Corrections need an adjustment run."
- 44 px targets, no sideways scroll at 320, 390 and 768 px. Wide run tables scroll inside their own container.
- **Daniel's early look (2026-11-06): OK with notes, folded in here:**
  - **Money** uses Billing's shared `myr()` (`resources/js/types/billing.ts`) everywhere, so payroll and billing match exactly: "RM 2,500.00" with a thousands separator, and after BIL-9 a negative amount reads "−RM 1.50" with a true minus sign (Farah, 2026-11-06).
  - **The run table** from 1024 px keeps the person column sticky while it scrolls inside its container (the UX-9 pattern), with the total row at the bottom.
  - **Warnings and errors are told apart.** Warnings never stop sending, and the list says so ("Warnings don't stop you sending"). Anything that does stop sending, such as no confirmed pay profile, no checked table for a scheme in use, or MTD not entered for someone with MTD on, is an error in the destructive tone with its reason, and the disabled Send button says why.
  - **The Lock dialog's** primary button is "Lock run", never red: locking is not deleting.
  - **Masked amounts** "RM ••••" are read to screen readers as "Amount hidden".
  - **Table editing:** each row's add and remove buttons are named ("Remove band 3"), and an overlap or gap error sits under the row it belongs to.
  - **Exports:** before the download, a summary of what the file holds (run, period, number of people and total); afterwards a muted "Downloaded at 2:14 PM. Recorded." (the HR-4 Show pattern).
  - **Payslip print:** an A4 `@page` margin of 15 to 20 mm, checked on images taken from a real A4 PDF; the period is written as "October 2026".
  - **Empty states** for each tab: no runs yet; no tables checked (pointing to Tables); no payslips yet.
- **Screenshots when built:**
  - a statutory table being checked;
  - a pay profile change waiting;
  - a draft run with warnings;
  - a person's line panel;
  - a run waiting for approval as seen by its preparer;
  - an approved and locked run;
  - a payslip on screen and in print;
  - the export page;
  - all at 390 and 1440 px, light and dark.

### 14.7 Tests (planned)

- Grants for every role, the TV account refused, none in the authority set, and the hub map line to approve.
- **Calculation golden files:** synthetic tables and profiles for a monthly and an hourly worker, with overtime on
  each day type, unpaid leave, a claim refund, manual lines, each scheme and each rounding rule. Each expected figure
  is written by hand in the test, never computed by the code under test.
- Integer-only arithmetic, with a source scan that fails on float casts or `round()` on money in the payroll code.
- The two-person rules for tables, profiles and runs (service and PostgreSQL); a checked or confirmed version never
  changes; version ids are stored on approval, and a later version leaves an approved run unchanged.
- A claim paid through payroll exactly once (HR-6 payment row), including a two-process race between locking the
  run and a Finance payment of the same claim.
- An adjustment run holds only the differences; a locked run and its payslips never change.
- Payslips: own only, encrypted, the view recorded, the print white.
- Exports: from locked runs only, audit before release, formula-neutralised, no stored file, the row cap and
  throttle.
- Visibility: salary absent from every other page payload (a scan), amounts never in audit.
- Clinic money untouched: billing, Panel, cash closing and Insights tables compared before and after.

### 14.8 What stays out

- Any payment, bank API or bank-specific integration beyond the reviewed file.
- Any filing with KWSP, PERKESO, LHDN or HRD Corp, and any outside HR or payroll product.
- **MTD computed by KPOne** (a later slice HR-7d, designed with the accountant: reliefs, TP1, TP3, year-to-date and
  additional remuneration).
- Locum pay in KPOne, which v1 leaves outside (a later decision once locums can be rostered); EA forms and
  year-end statements (a later slice); bonuses by formula; salary advances and loans.
- The Director's and the HR Manager's own pay, which v1 keeps outside KPOne (option (a), TEAM.md decision 54): no
  pay profile for them, and they are never in a run (service, PostgreSQL and page).
- Accounting ledger entries; costs on the HR dashboard (HR-9 after HR-7).
- Any real salary, statutory number or payslip before the accountant's sign-off and the PDPA review.

### 14.9 Known risks

- **Statutory correctness depends entirely on the tables the clinic enters.** The two-person check and the source
  note reduce mistakes but cannot replace the accountant.
- Hourly pay depends on HR-3a clock data, and a missing clock-out pays nothing until it is corrected.
- Encrypted amounts cannot be summed in SQL, so totals are computed in PHP from decrypted lines. That is acceptable
  for a clinic's headcount; the run page is not load-tested.

### 14.10 Questions only the clinic can answer [TO CONFIRM] (most with the accountant)

1. Pay groups and pay day? (Default: one monthly group, pay day entered by HR.)
2. Who may approve runs? (Default: the Director only, never the preparer. A run the Director prepares needs another
   Director; tell us if the HR Manager may approve one in that case.)
3. Who checks statutory tables and confirms pay profiles? (Default: the Director, or the HR Manager when the
   Director entered it.)
4. Which pay items count for EPF, SOCSO, EIS, MTD and HRD? (Default: none until the clinic ticks them.)
5. Daily and hourly rate divisors for overtime and unpaid leave? (Default: none shipped; the accountant enters them.)
6. MTD: entered per person per run in v1 (default), with KPOne's own calculation later as HR-7d?
7. Part-timers paid hourly from clock data (default yes); locums paid outside KPOne in v1 (default yes)?
8. The bank file layout, and does Finance need the accountant summary? (Default: generic CSV, and yes.)
9. Paper payslips: does HR print them for anyone? (Default: each person prints their own.)
10. Date format on payslips and other HR prints (Daniel's question): the MC and drug label print "30/10/2026", while invoices and receipts print "30 Oct 2026". (Default: "30 Oct 2026", as on invoices and receipts, with the period as "October 2026".)

## 15. HR-12 to HR-16: the five newly authorised items (design notes, drafts)

Written 2026-11-06 after the owner authorised the five items that were not authorised ("all i want."; "the one that
needed before real use i will provide later", relayed verbatim by B.E.R.N.I.S.S; TEAM.md decision 45). **Status:
approved by the owner on 2026-11-06 ("approve all five HR notes for liyana"; TEAM.md decision 47); the 15.7 questions
stay open.** Nothing in this section is built until (a) B.E.R.N.I.S.S's docs PR
records the authorisation in `AGENTS.md` and `docs/BASELINE.md`, (b) the owner approves that slice's note, and (c)
Daniel has had his look. **Scope: staff and HR only.** No note here sends anything to a patient or reads patient
data; patient messaging stays a separate owner decision. **Synthetic data only** in every slice until the owner
provides the PDPA review (and, where named, the leave policy and the accountant's confirmation).

Shared rules for all five:
- **Secrets** (API keys, tokens, webhook signing secrets) live in the environment's secret store and reach KPOne as
  environment variables named in `.env.example` with empty placeholders. Never in code, in the database, in a commit
  or in a real `.env` that the team opens. A missing key turns the feature off with a plain message; it never fails a
  clinical or HR write.
- **Every outside call** is made from a queued job or a short synchronous call with a timeout, never inside a
  database transaction that holds row locks, and every attempt is logged with ids and status codes only.
- **Every write** keeps the HR pattern: a fresh permission check under lock, `DB::transaction`, row locks,
  `lock_version`, idempotency keys and audit with ids and codes only.
- **HR never blocks clinical work**, and nothing here is read by a clinical, billing or Panel screen.

### 15.1 HR-12 Outside HR product link (smallest; first)

- **Question first (for the owner):** which product, and does it have a documented API? The proposal names
  MySyarikat-style features, not a specific integration. **If there is no API, there is no live link**: KPOne offers
  a reviewed **file export and import** instead.
- **Direction (default):** KPOne → the outside product only, one way, for the data the clinic names (default: staff
  list with employment type and dates, approved leave days, approved overtime minutes; never identity numbers, bank
  details or documents unless the owner and the PDPA review say so). Nothing flows back into KPOne in v1, so KPOne
  stays the source for what it holds.
- **With an API:** the credentials (client id and secret, or token) are held by a named person the owner chooses,
  entered in the environment's secret store; a Director turns the link on; each send is a queued job with a retry
  limit, logged with counts only; a failed send never changes HR data.
- **Without an API (file):** a CSV export in the product's import format, made on request by the Director or HR
  Manager, audited (filters and row count), formula-safe, at most 5,000 rows, never stored, plus an optional
  import preview that **shows** differences and changes nothing.
- **Tests:** the export's exact columns, formula neutralising, row cap, audit, and that no identity or bank value is
  in the file by default; with an API, a fake HTTP client proves timeouts and failures change nothing.

### 15.1a HR-12 as built (2026-11-07)

- **The owner's answers** (15.7 item 1, in the HR session): a generic CSV export first. There is no named product or API yet, so there is no live link and no import preview in v1. The data is the staff list, approved leave and approved overtime only. No payroll, identity numbers, bank details or documents.
- **Where:** a closed-by-default "Export for an outside HR product" section on the Staff files tab of `/hr/records`, for the Director and the HR Manager (`hr.records.manage.organisation`). No new permission, no migration and no Clinic management line.
- **Files:**
  - Staff list: KPOne ID, name, kind, position, employment type and dates, primary branch, and status in the Staff files words, plus Starting.
  - Approved leave touching a month: type, from, to, "Days in request" and paid. A note line in the file explains requests that cross months.
  - Approved overtime in a month: date, day type and approved minutes, never pay.
- **Rules:** each download is a POST, audited before release with the kind, month and row count, and never stored. Cells are formula-safe, and the 5,000-row cap refuses rather than cuts.
- **Known limits:**
  - The file is uploaded to the outside product by hand.
  - Leave rows carry the whole request's days.
  - The leave type is shown by name and can hint at health. "Hide leave type in the outside-HR export?" is on the owner's PDPA-review list (B.E.R.N.I.S.S for the owner).
- Built in PR #397, merged as `8c6d78b`. Daniel: OK. Suresh: ready to merge after M1.

### 15.2 HR-13 Job application intake (KPOne's first public intake)

- **What:** a public "Apply" form for open positions; applications land in a private HR list; HR moves an
  application through New, Shortlisted, Interview, Offered, Not taken forward, Withdrawn. Turning an applicant into
  staff is still done by the account administrator through `StaffProvisioningService`; HR-13 creates no account.
- **Where:** the careers pages are static today (W1b drafts; the website is not published from KPOne). v1 serves the
  form from KPOne at a public route, like the QR check-in, linked from the careers page later. **Turned off by
  default** (`HR_APPLICATIONS_ENABLED=false` in `.env.example`) until the privacy notice is approved.
- **Protection:** per-IP and per-form rate limits, a honeypot field and a minimum fill time, a short-lived signed
  form token, server-side validation as the only authority, file upload checked by content (PDF only, at most 5 MB,
  one CV), re-encoded or stored encrypted on the private disk, no original file name kept, and no file ever served
  publicly. No CAPTCHA service in v1 (it would be an outside call); the owner may choose one later.
- **Privacy:** a privacy notice version shown and recorded with each application (draft until the owner's legal
  check), the minimum fields (name, phone, email, position, short message, CV), encrypted at rest, deleted after a
  clinic-entered retention (no number shipped), never used for marketing. A thank-you page shows no data back.
- **Permissions:** `hr.applications.manage.organisation` (Director, HR Manager). Every CV view is audited before any
  byte is sent.
- **Tests:** rate limits, honeypot and timing, file checks, the switch off by default, audit before release, no
  account created, and retention purge.

### 15.2a HR-13 as built (2026-11-07)

- **The owner's answers** (15.7 item 4 and the form language, in the HR session):
  - Positions: HR keeps a list in KPOne.
  - Retention: HR enters it, and KPOne ships no number.
  - Language: the public form shows English and Bahasa Melayu side by side, its error messages included.
  - Who approves the privacy notice: the owner. The notice stays a draft (`draft-hr13-applications-v1`) until then.
- **Switched off twice over:**
  - The form returns 404 unless `HR_APPLICATIONS_ENABLED=true` (false in `.env.example` and in the config default).
  - Even with the switch on, it stays closed until HR saves a retention period, because the notice promises deletion after that period (Daniel; option (a), chosen by B.E.R.N.I.S.S for the owner).
  - **The switch must not be turned on until the owner approves the privacy notice. A lawyer review is recommended** (B.E.R.N.I.S.S for the owner).
- **The public form** (`/careers/apply`):
  - It asks for the position, name, phone, email, an optional message, one PDF CV of at most 5 MB, and a privacy tick.
  - Spam is kept out by a signed form token with a one-time nonce, a honeypot field and a 5-second minimum fill time. The honeypot is checked first, then the fields and the CV in one pass, then the timing, so a person sees what to fill in and a robot gets the thank-you page with nothing stored.
  - Each address may send 3 a minute and 10 an hour, and the whole form 100 an hour.
  - Personal values and the CV are encrypted, and the original file name is never kept.
  - Nothing typed goes into the session, and no account is ever created.
- **The HR page** (`/hr/applications`, Director and HR Manager, `hr.applications.manage.organisation`):
  - A status line says whether the form is on, off, or off until a retention period is saved.
  - Applications, Positions and Settings tabs.
  - Steps: New → Shortlisted → Interview → Offered, with Not taken forward and Withdrawn from any open step. A declined offer can become Withdrawn; every other closed step is final.
  - A CV open is recorded before any byte is sent.
- **Retention:**
  - A first or shorter period waits 7 days.
  - The daily `hr-applications:purge` clears the values and the CV, counting from when an application closed, or from when it was sent if nobody closed it (Suresh, M1). The row and its history stay.
- **Known limits:**
  - One organisation per installation.
  - An application still at Interview is cleared once the period has passed since it was sent.
  - Phone and email show on the HR list without a Show button.
  - The overall cap of 100 sends an hour can lock real applicants out for up to an hour.
  - Behind a proxy with no trusted list, all senders share one address for rate limiting.
  - The CV's size and hash and the nonce stay after a purge.
  - The clinic name is fixed text.
  - The PDF check is shallow.
  - Applicants have no self-service withdrawal or deletion.
  - Daniel's optional follow-up (showing the pending retention date on the "on" line) is left for later.
- Built in PR #402, merged as `9eedfd4`. Suresh: ready to merge after M1, M3, M4 and M5. Daniel: OK with changes, then OK.

### 15.3 HR-14 Staff messages: WhatsApp, SMS, email and OTP (foundation for HR-15)

- **Provider (owner to choose):** one provider per channel, for example an email service, an SMS gateway and the
  WhatsApp Business Platform through a provider. **The owner (or a person they name) holds each account**; KPOne
  holds only the API key, in the environment's secret store. WhatsApp needs approved message templates and a
  business number; this takes days with Meta and is outside KPOne.
- **Cost:** shown per message type in the settings page from a clinic-entered price per message; a monthly count
  per channel on the dashboard; a monthly cap per channel after which KPOne stops sending and says so.
- **Opt-in and opt-out:** each staff member chooses channels on My HR details (default: nothing on, in-KPOne
  notices only); every SMS and WhatsApp carries a stop instruction; a "stop" reply or an opt-out in KPOne turns that
  channel off at once. OTP for sign-in (HR-15) is the one message sent without a marketing opt-in, and only to the
  number on the person's own account.
- **What is sent:** fixed templates only, with no free text from KPOne users in v1: "Your leave request was
  approved", "A shift was added on 8 Mar", "Your payslip for October 2026 is ready in KPOne", "Your KPOne code is
  123456". **No patient data, no clinical text, no amounts of money and no identity or bank values in any message**;
  a template lint test refuses them.
- **What is stored:** a delivery row per message (template code, channel, recipient user id, provider message id,
  status, timestamps); never the rendered text for SMS or WhatsApp beyond the template code and its placeholder ids;
  provider webhooks verified by signature and recorded by status only.
- **OTP:** six digits, hashed at rest, valid 5 minutes, at most 5 tries and 3 sends per 15 minutes per account,
  never logged, never shown to staff.
- **Tests:** template lint (no patient or money fields), opt-out stops sending, caps stop sending, a fake provider
  proves failures never change HR data, webhook signatures checked, OTP limits.

### 15.4 HR-15 Locum portal (needs HR-14 for OTP)

- **Accounts:** a locum gets a KPOne sign-in only when HR asks and the account administrator creates it through
  `StaffProvisioningService` (public registration stays disabled; an unknown identity never creates a user; Google
  sign-in only for an existing active account). A new narrow role, `locum`, links the account to the HR-4a locum
  file.
- **What a locum sees:** their own roster shifts (HR-1 extended to roster locums with an account), open locum shifts
  the clinic offers, and their own claims and payslips if HR-6 and HR-7 apply to them. Nothing clinical by
  implication: any clinical permission for a locum doctor is a separate explicit role decision (AGENTS.md).
- **Sign-in:** password (the existing rules) or a one-time code by SMS or WhatsApp to the account's own number
  (HR-14). Sessions follow the existing timeout and inactive-account rules.
- **Open shifts:** HR posts an open shift; a locum asks to take it; a manager who is not the locum confirms; the
  confirmed shift joins the next roster draft. No automatic assignment.
- **Tests:** no self-registration path, role permissions exact, a locum sees only their own data, OTP limits, and a
  confirmed open shift never lands in an approved roster without approval.

### 15.5 HR-16 Selfie or location at clock-in (riskiest under PDPA; last)

- **This is the riskiest item in KPOne HR.** A selfie is biometric-adjacent personal data and a location is
  sensitive; both need the PDPA review the owner will provide **before any real staff use**, a written purpose, a
  consent text and a retention the clinic sets. The team will not turn either on for real staff before that.
- **Off by default, per branch:** a Director turns on "photo at clock-in" and/or "location check at clock-in" for one
  branch at a time, with the consent text shown to staff first.
- **Selfie:** taken in the browser at the moment of the press, re-encoded, stored encrypted on the private disk,
  never used for face matching (no biometric template, no outside face service), seen only by the HR Manager and
  Director on a disputed day, each view audited, deleted after a short clinic-set retention (default suggestion 30
  days, shipped as a suggestion only).
- **Location:** read once in the browser at the moment of the press and compared with the branch geofence (centre
  and radius entered by the Director); KPOne stores only "inside", "outside" or "not available" and the accuracy
  band, **never the coordinates**, and never tracks anyone before or after.
- **Fallback:** refusing the camera or location, or a GPS failure, never blocks the clock-in; the session is marked
  "no photo" or "location not checked" and appears on Review, where a manager may ask the person.
- **Tests:** off by default, coordinates never stored, selfie encrypted and purged on retention, every view audited,
  a refusal still clocks in, and nothing in HR-3a's rules changes when both are off.

### 15.6 Proposed order (owner to confirm)

After HR-4b, HR-5a to HR-5c, HR-6 and HR-7: HR-12 (smallest; may become a file export), HR-13 (public intake, its own
privacy notice), HR-14 (messages; HR-15 depends on it), HR-15 (locum portal), HR-16 (selfie and location; last,
riskiest). HR-14 could come earlier if the owner wants staff reminders for leave and claims sooner; the cost is
setting up provider accounts first.

### 15.7 Questions only the owner can answer [TO CONFIRM]

1. Which outside HR product, and who has its admin login? Does it have an API?
2. Which providers for email, SMS and WhatsApp, and who holds each account?
3. The monthly message cap and the price per message to show.
4. Applications: which positions, how long applications are kept, and who approves the privacy notice.
5. Locums: which locums get a sign-in, and may a locum doctor's account ever get clinical permissions?
6. Selfie and location: which branch first, the geofence radius, the retention, and the consent text (after the PDPA
   review).

## 16. HR-7b payroll runs: design note

Written 2026-11-06 after HR-7a (#376). It works out the runs part of section 14 in detail and changes no HR-1 to
HR-7a rule. **Status: approved by B.E.R.N.I.S.S for the owner (2026-11-06, under the owner's delegation that day),
with the defaults in 16.11 (TEAM.md decision 56); built in HR-7b (PR #379).** 16.13 records what was decided while building it.
Everything below is synthetic until the accountant's sign-off and the PDPA review. The accountant line stays on every
payroll page. The defaults are not an accountant's sign-off.

### 16.1 Scope
- **A monthly run per pay group:**
  - Draft (prepare and prepare again);
  - Sent for approval (frozen);
  - Approved by someone else;
  - Locked.
  - A returned run goes back to Draft with its reason.
- **An adjustment run** for a locked month holds only the differences and goes through the same steps.
- **Not in HR-7b:**
  - payslips and the bank, accountant and statutory exports (HR-7c);
  - MTD computed by KPOne (HR-7d);
  - EA forms;
  - any payment or filing.
- **Who is in a run:**
  - **Managers' own pay stays outside KPOne** (option (a), decided by B.E.R.N.I.S.S for the owner). A run never includes a Director or HR Manager, and the run page says so.
  - Locums stay outside.

### 16.2 What a run reads
Only approved records, as of the month's last day (the branch-local calendar of the worker's primary branch):

| Source | What becomes a line |
|---|---|
| Confirmed HR-7a pay profile in force on the last day | basic salary (monthly) or the hourly rate, and each fixed allowance |
| HR-4 employment periods | the days employed in the month, for joiners and leavers (16.3) |
| HR-3b overtime approved in the month | minutes × hourly rate × the multiplier stored on its approval's setting |
| HR-2a leave approved in the month, on types with `is_paid` = false | days (half days allowed) × daily rate, as a deduction |
| HR-3a worked minutes in the month (hourly staff) | minutes × hourly rate |
| HR-6 claims approved and not yet paid | a refund line ("Staff claim SC-000012 refund") |
| Manual lines | an addition or deduction with a pay item type, an amount and a reason code |

The pay group's name and pay day are copied onto the run when it is prepared (Suresh, HR-7a M3).

### 16.3 Calculation (whole sen, integer arithmetic only)
1. **Derived rates are kept as exact fractions:**
   - daily rate = basic ÷ the daily divisor;
   - hourly rate = the daily rate ÷ the hourly divisor.
   - A line is rounded once, half up to the nearest sen, and the line shows the rounding. Nothing is rounded twice.
2. **Joiners and leavers:** basic and allowances are pro rata by calendar days employed ÷ days in the month, rounded down to the sen. **[TO CONFIRM with the accountant]**
3. **The wage for each scheme** is basic, plus every line whose pay item type counts for that scheme, minus unpaid leave if its type counts. **Basic always counts** for every scheme the profile has. **[TO CONFIRM]**
4. **Statutory parts:**
   - find the band holding the wage in the checked table in force on the last day;
   - a rate part is wage × basis points ÷ 10000, rounded by the table's rule;
   - a fixed part is taken as written;
   - the employer part is shown as information.
5. **Choosing the table:**
   - **EPF and SOCSO:** the profile's category must match a checked table's category, ignoring case.
   - **EIS and HRD:** v1 needs exactly one checked table in force for the scheme, with any category; two in force is an error (Suresh, HR-7a M3).
   - HR-7b also warns at profile entry when no entered or checked table has the typed EPF or SOCSO category.
6. **MTD:** entered per person on the draft in RM, from the LHDN calculator or the accountant. It is required when the profile has MTD on.
7. **Net pay** = earnings − the employee's statutory parts − MTD − deductions + refunds.

### 16.4 Errors (stop sending) and warnings (never stop sending)
- **Errors:**
  - no confirmed profile for someone in the group;
  - no checked table for a scheme in use, or two for EIS or HRD;
  - MTD missing where it is on;
  - no rate settings while overtime or unpaid-leave lines exist;
  - net pay below zero.
  - The disabled Send button says which.
- **Warnings:**
  - bank details missing or waiting for confirmation;
  - an hourly worker with a clock-in that has no clock-out;
  - a claim older than 90 days.
  - "Warnings don't stop you sending."

### 16.5 Freezing, approval and locking
- **Freezing.** Send for approval freezes the run and stores every version it used:
  - profile ids and item-type versions;
  - table ids and the rate-settings version;
  - the copied pay group;
  - each line's source kind and id.
  - A later change to a source never changes a sent run. Prepare again works only in Draft.
- **Approval.** The approver is never the preparer (CHECK). The Director approves; a run prepared by the Director needs another Director (14.10 item 2 default).
- **Locking:**
  - the approver locks;
  - only a locked run will give payslips and exports (HR-7c);
  - on lock, each HR-6 claim in the run gets HR-6's own payment row with method Payroll, so it is never paid twice;
  - if the locker is that claim's claimant or approver, the lock is refused and names the claim;
  - a two-process race test covers a lock against a Finance payment of the same claim.
  - This adds `payroll` to the HR-6 payment method CHECK in a new HR migration.

### 16.6 Corrections
An adjustment run references the locked run and holds only the differences, with the same two-person steps. One open adjustment per run at a time. A locked run and its lines never change.

### 16.7 Tables (sketch)
| Table | Holds |
|---|---|
| `hr_payroll_runs` | pay group, month, kind regular/adjustment, adjusts run, status, preparer, approver, locker, copied group name and pay day, version ids used, `lock_version` |
| `hr_payroll_run_people` | run, worker, profile id, encrypted MTD, encrypted totals |
| `hr_payroll_lines` | run, worker, item type and version or scheme, encrypted amount, source kind and id, encrypted explanation |
| `hr_payroll_run_actions` | append-only: prepared, sent, returned (reason), approved, locked |

Retries are the shared `hr_record_retries`.

**PostgreSQL rules:**
- the approver is not the preparer;
- one regular run per pay group and month;
- a sent, approved or locked run and its lines never change;
- statuses move draft → sent → approved → locked, and sent or approved back to draft (16.13);
- nothing is deleted or truncated;
- one organisation throughout.

Migrations from `2026_11_10_002500`.

### 16.8 Permissions
The existing `hr.payroll.prepare.organisation` and `hr.payroll.approve.organisation`. No new grant and no hub change.

### 16.9 Screens (Hui Min, HR-PLAN 14.6 and Daniel's early look)
- **Runs tab:** one card per run with a worded status chip.
- **The run page:**
  - a table from 1024 px (person, earnings, deductions, refunds, employee contributions, net, warnings) with a sticky person column inside its own scrolling container, and a total row;
  - cards below 1024 px;
  - each person opens a side panel of lines, each with its source sentence ("Overtime: 3 h 30 min × RM 12.50 × 1.5 (normal day, approved 4 Nov)").
- **Errors and warnings:** errors are in the destructive tone with their reason, and warnings are worded chips.
- **Lock dialog:** the primary button is "Lock run", never red.

### 16.10 Tests (planned)
- Golden files written by hand: a monthly and an hourly worker, overtime on each day type, unpaid leave, a joiner, a claim refund, manual lines, each scheme and each rounding rule.
- A source scan that fails on a float cast or `round()` on money.
- Two-person and freeze rules (service and PostgreSQL).
- A later version leaves a sent run unchanged.
- A claim paid once, including the lock-against-Finance race.
- An adjustment run holds only the differences.
- Managers and locums are never in a run.
- No amount reaches audit or the session.
- Clinic money tables are identical before and after.

### 16.11 Questions [TO CONFIRM] and the defaults used meanwhile
1. Pro rata for joiners and leavers: calendar days (default) or working days?
2. Does basic always count for every scheme on the profile? (Default yes.)
3. Rounding of derived rates: one half-up rounding per line (default).
4. Who locks: the approver (default).
5. Rate settings and item-type scheme flags change pay with one person (Suresh, HR-7a M4). Default: the run shows who set each version, with no second person in v1.
6. A claim whose claimant or approver is the locker: the lock is refused and names the claim (default).

### 16.12 Known risks and limits
- Statutory correctness depends entirely on the tables the clinic enters.
- Hourly pay depends on HR-3a clock data.
- Encrypted amounts are totalled in PHP, and the run page is not load-tested.
- When a person drops out of a run (for example their profile moved group), their MTD and manual lines drop out
  without notice (Suresh, M2).
- An approver deactivated at the very moment another approver takes a draft step can leave a run with no approver;
  reactivating them or adding an approver recovers it.
- The only-approver message names the HR Manager, so a clinic with one Director and no active HR Manager cannot
  prepare a run.
- Approval does not lock claim rows, so Finance can still pay a claim between approve and lock; the lock is then
  refused and the approver sends the run back.

### 16.13 Decided while building HR-7b (B.E.R.N.I.S.S for the owner, 2026-11-06)
- **Pay item types used by runs:** a versioned setting in HR-7b's own tables names the pay item type a run uses for
  overtime, unpaid leave and staff claim refunds, so each line knows which schemes it counts for.
- **Adjustment runs hold manual difference lines only** in v1, with a reason per line. A negative net on an
  adjustment is a warning, never an error, since a recovery is negative by nature (Suresh, S2).
- **Sending back:** a sent or approved run goes back to Draft with a reason; from approved, only its approver sends
  it back (Suresh, B1). The claim checks run before approval as well as at lock, so a run never reaches a lock
  nobody may take.
- **Every prepare step counts:** anyone who prepared any part of a run (prepare, prepare again, MTD, manual lines)
  never approves, sends back or locks it; `prepared_by` stays the first preparer (Suresh, S1).
- **The only approver left never prepares** (Suresh, S6, option (a)): while no other active approver who has not
  prepared the run would be left, an approve-holder's draft steps are refused with "You are the only person left
  who can approve this run, so the HR Manager makes this change.", and Prepare run with "You are the only person
  left who can approve payroll runs, so the HR Manager prepares them."
- **HR-6:** a claim paid through payroll shows the method "Payroll", which Finance never chooses in Record payment;
  reversing it is refused with "Paid through a locked payroll run. Correct it with an adjustment run." The HR-6
  migration change only adds `payroll` to the method CHECK; no HR-6 assertion changed.
- **Tabs:** Runs is a tab on `/hr/payroll`; Pay profiles stays the default. The one existing-assertion change is in
  `PayrollSetupTest` (`payroll.tabs` gains `runs`).
- **Daniel's look:** Lock run is the primary step and never red, with the Lock confirm in the standard tone; phone
  cards read "Employee contributions" and show Refunds when not zero.

## 17. HR-7c payslips and exports: design note

Written 2026-11-07 after HR-7b (#379). It works out the payslips and exports part of section 14 (14.2, 14.3 items 7
and 8, 14.4, 14.5 and 14.10) and changes no HR-1 to HR-7b rule. **Status: approved by B.E.R.N.I.S.S for the
owner on 2026-11-07, with the answers in 17.9 and the change to 17.3 below; built in PR #393.** Everything stays synthetic until the accountant's sign-off and the PDPA review, and the accountant line
stays on every payroll page. **KPOne makes no payment and files nothing.**

### 17.1 Scope
- **Payslips:**
  - one encrypted payslip snapshot per person per locked run, written at the moment of locking, and never changed;
  - each person sees only their own;
  - an adjustment run gives its own payslip, shown beside the original ("Adjustment to October 2026").
- **Exports from a locked run only:**
  - the bank file;
  - the accountant summary CSV;
  - the statutory summary on screen.
- **Not in HR-7c:**
  - MTD computed by KPOne (HR-7d);
  - EA forms and year-end statements;
  - any payment, bank API, filing, or link to the outside HR product;
  - emailing or messaging payslips (HR-14 later, "Your payslip for October 2026 is ready in KPOne" only, never the payslip itself).

### 17.2 Payslips
- **Where:** a "Payslips" section on the person's own My HR details page, newest first. A person with none sees "No payslips yet."
- **What one shows:**
  - the clinic letterhead (BIZ-01);
  - the person's name, and the pay group and period ("October 2026");
  - earnings, deductions and refunds as three short tables, each line in the words HR-7b stored ("Overtime · 3 h 30 min × RM 12.50 × 1.5");
  - the employee's statutory parts and MTD;
  - the employer contributions as information;
  - the net pay in bold;
  - the bank account masked to the last four, read from the HR-4 staff file at locking time;
  - the line "Generated by KPOne. To confirm with the clinic's accountant before real use."
- **Snapshot:** written in the lock transaction, one per person, encrypted, with a hash. A later change to the letterhead, bank details or a name never changes an issued payslip.
- **Opening is recorded** (who, which payslip, when; no amount), as HR-2b does for evidence files.
- **Print:** one A4 layout, white in every theme (PRINT-1), with a 15 to 20 mm `@page` margin, checked on images taken from a real A4 PDF. No PDF is stored.
- **Preparers and approvers** can open any payslip of a run they could open; nobody else sees another person's payslip.

### 17.3 Exports
- **Who:** the Director only in v1 (17.9 item 2), from a locked run only, on an Exports tab of `/hr/payroll`.
- **Each download:**
  - writes its audit row first (`hr_payroll_exports`: run, kind, layout, row count, by, time);
  - is never stored;
  - follows the CSV rules of the reports: UTF-8 BOM, formula-neutralised cells, a row cap and a throttle.
- **The bank file:** v1 ships one generic CSV: name, account holder, bank, account number, amount and the reference "Salary October 2026" ("Salary adjustment October 2026" for an adjustment run).
  - It pays the confirmed bank details frozen on each payslip at locking (`hr_payslips.bank_detail_id`), so the file always pays the account the payslip shows, and downloading it again gives the same file.
  - **Changed after review (Suresh's S1; approved by B.E.R.N.I.S.S for the owner, 2026-11-07):** anyone who had no confirmed bank details when the run was locked is left out of the file and named on the Exports tab, to be paid outside the file. The draft said to refuse the whole run; once the account is frozen at locking, that refusal could never be cleared.
  - A run where nobody had confirmed bank details gives no bank file, and an account that cannot be read, or is not 5 to 20 digits, refuses the whole file and names the person.
  - The Exports tab tells the Director to open the file in a spreadsheet as text, so account numbers keep their leading zeros.
- **The accountant summary CSV:** totals by person, section and item, plus each scheme's employee and employer parts, grouped by the worker so two people with the same name stay apart (Suresh's S2).
- **The statutory summary:** on screen only. It shows totals per scheme (EPF, SOCSO, EIS, HRD, MTD), employee and employer parts and the head count, for the clinic to type into the KWSP, PERKESO and LHDN portals by hand.

### 17.4 Permissions (decided in 17.9)
- `hr.payroll.export.organisation`: the bank file, the accountant summary and the statutory summary from locked runs. **The Director only in v1.** The Finance Officer gets no payroll access; this is to be revisited after the PDPA review.
- `hr.payslips.view.own`: my payslips, for every human role, not the TV account.
- Neither permission is people-and-access authority. One additive migration (`2026_11_10_002800`) grants them.
- Payroll preparers and approvers may open any payslip; nobody else sees another person's payslip.
- **Changes outside HR:**
  - the `/hr/payroll` page opens to exporters, for the Exports tab only;
  - the existing "KPOne HR payroll" Clinic management row also shows for exporters, through one approved `ClinicManagementTest` hub-map line: `'/hr/payroll' => ['hr.payroll.prepare.organisation', 'hr.payroll.export.organisation'],`;
  - My HR details gains the My payslips section.

### 17.5 Rules
- Payslips and exports exist only for a locked run. A locked run and its payslips never change; a correction is an adjustment run with its own payslip.
- Every write follows the HR pattern: a fresh permission check under lock, `DB::transaction`, `lock_version`, an idempotency key, and audit with ids, codes, counts and versions only, never an amount or an account number.
- Payslip snapshots are encrypted at rest. The account number reaches only the bank file and the masked last four on the payslip.
- PostgreSQL:
  - payslips and export rows are append-only, with no update, delete or truncate;
  - a payslip belongs to a locked run and to a person in that run;
  - one payslip per person per run;
  - one organisation throughout.

### 17.6 Tables (sketch)
| Table | Holds |
|---|---|
| `hr_payslips` | run, worker, encrypted snapshot, hash, created at |
| `hr_payslip_opens` | append-only: payslip, opened by, opened at |
| `hr_payroll_exports` | append-only: run, kind (bank, accountant), layout, row count, by, at (no file) |

Migrations from `2026_11_10_002700`.

### 17.7 Screens (Hui Min, with Daniel's look before the build)
- An Exports tab: one card per locked run, with Download bank file, Download accountant summary and the statutory summary in a disclosure. A run with someone missing bank details names them ("Not in the bank file: …, pay them outside the file"); the bank download is unavailable only when nobody in the run had confirmed bank details.
- My HR details: the Payslips section with one row per payslip and Print.
- The payslip page, white in every theme.

### 17.8 Tests (planned)
- Payslip figures equal the locked run's lines, written by hand.
- The snapshot never changes after a letterhead, name or bank change.
- Own payslips only, and every open recorded.
- The bank file: masked nowhere but the file, refused with missing bank details, the CSV safety rules, and the audit row written before the bytes.
- The accountant summary and the statutory summary totals by hand.
- An adjustment run's payslip beside the original.
- Permissions for each role, including the TV account.
- The PostgreSQL append-only rules.
- No amount or account number in audit or the session.
- The print white in dark mode.

### 17.9 Owner's answers (approved by B.E.R.N.I.S.S for the owner, 2026-11-07)
1. **Go-ahead for HR-7c:** yes, on synthetic data only.
2. **Who exports:** the Director only. The Finance Officer gets no payroll access; to be revisited after the PDPA review.
3. **Payslips:** `hr.payslips.view.own` for every human role, not the TV account.
4. **The bank file layout:** the generic CSV first. A bank's own layout waits for the clinic to name its bank.
5. **The accountant summary:** yes, exported by the Director.
6. **Paper payslips:** each person prints their own.
7. **The hub line:** yes; the exact `ClinicManagementTest` line is in 17.4.
8. **Dates:** "30 Oct 2026", with the period as "October 2026".

Also decided while building:
- **No logo on the payslip in v1:** letterhead text only, in the same block as the other letters (Daniel): the clinic name first and bold, the address on one line, then "Tel: …" through `PrintedPhone`. A branch with no Business details shows the organisation's name, never the bare branch name. "Logo on payslips?" is on the owner's later list.
- **The 17.3 change:** people without confirmed bank details at locking are left out of the bank file and named.

### 17.10 Known risks and limits
- Statutory correctness still depends entirely on the tables the clinic enters.
- The bank file holds account numbers and amounts once it leaves KPOne. The clinic's handling of the downloaded file is outside KPOne.
- Encrypted amounts are totalled in PHP, and the export is not load-tested.
- A former staff member whose account is inactive cannot sign in to open their old payslips; HR opens them as a preparer.
- Opening a payslip is a GET that writes an open row each time.
- The bank file has one generic layout only.

## 18. HR-7d MTD (PCB) worked out by KPOne: design note

Written 2026-11-07. **Status: approved by B.E.R.N.I.S.S for the owner with its defaults, on the owner's own "start the
HR-7d build" (2026-11-07); built in PR #396, merged as `bfe546b`.** Every [TO CONFIRM] stays on the owner's later list as
an accountant question. The defaults are not the accountant's sign-off.
It works out the MTD part that section 14.8 left for later, and changes no HR-1 to HR-7c rule.
**KPOne ships no tax number and files nothing.** Synthetic data only until the accountant's sign-off and the PDPA
review. The accountant line stays on every payroll page.

### 18.1 Scope
- **In:**
  - a yearly MTD schedule that the clinic enters, checked by someone else;
  - each person's MTD details on their pay profile;
  - KPOne working out MTD for **normal monthly remuneration** on a monthly run, from the year to date in KPOne's own locked runs;
  - the preparer may still type a different MTD for a person on a draft, and the line then says so.
  - **Changed after review (Suresh):**
    - **S1:** a manual line on a monthly run that counts for MTD is one-off pay. It is never projected over the year: the worked figure stops, and the run asks for a typed MTD.
    - **S2:** a run (regular or adjustment) of an earlier month this year that is not locked yet stops the worked figure until it is locked and the run is prepared again, or the MTD is typed.
    - **M1:** a typed MTD always gets past the schedule and band errors, with a warning instead.
- **Not in HR-7d (later, each with the accountant):**
  - additional remuneration (bonus, arrears and commission). A manual line that counts for MTD on a monthly run stops the worked figure, and the preparer types that person's MTD. Adjustment runs carry no MTD line in v1, which predates HR-7d (Suresh, M2), so the MTD on a bonus paid through an adjustment run is recorded outside KPOne for now;
  - TP1 (other reliefs and rebates the employee claims);
  - TP3 (pay, EPF, MTD and zakat from a previous employer this year);
  - zakat;
  - non-residents' flat rate;
  - EA forms;
  - CP39 or e-PCB files.
- **TP3 gap:** a person who joined in the year after January gets a warning saying that previous employment (TP3) is not counted, so the preparer can check it and type the MTD instead.

### 18.2 The MTD schedule (Tables tab)
- **One schedule per year:**
  - a source note (for example "LHDN schedule for 2026, as given by the accountant");
  - the rounding (round up to the next 5 sen, to the nearest sen, or none);
  - the smallest MTD that is deducted (a smaller result deducts nothing);
  - the yearly reliefs: individual (D), spouse (S), disabled individual (DU), disabled spouse (SU), each child (Q), and the EPF relief limit;
  - the bands, each with:
    - "chargeable income from" (M) and "to";
    - the rate (R);
    - the tax on M for categories 1 and 3 (B);
    - the tax on M for category 2 (B).
- **No number is shipped.** The form suggests nothing and starts empty.
- **Checked by someone other than the person who entered it,** with the same decider rule as the other tables (14.4): a Director, or the HR Manager when a Director entered it.
  - It counts only once checked, and a later checked schedule for the same year replaces it.
  - Rows are append-only, and every schedule says "To confirm with the clinic's accountant before real use."

### 18.3 MTD details on the pay profile
When "MTD" is ticked on a pay profile, it also holds:
- the tax category: 1 (single), 2 (married, spouse not working) or 3 (married, spouse working);
- the number of children for relief;
- disabled individual (yes or no);
- disabled spouse (yes or no; category 2 only).

They are confirmed with the profile by someone else, as the profile already is. A profile saved before HR-7d, with no category, keeps the HR-7b behaviour: MTD typed for each run.

### 18.4 How KPOne works it out (monthly run, normal remuneration)
This follows the computerised method's structure: the yearly chargeable income spread over the remaining months.
- **Y1:** this month's pay that counts for MTD.
  - Basic or worked hours always count, and pay item types count where "counts for MTD" is ticked.
  - Unpaid leave counts against it, as for the other schemes.
- **K1:** this month's EPF employee part.
- **Y and K:** the same, summed from the person's lines in this year's locked runs in KPOne.
- **X:** the MTD already deducted in those runs.
- **n:** the months left in the year after this one.
- **Y2 = Y1** for each remaining month, and **K2 = K1**, with the EPF limit applied to the year's total.
- **P** = Y − K + (Y1 − K1) + (Y2 − K2) × n − (D + S if category 2 + DU if disabled + SU if category 2 and disabled spouse + Q × children).
  - P is never below zero.
- **The band** is the one holding P, which gives M, R and B for the person's category.
- **MTD for the month** = ((P − M) × R + B − X) ÷ (n + 1), then:
  - never below zero;
  - rounded as the schedule says;
  - nothing deducted when below the smallest MTD.
- **Whole sen throughout,** with one rounding at the end (PayrollMath).

The line explains itself, for example: "MTD (PCB) · worked out: RM 34,560.00 chargeable for the year, category 1, 3 months left".

### 18.5 Errors and warnings on the run
- **Errors** (each becomes a warning when an MTD is typed for the person):
  - no checked schedule for the run's year when a person's profile has MTD details;
  - no band for P;
  - one-off pay on this run that counts for MTD;
  - an earlier month this year not locked yet;
  - "MTD not entered" (unchanged) for a profile without MTD details.
- **Warnings:**
  - "Joined this year: previous employment (TP3) is not counted";
  - "MTD typed for this run instead of RM x worked out".

### 18.6 Permissions and rules
- **No new permission:**
  - the schedule uses `hr.payroll.prepare.organisation` to enter, and the existing decider rule to check;
  - MTD details use the pay profile's own flow.
- Every write follows the HR pattern: a fresh permission check under lock, `DB::transaction`, row locks, `lock_version`, an idempotency key, and audit with ids, codes and versions only, never an amount.
- MTD details are not secret amounts: category, children and the disability flags. They are personal data, shown only where the pay profile is.

### 18.7 Tables (sketch)
| Table | Holds |
|---|---|
| `hr_mtd_schedules` | year, source note, rounding, the smallest MTD, the reliefs and the EPF limit (sen), status, entered by, decided by, lock version |
| `hr_mtd_schedule_rows` | append-only: position, chargeable from and to (sen), rate (basis points), tax on M for categories 1 and 3, and for category 2 (sen) |
| `hr_pay_profiles` (+) | `mtd_category`, `mtd_children`, `mtd_disabled_self`, `mtd_disabled_spouse` (nullable; required with MTD for new profiles) |

Migrations from `2026_11_10_002900` (after HR-7c's `002800`).

### 18.8 Screens (Hui Min, with Daniel's look)
- **Tables tab:** an "MTD schedule" card beside the other tables (year, status, bands and reliefs), with Enter, Check, Reject and Withdraw as for the other tables.
- **Pay profile form:** the MTD fields under the MTD tick.
- **Run lines:** the worked-out MTD with its sentence, and the typed one marked as typed. The MTD box still lets the preparer type a figure.

### 18.9 Tests (planned)
- Hand-worked synthetic cases:
  - category 1, 2 and 3;
  - children and disability;
  - a person over the EPF limit;
  - January and December;
  - a mid-year joiner with earlier runs in the year;
  - rounding to 5 sen;
  - below the smallest MTD.
- Year to date counts only locked runs of the same year and the same person.
- A typed MTD replaces the worked one, and the run says so.
- No checked schedule, or no band, gives an error; a profile without details keeps "MTD not entered".
- The two-person check and the PostgreSQL append-only rules on the schedule.
- No amount in audit.

### 18.10 Questions for the owner and the accountant [TO CONFIRM], with the defaults used
1. **Method:** LHDN's computerised calculation for normal remuneration, with the clinic entering the year's schedule and reliefs (default). Or keep typing MTD from the LHDN calculator?
2. **TP1, TP3 and zakat:** left for a later slice (default), with the mid-year joiner warning.
3. **Bonuses and arrears** (additional remuneration): MTD typed by hand on the adjustment run (default).
4. **Who may type over a worked-out MTD:** any preparer, with the run showing both figures (default).
5. **Rounding and the smallest MTD:** entered on each year's schedule (default: no value shipped).

### 18.11 Known risks and limits
- Correctness depends entirely on the schedule and reliefs the clinic enters, and on the accountant confirming the method against LHDN's current specification.
- TP1, TP3, zakat and additional remuneration are not counted in HR-7d, so those people need a typed MTD.
- Year to date counts only KPOne's own locked runs. Pay from before KPOne payroll began is not counted.
- Variable overtime is projected over the remaining months like basic pay (an accountant question).
- Child relief has one rate for every child; LHDN's differs by the child's age, education and disability (Suresh, M4).
- In a joiner's or leaver's month the part-month pay is projected over the remaining months, so that month's MTD is low; later months catch up through X.
- Each band's "From" is M; with bands running on by one sen, M is 1 sen from LHDN's figure for later bands (negligible).
- A worker in two regular runs for one month (a pay group change, from HR-7b) would be counted twice in the year to date.
- Adjustment runs carry no MTD line in v1.
- The person's MTD details are never flashed into the session (`dontFlash`).
- Deploy note: drafts prepared before the deploy ask to be prepared again once, because the run fingerprint gains the MTD schedule.
- An earlier month this year whose run was never prepared is not detected: the year to date silently lacks it.
