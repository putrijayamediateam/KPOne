# BIL-31: Director and Finance overview

Owner decisions: item 32 (20-21 Nov 2026), "a Director and Finance overview on top of Insights: targets, claims,
items waiting for approval"; round 2 P8, "the Director and Finance overview (Director, Finance Officer)".

Design note for review (Kumar), revision 1. Classic only. Synthetic data only; nothing here is production approval.
It only reads: no money, claim, target or approval changes.

## 1. What it does

One page, **Overview**, for the Director and the Finance Officer, shown first in Insights. It answers four
questions on one screen and links to the page where each is handled:

1. **How are sales doing?** Net sales today and month to date, per branch and in total, with the same days of last
   month beside them.
2. **Are we on target?** This month's sales target and progress per branch (BIL-30). Doctor targets stay on the
   Sales targets page.
3. **What is owed to us by Panels?** Panel claim money not yet received: awaiting decision, accepted but not paid,
   and approved Panel shares not yet claimed, with the part older than 90 days shown on its own.
4. **What is waiting for a decision?** Counts of items waiting for approval, by kind and branch.

Every figure has a "View" link to the page that holds the detail: Insights Sales, Sales targets, Panel summary,
Panel Claims, Daily cash closing, Patient Billing refunds, the Approvals inbox.

## 2. The numbers (all reused, so they match the pages they link to)

- **Sales:** `InvoiceSalesProjection::netSales` (BIL-30, K1), per branch, for the branch-local day and for the 1st of
  the month to today. "Last month" is the same day numbers of the previous month (1st to today's day number, capped
  at that month's last day). The total row is the sum of the branches.
- **Targets:** `SalesTargetService::progress` for each branch's target of the current month: target, achieved,
  percent and needed per day. A branch without a target shows "No target" and a "Set target" link.
- **Panel claims:** the Panel summary's own figures (`PanelSummaryReportService`), per branch, for invoices finalised
  from the first finalised invoice to today: "Awaiting decision", "Accepted outstanding", "Unclaimed", and each one's
  "over 90 days" part. To keep one calculation, the service gains a small public method that returns these totals
  for a branch; the report and its CSV call it and do not change (their existing tests prove it).
- **Waiting for approval:** the Approvals inbox's own sources (`ApprovalSources`) for the kinds that are money or
  claims: daily cash closes waiting, Panel price changes, supplier invoices, purchase requests. They are counted for
  every branch of the organisation, not only the viewer's. Two money approvals are not in the inbox yet: refunds
  waiting for approval, and Panel or pay-later proposals waiting on a bill. The overview counts them directly from
  their tables with the same "waiting" rule their pages use, until the Billing approvals list (task 32) adds them to
  the inbox.
- **Counts and amounts only.** No patient name, number, invoice number, diagnosis or staff name appears on the page
  or in its props.

## 3. Who sees it (P8)

- **Recommended:** a new permission `insights.overview.view.organisation`, for `director` and `finance_officer`
  only, through one additive migration (grants only what is missing, skips a missing role, never revokes), mirrored
  in `PermissionCatalogue`. It is not people-or-access authority. Nothing goes to `ca`, so the `medical_assistant`
  rule does not apply.
- **Alternative (question 1):** reuse `sales_targets.manage.organisation`, which today is held by exactly these two
  roles, and add no permission. This is shorter, but it ties the overview to target setting.
- The page is organisation-wide: it shows every active branch, as the Sales targets page does, because Director and
  Finance Officer work across branches. It still checks the viewer's own active, assigned branch for the usual actor
  rule, as BIL-30 does.
- Other Insights users see no change. The "Overview" tab and the Clinic management link show only with the
  permission. The route returns 403 without it.

## 4. Screen (Daniel's look)

- **Insights, then Overview** (first tab, only for the two roles); also in Clinic management under Reports.
- Four sections, each a card with a heading and a "View" link:
  - **Sales:** a small table, one row per branch plus Total: Today, Month to date, Same days last month and the
    difference in RM (no red or green).
  - **Targets:** one row per branch: target, achieved and percent, a thin progress bar, needed per day.
  - **Panel claims owed:** one row per branch: Awaiting decision, Accepted not paid, Not yet claimed, and "Over 90
    days" muted under each.
  - **Waiting for approval:** a short list "Daily cash close, Cheras: 1", "Refunds, Puchong: 2"; nothing shown for a
    kind with none; "Nothing is waiting." when all are zero.
- The page loads its figures fresh each time. At 390 px the tables become cards.

## 5. Tests

- **Numbers match their source pages:** on one synthetic fixture (a consultation sale, an OTC sale, a refund credit,
  a confirmed Panel claim adjustment, a claim awaiting decision, an accepted unpaid claim, an unclaimed Panel share,
  a waiting refund, a waiting Panel proposal and a waiting cash close), each overview figure equals the Insights,
  Sales targets, Panel summary or Approvals inbox figure for the same branch and period.
- **Same days last month:** the 31st in a month after a 30-day month; 29 February; the 1st of the month.
- **Panel summary unchanged:** its existing tests stay green after the shared method is added.
- **Access:** Director and Finance Officer get 200; every other role gets 403 and sees no tab or link; the payload
  has no patient or staff field.
- **Migration:** additive and idempotent, skips a missing role.
- No contention test: the page writes nothing.
- **Changed existing assertions**, expected and to be listed line by line for the owner before any edit:
  `tests/Feature/ClinicManagementTest.php` (hub map gains the overview); possibly the Insights tab test if it pins
  the exact tab list; the role permission pins if question 1 is answered with the new permission.

## 6. Questions for the owner

1. **Permission:** A (recommended), a new `insights.overview.view.organisation` for Director and Finance Officer;
   or B, reuse the sales target permission.
2. **Branches:** A (recommended), every active branch; or B, only the branches the viewer is assigned to.
3. **Waiting for approval:** A (recommended), everything waiting in the organisation, by branch; or B, only what the
   viewer can approve themselves (the Approvals inbox already shows this).
4. **Cash close status:** include a line per branch for yesterday's close (Approved and locked, Waiting, Not
   counted)? A, yes (recommended, it is the most common thing a Finance Officer checks each morning); B, no.
