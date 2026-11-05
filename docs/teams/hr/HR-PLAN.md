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
