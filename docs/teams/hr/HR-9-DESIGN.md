# HR-9 design note: the HR dashboard

Status: BUILT on branch `feature/hr-9-dashboard` (the owner's own "ship it HR-9", 2026-11-10); the HR team (Liyana, Suresh) is still asked to check section 6 against HR's data, and any change they ask for is a follow-up edit. Written by the Agent platform team (Irfan) on the coordinator's request, 2026-11-10. Source: HR-PLAN section "HR-9", TEAM.md section 9 item 9.

## 1. What it is

One read-only page, `/hr/dashboard`, for the Director and the HR Manager. It adds up what HR-1 to HR-3 already store. It writes nothing, sends nothing and stores no new HR data. Aggregates only: counts and names of who is rostered or away today, never a reason, a salary or a score.

## 2. Sections

| Section | Shows | Built from |
|---|---|---|
| Today per branch | Who is rostered now and who has clocked in, "Enough" or "Short" against minimum staffing for the current session, a "No doctor" alert in words, and who is on leave today ("On leave" or "half day" only) | approved roster shifts (HR-1), minimum staffing (HR-1), clock sessions (HR-3a), approved leave (HR-2a) |
| This month | Per branch and for the clinic: days clocked, late arrivals, left early, missing clock-in or clock-out, approved overtime minutes, leave days taken (all types added together, never by type), roster shortfall days | clock sessions and the HR-3a flags, approved overtime (HR-3b), leave ledger (HR-2a), roster warnings (HR-1, HR-5b) |
| Month picker | The current month and the 11 before it, branch-local dates | no new data |

Left for later, each needing an owner decision first (HR-PLAN "TO CONFIRM"): turnover (does it count locums?), compliance counts, and a twice-daily "shift report" (the clinic's times; a section on this page, never a message).

## 3. Staff cost

**Not in v1.** HR-7 is merged, but cost needs the accountant's confirmation, and the Director's and HR Manager's own pay stays outside KPOne (BASELINE section 6, item 30). Proposal for HR-9b once the owner agrees: one clinic-wide monthly total from approved payroll runs, no per-person and no per-branch split, shown only to `hr.payroll.approve.organisation` (Director).

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

- "Rostered now": shifts of the current approved roster version whose local start is at or before now and whose end is after now. A person on approved leave for the day is shown as on leave, not rostered.
- "Clocked in": an open clock session today at that branch.
- "Enough" or "Short" uses **the Planner's own rule for today** (`RosterPageService::shortfalls`, made public, so this page cannot disagree with the Planner's warning): a role below the branch's minimum in a session that has shifts today. It does not wait for the session's hours. It says "No shifts today" when nobody is rostered, and "Minimum staffing not set" when the branch has no minimum. "No Resident Doctor rostered now" shows only when someone else is rostered now and no doctor is (a closed or quiet branch is not alarmed). Both are words in a badge, never colour alone.
- Month figures reuse the HR-3a flag rules and the HR-3b approved minutes, so this page cannot disagree with Review and Summary there. The flags stay hidden until HR has saved attendance settings, as on Review.
- Performance: one query per section per branch group, no per-person loop. Capped at 200 names on "Today".

## 7. Questions for HR (Liyana, Suresh)

1. "Enough or Short" now uses the Planner's rule for the whole day, as built. Do you want it to follow the current session instead?
2. Should "late" count only flags after a saved grace period (as Review does)? We assume yes.
3. Is leave days taken a single figure, or do you want sick versus annual in the monthly summary? We assume a single figure (no leave type on a dashboard).
4. Reuse of the three existing permissions, or the new `hr.dashboard.view.organisation`?
5. Turnover and compliance: leave for HR-9b?

## 8. Found while building

`AttendanceDays::leaveDays` and `::rows` merged an empty list of sessions with the day's shifts through an Eloquent collection and failed ("Call to a member function getKey() on string") on any day with a shift and nobody clocked in yet. This would also break Review. Fixed with `toBase()` in three places; `DashboardTest` has a regression test. No other HR file changed.

## 9. Build plan

Read-only domain service `app/Domain/HR/Dashboard/` (one service per section, no models, no migration), one controller and page, one Clinic management row ("KPOne HR dashboard", under Staff & branches, which adds one line to the exact destination map in `ClinicManagementTest`: needs approval). Tests: per-role section visibility, every number against a hand-built fixture, branch-local midnight, no write of any kind (table counts before and after), no person-level cost or reason in the payload, and a payload key list. Then the usual flow: Shalini reviews, Daniel looks at the screens, gate, ship-it, PR.
