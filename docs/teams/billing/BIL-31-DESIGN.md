# BIL-31: Director and Finance overview

Owner decisions:
- item 32 (`docs/decisions/2026-11-20-finance-inventory-proposals.md`, lines 71-72): "a Director and Finance overview
  on top of Insights: targets, claims, items waiting for approval";
- P8 in the owner's round-2 answers of 21 Nov 2026 (relayed to the Billing session by B.E.R.N.I.S.S, recorded in
  `TEAM.md`): "the Director and Finance overview (Director, Finance Officer)".

Design note for review (Kumar), revision 2, after Kumar's first read ("approve with changes", twelve findings; F1 to
F12 mark where). Classic only. Synthetic data only; nothing here is production approval. It only reads: no money,
claim, target or approval changes.

## 1. What it does

One page, **Overview**, for the Director and the Finance Officer, as a tab in Insights. It answers on one screen:

1. **Are we on target?** This month's net sales and target progress per branch (BIL-30).
2. **What do Panels still owe us?** Panel claim money not yet received, with the part older than 90 days.
3. **What is waiting for a decision?** Counts of money and claim items waiting, by kind and branch.
4. **How is this month going against last month?** Month to date against the same days of last month. This goes
   beyond the literal item 32, so it is question 5 for the owner (F12); it costs one extra query per branch.

Counts and amounts only: no patient name or number, invoice number, diagnosis or staff name in the page or its
props.

## 2. The numbers

### 2.1 Sales and targets (F6, F7)

- **One sum per branch:** `InvoiceSalesProjection::netSales` (BIL-30, K1) for the branch-local month so far, from
  local midnight on the 1st to the current time. The same value feeds "month to date" and the target's "achieved",
  so there is no second query (F7). The total row adds the branches, each on its own clock.
- **Last month:** branch-local whole days from the 1st of last month to day `min(today's day number, days in last
  month)`, inclusive, so the 31st is dropped on the last day of a 30-day month; the page says "1–30 Nov" so this is
  visible. Because today is not over, the comparison column compares **month to date up to the end of yesterday**
  with last month's 1st to yesterday's day number, and today's figure is shown on its own, labelled "today so far"
  (F6). On the 1st there is no comparison ("No full day yet this month").
- **Difference column:** month to date up to yesterday minus last month's same days, in RM, with no red or green.
- **Targets:** the current month's branch target row (`sales_targets`, `doctor_user_id` null). A cleared target
  (`amount_sen` null) or no row reads "No target". Progress comes from the public
  `SalesTargetService::progress`. The overview reads targets itself, because `SalesTargetService::page` requires the
  manage permission (F7). The "Set target" link shows only to holders of `sales_targets.manage.organisation`
  (today the same two roles).

### 2.2 Panel claims owed (F1, F2)

- **Figures:** awaiting decision, accepted outstanding and unclaimed, each with its over-90-day part. These are
  values 19, 9, 5 and 23, 13, 17 of the Panel summary row.
- **One calculation:** `PanelSummaryReportService` gains a public method, roughly
  `totals(Branch $branch, ?CarbonImmutable $from, CarbonImmutable $asOf)`, that adds up per allocation and returns those six
  numbers. `project()` calls it for its own window, so the report, its CSV and their tests do not change.
- **Window:** all finalised Panel invoices up to today (money owed has no start date). The Panel summary page is
  limited to 31 dates, so its figure cannot equal the overview's. Instead:
  - one test checks a 31-day window against the report page;
  - the overview's "View" link goes to Panel Claims follow-up ("open claims with no action"). The section says
    "All Panel bills to date"; the Panel summary link carries the note "covers at most 31 days".
- **Cost:** `project()` reads every approved allocation; over all history this is the known heavy path (BASELINE
  section 6 item 9). The new method reads only allocations that still owe money (unclaimed, or claimed and not
  fully remitted or rejected), filtered in SQL before the per-claim step. A test bounds its queries.
- **Scope:** Panel summary is branch-scoped (`reports.panel_summary.view.branch`, active branch only). The
  overview shows these totals for every active branch (question 2). This relaxes the branch-assignment rule **for
  totals only**, which the owner is asked to confirm. Insights already shows Panel amounts across all branches to
  every Insights user, so this adds no patient or claim detail.
- **Audit:** the Panel summary audits each view because it releases a report with per-Panel lines. The overview
  shows only branch totals, as Insights does, and Insights does not audit views. So the overview does not audit,
  unless the owner chooses otherwise (question 7).

### 2.3 Waiting for a decision (F3, F4, F5)

The Approvals inbox sources are viewer-dependent: each `waitingFor()` leaves out the viewer's own items, and
`visibleTo()` needs the decide permission and an assignment at the branch. So the overview cannot reuse them for an
organisation-wide count. Each kind below has a **viewer-independent waiting rule** in one small read class,
`OverviewWaitingCounts`. Each row is counted per branch, except where marked as one line for the organisation.

| Kind | Waiting rule | Decide permission (Director, Finance Officer) | Link |
|---|---|---|---|
| Daily cash close | latest revision of a branch-day has no decision row | `payments.cash_closing.approve.branch` | Daily cash closing |
| Refund | `payment_refunds` row with no `payment_refund_decisions` row, on a finalised invoice of the same organisation and branch | `payments.refunds.approve.branch` | Billing space, Financial work (no list page exists; no visit link) |
| Panel or pay-later share | `coverage_allocations.status = 'proposed'`, `current_invoice_guard = invoice_id`, invoice finalised and not void, visit registered, `expected_invoice_version = invoices.lock_version` (stale or superseded proposals are not counted) | `coverage.approve.branch`, `outstanding.approve.branch` | Billing space, Financial work |
| Panel claim adjustment | `panel_claim_adjustments.status = 'requested'` | `panel.claims.adjustments.confirm.branch` | Panel Claims |
| Supplier invoice | the supplier invoice source's waiting predicate without the maker exclusion | `inventory.supplier_invoices.approve.branch` | Supplier invoices |
| Purchase order | `ProcurementService` status `submitted` | `inventory.purchase_orders.approve.organisation` | Purchase orders (one line for the organisation) |
| Panel price change | the price change source's waiting predicate without the maker exclusion | `panel.prices.approve.organisation` | Panel price changes (one line for the organisation; not branch-scoped) |

- **Left out:** purchase requests (a CA Supervisor or Store Admin task to turn a request into a draft order, not a
  money approval; the Director does not hold its permission). Staff claims and other HR approvals stay with HR
  (question 6).
- **Counts differ from the inbox:** the overview counts everything waiting, including items the viewer recorded
  themselves; the inbox shows only what this person may decide. The page says so in one line.
- **Inbox later:** refunds and proposals are not inbox sources yet. Adding them is the Billing team's separate
  "approvals waiting list" slice (task 32 in the Billing task list, not owner item 32). That slice can reuse these
  rules.

### 2.4 Daily cash close (question 4, F8)

- If the owner says yes: one line per branch for yesterday's close, from a new read method
  `CashClosingService::status(Branch, date)`. Today's `page()` and `month()` use the active branch only. The method
  returns the existing statuses: approved and locked, waiting, returned for recount, not counted, or before the start
  date. "Open" (today) is not shown.
- There is no "branch closed that day" concept apart from the start date, so a branch closed on a public holiday
  shows "Not counted". The line is information, not an alarm, and has no colour.
- Since BIL-32 the Branch Manager approves closes; the Finance Officer can approve when the Branch Manager is away.

## 3. Who sees it (P8, F2)

- **Permission:** a new `insights.overview.view.organisation` for `director` and `finance_officer` only, through one
  additive migration (grants only what is missing, skips a missing role, never revokes), mirrored in
  `PermissionCatalogue`. It is not people-or-access authority. Nothing goes to `ca`, so the `medical_assistant`
  rule does not apply. (Question 1; Kumar agrees.)
- **Actor rule:** as BIL-30. The viewer must be active, with an active and effectively assigned branch, and hold the
  permission. Then the page lists every active branch of the organisation. Inactive branches are left out.
- **What widens:**
  - Sales across branches already reach every Insights user, so nothing new there.
  - Panel claim totals and waiting counts lose their branch-assignment rule for the two roles, at totals level only
    (question 2).
- **Route:** its own `GET insights/overview` with `insights.view.organisation` and the new permission, registered
  before `insights/{section}` (F9).
- **Tabs:** the Insights tab lists in `Today.vue` and `Report.vue` are hard-coded. A shared prop `canOverview` adds
  the tab, keeping `useScrollStrip(reportTabs` and the `aria-current` markup that `strip-fix.test.mjs` pins. Today
  stays the landing tab unless the owner says otherwise (question 8). Clinic management lists "Director and Finance
  overview" under Reports for the same permission.

## 4. Screen (Daniel's look)

- **Insights, then Overview.** Four cards, each with a heading and "View" links:
  - **Targets this month:** per branch, target, achieved and percent, a thin bar and needed per day; "No target" with
    a "Set target" link when allowed.
  - **Panel money owed:** per branch, Awaiting decision, Accepted not paid and Not yet claimed, each with "over 90
    days" muted under it; "All Panel bills to date".
  - **Waiting for a decision:** a short list such as "Daily cash close · Cheras · 1" and "Refunds · Puchong · 2";
    kinds with none are not shown; when nothing is waiting, "Nothing is waiting."
  - **Month so far** (if question 5 is yes): per branch and total, "Today so far", "Month to yesterday", "Same days
    last month (1–23 Oct)", and the difference.
- No red or green anywhere. At 390 px the tables become cards.

## 5. Tests (F10)

- **Numbers:**
  - Fixture: a consultation sale, an OTC sale, a refund credit, a confirmed Panel claim adjustment, a claim awaiting
    decision, an accepted unpaid claim and an unclaimed Panel share.
  - Branch sales and targets equal the Insights Sales figure and the Sales targets page for the same branch and
    month.
  - Panel totals equal `PanelSummaryReportService::totals`, and for a 31-day window equal the report page.
  - Waiting counts follow each rule in 2.3:
    - approved and rejected refunds are not counted;
    - stale, superseded and completed-visit proposals are not counted;
    - the viewer's own items are counted;
    - Panel price changes and purchase orders are one organisation line.
- **Days (frozen clock):** 00:30 and 23:30 Kuala Lumpur time, the 1st of the month, the 31st after a 30-day month,
  and 29 February. Today is "so far"; the comparison stops at yesterday.
- **Unchanged reports:** the Panel summary page, its CSV and their existing tests stay green after the shared
  method.
- **Query bound:** the Panel totals method stays within a fixed number of queries whatever the number of settled
  allocations.
- **Access:**
  - Director and Finance Officer get 200.
  - Every other role gets 403 and sees no tab or link.
  - A viewer with no active, assigned branch is refused.
  - An inactive branch is left out.
  - The payload has no patient or staff field.
- **Migration:** additive and idempotent, skips a missing role.
- **Frontend:** the tab and the hub link show only with the prop; the strip markup is unchanged.
- No contention test: the page writes nothing.
- **Changed existing assertions** (listed line by line for the owner before any edit):
  - `tests/Feature/ClinicManagementTest.php`: the hub map gains the overview path.
  - Any test pinning the exact Director or Finance Officer permission lists, to be found when building.

## 6. Questions for the owner (F11)

1. **Permission:** A (recommended), a new overview permission for Director and Finance Officer; B, reuse the sales
   target permission.
2. **Branches:** sales already show every branch in Insights. Should Panel money owed and waiting counts also show
   every active branch, at totals level, for these two roles, even branches they are not assigned to? A, yes
   (recommended); B, only branches they are assigned to.
3. **Waiting counts:** A (recommended), everything waiting, including items the viewer recorded themselves; B, only
   what the viewer may decide (the same as the Approvals inbox).
4. **Daily cash close line:** show yesterday's close per branch (approved and locked, waiting, returned for recount,
   not counted, before the start date; a branch closed for a holiday shows "Not counted")? A, yes (recommended); B,
   no.
5. **Month so far:** add month to date against the same days last month (beyond item 32's wording)? A, yes
   (recommended); B, no.
6. **Kinds counted as waiting:** A (recommended), the seven in 2.3 (cash close, refund, Panel or pay-later share,
   Panel claim adjustment, supplier invoice, purchase order, Panel price change); B, also staff claims from HR.
7. **Audit:** A (recommended), no audit of views, as Insights; B, audit each view, as the Panel summary.
8. **Landing tab:** A (recommended), Insights still opens on Today and Overview is a tab; B, Overview opens first for
   these two roles.

## 7. Owner answers (24 Nov 2026, in the Billing session)

"1A 2A 3A 4A 5A 6A 7A 8A":
1. A new `insights.overview.view.organisation` for Director and Finance Officer.
2. Panel money owed and waiting counts for every active branch, at totals level.
3. Everything waiting is counted, including the viewer's own items.
4. Yesterday's daily close line per branch is shown.
5. Month so far against the same days last month is shown.
6. The seven money and claim kinds in 2.3.
7. Views are not audited.
8. Insights still opens on Today; Overview is a tab.
