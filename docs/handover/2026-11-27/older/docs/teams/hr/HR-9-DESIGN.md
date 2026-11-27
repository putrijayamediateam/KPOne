# HR-9 design note: the HR dashboard

Status: BUILT and merged in PR #434 (HR-9); HR-9b (staff cost, section 3) built on `feature/hr-9b-staff-cost`. Original note: built on branch `feature/hr-9-dashboard` (the owner's own "ship it HR-9", 2026-11-10); the HR team (Liyana, Suresh) is still asked to check section 6 against HR's data, and any change they ask for is a follow-up edit. Written by the Agent platform team (Irfan) on the coordinator's request, 2026-11-10. Source: HR-PLAN section "HR-9", TEAM.md section 9 item 9.

## 1. What it is

One read-only page, `/hr/dashboard`, for the Director and the HR Manager. It adds up what HR-1 to HR-3 already store. It writes nothing, sends nothing and stores no new HR data. Aggregates only: counts and names of who is rostered or away today, never a reason, a salary or a score.

## 2. Sections

| Section | Shows | Built from |
|---|---|---|
| Today per branch | Who is rostered now and who has clocked in, "Enough" or "Short" against minimum staffing for the current session, a "No doctor" alert in words, and who is on leave today ("On leave" or "half day" only) | approved roster shifts (HR-1), minimum staffing (HR-1), clock sessions (HR-3a), approved leave (HR-2a) |
| This month | Per branch: days clocked, late arrivals, left early, missing clock-in or clock-out, approved overtime minutes, leave days taken (all types added together, never by type), roster shortfall days | clock sessions and the HR-3a flags, approved overtime (HR-3b), leave ledger (HR-2a), roster warnings (HR-1, HR-5b) |
| Month picker | The current month and the 11 before it, branch-local dates | no new data |

Left for later, each needing an owner decision first (HR-PLAN "TO CONFIRM"): turnover (does it count locums?), compliance counts, and a twice-daily "shift report" (the clinic's times; a section on this page, never a message).

## 3. Staff cost (HR-9b, BUILT)

Built on branch `feature/hr-9b-staff-cost` after the owner's own "ship it HR-9b" (2026-11-10). HR-7b is merged, so approved pay runs exist. The accountant's confirmation and the PDPA review are still owed before real use; the card says so and data stays synthetic.

- **One figure per month, clinic-wide.** "Staff cost" for the month in the picker = gross pay plus the employer's statutory contributions, summed over the totals of **approved or locked** pay runs of that month (regular runs and adjustment runs), with unpaid leave taken off the gross. A draft or a run waiting for approval never counts; the card says how many are waiting. "People paid" counts each person once.
- **Limits (found in review).** An adjustment run holds only the correction lines entered on it, so only earnings-type corrections move the figure; a recovery or refund correction and the employer's share of a correction do not. Whether to net other deductions is an accountant decision. An approved run whose stored total cannot be read is counted in an "unreadable" figure and the card says the total is incomplete, never RM 0. The card is labelled an estimate until the accountant has checked the pay rules.
- **By pay month.** An adjustment run carries the month it corrects, so approving one later changes that earlier month's figure; the card says "Includes corrections approved later". "People paid" counts only people paid through KPOne: locums and the Director's and HR Manager's own pay are outside KPOne (HR-7a option (a)). Confirmed against the payroll code by HR (Suresh).
- **No split.** Nothing per person, per branch or per pay group leaves the server (a test pins the key list and that no name appears). The Director's and HR Manager's own pay and locums stay outside KPOne (HR-7a option (a)), so they are not in it.
- **Who sees it.** Only a holder of `hr.payroll.approve.organisation` (the Director). The HR Manager sees the dashboard as before and the cost card is absent (the payload value is `null`). No new permission, route or migration.
- **How it is made.** `StaffCostService` reads the run and run-person rows in two queries, decrypts each person's stored totals only to add them up, and returns the sums. It writes nothing. The existing `hr.dashboard.viewed` audit row gains `staff_cost` in its `sections` list for the Director (no figure in the audit).
- **Not in HR-9b:** per-branch cost, cost per person, comparison with revenue, export. Each needs the owner and the accountant first.

## 4. Who sees it (decision for HR)

HR-PLAN names a new permission, `hr.dashboard.view.organisation`. The coordinator asked for existing permissions where possible. **Recommendation: reuse, no new permission and no migration.** The page opens for anyone holding at least one of the three below, and each section shows only to the holder of its own permission:

- Today and roster shortfalls: `hr.roster.manage.organisation`
- Attendance and overtime figures: `hr.attendance.review.organisation`
- Leave figures: `hr.leave.manage.organisation`

Director and HR Manager hold all three today, so the result is the same as the planned permission. Nobody else holds any of them (a test pins the exact holders). A CA Supervisor branch view stays out until the owner decides it. If HR prefers the new permission, it is one additive migration in the range the coordinator gives (2026_11_13_1xxxxx).

## 5. Rules it must keep

- Fresh permission check in the service on every request, not only middleware; `sensitive.no-store`; throttled.
- Branches: only active branches in the viewer's organisation. The Director and the HR Manager see all of them (the same rule as the roster planner).
- Names appear only for "rostered now", "clocked in" and "on leave today", and only to a viewer who may already see the roster or the leave calendar. Leave never shows its type or reason (as the team calendar).
- No score of a person from clinical throughput or sales (HR-PLAN). No ranking.
- Every figure is computed in branch-local dates with `CarbonImmutable` and the branch time zone (the clock lesson, BASELINE section 6 item 21). Tests freeze the clock at 16:30 UTC, which is 00:30 in Kuala Lumpur.
- Each page view that lists names writes one structural audit row (`hr.dashboard.viewed`: branch ids and month only, no names).
- HR-17 files are not touched.

## 6. How the numbers are made (so HR can check them)

- "Rostered now": shifts of approved roster versions only (a draft, a replaced version or a draft overlap never counts) whose local start is at or before now and whose end is after now, for active staff. A person whose approved leave covers now is shown as on leave and not as rostered (a half day: on leave for that half, rostered for the other half). A clinic-wide total is not built; figures are per branch.
- "Clocked in": an open clock session at that branch.
- "Enough" or "Short" (Liyana, HR): **for today, by the Planner's own rule** (HR: "matching the Planner matters more than a session refinement"). Each role's count on each session's shifts today (a full-day shift counts for morning and afternoon) is compared with that session's minimum through `RosterPageService::shortfalls`, which the dashboard now depends on (docblock says so), so a CA Supervisor never counts towards the CA minimum and the page cannot disagree with the Planner. Labelled "for today", not "now". States: Enough, Short, "No shifts today", "Minimum staffing not set", and nothing at all for a branch with no shifts that does not work today (HR-2a working days). The page says that minimums apply to every day (HR-1 limit).
- "No Resident Doctor rostered now" shows only when someone else is on shift now and no doctor is (a person on leave does not count as on shift).
- Overtime minutes: approved requests dated in the month, with a later reversal excluded, using the stored approved minutes. Short days: counted from approved rosters only, by today's minimums (an HR-5b limit, stated on the page).
- Month figures reuse the HR-3a flag rules and the HR-3b approved minutes, so this page cannot disagree with Review and Summary there. The flags stay hidden until HR has saved attendance settings, as on Review.
- Performance: one query per section per branch group, no per-person loop. Capped at 200 names on "Today".

## 7. Questions for HR (Liyana, Suresh)

1. Enough or Short: answered by Liyana, for today by the Planner's rule, as built.
2. Should "late" count only flags after a saved grace period (as Review does)? We assume yes.
3. Is leave days taken a single figure, or do you want sick versus annual in the monthly summary? We assume a single figure (no leave type on a dashboard).
4. Reuse of the three existing permissions, or the new `hr.dashboard.view.organisation`?
5. Turnover and compliance: leave for HR-9b?

## 8. Found while building

`AttendanceDays::leaveDays` and `::rows` merged an empty list of sessions with the day's shifts through an Eloquent collection and failed ("Call to a member function getKey() on string") on any day with a shift and nobody clocked in yet. This would also break Review. Fixed with `toBase()` in three places; `DashboardTest` has a regression test. No other HR file changed.

## 9. Build plan

Read-only domain service `app/Domain/HR/Dashboard/` (one service per section, no models, no migration), one controller and page, one Clinic management row ("KPOne HR dashboard", under Staff & branches, which adds one line to the exact destination map in `ClinicManagementTest`: needs approval). Tests: per-role section visibility, every number against a hand-built fixture, branch-local midnight, no write of any kind (table counts before and after), no person-level cost or reason in the payload, and a payload key list. Then the usual flow: Shalini reviews, Daniel looks at the screens, gate, ship-it, PR.
