# Insights permissions: who may open Insights (design note)

Status: **revised 24 Nov 2026 after the owner's answers; INS-1 built (decoupling only), awaiting the ship-it.** First version proposed a revoke; the owner said no. Written by Staff Experience (Kavitha) on 24 Nov 2026 from the code on `main` (`40d00817a`),
for B.E.R.N.I.S.S and the owner. Owner words (24 Nov 2026): "Insights... betulkan backend permission, kekalkan penggantian tu dalam
insight canvas". Synthetic data only. This note authorises nothing; the usual path follows: Daniel's look if a screen changes, the
owner's ship-it, then the PR.

## 1. What is true today (facts, with the file to check)

- `insights.view.organisation` is given to **every** role by one loop in `PermissionCatalogue::roles()` (line ~548), then the TV
  account and the Locum are left out. So today these roles hold it: Director, Resident Doctor, CA, CA Supervisor, Panel Officer,
  Finance Officer, Administrator, Business Development, Marketing, HR Manager, Technical Admin, Branch Manager, Account Assistant,
  Store Admin and Medical Assistant. The older additive migration `2026_10_01_000100_sync_insights_permission_additively` and the
  ROLE-1 and MA-1a migrations each hard-code it into their role tables too.
- The Insights pages are **organisation-wide for every holder**: `ClinicInsightsController` and `ClinicInsightsReportService` /
  `ClinicInsightsService` read every active branch of the organisation and never look at the viewer's branch assignment. So today a CA
  or a Panel Officer can open sales, payments, in-clinic, inventory and patient aggregates for all branches.
- The overview (BIL-31) already has its **own** permission, `insights.overview.view.organisation`, granted only to Director and
  Finance Officer by `2026_11_24_100000_grant_director_finance_overview_permission`. But it also requires `insights.view.organisation`
  as a second condition in three places: the route `insights.overview` (`routes/web.php` ~802), `DirectorOverviewService::page`
  (line 41) and the Clinic management row "Director and Finance overview". (The Today page's `canOverview` prop reads only the overview permission.)
- The sales and payments **downloads** require `insights.view.organisation` as well as their own export permission, in the route
  middleware (`insights.daily-sales`, `daily-receipts`, `sales-summary`) and in `DailyReportExportService` (line 37) and
  `SalesSummaryExportService` (line 32). The report code itself (`ClinicInsightsReportService::report`) never checks
  `insights.view`. **The Branch Manager's Sales summary and Payments exports (owner "BM reports B", PR #1061) therefore only work
  while the Branch Manager still holds `insights.view.organisation`.**
- Other readers of `insights.view.organisation`: the Clinic management row "Insights", the top bar and sidebar item (`HandleInertiaRequests` line 100,
  `AppSidebar.vue`), the agent tools (`insights.today`, `insights.sales_summary`, `clinic.day_summary`, `money.day_summary`,
  `panel.claims_summary`, the three connector tools) as the requester permission, and the daily summary's "clinic" block. Of these,
  only the Director, CA Supervisor, Finance Officer and Administrator hold the daily summary (`ai_daily_summary.view.branch`) and only
  the Director holds Ask (`ai_chat.use.organisation`), so no role in section 2 loses a working agent feature.
- The quick questions (`SpaceAskService`) and the Me / My day pages do not read this permission.
- `KPOneReferenceSeeder` calls `syncPermissions` from the catalogue, so a fresh seed follows `PermissionCatalogue::roles()`.

## 2. The owner's decisions (24 Nov 2026, "Lulus semua")

- **I1: NO revoke.** Resident Doctor, CA, Medical Assistant, Branch Manager and HR Manager **keep** `insights.view.organisation`. So there is no permission grant or revoke and **no migration** in INS-1.
- **I2: yes.** Administrator, Panel Officer and Store Admin keep the old Insights pages until their own overview exists in the canvas.
- **I3: approved.** The downloads and the Director and Finance overview stand on their own permissions.

The target table the owner gave (Director full overview, Finance money overview, Account Assistant view only, Administrator totals only,
CA Supervisor all branches, Panel Officer own branch and Panel only, Store Admin central store and stock only, the three aggregate-Insights
roles, and no branch money overview for the Doctor, CA, MA, Branch Manager and HR Manager) describes **what each role sees**. Today every
Insights page shows every section for all branches to every holder, so that column is met by the role overviews of the canvas, one
narrow permission each (the pattern `insights.overview.view.organisation` already sets), not by this change. Those later slices (INS-2
onward) are listed in section 6.

## 3. What INS-1 changes (decoupling only)

1. The three download routes (`insights.daily-sales`, `daily-receipts`, `sales-summary`) no longer require `insights.view.organisation`; each needs only its export permission (`reports.sales.export.organisation` or `reports.payments.export.organisation`).
2. `DailyReportExportService` and `SalesSummaryExportService` no longer check `insights.view.organisation`; they check the export permission only. The own-branch rule for accounts that cannot switch branches (`BranchOnlyExportScope`) and the active-branch authority check stay.
3. The overview route `insights.overview`, `DirectorOverviewService::page` and the Clinic management row "Director and Finance overview" rely on `insights.overview.view.organisation` alone (the active-branch effective-assignment check in the service stays).
4. No new permission, no grant, no revoke, no migration, no route name, no screen.

Effect: nobody gains or loses anything today. Every role that could download or open the overview before still can (Director, Finance Officer, Administrator, Branch Manager). What changes is the dependency: removing the Insights pages from a role later no longer breaks its downloads, and the overview does not depend on the old pages.
`/insight`, `/insights/today` and `/insights/{section}` keep requiring `insights.view.organisation`.

## 4. Does the overview already use its own permission?

Yes: `insights.overview.view.organisation`, granted to Director and Finance Officer only by `2026_11_24_100000_grant_director_finance_overview_permission`. Before INS-1 it was one of two conditions (the other was `insights.view`); after INS-1 it is the only one.

## 5. Tests

- **No existing assertion needed to change.** The Insights, Reports, DirectorOverview, ClinicManagement, RoleOne, AgentToolGateway and Access suites all pass unchanged (124 tests); the frontend suite passes (2131).
- New `InsightsDecouplingTest` (4 tests): a Branch Manager whose role has lost `insights.view` still downloads Sales summary and both daily files for its own branch (and is still refused `branch=all` and `insights.today`); a CA, who holds `insights.view` but no export permission, is still refused the download; a Director without `insights.view` still opens the overview and sees its hub row (and is refused `insights.today`); an Account Assistant, who holds `insights.view` but not the overview permission, is still refused the overview.

## 6. Later slices (not in INS-1; each needs its own design look and ship-it)

| Slice | What |
|---|---|
| INS-2 | Panel overview: own branch, Panel figures only (new narrow permission) |
| INS-3 | Stock overview: central store, stock only, no sales money |
| INS-4 | Administrator totals only (no targets, claims, cash or cost) |
| INS-5 | Doctor's own target and sales (from the Sales targets work) |
| INS-6 | When the overviews are live: revoke `insights.view.organisation` from roles that no longer need the old pages. **That would be a non-additive migration; it needs the owner's own approval then, with a safe `down()`.** |

Narrowing the old pages by role name is not an option: the backend checks permissions, not role names.
