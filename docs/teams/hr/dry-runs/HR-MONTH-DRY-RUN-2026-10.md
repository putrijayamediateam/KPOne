# HR month dry run: October 2026

Report only. Nothing in the code, tests or migrations was changed. Synthetic `.test` data only, in the task-owned
database `kpone_hrdry_testing`. Run on 2026-11-15/16 against `main` at `708a549a`, by calling the real HR domain
services from helper scripts kept outside the repository. No message of any kind was sent (0 rows in
`hr_message_deliveries`; mail was pointed at the in-memory driver).

## 1. Summary

One synthetic month (October 2026) was walked end to end at the CHERAS branch: leave setup with KPOne defaults,
a five-week roster, 95 photo clock-ins with 93 clock-outs, a late arrival, a forgotten clock-out and two corrections,
four overtime requests, three leave requests, two staff claims, payroll setup, an October run, payslips, the two
exports and an adjustment run.

Every step could be done through the services, and every two-person rule held when tried. All the money on the
three October payslips matched my own arithmetic to the sen, against the tables I entered.

Three serious findings, all about money that is wrong but looks right:

- a late or early-leaving session keeps its lunch break as worked time, so an hourly worker is paid more for
  arriving late (S1);
- in the first KPOne payroll month of a year, MTD is worked out as if the person earned nothing in January to
  September, with no warning on the run (S2);
- an adjustment run cannot carry EPF, SOCSO, EIS or MTD, so a bonus or back-pay paid through it has no statutory
  parts (S3).

**Verdict:** the month can be run, and the controls work. It is not ready for real pay until S1 to S3 are settled
and the statutory figures are checked against current law.

## 2. Setup used

- **Database:** `kpone_hrdry_testing` on 127.0.0.1:55601, freshly migrated and seeded (`migrate:fresh --seed`,
  `KPOnePresentationSeeder`, `KPOneDemoSetupSeeder`). The effective database was checked before each script ran, and
  each script refused to run unless the name ended in `_testing`.
- **Branch:** CHERAS (Asia/Kuala_Lumpur). Working days Mon to Sat. Holidays added: Deepavali (8 Nov) and Christmas
  (25 Dec). October had no public holiday.
- **Staff in the walk** (demo accounts, by role): the demo Resident Doctor, the demo Panel Officer and the demo
  Marketing account, plus the development technical admin (roster and attendance only). These are the only human
  accounts the seeders assign to CHERAS in October (see 4, note N1).
- **Who acted:** the demo HR Manager planned, set up and prepared. The demo Director approved, checked, confirmed
  and locked. The demo Finance Officer paid a claim.
- **Staff files:**
  - Resident Doctor: permanent from 1 Mar 2024.
  - Panel Officer: permanent from 1 Jun 2021.
  - Marketing: part-time from 1 Feb 2026.
  - Bank details were confirmed for the Resident Doctor and the Panel Officer only.
- **Time:** each action ran at its own day and time in October (time travel), in the order a clinic would do it.
- **Photos:** synthetic random-noise JPEGs, never a face. 188 photos were stored, one per press.
- **Not run:** the browser check of the HR pages (see section 7).

## 3. Walk, step by step

### 3.1 Leave setup (28 Sep)
- **"Use KPOne defaults" for 2026:**
  - The preview said it would add 5 types and 37 entitlement rows.
  - People by service tier: 11 in the first tier, 1 in the middle tier, 0 in the long tier.
  - It also counted 9 people with no staff file, 2 whose 2- or 5-year anniversary falls in 2026, and 1 prorated.
  - Applying it created Annual, Sick, Hospitalisation, Maternity and Paternity, each marked "set by defaults".
  - The 37 rows were 33 role rows (11 human roles × 3) plus 4 person rows. A second preview showed nothing more to
    add.
- Working days and the two holidays saved. An "Unpaid leave" type was added (not paid, no balance).
- **"Give this year's entitlement":** the preview said 12 people and 36 lines, and the grant wrote 36 lines.
- **Starting balances (days), all as expected:**
  - Resident Doctor (1 y 10 m at 1 Jan): Annual 8, Sick 14, Hospital 46.
  - Panel Officer (4 y 7 m): Annual 12, Sick 18, Hospital 42.
  - Marketing (joined 1 Feb, 11 completed months): Annual 7 (8 × 11 ÷ 12 = 7.33, rounded down), Sick 14, Hospital 46.
  - Hospitalisation is 60 less the sick figure, as the note says.

### 3.2 Roster (29 Sep)
- The HR Manager started five blank drafts (weeks of 28 Sep to 26 Oct) and added 98 October shifts:
  - Resident Doctor and Panel Officer: 27 shifts each (Mon to Sat, 09:00 to 18:00, 60 min break);
  - Marketing: 22 shifts (Mon to Fri, 09:00 to 17:00);
  - technical admin: 22 shifts (Mon to Fri, 10:00 to 19:00).
- The HR Manager sent each week. The HR Manager could not approve (no approve permission), and the Director approved
  all five.
- **Refusals, as designed:**
  - On a later week, the Director sent a week and tried to approve it: "You sent this week for approval. Someone
    else must approve it."
  - A shift for the demo CA at CHERAS on 3 Nov was refused: "Demo Ca is not assigned to Cheras on 3 Nov 2026."

### 3.3 Attendance (1 to 31 Oct)
- Attendance settings were saved from 1 Oct (grace 5 min, forgotten window 120 min, 7 correction days).
- Daily presses with a photo, plus these events:
  - **Late arrivals:** the Resident Doctor came in at 09:20 on 6 Oct, and Marketing at 09:30 on 2 Oct.
  - **Left early:** the Resident Doctor left at 16:00 on Sat 17 Oct.
  - **Forgotten clock-out:** the Panel Officer did not clock out on 13 Oct. The next morning's clock-in marked that
    session "missing clock-out". The Panel Officer proposed 18:00, and the HR Manager approved it.
  - **Correction on someone's behalf:** the HR Manager proposed a clock-in change for Marketing on 2 Oct. The HR
    Manager could not decide it ("You may not decide a correction you proposed."), and the Director rejected it.
  - **No clock-in:** Marketing did not come in on 26 Oct.
  - **Rest day:** the Resident Doctor worked Sunday 25 Oct, 10:00 to 14:00, with no shift.
  - **Leave days:** the Resident Doctor's four leave days had no presses.
- **Review page:** the flags read as expected:
  - "Late 30 min", "Late 20 min" and "Left early 120 min";
  - "On approved leave" on 22 and 30 Oct;
  - "Not on the roster" on 25 Oct;
  - "No clock-in" on 26 Oct;
  - no flag on 13 Oct after the correction.
- The Summary figures for the month are in section 6. They match my hand totals, but see S1 and m1.

### 3.4 Overtime
- Settings were saved from 1 Oct with the KPOne default rates (1.5x, 2x, 3x; nearing 40 h, limit 45 h). The roles
  that may claim are Resident Doctor, Panel Officer, Marketing and Technical Admin.
- **8 Oct:** the Resident Doctor asked for 120 min. The Resident Doctor could not approve it, and the HR Manager
  approved it in full (normal day, 1.50).
- **15 Oct:** the Resident Doctor asked for 90 min. Approving 60 with no reason was refused ("Choose why fewer minutes
  are approved."), then 60 was approved with "not all needed".
- **20 Oct:** the Panel Officer asked for 60 min, and the HR Manager rejected it as "not agreed in advance".
- **Sun 25 Oct:** the Resident Doctor asked for 240 min. It was approved as a rest day at 2.00. The allowance was the
  whole session, as the note says.

### 3.5 Leave in use
- **Annual leave, 22 to 23 Oct (2 days):** the Resident Doctor applied on 12 Oct, and the HR Manager approved it.
- **Unpaid leave, Fri 30 Oct (1 day):** the Resident Doctor applied on 19 Oct, and it was approved.
- **Sick leave (MC), 27 Oct:** the Resident Doctor applied that morning with an MC reference, and it was marked late.
  Approving without "Evidence seen" was refused, then approved with it.
- **Balances after:** Resident Doctor Annual 8 → 6 and Sick 14 → 13. Hospitalisation stayed 46, and unpaid leave
  used no balance. The other two people's balances did not move.

### 3.6 Staff claims
- **Settings and category:**
  - Director level RM 500, 30 days back;
  - category "Travel and parking": receipt needed, RM 200 a claim, RM 300 a month.
- **SC-000001, RM 45.50 parking (Panel Officer, 9 Oct, synthetic receipt):**
  - A second claim with no receipt was refused ("Travel and parking needs a receipt.").
  - The Panel Officer could not approve their own claim, and the HR Manager approved it.
  - The HR Manager, as the approver, could not pay it. The Finance Officer recorded a bank transfer on 16 Oct.
- **SC-000002, RM 80.00 travel (Resident Doctor, 20 Oct):** the HR Manager approved it and left it unpaid, so payroll
  picked it up.

### 3.7 Payroll
- **KPOne ships no statutory figures.** I entered synthetic EPF, SOCSO, EIS and HRD tables and a 2026 MTD schedule
  (all in section 5). Each says it is synthetic.
- **Setup by the HR Manager:**
  - pay group "Monthly staff" (pay day 28);
  - six pay item types with their scheme ticks: Fixed allowance, Phone allowance, Overtime, Unpaid leave, Staff claim
    refund and Bonus;
  - rate settings of 26 days and 8 hours;
  - the run line types (overtime, unpaid leave and claim refunds).
- **Checks and confirmations:**
  - The HR Manager could not check their own table ("Someone else checks this. You entered it."). The Director checked
    the four tables and the MTD schedule.
  - Pay profiles were entered by the HR Manager, from 1 Oct:
    - Resident Doctor: monthly RM 6,000, Fixed allowance RM 500, Phone allowance RM 100, all schemes, MTD category 1;
    - Panel Officer: monthly RM 3,000, MTD ticked with no details, so it is typed each run;
    - Marketing: hourly RM 15, no MTD.
  - The HR Manager could not confirm their own entry, and the Director confirmed all three.
- **October run (2 Nov):**
  - The Director could not prepare it ("You are the only person left who can approve payroll runs, so the HR Manager
    prepares them."), and the HR Manager prepared it.
  - The Panel Officer showed the error "MTD not entered" until the HR Manager typed RM 0.00. Marketing showed the
    warning "No confirmed bank details".
  - The HR Manager sent it. The HR Manager could not approve it, and the Director approved and locked it.
  - On locking:
    - SC-000002 was recorded as paid with the method "payroll";
    - three payslips were written;
    - Finance could not pay or reverse SC-000002 again ("Paid through a locked payroll run. Correct it with an
      adjustment run.").
- **Payslips:**
  - The Resident Doctor opened their own payslip, and the open was recorded.
  - The Panel Officer could not open the Resident Doctor's payslip.
  - Marketing's "My payslips" lists October.
- **Exports:**
  - The bank file has 2 rows. Marketing is left out and named as missing bank details.
  - The accountant summary carries the note line.
  - The statutory summary totals match mine (section 6).
  - The HR Manager could also download the accountant file. This is the owner's later decision (PDPA 14, migration
    `2026_11_10_006700`), but the plan still says Director only (m5).
  - A second regular October run was refused ("This pay group already has a run for this month.").
- **Adjustment run (3 Nov):**
  - The Director reversed the 8 Oct overtime that the locked run had already paid. This was allowed with no warning
    (M1).
  - The HR Manager prepared an October adjustment with a manual recovery of RM 86.54 for the Resident Doctor and a
    RM 200 bonus for the Panel Officer.
  - The run warned "This correction takes money back: net below zero" for the Resident Doctor.
  - It was sent, approved and locked by the Director. The Resident Doctor now has two October payslips (regular and
    adjustment).
  - No statutory line appeared on either adjustment payslip (S3).

**Where a second person is required (all held when tried):**

| Step | First person | Second person |
|---|---|---|
| Bank details | HR enters | the Director confirms, and must Show the account first |
| Roster week | the sender | someone else approves (the Director) |
| Attendance correction | the subject or the proposer | someone who is neither |
| Overtime | the subject or the requester | someone who is neither |
| Leave | the applicant | someone else decides |
| Staff claim | the claimant | someone else approves; the payer is neither the claimant nor the approver |
| Statutory table and MTD schedule | the entrant | the Director checks |
| Pay profile | the entrant (never the worker) | the Director confirms |
| Payroll run | the preparer (anyone who prepared any part) | the Director approves, and the approver locks |

The lock is also refused if the locker sent or approved a claim in the run.

**One person is enough** for these. Each of them changes pay or pay-related figures:

- rate settings;
- pay item type scheme ticks;
- run line types;
- pay groups;
- leave defaults, the grant, working days and holidays;
- attendance and overtime settings;
- claim settings and categories;
- the MTD typed on a run (the run itself is still approved by someone else).

The rate settings and the scheme ticks are a known limit in HR-PLAN 16.11 item 5.

## 4. Defects and rough edges

| Id | Sev | Area | One line |
|---|---|---|---|
| S1 | S | Attendance → Payroll | A late or early-leaving session keeps its break as worked time, so hourly staff are paid more for being late |
| S2 | S | Payroll MTD | First KPOne payroll month of a year: MTD worked as if January to September earned nothing, with no warning (RM 0 instead of about RM 250) |
| S3 | S | Payroll adjustment | Adjustment runs carry no EPF, SOCSO, EIS or MTD, so a bonus or back-pay through one has no statutory parts |
| M1 | M | Overtime, leave, corrections → Payroll | Overtime already paid by a locked run can be reversed with no warning, and nothing links it to the run |
| M2 | M | Payroll tables | The official EPF Third Schedule does not fit the 200-band cap (to confirm) |
| M3 | M | Overtime defaults | Rest-day and overtime pay are a flat hourly multiplier on basic only; Employment Act rest-day rules differ (to check) |
| M4 | M | Payroll wording | Payroll pages and payslips say "Set by KPOne defaults" although every statutory figure was entered by the clinic |
| m1 | m | Attendance summary | "Rostered" includes days on approved leave |
| m2 | m | Overtime | The most a person may claim includes minutes clocked in before the shift starts (5 to 10 min every day) |
| m3 | m | Payslip and accountant CSV | An "HRD levy employee · RM 0.00" line appears under employee contributions |
| m4 | m | Leave defaults | Someone whose 2- or 5-year anniversary falls in the year keeps the lower tier all year unless HR adds a row by hand |
| m5 | m | Docs | HR-PLAN 17.3/17.4 and HR TEAM.md still say exports are Director only; the HR Manager can export since PDPA 14 |
| m6 | m | Bank file | The account number is written unquoted, so a spreadsheet drops a leading zero |
| m7 | m | Settings | Attendance and overtime settings cannot start before today, so a clinic starting mid-month cannot cover its first days |

Notes that are not product defects:

- **N1 (demo data):** `KPOneDemoSetupSeeder` dates its CHERAS moves 2026-11-15. For all of October, the demo CA,
  CA Supervisor and Finance Officer had only an ended non-primary assignment elsewhere, and the Director had no
  primary branch. The demo CA could not apply for October leave ("You have no primary branch on that date. Ask HR.").
  That is why the walk used four staff. A back-dated demo month needs back-dated assignments.
- **N2 (test residue):** the probe that checked the demo CA in October left one open clock session on 10 Oct at
  PUCHONG. The service chose the person's own assigned branch, not the one in my session, which is correct.

### S1: break kept as worked time on a late or early session (wrong money for hourly staff)
- **Steps:**
  1. Roster Marketing 09:00 to 17:00 with a 60 min break.
  2. Clock in at 08:55 and out at 17:02 on a normal day.
  3. On another day, clock in at 09:30 and out at 17:02.
  4. Run payroll for an hourly profile.
- **What happened:**
  - The normal day counts 7 h 07 min (487 − 60).
  - The late day counts 7 h 32 min: the 60 min break is not taken off, so the late day earns 25 min more.
  - Marketing's October pay includes those 25 min (RM 6.25 at RM 15 an hour). The same happens on the Resident
    Doctor's late day (8 h 45 min against 8 h 10 min) and early-leave day (7 h 05 min, break not taken off), which
    inflates their "worked" figure.
- **Expected:** the break comes off whenever the session spans the break (or a share of it), so arriving late never
  increases worked time.
- **Where:** `AttendanceDays::worked()` ("the shift's break comes off only when the session covers the whole shift").
  It is used by `AttendanceTotals::worked()`, which `PayrollRunCalculator` uses for hourly pay, and by the Summary and
  Review figures.

### S2: first payroll month in a year under-deducts MTD with no warning
- **Steps:**
  1. Start KPOne payroll in October, with no earlier locked runs that year.
  2. Give a person a pay profile with MTD details (category 1). The person joined in 2024, so there is no TP3 warning.
  3. Prepare the run.
- **What happened:**
  - Y, K and X are 0 and n = 2.
  - P = (Y1 − K1) × 3 − 9,000 = RM 9,119.43, and MTD is RM 0.00.
  - The run shows no error or warning.
- **Expected:** a warning, or an error that a typed MTD clears, when the person was employed earlier in the year but
  KPOne has no locked runs for those months.
  - With a full year at this pay, P would be about 12 × 6,039.81 − 9,000 = RM 63,477.72, giving about RM 248.55 a
    month (band from RM 50,000.01: 11% and RM 1,500).
  - HR-PLAN 18.11 lists "pay from before KPOne payroll began is not counted" as a known limit, but nothing on the run
    says so. The TP3 warning covers only people who joined this year.
- **Where:** `PayrollRunCalculator::calculate()` (the MTD part, `mtdYearToDate()` and `joinedThisYear()`) and
  `MtdCalculator::month()`.

### S3: adjustment runs have no statutory parts
- **Steps:**
  1. Lock October.
  2. Prepare an adjustment for October and add a RM 200 Bonus line (the type counts for EPF and MTD).
  3. Send, approve and lock it.
- **What happened:**
  - The adjustment payslip shows Bonus RM 200 and net RM 200, with no EPF employee or employer part and no MTD.
  - The statutory summary for that run has nothing to report.
  - A manual line cannot carry a scheme, so HR cannot add the contribution by hand either.
- **Expected:** statutory parts worked out on the difference in each scheme wage, or the adjustment refused for lines
  that count for a scheme, or at least a warning naming the missing contributions.
  - HR-PLAN 16.13 says adjustment runs hold manual difference lines only, and 18.1 says MTD on a bonus through an
    adjustment run is recorded outside KPOne. EPF, SOCSO and EIS are not mentioned, and the run gives no warning.
- **Where:** `PayrollRunCalculator::calculate()` (the `adjustment` branch returns manual lines only).

### M1: paid overtime can be reversed after locking, with no link to the run
- **Steps:**
  1. Lock October, which paid the Resident Doctor 2 h of overtime on 8 Oct (RM 86.54).
  2. The Director reverses that overtime on 3 Nov.
- **What happened:** the reversal is accepted with no message. The request now reads "reversed", while the locked
  run and the payslip still hold the RM 86.54 line. Nothing prompts an adjustment, and HR must notice and add a
  recovery by hand. I used the "Unpaid leave" deduction type for it, as there is no other deduction type.
- **Expected:** a warning that the item was paid in a locked run (naming the run), or a flag on the run's Exports
  card.
  - The same is likely for approved leave cancelled after locking and for corrections that change an hourly worker's
    minutes after locking. Neither was tried.
- **Where:** `OvertimeService::reverse()`. There is no payroll check in `app/Domain/HR/Attendance` or
  `LeaveRequestService`.

### M2: the official EPF schedule may not fit
- **What happened:** a statutory table holds at most 200 bands (`PayrollSetupService::MAX_ROWS`), and only one table
  is in force per scheme and category.
- **My reading of the EPF Third Schedule:** RM 20 bands up to RM 5,000, then RM 100 bands up to RM 20,000, about 400
  bands. The contribution comes from the band's upper limit, so a percentage band cannot reproduce it.
  - Example: on RM 2,248, my 11% band gives RM 248. The schedule's RM 2,240.01 to RM 2,260 band would give RM 249 (11%
    of RM 2,260, rounded up).
- **Expected:** a cap large enough for the official schedule, or a way to express "percentage of the band's upper
  limit". To confirm against the current KWSP schedule.
- **Where:** `PayrollSetupService::table()`.

### M3: overtime and rest-day pay basis (to check)
- **What happened:**
  - Overtime is basic ÷ 26 ÷ 8 × hours × multiplier. Fixed allowances are left out of the rate.
  - Rest-day work is the hourly rate × 2 for every hour. For the Resident Doctor's 4 h on Sunday, that is RM 230.77,
    one full day.
  - The Employment Act's rest-day rule pays a half day's wage for work up to half the normal hours. That would be
    RM 115.38 here, and the Act applies only to some employees. Unpaid leave also comes off basic only.
- **Expected:** the owner and the clinic's adviser confirm the basis. The defaults label already says "not
  professional advice".
- **Where:** `PayrollRunCalculator` (overtime and unpaid leave lines) and `OvertimeSettingsService::SUGGESTED`.

### M4: "Set by KPOne defaults" on payroll is misleading
- **What happened:** the payroll check line (`AccountantNote.vue`), the payslip footer (`Payslip.vue`) and the
  accountant CSV all say "Set by KPOne defaults at the owner's instruction; check against current law (official
  KWSP/PERKESO/HRD Corp/LHDN schedules)…". But KPOne ships no statutory table or MTD schedule: every statutory number
  on the payslip came from tables the clinic typed in. Only the overtime multipliers are KPOne defaults.
- **Expected:** wording that says the statutory figures come from the clinic's own checked tables, and keeps the
  "check against current law" part.

### m1 to m7
- **m1 (Summary):** "Rostered" counts shifts on approved-leave days. The Resident Doctor shows 216 h rostered against
  194 h 40 min worked, although 4 rostered days were leave. Where: `AttendanceTotals::summary()`.
- **m2 (Overtime):** `AttendanceDays::beyondRoster()` adds minutes before the shift start, so an 08:55 clock-in on a
  09:00 shift makes 5 min claimable every day.
- **m3 (HRD levy line):** a table must have an employee part, so the HRD levy shows "employee · 0.00% … RM 0.00" on
  the payslip and the accountant CSV. HRD levy is an employer cost only.
- **m4 (Leave defaults):** the Resident Doctor reaches 2 years on 1 Mar 2026 but keeps 8 annual and 14 sick days for
  the year. The preview only counts "crossing: 2", and HR adds per-person rows by hand. This is documented in
  `LeaveDefaultsService`.
- **m5 (Docs drift):** `2026_11_10_006700_grant_hr_payroll_export_to_hr_manager` gives the HR Manager the export
  permission (owner, 2026-11-11). HR-PLAN 17.3 and 17.4 and the HR-7c row in TEAM.md still say Director only.
  - The HR Manager can now prepare a run and download its bank file, although only the Director can approve it. That
    is the owner's call, worth recording beside the decision.
- **m6 (Bank file):** account numbers such as `0098765432101` are not quoted. The Exports tab tells the Director to
  open the file as text, which avoids the problem, but a quoted or tab-safe cell would be safer.
- **m7 (Settings start date):** attendance and overtime settings take an effective date of today or later, so days
  before the first save cannot be flagged or claimed. This is documented for overtime. The dry run had to save them
  "on 1 Oct" by time travel.

## 5. Payslip hand check: the Resident Doctor, October 2026

### Inputs
- **Pay profile:**
  - monthly basic RM 6,000.00;
  - Fixed allowance RM 500.00 (counts EPF, SOCSO, EIS, MTD and HRD);
  - Phone allowance RM 100.00 (counts MTD only);
  - all schemes;
  - MTD category 1, 0 children.
- **Rate settings:** daily divisor 26, hourly divisor 8. The derived hourly rate is 6,000 ÷ 26 ÷ 8 = RM 28.846154.
- **Pay item types:** Overtime counts SOCSO, EIS, MTD and HRD (not EPF). Unpaid leave counts all five schemes.
- **Approved overtime in October:** 120 min at 1.50 (8 Oct), 60 min at 1.50 (15 Oct), and 240 min at 2.00 (25 Oct,
  rest day).
- **Approved unpaid leave:** 1 day (30 Oct).
- **Approved and unpaid claim:** SC-000002, RM 80.00.
- **Tables I entered** (synthetic, from 1 Jan 2026, each checked by the Director):
  - **EPF "Malaysian below 60"** (rounding: up to the next ringgit):
    - RM 0 to 5,000: employee 11%, employer 13%;
    - from RM 5,000.01: employee 11%, employer 12%.
  - **SOCSO "Category 1"** (nearest sen):
    - RM 0 to 3,000: fixed 14.75 / 51.65;
    - RM 3,000.01 to 5,900: 0.5% / 1.75%;
    - from RM 5,900.01: fixed 29.75 / 104.15.
  - **EIS** (nearest sen): RM 0 to 6,000 at 0.2% / 0.2%; from RM 6,000.01 fixed 11.90 / 11.90.
  - **HRD** (nearest sen): employee 0%, employer 1%.
  - **MTD 2026** (rounding up to the next 5 sen, smallest MTD RM 10):
    - reliefs: individual 9,000, EPF limit 4,000 (spouse 4,000, disabled 7,000 and 6,000, child 2,000, not used here);
    - bands, as (from, rate, tax on M for categories 1 and 3): (0, 0%, 0), (5,000.01, 1%, −400), (20,000.01, 3%, −250),
      (35,000.01, 6%, 600), (50,000.01, 11%, 1,500), (70,000.01, 19%, 3,700), (100,000.01, 25%, 9,400) and higher
      bands.

### Earnings
| Line on payslip | My working | Mine | KPOne |
|---|---|---|---|
| Basic salary | full month employed | 6,000.00 | 6,000.00 |
| Fixed allowance | from profile | 500.00 | 500.00 |
| Phone allowance | from profile | 100.00 | 100.00 |
| Overtime · 2 h × 1.5 (normal, 8 Oct) | 28.846154 × 2 × 1.5 = 86.5385 → half up | 86.54 | 86.54 |
| Overtime · 1 h × 1.5 (normal, 15 Oct) | 28.846154 × 1 × 1.5 = 43.2692 | 43.27 | 43.27 |
| Overtime · 4 h × 2 (rest day, 25 Oct) | 28.846154 × 4 × 2 = 230.7692 | 230.77 | 230.77 |
| **Total earnings** | | **6,960.58** | **6,960.58** |

### Deductions and refunds
| Line | My working | Mine | KPOne |
|---|---|---|---|
| Unpaid leave · 1 day from 30 Oct | 6,000 ÷ 26 × 1 = 230.7692 | 230.77 | 230.77 |
| Staff claim SC-000002 refund | claim amount | 80.00 | 80.00 |

### Scheme wages
- **EPF:** basic 6,000 + Fixed 500 − unpaid 230.77 = **6,269.23**.
- **SOCSO, EIS and HRD:** 6,000 + 500 + overtime 360.58 − 230.77 = **6,629.81**.
- **MTD (Y1):** 6,000 + 500 + 100 + 360.58 − 230.77 = **6,729.81**.

### Contributions
| Line | My working | Mine | KPOne |
|---|---|---|---|
| EPF employee · 11.00% of RM 6,269.23 (up to the next ringgit) | 689.62 → 690 | 690.00 | 690.00 |
| EPF employer · 12.00% of RM 6,269.23 | 752.31 → 753 | 753.00 | 753.00 |
| SOCSO employee · fixed for wages RM 5,900.01 and above | band ≥ 5,900.01 | 29.75 | 29.75 |
| SOCSO employer · fixed | same band | 104.15 | 104.15 |
| EIS employee · fixed for wages RM 6,000.01 and above | 6,629.81 > 6,000 | 11.90 | 11.90 |
| EIS employer · fixed | same | 11.90 | 11.90 |
| HRD levy employee · 0.00% | 0 | 0.00 | 0.00 |
| HRD levy employer · 1.00% of RM 6,629.81 | 66.2981 → 66.30 | 66.30 | 66.30 |

### MTD (PCB)
- **Figures:** Y = K = X = 0 (no earlier locked run this year), Y1 = 6,729.81, K1 = 690.00, n = 12 − 10 = 2.
- **K2** = min(K1, (4,000 − 0 − 690) ÷ 2 = 1,655) = 690.00.
- **P** = 0 − 0 + (6,729.81 − 690) + (6,729.81 − 690) × 2 − 9,000 = 6,039.81 × 3 − 9,000 = **9,119.43**.
  KPOne shows "RM 9,119.43 chargeable for the year".
- **Band** from RM 5,000.01: M = 5,000.01, R = 1%, B = −400.
- **MTD** = ((9,119.43 − 5,000.01) × 1% + (−400) − 0) ÷ 3 = (41.19 − 400) ÷ 3, which is below zero, so **0.00**.
  KPOne shows 0.00. It matches the method, but see S2: the figure is low only because January to September are
  missing.

### Totals
- Employee contributions = 690.00 + 29.75 + 11.90 + 0.00 = **731.65** (KPOne 731.65).
- Employer contributions = 753.00 + 104.15 + 11.90 + 66.30 = **935.35** (KPOne 935.35).
- **Net** = 6,960.58 − 230.77 − 731.65 − 0.00 + 80.00 = **6,078.16** (KPOne 6,078.16, and the same amount in the bank
  file).

**Result: every line matches to the sen.**

### Labels on the payslip
- **Header:**
  - name, "October 2026", pay group "Monthly staff", pay day 28, "issued 2 Nov 2026";
  - the letterhead text of CHERAS (clinic name, address and email only, as the demo Business details hold no
    phone);
  - bank "Synthetic Bank Berhad", last four "0123".
- **Lines:** each carries its own sentence, as listed above, for example "Overtime · 4 h × 2 (rest day, 25 Oct)" and
  "MTD (PCB) · worked out: RM 9,119.43 chargeable for the year, category 1, 2 months left".
- **The KPOne-defaults wording:**
  - The payslip page footer reads "Generated by KPOne. Set by KPOne defaults at the owner's instruction; check
    against current law (official KWSP/PERKESO/HRD Corp/LHDN schedules) before real use. Synthetic data only." This is
    in `Payslip.vue`, not in the stored snapshot.
  - The accountant CSV carries the same "Note" line.
  - The payroll pages carry it through `AccountantNote.vue`.
  - No line on the payslip carries the leave label ("…not professional advice. Check against current law before real
    use."). That label is on leave types and entitlements written by "Use KPOne defaults", and on the overtime
    settings form.
- **Mismatches:** no money mismatch. On wording, see M4 (the defaults wording on clinic-entered figures) and m3 (the
  HRD employee line).

## 6. Numbers checked

### Leave balances (days)
| Person | Annual start → end | Sick start → end | Hospital | Check |
|---|---|---|---|---|
| Resident Doctor | 8 → 6 (2 days, Thu and Fri) | 14 → 13 (MC 27 Oct) | 46 → 46 | matches |
| Panel Officer | 12 → 12 | 18 → 18 | 42 | matches |
| Marketing | 7 → 7 (8 × 11 ÷ 12, rounded down) | 14 | 46 | matches |

### Attendance summary, CHERAS, October

Worked time is the session less the shift break when the session covers the whole shift.

**Resident Doctor:** KPOne shows 216 h rostered, 194 h 40 min worked, 1 late (20 min), 1 left early, 0 no clock-in,
0 no clock-out.

- Rostered: 27 shifts × 480 = 12,960 min (includes 4 leave days, m1).
- Worked:
  - 19 normal days × 490 = 9,310;
  - plus 6 Oct 525, 8 Oct 605, 15 Oct 575, 17 Oct 425 and 25 Oct 240;
  - total 11,680 min.

**Panel Officer:** KPOne shows 216 h rostered, 221 h 20 min worked, all flags 0.

- Rostered: 27 × 480 = 12,960.
- Worked: 25 × 490 + 485 (13 Oct, corrected) + 545 (20 Oct) = 13,280.

**Marketing:** KPOne shows 154 h rostered, 149 h 52 min worked, 1 late (30 min), 1 no clock-in.

- Rostered: 22 × 420 = 9,240.
- Worked: 20 × 427 + 452 (2 Oct, late day with no break taken off, S1) = 8,992.

**Technical admin:** KPOne shows 176 h rostered and 177 h 45 min worked.

- Rostered: 22 × 480 = 10,560.
- Worked: 21 × 485 + 480 (9 Oct) = 10,665.

All four rows match my figures, and the total row (762 h rostered, 743 h 37 min worked) adds up.

### Overtime minutes
- **Requested:** 120 + 90 + 60 + 240.
- **Approved:**
  - Resident Doctor normal days: 120 + 60 = **180 min** (Summary "3 h (normal day)");
  - Resident Doctor rest day: **240 min** ("4 h (rest day)");
  - Panel Officer: 0 (rejected).
- Multipliers stored on approval: 1.50, 1.50 and 2.00.

### Claims
- **SC-000001:** RM 45.50, paid by bank transfer on 16 Oct by Finance.
- **SC-000002:** RM 80.00, paid by payroll on 2 Nov, and on the payslip as a refund.
- **Total claimed in October:** RM 125.50.

### Payroll totals, October regular run
| Person | Earnings | Deductions | Refunds | Employee | Employer | MTD | Net |
|---|---|---|---|---|---|---|---|
| Resident Doctor | 6,960.58 | 230.77 | 80.00 | 731.65 | 935.35 | 0.00 | 6,078.16 |
| Panel Officer | 3,000.00 | 0 | 0 | 350.75 | 477.65 | 0.00 (typed) | 2,649.25 |
| Marketing (hourly, 149 h 52 min × RM 15) | 2,248.00 | 0 | 0 | 267.25 | 371.63 | n/a | 1,980.75 |
| **Total** | 12,208.58 | 230.77 | 80.00 | 1,349.65 | 1,784.63 | 0.00 | **10,708.16** |

**Checks behind the table:**
- **Panel Officer:** EPF 330 / 390 (11% and 13% of 3,000), SOCSO 14.75 / 51.65, EIS 6.00 / 6.00, HRD 30.00.
- **Marketing:**
  - 8,992 min × 15 ÷ 60 = 2,248.00;
  - EPF 247.28 → 248 and 292.24 → 293;
  - SOCSO 14.75 / 51.65;
  - EIS 4.496 → 4.50;
  - HRD 22.48.
- **Statutory summary on the Exports tab:**
  - EPF RM 1,268.00 employee and RM 1,436.00 employer;
  - SOCSO RM 59.25 and RM 207.45;
  - EIS RM 22.40 and RM 22.40;
  - HRD RM 0.00 and RM 118.78;
  - MTD for 2 people at RM 0.00.
  - These are all equal to the sums of the table above.
- **Bank file:** 2 rows (RM 6,078.16 and RM 2,649.25). Marketing's RM 1,980.75 is named as "Not in the bank file".
- **Adjustment run:** Resident Doctor −86.54 (net below zero, a warning only) and Panel Officer +200.00, with no
  statutory lines (S3).

### Audit and privacy spot checks
- No payroll or payslip audit row held an amount (search of `hr.payroll.*` and `hr.payslips.*` metadata).
- Claim audit rows hold `amount_sen`, which HR-PLAN 13.3 rule 9 allows.
- No row in `hr_message_deliveries`.

## 7. Not covered and why

- **Browser check of the HR pages** (V2 and Classic): skipped. The worktree has no built frontend (`public/build` is
  missing), and building it would write files into the repository, which this run was told not to do.
- **Messages:** WhatsApp, SMS, email and OTP were left out of scope, as instructed. No message preferences were set,
  so the roster-ready, leave-decided and payslip-ready notices had nowhere to go.
- **Not tried in this run:**
  - public-holiday overtime (October has none);
  - leave evidence files (only the MC reference text was used);
  - carry-forward and expiry;
  - minimum staffing and roster warnings;
  - weekly-hours warnings;
  - location checks.
- **Payroll not tried:**
  - joiners and leavers in the month;
  - a second Director;
  - returning a sent run;
  - a lock refused because the locker approved a claim;
  - concurrency races (covered by the contention tests, not by this walk).
- **Statutory accuracy:** the tables and the MTD schedule are synthetic and typed from memory. The hand check proves
  KPOne's arithmetic against those tables, not that the tables match KWSP, PERKESO, HRD Corp or LHDN today.
- **Real users and data:** only demo accounts and synthetic records were used. Nothing was deployed.
