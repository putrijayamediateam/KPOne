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
| HR-1 | Roster planner at `/hr/roster` (My roster, Planner, Minimum staffing); weekly branch versions draft, waiting for approval, approved, replaced; two-person send and approve or return backed by PostgreSQL; refusals for missing branch assignment, overlaps across branches, shifts over 16 hours; warnings for other drafts, shared rooms and minimum staffing; one hub link. Permissions `hr.roster.manage.organisation` (Director, HR Manager), `hr.roster.approve.organisation` (Director), `hr.roster.view.own` (every human role, not the TV account). Migrations `2026_11_10_000100` and `000200`. | PR #295, merged as `dca171e` (head `a84e395`) |
| HR-2a | Leave at `/hr/leave` (My leave, Approvals, Team calendar, Settings): leave types and per-role entitlements per year, branch working days and public holidays, half days, requests with an encrypted short evidence reference, approval by someone other than the applicant, cancel and withdraw, balances from approved and pending requests, and roster warnings for leave on approved shifts. Migrations from `2026_11_10_000300`. | PR #302, merged as `836fe96` (head `0b4c189`) |
| HR-5c | SOPs at `/hr/sops`: versioned SOPs with audiences by role, an open before each acknowledgement, and who is left to acknowledge. Migrations `2026_11_10_001800` and `001900`. | PR #364, merged as `f259810` (head `b0d02d7`) |
| HR-6 | Staff claims at `/hr/claims` (My claims, Approvals, Pay, Monthly, Settings): claims with encrypted purpose and receipts, approval by someone else (HR Manager and Director rule, the Director level), payment recorded by a third person, reversal back to Approved, monthly totals on screen; KPOne pays nothing. Migrations `2026_11_10_002000` and `002100`. | PR #370, merged as `320f6a9` (head `dbfdd1a`) |
| HR-7a | Payroll setup at `/hr/payroll` (Pay profiles, Tables, Settings): effective-dated pay profiles with encrypted salary and allowances, confirmed by someone else; clinic-entered EPF, SOCSO, EIS and HRD tables checked by someone else; versioned pay item types and rate settings; managers' own pay and locums outside KPOne; nothing calculated. Permissions `hr.payroll.prepare.organisation` (Director, HR Manager), `hr.payroll.approve.organisation` (Director). Migrations `2026_11_10_002300` and `002400`. | PR #376, merged as `845288e` (head `d4f9418`) |
| HR-7b | Payroll runs on the Runs tab of `/hr/payroll`: prepare a monthly or adjustment run per pay group and month; lines worked out from confirmed pay profiles, approved overtime, staff claims to be refunded through payroll and the checked statutory tables, each with the sentence it came from; MTD entered by hand; errors stop sending and warnings never do; send, approve by a Director and lock, with the only approver left never preparing; adjustment runs hold manual difference lines only; staff claims paid by a locked run show as "Payroll". Nothing is paid and nothing is filed. Migrations `2026_11_10_002500` and `002600`. | PR #379, merged as `2c11b41` (head `590e261`) |
| HR-7c | Payslips and exports: one encrypted, hashed payslip snapshot per person written when a run is locked (letterhead text, lines, totals, bank last four), opened only by its owner or a payroll preparer or approver, every open recorded; My payslips on My HR details; a printable A4 payslip; an Exports tab for the Director only with the generic CSV bank file (paying the bank details frozen at locking, anyone without them named and left out), the accountant summary CSV and the statutory summary on screen, each download audited first and never stored. Permissions `hr.payroll.export.organisation` (Director) and `hr.payslips.view.own` (every human role, not the TV account). Migrations `2026_11_10_002700` and `002800`. | PR #393, merged as `f29209f` (head `08138fd`) |
| HR-7d | MTD (PCB) worked out by KPOne: a yearly MTD schedule (bands, reliefs, EPF relief limit, smallest MTD, rounding) entered by the clinic and checked by someone else; optional MTD details on the pay profile (category, children, disability); MTD worked out on monthly runs from the year to date of locked runs with the line explaining itself; one-off pay, an unlocked earlier month, no schedule or no band stop the worked figure; a typed MTD always counts and the line shows both. No new permission. Migration `2026_11_10_002900`. | PR #396, merged as `bfe546b` (head `81accec`) |
| HR-12 | Export for an outside HR product: a closed-by-default section on the Staff files tab for the Director and HR Manager with three generic CSV downloads (staff list as of today, approved leave touching a month, approved overtime in a month), each audited before release, formula-safe, capped at 5,000 rows and never stored; no identity numbers, bank details, documents, pay or reasons. No new permission and no migration. | PR #397, merged as `8c6d78b` (head `cf24954`) |
| HR-13 | Job applications: a public bilingual (English and BM) Apply form at `/careers/apply`, off by default and closed until HR saves a retention period, with a signed one-time form token, a honeypot, a minimum fill time, rate limits, an encrypted PDF CV and encrypted values. A "Job applications" page for the Director and HR Manager covers positions, steps with a declined offer allowed to become Withdrawn, recorded CV opens and a clinic-entered retention with a daily purge. New permission `hr.applications.manage.organisation`; migrations `003000` and `003100`. **The switch stays off until the owner approves the privacy notice (a lawyer review is recommended).** | PR #402, merged as `9eedfd4` (head `01e6503`) |
| HR-14 | Staff messages at `/hr/messages` (My messages, Settings, Sent): email for four HR events (leave decided, roster approved, payslip ready, staff claim decided or paid) with fixed templates and no amount, reason or patient detail; nothing on by default; per-channel switch, clinic price and monthly cap; sent right after the commit with no queue, a failure recorded and never breaking the HR action; SMS and WhatsApp built but always "Not connected"; no OTP. Migrations `2026_11_10_003200` and `003300`. | PR #414, merged as `fa4d2f9` (head `5b8c4ba1`) |
| HR-17a | Hiring stages at `/hr/applications`: a position type (Clinic Assistant, Intern, Locum, Resident Doctor) with its own form questions; a three-step public form (still off until the owner approves notice `draft-hr17-applications-v1`); stages New, Phone screening, Scheduling, Interview, then Passed or Failed (or Not taken forward, Auto-rejected, Withdrawn); a recorded phone call with HR's checklist, a 1 to 5 rating, an encrypted note and Pass or Reject with a fixed reason; Scheduling needs a passed call (service and PostgreSQL). No offer in KPOne; no talent-pool tick until HR-17e. Migrations `2026_11_10_003400` and `003500`. | PR #419, merged as `6da682e` |
| HR-17b | Candidate private link and hiring messages: a link shown once on the thank-you page (token in the URL fragment, swapped for an httpOnly cookie on `/careers/status`, only a keyed hash stored and cleared by the purge); a status page with the stage in plain EN/BM words, the clinic's messages and "Withdraw my application"; fixed EN/BM templates recorded once per application and stage, emailed only when `HR_HIRING_EMAIL_ENABLED` is on (off by default); auto-reject at New and Phone screening after 3 branch working days, with a reminder to `HR_HIRING_MAILBOX` first (`hr-applications:auto-reject`, hourly at :15, from 08:00 branch time); "Needs you" on the HR page. No new permission; migration `2026_11_10_003600`. | PR #429, merged as `a1bff98` (head `eccf9e1`) |
| HR-17c | Interview slots: at Scheduling the candidate picks exactly three one-hour slots (9, 10, 11, 12, 2, 3 and 4 o'clock branch time) over the next 10 working days less public holidays, never one already confirmed in the organisation; HR confirms one under a per-slot advisory lock, asks for new times (a new round), or cancels an interview with a fixed reason, which frees the slot and returns to Scheduling; Passed or Failed only once the interview has started; messages `interview_confirmed`, `pick_again` and `interview_cancelled`; "Times to confirm" and "Interviews today" in "Needs you". No new permission; migration `2026_11_10_003700`. | PR #440, merged as `f0ebbbe` (head `e9ec37b`) |
| HR-17d | Interview documents: HR uploads PDFs per position type (at most 10 MB each and 10 active per type, held in PostgreSQL under the service's lock), stored encrypted under a generated name with no original file name; every HR open is recorded; Remove keeps the row and deletes the bytes; the candidate downloads them from the private page only while their interview is confirmed (a PostgreSQL trigger refuses any other download), each download recorded before any byte is sent; HR's drawer lists what was downloaded. No new permission; migration `2026_11_10_003800`. | PR #442, merged as `4040176` (head `339e315`) |
| HR-17e | Talent pool and staff-account requests: an optional, never preset pool tick stored with its wording version (`draft-hr17e-pool-v1`); a ticked application with a result stays in the pool for HR's clinic-entered days (none shipped; empty keeps nobody), counted from its close, and is kept out of the purge; the candidate leaves once from the private page; HR invites a pool member to an open position of the same type, which makes a new application at Phone screening with a copied, re-encrypted CV, its own link and the `pool_invited` message (once per position, unique index). For a passed candidate HR asks for a staff account; the request shows on the Staff page only to people with `staff.manage.organisation` and `access.manage.organisation`, who create the account the usual way and mark it done; HR creates no account and changes no role or branch. No new permission; migration `2026_11_10_003900`. | PR #443, merged as `e02a771` (head `ebfdd27`) |
| HR-10a | Letters at `/hr/letters` (Templates, Issue, Issued; My letters for every human role): HR-entered EN and BM templates (confirmation, warning, show-cause, other) with fixed placeholders only, `{details}` in warning and show-cause letters; KPOne ships no wording. Issue from a server preview to an encrypted, immutable snapshot numbered `HRL/{year}/{6-digit}` with the letterhead text and logo bytes (through `DocumentLetterheadCapture`) and the template version; replace, void with a reason, and "given on" once. Nobody issues to themselves; a Director's or HR Manager's letter only by a Director. Printed A4 with two signature lines; nothing is emailed. Permission `hr.letters.issue.organisation` (Director, HR Manager); migrations `2026_11_10_004000` and `004100`. | PR #445, merged as `c3f7f79` |
| HR-10b | Appraisals at `/hr/appraisals` (Criteria, Cycles; my appraisals for every human role): HR-entered, versioned criteria (KPOne ships none); a cycle keeps its criteria version; 1 to 5 per criterion with an optional comment, no total and no ranking; a summary of attendance, leave and overtime for the period, live on a draft and frozen on send; the person sees it only once sent and adds one comment; a Director signs off once the person commented or 7 days after sending, never their own. Nobody appraises themselves; a Director's or HR Manager's appraisal only by a Director (service and PostgreSQL). Ratings, comments and the summary are encrypted. Permissions `hr.appraisal.manage.organisation` (Director, HR Manager) and `hr.appraisal.view.own` (11 human roles); migrations `2026_11_10_004200` and `004300`. | PR #448, merged as `f39b490` |
| HR-10c | Discipline at `/hr/discipline` (Director and HR Manager only): a case with an encrypted allegation that never changes; an append-only timeline of notes, linked HR-10a show-cause letters, the staff member's reply and files (PDF, JPG or PNG in their own `hr_discipline_files` table, not the HR-4b vault); one outcome closes it (no action, verbal, written or final warning, or "Refer to the Director for termination"; a written or final warning needs a current warning letter). KPOne never ends employment or changes an account. Nobody handles a case about themselves; a Director's or HR Manager's case only by a Director (service and PostgreSQL, `hr_discipline_director_only`); cases the viewer may not handle are left out of lists and counts; no audit row has a subject. HR-10a's Issued list leaves out letters to people the viewer may not manage, and letter audit holds ids only. Permission `hr.discipline.manage.organisation`; migrations `2026_11_10_004400` and `004500`. | PR #449 |
| HR-10d | Incident reports at `/hr/incidents` (My reports for every human role; Open, Needs clinical review and Closed for the Director and HR Manager): kind, branch, date and time, an encrypted description and up to 3 photos (JPG or PNG, re-encoded, EXIF removed); acknowledge, actions with an owner and a due date, HR notes never shown to the reporter, and close with an outcome once every action is done; the reporter sees the status only. Patient-care reports are flagged "Needs clinical review" and the form warns not to write patient details. A staff-conduct report may be sent to the Director only (immutable; only a Director lists, reads or handles it; refused when the reporter is the only Director). A staff-conduct report links to an open HR-10c case only by someone who may handle it, and a manager who may not is not told of the link. Nobody handles their own report (service and PostgreSQL). Permissions `hr.incidents.manage.organisation` (Director, HR Manager) and `hr.incidents.report.own` (11 human roles); migrations `2026_11_10_004600` and `004700`. | Not merged yet |

## 6. Decisions (never ask again)

Decisions marked **B.E.R.N.I.S.S for the owner** were made by the coordinator under the owner's delegation of
2026-11-05 ("for now, BE ME"). The owner returned on 2026-11-06 at about 01:10 UTC; that delegation has ended, and
the entries marked "confirmed by the owner, 2026-11-06" were confirmed in the owner's own words ("ok for the HR
check items, keep them"). The rest stand unless the owner changes them.

1. KPOne HR is authorised as a programme, built one slice at a time; each slice gets a design note approved before
   building and a "ship it" before its PR (owner, 2026-11-05: "i want all the things inside it and can be kpone
   mysyarikat").
2. On screen it is called **KPOne HR**; it does not copy the outside product's name, logo, screens or wording
   (B.E.R.N.I.S.S for the owner; confirmed by the owner, 2026-11-06).
3. Who manages: Director and HR Manager manage; the Director approves rosters and HR Manager decisions that affect
   pay; nobody approves their own request (leave, claim, overtime, correction); each staff member sees their own
   (B.E.R.N.I.S.S for the owner, following `docs/reports/ANALYSIS-HR.md`).
4. HR never blocks clinical work: a missing or wrong roster, leave or credential does not stop registration, Call
   In, consultation or document issue. A later clinical link would be warnings first, by separate decision
   (B.E.R.N.I.S.S for the owner).
5. Reminders and alerts are inside KPOne only, worked out when a permitted page is opened. No WhatsApp, SMS, email,
   OTP or push notification (AGENTS.md; B.E.R.N.I.S.S for the owner). **Amended 2026-11-10:** HR-14 (PR #414) can
   send staff email for four HR events, off by default behind `HR_MESSAGES_EMAIL_ENABLED`, by the owner's decision
   (decisions 74 to 76); hiring email (HR-17b) is the same, behind `HR_HIRING_EMAIL_ENABLED`. SMS, WhatsApp, OTP and
   push stay off; in-KPOne reminders stay the default.
6. Clock-in is a button in KPOne on the staff member's own signed-in account, with manual corrections approved by
   someone else. No selfie or face photo, no continuous location. A branch geofence check may be added later only
   after a privacy (PDPA) review (B.E.R.N.I.S.S for the owner; the clinic HR proposal asked for geofence and selfie;
   confirmed by the owner, 2026-11-06).
7. Payroll is built inside KPOne (the owner asked for everything), late in the order, with every statutory rate
   and table (EPF, SOCSO, EIS, PCB/MTD, HRD) entered and versioned by the clinic, never hard-coded, and marked
   "to confirm with the clinic's accountant" before real use. KPOne makes no payment; it can export a reviewed
   file (B.E.R.N.I.S.S for the owner; the HR analysis had advised keeping payroll outside; confirmed by the owner,
   2026-11-06).
8. Locums are HR-managed worker records without a KPOne login; there is no outside locum portal, OTP or WhatsApp
   broadcast (AGENTS.md: unknown identities never create users; messaging unauthorised) (B.E.R.N.I.S.S for the
   owner; confirmed by the owner, 2026-11-06).
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
    Director (B.E.R.N.I.S.S for the owner, 2026-11-05; confirmed by the owner, 2026-11-06).
14. HR-1 defaults: sessions Morning, Afternoon, Evening, Night, Full day, Other; 16-hour maximum shift; minimum
    staffing for Resident Doctor, CA and CA Supervisor; no travel buffer between branches; CA Supervisors do not see
    the whole branch roster in HR-1 (a branch view is the possible follow-up HR-1b) (B.E.R.N.I.S.S for the owner,
    2026-11-05).
15. Existing-assertion change approved: the `ClinicManagementTest` hub destination map gains `/hr/roster` for
    `hr.roster.manage.organisation`, `hr.roster.approve.organisation` and `hr.roster.view.own` (B.E.R.N.I.S.S for
    the owner, 2026-11-05).
16. Daniel (Lead UI/UX) on the HR-1 note: OK with changes. Status tones Draft neutral, "Waiting for approval"
    warning, Approved success, Replaced neutral; the 8-column week grid only from 1024 px with day cards below; the
    version switch is a two-button group with `aria-pressed`; grid chips at least 44 px high; dark-mode icons of
    native date and time inputs are design debt DD-07 (not HR's to fix) (relayed by B.E.R.N.I.S.S, 2026-11-05).
17. A clinic compliance slice (clinic document licences, internal audit checklist, vaccine fridge temperature log)
    joins the backlog as HR-11, after HR-10 (B.E.R.N.I.S.S for the owner, 2026-11-05; confirmed by the owner,
    2026-11-06).
18. HR-2 leave design note approved, split into HR-2a (settings, balances, requests, calendar, roster link) and HR-2b
    (evidence files, its own note later); HR-2a keeps only an encrypted short evidence reference and an
    "Evidence seen" tick (B.E.R.N.I.S.S for the owner, 2026-11-05).
19. HR-2 defaults: no leave types, entitlements, carry-forward or holidays ship with KPOne (clinic-entered,
    versioned); the yearly entitlement is granted by a manager action; no negative balance unless the type allows
    it; replacement leave is a manual adjustment; a late application is flagged, not refused (B.E.R.N.I.S.S for
    the owner, 2026-11-05).
20. HR-2 two-person rules: the decider is never the applicant; an HR Manager's own leave goes to the Director, a
    Director's to the HR Manager or another Director; opening balances and adjustments need a second manager's
    confirmation; all backed by PostgreSQL (B.E.R.N.I.S.S for the owner, 2026-11-05).
21. HR-2 roster link: a new shift on an approved-leave day is refused; existing shifts and pending leave only warn;
    approving leave never changes a shift; the roster and team calendar show "On leave" only, never the type or
    reason (B.E.R.N.I.S.S for the owner, 2026-11-05).
22. Existing-assertion change approved: the `ClinicManagementTest` hub map gains `/hr/leave` for
    `hr.leave.request.own`, `hr.leave.approve.organisation` and `hr.leave.manage.organisation` (B.E.R.N.I.S.S for
    the owner, 2026-11-05).
23. HR-2a design (Daniel, OK with changes, relayed by B.E.R.N.I.S.S 2026-11-06): no primary button in a list
    (Approve, Reject and Confirm are outline; the primary "Approve leave" is in the dialog); a one-day request shows
    one "Half day" control; the balance error reads "Not enough leave left: this request needs N days and you have
    M." with no negative figure; the team calendar grid scrolls inside its box with a sticky name column, a caption
    and screen-reader names per cell; the phone tab strip follows the Queue board's DD-30 pattern (#296); below
    768 px entitlements are one card per role; "Rejected" and "Cancelled" are neutral.
24. HR-2b: the clinic wants MC files in KPOne (owner, 2026-11-06, in the HR session: "yes, the clinic wants MC files
    in KPOne"). The rest of the HR-2b note (HR-PLAN section 8) still needs approval before building, and no real file
    is stored before the PDPA review.
25. Daniel's early look at the HR-2b screens (HR-PLAN 8.7): OK with changes, folded into the note: a labelled outline
    button instead of the bare file control (no `capture`), "Uploading…" while sending, request-specific
    screen-reader names, photo alt text without medical text, a confirm dialog for every Remove (reason for managers
    only), retention help text, "No file yet" never red, photos on a neutral bordered surface (relayed 2026-11-06).
26. HR-2b design note (HR-PLAN section 8) approved with its defaults (owner, 2026-11-06, in the HR session: "approve
    the note with the defaults"): files only on leave types HR switches on; retention days set by HR per type, no
    number shipped; staff may add files up to 14 days after the leave ends; only the Director and the HR Manager open
    other people's files; HR may scan paper copies on a staff member's behalf. Synthetic files only until the PDPA
    review.
27. HR-2b screens (Daniel, OK with changes, relayed by B.E.R.N.I.S.S 2026-11-06), all applied: a file whose name
    says JPG, PNG or PDF but whose content is not one reads "This file can't be opened as a JPG, PNG or PDF. Try
    saving it again or take a photo instead."; both Remove buttons are at least 44 px on phones; the Approvals flag
    reads "Evidence attached: check it" when a file exists, "Evidence reference given: check it" with a reference
    only, and "Evidence needed" only when there is neither. Panel files are named "Photo 1" or "PDF 1" because file
    names are never stored. Final verdict at `4ea34b2`: OK (Daniel, relayed by B.E.R.N.I.S.S 2026-11-06).
28. Daniel's early look at the HR-3 screens (HR-PLAN 9.6): OK with changes, folded into the note: one primary button
    with three states and a confirm on Clock out only; the ticking server time never in a live region; KPOne time
    formats and "9 h 2 min"; warning (never danger) chips for flags; recorded beside new time in corrections and a
    "Correction waiting" chip; the privacy line "KPOne records the time only. No location or photo."; a summary line
    on Review and an outline "Clock in" link on My roster (relayed 2026-11-06). The note itself still waits for
    the owner's approval.
29. HR-3 design note (HR-PLAN section 9) approved with its defaults (owner, 2026-11-06, in the HR session: "approve
    the HR-3 note with the defaults"): HR-3a first, then HR-3b; grace minutes suggested 5, forgotten clock-out
    window 60 minutes, corrections up to 7 days back, both shared PC and own phone, overtime roles chosen by HR in
    settings, rest days are the branch's non-working weekdays, a split shift is two sessions, no top-bar button.

30. HR-3a screens (Daniel, relayed by B.E.R.N.I.S.S 2026-11-06): **OK**. All seven early-look notes met. Not
    blocking: the confirm dialog's navy background is changed for every dialog by UX-10; native date inputs show
    mm/dd/yyyy on every page. The PR carries the Review shot at 390 px (cards with flags). Nothing for DEBT.md.
31. Existing-assertion change approved: the `ClinicManagementTest` hub map gains `/hr/attendance` for
    `hr.attendance.clock.own` and `hr.attendance.review.organisation`; HR-3a shipped (owner, 2026-11-06, in the HR
    session: "yes, add the hub line and ship it").
32. HR-3b design note (HR-PLAN section 10) approved with its defaults (owner, 2026-11-06, in the HR session:
    "approve the HR-3b note with the defaults"): no overtime role ticked until HR ticks one; overtime asked for after
    it is worked, within the correction window; multipliers 1.5x, 2x and 3x and weekly levels 40 h and 45 h as form
    suggestions; rest days are the branch's non-working weekdays; a manager may reverse an approved request with a
    reason, never their own; staff see their own month as well as their week.
33. Daniel's early look at HR-3b (HR-PLAN 10.5, relayed by B.E.R.N.I.S.S 2026-11-06): OK with changes, all applied:
    a weekly chip only when there is something to warn about; two outline buttons (Approve, Reject) with the minutes
    filled in at the amount asked and a reason required when they are lowered ("Approve 120 min"); "Approved 2 h of
    3 h"; "N waiting · 1 approved day changed since" with "Recorded … → now …"; a right-aligned Summary with a total
    row and a sticky person column; the shared tabs as they are; empty states that say what to do next; the minutes
    error in the same submit as the reason error; Withdraw as an outline button; the app's role labels.
34. Existing-assertion change approved by B.E.R.N.I.S.S for the owner (2026-11-06, the owner's "BE ME"): the
    `AttendanceTest` `viewers()` tab list for Director and HR Manager changes from `['my', 'review', 'settings']` to
    `['my', 'review', 'overtime', 'summary', 'settings']` (HR-3b's two new tabs). No other existing assertion changed.
35. HR-3b screens (Daniel, relayed by B.E.R.N.I.S.S 2026-11-06): **OK** at 819f136, every note in; optional nit applied
    (the summary line drops "0 waiting" when only a changed day is listed). HR-3b ship-it given by B.E.R.N.I.S.S for
    the owner ("BE ME"); the PR opens after #327 merges, with main merged in.
36. HR-4 design note (HR-PLAN section 11) approved with its defaults (owner, 2026-11-06, in the HR session: "approve
    the HR-4 note with the defaults"): HR-4a first (staff file), then HR-4b (documents and checklists); Show for full
    identity and bank values for Director and HR Manager, each view recorded; a bank change confirmed by a second
    person; staff change their own address, personal phone and emergency contact, the rest by a correction;
    employment types Permanent, Contract, Part-time, Locum, Intern and the listed end reasons; staff see their own
    documents (view only); a probation alert 14 days before the end, with no probation length shipped; document
    categories and retention days entered by the clinic (adviser's call). Synthetic data only until the PDPA review.
37. Daniel's early look at the HR-4 screens (HR-PLAN 11.6, relayed by B.E.R.N.I.S.S 2026-11-06): OK with changes,
    all ten notes folded into 11.6 (one last-four masking pattern read as words; Show turns into Hide and never
    persists; masked bank cards side by side; a file header with a status chip; "Needs attention" with its reasons in
    words; one shared synthetic-data line; the HR-3a correction sheet; "No KPOne sign-in" on a locum; empty states and
    "Not given"; HR-4b expiry chips). The owner's own words relayed the same day: "approve the HR-4 plan with the
    defaults". The `/hr/records` hub-map assertion still needs the owner's OK at build time.
38. HR-5 design note (HR-PLAN section 12) approved with its defaults (owner, 2026-11-06, in the HR session: "approve
    the HR-5 note with the defaults"): HR-5a credential register (after HR-4b), HR-5b planner warnings, HR-5c SOPs;
    no credential ticked per role until HR ticks it (the form suggests APC and BLS for Resident Doctor, BLS for CA and
    CA Supervisor, Hepatitis B for clinical roles); warnings from 60 days with the words changing at 30 and 7;
    Hepatitis B as Done or Not done and a date only; renewals checked by Director or HR Manager, never the person;
    opening hours and a minimum of resident doctors entered per branch (suggests 1); work-hour suggestions 6 days in a
    row, 11 h rest and 48 h a week, to confirm with the HR adviser; SOP audiences chosen per SOP by role. DOC-02a stays
    Director only and nothing blocks clinical work. The `/hr/credentials` and `/hr/sops` hub-map assertions still need
    the owner's OK at build time.
39. Daniel's early look at the HR-5 screens (HR-PLAN 12.6, relayed by B.E.R.N.I.S.S 2026-11-06): OK with changes,
    all nine notes folded into 12.6 (time in the status words with Expired stronger and an icon; a sticky worded
    matrix with "—" for not required; "Someone else checks this"; a worded planner chip with "Warnings only"; SOP
    acknowledgement only after opening and per version; plain "who hasn't" list; read-only MMC with its source line;
    Health item help text; empty states; one extra register shot at 1440 px dark).
40. HR-6 design note (HR-PLAN section 13) approved with its defaults (owner, 2026-11-06, in the HR session: "approve
    the HR-6 note with the defaults"): one slice, after HR-5c; no categories shipped (the form suggests Meals, Travel
    and parking, Training and courses, Supplies bought for the clinic, Other, each needing a receipt); limits warn
    only; no Director level until entered; claims up to 30 days back (suggestion); the payer is never the claimant
    or the approver; claims are not paid from the till, and a recorded cash payment is never linked to daily cash
    closing. KPOne pays nothing and never touches Panel Claims or clinic money. The `/hr/claims` hub-map assertion
    still needs the owner's OK at build time.
41. Daniel's early look at the HR-6 screens (HR-PLAN 13.6, relayed by B.E.R.N.I.S.S 2026-11-06): OK, with five
    notes folded into 13.6 ("Approved" vs "Paid 6 Nov 2026"; "Someone else approves this" and "Waiting for the
    Director"; "Payment reversed" neutral; the RM prefix with decimal input; receipt alt text and PDF tiles). His
    suggestion of a partial approval ("Approved RM 30.00 of RM 45.60") conflicts with rule 6 and is open for the owner.
42. HR-6 rule 6 kept (owner, 2026-11-06, in the HR session: "keep rule 6"): no partial approval of a staff claim; a
    wrong amount is rejected (Wrong amount) and sent again. Daniel's "Approved RM x of RM y" is not built.
43. HR-7 design note (HR-PLAN section 14) approved with its defaults, including the split of what stops sending a
    payroll versus what only warns, by B.E.R.N.I.S.S for the owner on 2026-11-06 at 06:41 UTC, during the owner's
    20-minute "BE ME". Payslip dates (14.10 item 10) take the default: "30 Oct 2026" for dates and "October 2026" for
    the period. Marked "Check this" on the owner's board; the owner's own words win if they differ. The banner "To
    confirm with the clinic's accountant before real use" stays. Nothing is built until HR-6 is done.
44. HR-4a hub-map line (`'/hr/records' => ['hr.records.view.own', 'hr.records.view.organisation']` in
    `ClinicManagementTest`): approved by the owner, relayed verbatim by B.E.R.N.I.S.S on 2026-11-06: "approve the hub
    line for liyana".
45. The five items that were not authorised for KPOne HR are now authorised (owner, 2026-11-06, relayed verbatim by
    B.E.R.N.I.S.S: "all i want." and "the one that needed before real use i will provide later"). They are WhatsApp,
    SMS, email and OTP messages; a locum portal; selfie or location checks at clock-in; a link to the outside HR
    product; and job application intake. Each is built like every other slice: a design note approved by the owner,
    Daniel's look and a ship-it. Scope: staff and HR only. Patient messaging stays a separate decision. Synthetic
    data only until the owner provides the PDPA review, the leave policy and the accountant's confirmation. Nothing
    of these five is built until B.E.R.N.I.S.S's docs PR records the authorisation in AGENTS.md and BASELINE.
46. Order of the five (owner, 2026-11-06, relayed verbatim by B.E.R.N.I.S.S: "go with Liyana's order"): after HR-7,
    HR-12 outside HR product link, HR-13 job application intake, HR-14 staff messages (WhatsApp, SMS, email, OTP),
    HR-15 locum portal, HR-16 selfie or location at clock-in. Draft notes in HR-PLAN section 15.
47. The five notes in HR-PLAN section 15 (15.1 to 15.5, with the order in 15.6) approved by the owner, 2026-11-06,
    relayed verbatim by B.E.R.N.I.S.S: "approve all five HR notes for liyana". The 15.7 questions stay open for the
    owner. Nothing of the five is built until B.E.R.N.I.S.S's AGENTS.md and BASELINE docs PR merges, and each still
    needs Daniel's look and a ship-it before its PR.
48. HR-5a hub-map assertion approved (the owner's own words, relayed verbatim by B.E.R.N.I.S.S 2026-11-06: "approve
    the credentials hub line for liyana"): `ClinicManagementTest` gains one line after `'/hr/records'`,
    `'/hr/credentials' => ['hr.credentials.view.own', 'hr.credentials.manage.organisation'],` and nothing else.
49. HR-5a shipped (the owner, 2026-11-06, in the HR session: "ship it for HR-5a"), after Suresh's "ready to merge"
    and Daniel's OK (one nit fixed: Archive as an outline button). The PR opens after #347 (HR-4a) and HR-4b merge.
50. HR-5b shipped (the owner's own words, relayed verbatim by B.E.R.N.I.S.S 2026-11-06: "ship it for HR-5b for
    liyana"), after Suresh's re-check ("ready") and Daniel's OK (his items fixed: consecutive-day rest breaches shown
    as one line with a count, the chip counting breaches, planner times that never break). The PR opens after HR-5a
    (#356) merges, since HR-5b builds on it.
51. HR-5c SOPs hub-map assertion approved by B.E.R.N.I.S.S for the owner (2026-11-06, 11:22 UTC, under the owner's
    delegation that day: "BE ME, JUST APPROVE ALL NO NEED TO PUT ASIDE FOR ME TO APPROVE LATER"): `ClinicManagementTest`
    gains one line after `'/hr/credentials'`, `'/hr/sops' => ['hr.sops.acknowledge.own', 'hr.sops.manage.organisation'],`
    and nothing else. HR-5c's ship-it was given by B.E.R.N.I.S.S for the owner, once Daniel's verdict is OK (or OK with
    changes, made). Daniel's verdict, relayed by B.E.R.N.I.S.S: OK, with one nit fixed (at 0 not yet acknowledged, the
    button reads "Show versions" and no empty list opens). The PR opens after HR-5a (#356) and HR-5b (#361)
    merge.
52. HR-6 build started, approved by B.E.R.N.I.S.S for the owner (2026-11-06, 13:35 UTC, under the owner's delegation
    of 11:22 UTC): "Yes, start HR-6 (staff claims) now, per its approved note"; its PR opens after Hui Min's sketch,
    Suresh's review and Daniel's look, with no further ship-it. HR-5c took migration `2026_11_10_001900`, so HR-6's
    migrations start at `2026_11_10_002000` (HR-PLAN 13.4 updated). Synthetic data only.
53. HR-6 staff claims hub-map assertion approved by B.E.R.N.I.S.S for the owner (2026-11-06, 13:37 UTC, under the
    owner's delegation of 11:22 UTC): `ClinicManagementTest` gains one line after `'/hr/sops'`,
    `'/hr/claims' => ['hr.claims.submit.own', 'hr.claims.approve.organisation', 'hr.claims.pay.organisation', 'hr.claims.manage.organisation'],`
    and nothing else.
54. **Option (a): the Director's and the HR Manager's own pay stays outside KPOne in v1** (Suresh's HR-7a S1),
    approved by B.E.R.N.I.S.S for the owner, 2026-11-06. No pay profile for them and never in a run (service,
    PostgreSQL and page), with Daniel's wording: "In this first version, the Director's and the HR Manager's own pay is
    kept outside KPOne." and, on their cards, "Pay kept outside KPOne in this version." (HR-PLAN 14.8.)
55. HR-7a hub-map assertion approved by B.E.R.N.I.S.S for the owner (2026-11-06): `ClinicManagementTest` gains one
    line after `'/hr/claims'`, `'/hr/payroll' => ['hr.payroll.prepare.organisation'],` and nothing else.
56. HR-7b design note (HR-PLAN section 16) approved by B.E.R.N.I.S.S for the owner (2026-11-06) with its defaults
    (16.11): calendar-day pro rata rounded down to the sen, basic counts for every scheme, one half-up rounding per
    line, the approver locks, and the lock refused when the locker is a claim's claimant or approver. Also approved:
    the versioned "pay item types used by runs" setting, and adjustment runs holding manual difference lines only
    in v1 (16.13). The [TO CONFIRM] items stay on the owner's later list; the defaults are not an accountant's
    sign-off. Rate-setting and scheme-flag changes by one person stay on that list too (16.11 item 5).
57. HR-6 label and reversal for payroll payments approved by B.E.R.N.I.S.S for the owner (2026-11-06): the method shows
    as "Payroll", is kept out of Finance's Record payment, and reversing it is refused with "Paid through a locked
    payroll run. Correct it with an adjustment run." The HR-6 migration change only adds `payroll` to the method
    CHECK; no HR-6 assertion changes (ask first if one must).
58. HR-7b tabs assertion approved by B.E.R.N.I.S.S for the owner (2026-11-06): in `PayrollSetupTest`,
    `->where('payroll.tabs', ['profiles', 'tables', 'settings'])` becomes
    `->where('payroll.tabs', ['runs', 'profiles', 'tables', 'settings'])` and nothing else. Pay profiles stays the
    default tab, and who sees Runs follows the existing prepare and approve permissions only.
59. HR-7b S6, option (a), approved by B.E.R.N.I.S.S for the owner (2026-11-06): the only approver left never prepares
    a run or takes a draft step (HR-PLAN 16.13), with no new permission or grant change. Not a warning only (b), and
    no "cancel draft run" (c) in v1. HR-7b needs no further ship-it after Suresh's review and Daniel's OK.
60. HR-7c design note (HR-PLAN section 17) approved by B.E.R.N.I.S.S for the owner (2026-11-07), with the 17.9
    answers: synthetic data only; exports by the **Director only** (`hr.payroll.export.organisation`), and the Finance
    Officer gets no payroll access until it is revisited after the PDPA review; `hr.payslips.view.own` for every human
    role, not the TV account; a generic CSV bank file first; the accountant summary, exported by the Director; each
    person prints their own payslip; dates "30 Oct 2026" and the period "October 2026".
61. HR-7c hub-map assertion approved by B.E.R.N.I.S.S for the owner (2026-11-07): in `ClinicManagementTest`,
    `'/hr/payroll' => ['hr.payroll.prepare.organisation'],` becomes
    `'/hr/payroll' => ['hr.payroll.prepare.organisation', 'hr.payroll.export.organisation'],` and nothing else.
62. HR-7c bank file (HR-PLAN 17.3 changed after Suresh's S1), approved by B.E.R.N.I.S.S for the owner (2026-11-07):
    each payslip freezes the confirmed bank row at locking (`hr_payslips.bank_detail_id`) and the bank file pays only
    that row; anyone with none is left out of the file and named on the Exports tab, to be paid outside the file. Not
    "refuse the whole run", which could never be cleared once the account is frozen.
63. HR-7c payslip letterhead (Daniel, 2026-11-07): the same text block as the other letters (clinic name first and
    bold, the address on one line, then "Tel: …" through `PrintedPhone`); a branch with no Business details shows the
    organisation's name; no logo in v1 ("logo on payslips?" is on the owner's later list).
64. HR-7c fixture clean-up approved by B.E.R.N.I.S.S for the owner (2026-11-07): `PostgresPayrollContentionTest`
    adds `hr_payslip_opens`, `hr_payroll_exports` and `hr_payslips` to its clean-up list, with no assertion change.
    The new `HR/Payslip` no-layout case in `app.ts` sits before `PanelClaims/Pack`, because a Panel test pins that
    case next to `QueueDisplay/Screen`.

65. HR-7d design note (HR-PLAN section 18) approved by B.E.R.N.I.S.S for the owner with its defaults, on the owner's own
    "start the HR-7d build" (2026-11-07). KPOne ships no tax number; the clinic enters the yearly schedule and someone
    else checks it; every worked MTD is labelled as worked out by KPOne and to be checked with the clinic's accountant;
    a typed MTD stays and the line shows both figures; the 18.10 items are accountant questions on the owner's list.
66. HR-7d review changes (Suresh): one-off pay (any manual line counting for MTD) and an unlocked earlier month this
    year stop the worked figure; a typed MTD always gets past the stop codes with a warning; MTD details are never
    flashed into the session. Adjustment runs carry no MTD line in v1 (a known limit from HR-7b).
67. HR-7d screens (Daniel, OK on v2): a note under the MTD figure says which figure counts; shorter band labels; the
    HRD levy's zero employee line is hidden in the lines panel (display only).
68. HR-12: the owner answered 15.7 item 1 in the HR session (2026-11-07): a generic CSV export first (no product or API
    named yet), with the staff list, approved leave and approved overtime, and no payroll, identity, bank or document
    data. It reuses `hr.records.manage.organisation`, so no new permission, migration or hub line.
69. HR-12 leave type (Suresh, M2): kept by name in v1 on synthetic data, as a known limit; "hide leave type in the
    outside-HR export?" is on the owner's PDPA-review list (B.E.R.N.I.S.S for the owner, 2026-11-07).
70. HR-13: the owner answered in the HR session (2026-11-07): open positions are kept in KPOne by HR; retention is
    entered by HR, with no number shipped; the public form shows English and Bahasa Melayu side by side.
71. HR-13 conditions (B.E.R.N.I.S.S for the owner, 2026-11-07): the form ships off (`HR_APPLICATIONS_ENABLED=false`,
    config default false), a test proves it is closed while off, and **the switch must not be turned on until the owner
    has approved the privacy notice (a lawyer review is recommended)**. Turning it on is the owner's call. The hub line
    `'/hr/applications'` in `ClinicManagementTest` was approved by B.E.R.N.I.S.S for the owner.
72. HR-13 screens (Daniel, OK with changes, then OK): the form stays closed until a retention period is saved, because
    the notice promises deletion after that period (option (a), chosen by B.E.R.N.I.S.S for the owner); each step chip
    has its own tone; the public CV control is the shared FileChooseButton, "Choose PDF / Pilih PDF".
73. HR-13 review changes (Suresh): an application nobody moved is purged too, counted from when it was sent; no input
    is flashed into the session on a refusal; the migration's `down()` keeps the evidence; a declined offer can be
    marked Withdrawn; a double send gives "already sent". Found in the screenshot run: the field checks now run before
    the timing check, so a person who sends an empty form sees what to fill in.

74. HR-14 (owner, 2026-11-10, "start"): email now, SMS and WhatsApp later; the four messages are leave approved or
    rejected, roster week approved, payslip ready, and staff claim decided or recorded as paid.
75. HR-14 scope (owner, 2026-11-10, relayed by B.E.R.N.I.S.S): "Keep SMS/WhatsApp unconnected" (the channels exist,
    always "Not connected", no provider, no sending); "leave OTP out, decide with HR-15" (no OTP code, table or test);
    "Send directly, no worker" (sent right after the commit, no queue, no worker; a failure is recorded on the sent
    row and never breaks the HR action); "Narrow the test" (the WA-01 guard exempts only
    `2026_11_10_003200_create_hr_message_tables.php` from its word check, still refusing `wa.me` and WA-01 in it);
    "Approve" the hub line `'/hr/messages'` in `ClinicManagementTest`.
76. One mail setup (B.E.R.N.I.S.S, 2026-11-10): staff messages and hiring emails share Laravel's `MAIL_*` mailer;
    Resend connects over SMTP with the built-in mailer, so no new Composer package (owner: "fine").
77. HR-14 screens (Daniel, OK with changes): "Not connected" chips and no button for a channel that is not connected,
    one wording on both tabs; its settings visible but disabled and never saved ahead; Save solid only with unsaved
    changes; errors in their own card under their field; "cost RM x"; "Showing 1–25 of N" and a status filter on Sent.
78. HR-14 review (Suresh): a queue or send failure after commit never turns a saved action into an error; within one
    roster approval or payroll lock, a mail service that fails is not tried again (`send_skipped`), proven through a
    real roster approval.
79. Hiring plan approved (B.E.R.N.I.S.S for the owner, 2026-11-10): HR-PLAN section 19, slices HR-17a to HR-17e. The
    owner's answers: auto-reject at both HR stages after 3 working days with a reminder the day before ("Both
    stages"); slots up to "10 working days" ahead; one confirmed interview per slot for the "Whole group"; the
    staff-account handoff shown to "anyone yg ada access to create staff", through the staff-creation check.
80. HR-17a review (Suresh): no talent-pool tick or promise until HR-17e builds the pool and a way to withdraw
    consent; a screening note is cleared only by the purge; the screening audit holds the outcome and the tick count,
    never the rating; a warning when a position's type changes while it has open applications.
81. HR-12 leave export (owner, 2026-11-10, word for word, relayed by B.E.R.N.I.S.S): "On leave only". The outside HR
    product's leave file shows "On leave" for leave days and never the leave type (sick, maternity, hospitalisation and
    so on), because health information is sensitive under PDPA. The merged HR-12 printed the type; the fix is PR
    `fix/hr-12-leave-on-leave-only`. The Paid column stays.
82. HR backlog split (owner, 2026-11-10: "i want to start all that"; B.E.R.N.I.S.S's plan): HR-8 announcements go to
    Queue & TV (Amirul), HR-9 the HR dashboard to Agent platform (Irfan) and HR-11 clinic compliance to Catalogue &
    settings (Adlina), each under this plan and with this team agreeing its design note first; migration ranges
    `2026_11_13_2`, `2026_11_13_1` and `2026_11_13_3`. HR keeps HR-17b to HR-17e, then HR-10 (design note first).
    HR-15 and HR-16 are design notes only until the owner decides.
83. Data Protection Officer (owner, 2026-11-10, word for word, relayed by B.E.R.N.I.S.S): "yes, DPO HR Manager".
    Data Protection Officer: HR Manager. Repository documents name the role only, never a person; the owner keeps the
    name and contact details outside the repository.
84. HR-17e talent pool (Suresh's review, 2026-11-10): the tick is optional and never preset, and each tick stores the
    version of its wording (`talent_pool_text_version`). A person is in the pool only through the application they
    ticked; an application made by inviting them from the pool holds no tick of its own, so it never starts a new
    period, and leaving from either private page leaves the pool for good. Before the form is switched on, the
    owner's PDPA review approves the tick wording and decides whether the applications privacy notice needs a v2.
85. HR-10 recommendations approved (owner, 2026-11-10, word for word, relayed by B.E.R.N.I.S.S): "all continue with
    your recommendations". This covers HR-10a to HR-10d as in HR-PLAN section 20, the six new permissions and one
    Clinic management line per slice, then HR-15 and HR-16. No offer letters; KPOne ships no letter wording and no
    appraisal criteria; the clinic's adviser approves letter wording before real use.
86. HR-10a `{details}` (Suresh agreed): the free paragraph is allowed in show-cause letters as well as warnings,
    because a show-cause letter has to state the alleged facts. HR-PLAN 20.3 named warnings only.
87. HR-10a letterhead: HR letters use the clinical documents' `DocumentLetterheadCapture`, so a change to the clinical
    letterhead rules also changes HR letters, and an incomplete letterhead refuses an HR letter.
88. HR-10c files (Suresh agreed): discipline files live in their own `hr_discipline_files` table, not the HR-4b vault
    under a Discipline category, because the vault is listed on the staff file, opened by the person themselves and
    purged by document-type retention, any of which would leak a case.
89. HR-10c confirm dialog (Daniel): the shared `OperationalConfirmDialog` has no disabled state, so a close that still
    needs its warning letter is refused with the reason in the dialog's error box. Daniel logs "confirm dialog with a
    disabled state" as a shared-component item.
90. HR-10d incidents (Suresh's review, 2026-11-10):
    - a manager who may not handle a linked discipline case is not told that a case exists;
    - a staff-conduct report may be sent to the Director only (for a report about the HR Manager), checked in the
      service and in PostgreSQL, and refused when the reporter is the only Director, because nobody else could
      handle it;
    - **owner decision to confirm:** clinical review of patient-care reports happens outside KPOne. KPOne flags them,
      HR reads them, and the form tells the reporter not to write a patient's name, IC or clinical details (the visit
      number only). Showing them only to a Director, or naming a clinical reviewer role, waits for the owner.
91. HR-15 migrations: `2026_11_10_004800` to `005499` (B.E.R.N.I.S.S, 2026-11-10); HR-16 continues from `005500`.
92. HR-15a `locum` role (B.E.R.N.I.S.S for the owner, 2026-11-10): exactly `profile.view.own` (own password,
    two-factor and sessions), `branch_context.switch.branch` (a choice among the locum's own assigned branches only,
    as the TV account has; no data comes with it; approved by B.E.R.N.I.S.S for the owner so the existing Inventory
    tests that select a branch for every role stay unchanged) and `hr.roster.view.own`, with `hr.open_shifts.request.own` from HR-15b; no
    `dashboard.view.own`, no `staff.view.own`, no Insights, and not in `RosterPermissions::HUMAN_ROLES`. Pinned by a
    test. The existing role lists in `RosterTest` and the nav-02 top-bar test gain `locum`, and the hub map will gain
    `/hr/open-shifts` in HR-15b (approved by B.E.R.N.I.S.S for the owner).
93. HR-15a sign-ins (Suresh's review): HR asks with the email the locum will use; the account administrator creates the
    account the usual way and links it on the Staff page. Only an active account whose one role is Locum, with that
    email, no staff file and no other link, can be linked (service and PostgreSQL). `StaffRoleService` refuses Locum
    combined with any other role, and any other role on a linked account ("A locum sign-in holds the Locum role
    only."), approved by B.E.R.N.I.S.S for the owner as a tightening only.
94. Locum accounts are created by the Director only, the same as CA Supervisor accounts (B.E.R.N.I.S.S for the owner,
    2026-11-10). From HR-15b the role holds `hr.open_shifts.request.own`, which Technical Admin does not cover, so
    `canAssignRoles` refuses it to Technical Admin; Technical Admin still administers and links existing locum accounts.
    The authority rules are unchanged, and Technical Admin is never given HR permissions to work around this.

## 7. Open items and known limits

- Every statutory and policy question in `docs/reports/ANALYSIS-HR.md` section 8 is open and is for the clinic's
  accountant, HR adviser or lawyer, not the team.
- The clinic HR proposal's WhatsApp, OTP, locum portal and MySyarikat-integration items are not authorised.
- A privacy (PDPA) review is needed before real staff data and before any location check at clock-in.
- **Authorisation record (Reviewer, HR-1): resolved.** The coordinator recorded the KPOne HR authorisation in
  `docs/BASELINE.md` and added a narrow KPOne HR clause to `AGENTS.md` (PR #291, merged as `837dd56`).
- HR-1 known limits: minimum staffing applies to every day, so a day the branch is closed shows as short (no
  opening-days setting yet); a CA Supervisor counts as a CA Supervisor only, not as a CA; a staff member's own roster
  shows one week at a time; the planner reads other drafts' overlaps with one query per shift (fine for a clinic
  week, not load-tested); shift swaps and a branch view for CA Supervisors are HR-1b; dark-mode icons of native
  date and time inputs are design debt DD-07.
- HR-2a known limits: changing a branch's working days does not recount requests already sent (they keep their day
  count; the Settings card says so); carry-forward subtracts pending requests, so days of a request rejected after
  carry-forward stay in the closed year; a year carried forward is closed for new spending by that person and type
  (Reviewer finding, fixed); a grant that lowers an entitlement below what was already used is refused and needs an
  adjustment instead; the shared confirm dialog has no disabled state, so Approve and Reject say what is missing
  instead of greying out; ledger and action foreign keys to requests, openings and adjustments are single-column
  (organisation is checked in the service, not in PostgreSQL); no evidence files until HR-2b.
- HR-2b known limits: a PDF is checked only for its header and end marker, not sanitised (it is only ever downloaded,
  with `nosniff` and a sandbox policy); no virus scanner is available; opening a file is a GET that records an event,
  so a link from another site can add "opened" rows for a signed-in manager (throttled, as WA-01's redirect); photos are
  refused above about 24 MP or when memory is short, with "Use a smaller photo or a PDF"; the photo tests need the GD
  extension, which the cloud container lacks, so they run in CI only; files keep their retention days even when a
  type stops taking files, and the days cannot be cleared while files remain; both purge rules delete on the day
  after the period ends, in the branch time zone. Synthetic files only until the PDPA review.
- The 44 px phone tab height on the Leave page reaches into the shared `OperationalTabs` buttons
  (`max-sm:[&>button]:h-11`, the DD-30 pattern from #296); if that component changes its buttons the height quietly
  stops applying. A phone-height option in `OperationalTabs` (Staff Experience) would remove both overrides.

- HR-3a known limits (Suresh's review, kept for later): a correction withdrawal records no actor in the row (only the
  audit says who withdrew); the session overlap trigger relies on the subject's account lock, not an `EXCLUDE`
  constraint; a settings version saved for today also changes today's earlier flags; a clock-in racing an approved
  correction for the same person has no two-process test yet; Review loads shifts for one branch, so a split shift
  across two branches shows the other branch's session as "Not on the roster"; the toast and the live region can
  both read a successful Clock in.

- HR-7c known limits: a former staff member whose account is inactive cannot sign in to open their old payslips
  (HR opens them as a preparer); no logo on payslips in v1; opening a payslip is a GET that writes an open row each
  time; statutory and export totals are summed in PHP over decrypted lines and are not load-tested; one generic bank
  file layout only; the "Exports" tab is cut at 390 px (the shared tab-edge fade, DD-40).

- HR-7d known limits: TP1, TP3, zakat, additional remuneration and non-residents are not counted (a typed MTD
  covers them); adjustment runs carry no MTD line; the year to date counts only KPOne's own locked runs, and an earlier
  month never prepared is not detected; variable overtime is projected like basic pay; one child-relief rate; a part
  month for a joiner or leaver is projected; each band's From is M; a worker in two regular runs for one month would
  be counted twice. Drafts prepared before the deploy ask to be prepared again once.
- HR-12 known limits: the CSV is uploaded to the outside product by hand; no live link or import preview; leave rows
  carry the whole request's days; the leave type is shown by name (PDPA-review item).
- HR-14 known limits: email goes to the account's sign-in address; no SMS or WhatsApp provider, so no stop-reply
  handling; a claim refunded through payroll sends no claim message; a message still `sending` when PHP stops counts
  against the month's cap; a later provider bounce is not recorded; locking a large payroll with email on adds about
  one to two seconds per person (deploy note: set a short `MAIL_TIMEOUT`).
- HR-17a known limits: the form's answers and the call note are decrypted for the 25 listed rows; the talent pool and
  consent withdrawal come with HR-17e; the MMC number and APC are as stated by the candidate.
- HR-17b known limits: the private link works for anyone holding it (HR-PLAN 19.13); a busy hiring mailbox may miss
  reminders, so "Needs you" is the reliable place to look; "Emailed" means the mailer accepted it, and a later bounce is
  not recorded; "Needs you" scans up to 500 waiting applications per view and is not load-tested.
- HR-17c known limits: no deadline at Scheduling, so an application waiting for picks is never auto-rejected; a slot is
  one fixed hour, with no interview room or interviewer per branch.
- HR-17d known limits: PDFs are stored as uploaded and never rendered in KPOne (shallow check); opening or downloading
  is a GET that writes an event row each time; dummy PDFs only until the owner provides the real ones.
- HR-10a known limits: opening a letter is a GET that writes an open row each time; a voided replacement leaves no
  current letter; the year in the number comes from the first active branch's time zone; letter wording needs the
  clinic's adviser before real use.
- HR-10b known limits: with one Director, the same person writes the HR Manager's appraisal and signs it off; leave
  across the period edge is recounted with today's working days; PostgreSQL's 7-day check compares `signed_off_at`
  with `sent_at` (the service check is the real one); drafts left when a cycle closes stay unsent.
- HR-10c known limits: opening a case or a file writes an audit row each time; a file added by mistake stays with the
  case; the list reads the latest 300 cases before filtering; HR-10a's Issued list applies its 200-row limit before
  its new filter.
- HR-10d known limits: photos come only with the report, never later; the list reads the latest 500 reports before
  the tabs; opening a report or a photo as a manager writes an audit row each time; patient-care reports are read by
  HR until the owner names a clinical reviewer.
- HR-15a known limits: there is no PostgreSQL trigger on `model_has_roles` (shared by every role and the seeders), so
  the Locum-only rule after linking is held by `StaffRoleService` and the link guard; a trigger is a possible
  follow-up that needs its own review. The roster does not count a locum doctor towards the resident-doctor minimum.
  A future grant migration that loops over every role would reach `locum`; grant migrations name their roles.
  The Staff page shows locum sign-in and staff-account requests to everyone who passes the staff-creation check
  (Director and Technical Admin) without checking the role the account needs, so Technical Admin can see a locum or
  CA Supervisor request only the Director can finish; `StaffProvisioningService` refuses the role, and from HR-15b
  the card says "Only the Director can create a Locum account." (B.E.R.N.I.S.S: lists unchanged for v1).
- HR-15b known limits: a confirmed open shift points at an ordinary draft roster shift (no foreign key), so if HR
  later removes or moves that shift in the planner before approval, the open shift stays "Filled" with nobody
  rostered; HR posts it again. Two confirms into the same branch week take turns on an advisory lock so the second
  uses the first's new draft; no two-process test covers that case (the race test covers two confirms of one shift).
- HR-17e known limits: the pool tick wording and the invitation message are drafts (Malay included); a staff-account
  request is a note between people, and the account created is not linked back to the application; the pool offers
  only open positions of the same type, because the form's questions differ by type.
- HR-13 known limits: the public form stays off until the owner approves the privacy notice; one organisation per
  installation; an application still in progress is cleared once the period has passed since it was sent; phone and
  email show on the HR list without a Show button (only CV opens are audited); the overall cap of 100 sends an hour can
  lock real applicants out for up to an hour, and behind a proxy with no trusted list all senders share one address;
  the CV's size and hash and the nonce stay after a purge; the clinic name on the form is fixed text; the PDF check is
  shallow; applicants have no self-service withdrawal or deletion; Daniel's optional pending-date hint is not built.

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
12. **Authorised 2026-11-06 (decision 45), after HR-7, in this order (owner, decision 46):**
    - **HR-12 Outside HR product link.** Smallest and lowest risk. First find out whether the product has an API;
      if not, a reviewed file import and export.
    - **HR-13 Job application intake.** KPOne's first public intake, needing rate limits, spam protection, file
      checks and a privacy notice. It sits beside the static careers pages.
    - **HR-14 Staff messages (WhatsApp, SMS, email, OTP).** Built in PR #414: email only, off by default
      (`HR_MESSAGES_EMAIL_ENABLED`), opt-in and opt-out, no patient data; SMS and WhatsApp "Not connected"; OTP left
      to HR-15 (decision 75).
    - **HR-15 Locum portal.** Accounts made only by HR through StaffProvisioningService, a narrow locum role, and OTP
      or password sign-in.
    - **HR-16 Selfie or location at clock-in.** The riskiest under PDPA (biometric and location data), so last.
      Optional per branch, consent text, encrypted selfies with short retention, a branch geofence checked only at
      the moment of clock-in, and a fallback.
