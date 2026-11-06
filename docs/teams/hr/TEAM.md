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
   OTP or push notification (AGENTS.md; B.E.R.N.I.S.S for the owner).
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
    - **HR-14 Staff messages (WhatsApp, SMS, email, OTP).** An outside provider, keys in the environment's secrets,
      opt-in and opt-out, no patient data. OTP and the locum portal depend on it.
    - **HR-15 Locum portal.** Accounts made only by HR through StaffProvisioningService, a narrow locum role, and OTP
      or password sign-in.
    - **HR-16 Selfie or location at clock-in.** The riskiest under PDPA (biometric and location data), so last.
      Optional per branch, consent text, encrypted selfies with short retention, a branch geofence checked only at
      the moment of clock-in, and a fallback.
