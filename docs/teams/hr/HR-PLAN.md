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
