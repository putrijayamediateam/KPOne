# BIL-30: monthly sales targets per branch and per doctor

Owner decisions: item 13 (20-21 Nov 2026), and round 2 of 21 Nov 2026:
- P6: Director and Finance Officer set targets;
- P7: a Resident Doctor sees their own target;
- 18 = A: monthly targets, and "needed per day" uses the calendar days left.

BIL-30 answers (21 Nov 2026):
- 1A: past months are fully locked;
- 2: yes, the doctor sees a card on the consultation queue page, and on "My day" later (Staff Experience, item 31);
- 3: yes, Panel sales count.

Design note for review (Kumar). Revision 2, after Kumar's first read ("approve with changes", nine findings, K1 to K9
mark where). Classic only. Synthetic data only; nothing here is production approval.

## 1. What it does

- **Set (Director, Finance Officer).** For each month (branch-local calendar month), the person sets:
  - a sales target for each branch;
  - optionally, a target for each doctor at that branch.
- **See:**
  - **Director and Finance Officer:** a Sales targets page. For each branch, then each doctor: target, achieved so far,
    balance, needed per day, and the share achieved as a percentage.
  - **Resident Doctor:** "My sales target" for the current month, showing only their own line at their active branch.
    No other doctor's figures, no branch figure, no patient detail.
- No money changes. Targets are read against existing sales; nothing is charged, moved or recalculated.

## 2. The numbers (the same code as Insights, so they always agree)

- **K1, one shared calculation.** Today the Insights headline builds its month window, branch scope and offset
  periods in private methods (`ClinicInsightsReportService::scopePeriod` and the headline total,
  `ClinicInsightsService`). BIL-30 moves this into one public method, `InvoiceSalesProjection::netSales(organisation,
  branch, startUtc, endUtc, ?doctor)`, and makes the Insights headline and Provider rows call it. Targets call the same
  method, so the two cannot drift. This is an internal move only: Insights' figures, screens and access stay exactly as
  they are (coordinator: "don't change Insights in BIL-30"), and the existing Insights tests, unchanged, prove it.
- **Month boundaries:** local midnight on the 1st of the month, in the branch timezone, converted to UTC; the end is
  local midnight on the 1st of the next month.
- **Achieved (branch)** = net invoice sales for the month at that branch:
  - the payable total of invoices finalised in the month;
  - minus refund credits approved in the month;
  - plus Panel claim adjustments confirmed in the month.

  Each item counts on its own day, so a past day never changes. Panel sales count (answer 3).
- **K2, Achieved (doctor)** = the same three parts restricted to that doctor's invoices: their invoices finalised in
  the month, minus credits approved in the month on their invoices, plus Panel adjustments confirmed in the month on
  their invoices. This is what the Insights Provider rows show (after `withCredits()`). The doctor is
  `consultation_checkouts.attending_doctor_user_id` (never null); every invoice has exactly one anchor, so the only
  sales with no doctor are OTC (medicine-only) sales.
- **Doctor lines can add up to less than the branch line**, for two reasons: OTC sales, and doctors with no target.
  The page says so in one line. **K9:** doctor targets may also add up to more or less than the branch target; they
  are separate goals, and the page does not check one against the other.
- **Balance** = target − achieved. When achieved passes the target it reads "Target reached" with the amount over,
  never a negative balance.
- **Needed per day** = balance ÷ calendar days left in the month, counting today (owner 18 = A), rounded up to the
  sen. On the last day it is the whole balance. After the month ends it is not shown. **K9:** for a future month,
  achieved is RM 0.00 and the days left are all the days of that month.
- **Figures** are computed fresh when the page loads, in the branch timezone.

## 3. Data (one additive migration, plus one permission migration)

- **`sales_targets`**: one row per (organisation, branch, month, doctor or none). Columns:
  - `organisation_id`, `branch_id`, `month` (first day, date), `doctor_user_id` (null for the branch target);
  - `amount_sen` (null once cleared), `cleared_at` (K3: a clear is soft, so the row and its `lock_version` stay);
  - `lock_version`; created and updated user and time.

  PostgreSQL:
  - a unique key on (organisation, branch, month, coalesce(doctor, 0));
  - `CHECK (month = date_trunc('month', month))` (K3);
  - `CHECK ((cleared_at IS NULL AND amount_sen BETWEEN 1 AND <ExactMoney ceiling>) OR (cleared_at IS NOT NULL AND
    amount_sen IS NULL))`;
  - composite foreign keys (branch_id, organisation_id) → branches and (doctor_user_id, organisation_id) → users, as
    `checkout_doctor_owner_fk` does (K3);
  - **K5, past months locked:** a trigger refuses insert, update or clear when `month < date_trunc('month', now() AT
    TIME ZONE branches.timezone)`, computed inside the transaction. The service checks the same first, with a plain
    message.
- **`sales_target_revisions`**: append-only, one row per set, change or clear. It carries its own organisation,
  branch, month and doctor (K3), the target id, who and when, the old and new amount, a reason up to 200 characters
  for a change or clear, and `idempotency_key` with `payload_hash`, unique per organisation (K6). UPDATE, DELETE and
  TRUNCATE are refused by a trigger, as in other append-only Billing evidence.
- No existing table changes.

## 4. Rules (domain service `SalesTargetService`)

- **Who sets:** holders of `sales_targets.manage.organisation`, at any branch of their organisation, after the usual
  actor lock (`TransactionalActorAuthority`).
- **What can be set:** only the current or a future month (answer 1A). A past month is read only, in the service and
  in PostgreSQL.
- **K4, which doctors:** an active user with the `resident_doctor` role whose branch assignment at that branch overlaps
  the target month (not just "today", so a future month works). This reuses the logic of
  `VisitDoctorEligibilityService::lockAndValidate`.
- **K6, protection:**
  - a transaction; the branch row is locked `FOR UPDATE` first, so two first sets of the same target cannot race into
    the unique key;
  - then the target row, with an optimistic `lock_version`;
  - an idempotency key: the same key with the same payload returns the first result; with a different payload it is
    refused;
  - an audit record (`sales_target.set`, `sales_target.changed` or `sales_target.cleared`) with the branch, month,
    whether it is a doctor target, and the old and new amounts; never a patient.
- **Changes:** a target can change any time within its month. Every change is a revision.

## 5. Permissions (owner approved P6 and P7, round 2)

- **`sales_targets.manage.organisation`:** Director, Finance Officer. Sets targets and sees every branch's and
  doctor's targets and progress.
- **`sales_targets.view.own`:** Resident Doctor. Sees only their own line.
- No other role gets either. Neither is in the people-and-access authority set. Both come through one additive
  permission migration (grants only what is missing, skips a missing role with a log line, never revokes), mirrored in
  `PermissionCatalogue`.
- **K7, privacy:**
  - the doctor's id comes only from the signed-in user, never from the request;
  - the doctor card's payload holds only that doctor's line; no branch total or other doctor's figure is sent in its
    page props or any shared props;
  - **owner answer (22-23 Nov 2026):** "in insight everyone can see also if the have insight access". Insights stays as
    it is: anyone with Insights access sees per-doctor sales there. P7 applies to targets only: each doctor sees only
    their own target. BIL-30 changes nothing in what Insights shows or who sees it.
- The Director and Finance overview (BIL-31) reads targets through the manage permission.

## 6. Screens (Daniel's look)

- **Clinic management, then Sales targets (Director, Finance Officer):**
  - a month picker (current month by default);
  - a branch table: target, achieved, balance, needed per day, % reached;
  - expand a branch for its doctors;
  - "Set target" opens a small dialog with an amount in RM, and a reason when changing or clearing;
  - past months show read only;
  - at 390 px, the table becomes cards.
- **Resident Doctor:** a small "My sales target" card on the consultation queue page (answer 2), with the month,
  target, achieved, balance, needed per day and a progress bar, and no other names. If no target is set, the card does
  not show. "My day" adds the same card when that page exists.

## 7. Tests

- **Service:** who can set and who is refused; the past-month refusal (service and direct SQL); a doctor without an
  overlapping assignment refused; idempotency replay and changed-payload refusal; a stale `lock_version`; clear keeps
  the row and its version; revisions append-only (UPDATE, DELETE refused in PostgreSQL); audit content.
- **Numbers:**
  - achieved equals the Insights Sales net figure for the same branch and month, on a fixture with an adjustment, a
    refund credit after completion and a confirmed Panel claim adjustment;
  - the doctor line equals that doctor's Provider row; the doctor lines exclude OTC;
  - needed per day for the first, a middle and the last day, after the target is reached, and for a future month;
  - **time zone:** a target set at 23:59 local on the last day of the month, then at 00:01 the next day (the first is
    accepted, the second refused as a past month).
- **Insights unchanged:** the existing Insights tests stay green after the shared calculation moves.
- **Privacy:** a doctor sees only their own line and cannot ask for another doctor's; a CA, Administrator, Panel
  Officer, Branch Manager, Account Assistant, Store Admin and Locum get 403 (K7); no patient field appears in any
  target payload.
- **Migration:** the permission migration is additive and idempotent, and skips a missing role.
- **Contention test** (task-owned PostgreSQL, `Contention` suite): two simultaneous first sets of the same target
  leave one row and one refusal or two consecutive revisions; never a unique-key error.
- **K8, changed existing assertions**, listed line by line for the owner before any edit and left failing until
  approved. Files expected:
  - `tests/Frontend/fixtures/access-control-roles.json` (exact lists and counts for director, finance_officer and
    resident_doctor);
  - `tests/Feature/ClinicManagementTest.php` (exact hub map);
  - the permission catalogue pins;
  - `tests/Feature/RoleOneRolesTest.php` should not change and must stay green;
  - `SpaceQuestionsRegistryTest` and `DepartmentSpaceMatrixTest` only if a space link is added (none planned).

## 8. Owner answers

1. Past months: fully locked (1A).
2. Doctor card: the consultation queue page, and "My day" later.
3. Panel sales count.
4. Doctor targets are independent of the branch target (accepted, coordinator for the owner, 22 Nov 2026).
5. Insights stays as it is; P7 covers targets only (owner, 22-23 Nov 2026).
