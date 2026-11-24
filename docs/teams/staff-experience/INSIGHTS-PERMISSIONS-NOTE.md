# Insights permissions: who may open Insights (design note)

Status: **proposal, nothing built.** Written by Staff Experience (Kavitha) on 24 Nov 2026 from the code on `main` (`40d00817a`),
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

## 2. The target, and which part is "permission" and which part is "canvas"

| Role | Owner's target | Backend meaning |
|---|---|---|
| Director | Full overview, all branches | keeps `insights.view` and the overview permission |
| Finance Officer | Money overview, all branches | keeps both |
| Account Assistant | All branches, view only | keeps `insights.view`; has no export permission, so view only already |
| Administrator | All branches, totals only (no targets, claims, cash or cost) | keeps `insights.view`; **the narrowing is a canvas rule** (section 6) |
| CA Supervisor | All branches ("semua") | keeps `insights.view` |
| Panel Officer | Own branch, Panel figures only | keeps `insights.view` for now; **the narrowing is a canvas rule** |
| Store Admin | Central store, stock only, no sales money | keeps `insights.view` for now; **canvas rule** |
| Technical Admin, Marketing, Business Development | Keep aggregate Insights | keep `insights.view` |
| Resident Doctor, CA, Medical Assistant, Branch Manager, HR Manager (Locum, TV never had it) | No branch money overview | **lose `insights.view.organisation`** |

So the smallest backend change that matches the "who may open Insights at all" column is: **revoke one permission from five roles,
and stop two other features from depending on it.** The "what each role sees" column cannot be met by a permission revoke, because
the aggregate pages show every section to every holder; it needs the role overviews of the canvas (section 6).

## 3. Proposed change (step 1, the smallest)

1. **Catalogue.** In `PermissionCatalogue::roles()` stop giving `insights.view.organisation` to `resident_doctor`, `ca`,
   `medical_assistant`, `branch_manager` and `hr_manager` (keep it for the other ten human roles). One loop edit, so seeding stays in step.
2. **Migration (new, non-additive; section 5).** Revoke it from those five roles on existing databases.
3. **Decouple the downloads.** Drop the `insights.view.organisation` condition from the three download routes and from
   `DailyReportExportService` and `SalesSummaryExportService`; the export permission (`reports.sales.export.organisation` or
   `reports.payments.export.organisation`) becomes the only gate, as it already is for Daily summary, Stock movement and Panel
   summary. This keeps the Branch Manager's approved downloads working and changes nothing for Director, Finance Officer or
   Administrator (they hold both today). The Branch Manager's own-branch rule (`BranchOnlyExportScope`) stays.
4. **Decouple the overview.** Drop the second condition `insights.view.organisation` from the `insights.overview` route,
   `DirectorOverviewService::page` and the Clinic management row, so the overview stands on `insights.overview.view.organisation` alone
   (the active-branch authority check in the service stays). Director and Finance Officer hold both, so nothing changes for them today.
5. No new permission, no new screen, no route change.

## 4. How pages and links behave afterwards

- The five roles lose: the "Insights" item in the top bar and sidebar, the "Insights" row in Clinic management, and the `/insight` and
  `/insights/*` pages (403). Nothing else in their work changes. The Branch Manager keeps Reports (Daily summary, Stock movement, Panel
  summary) and the Sales summary and Payments downloads; the HR Manager keeps the HR dashboard.
- Director, Finance Officer and the other keepers see no change in step 1.
- The Me page, My day and the quick questions are unaffected (they do not read this permission).
- The agent tools and the daily summary's clinic block are unaffected for the roles that can use them (section 1).
- A person with a direct, per-user grant of the permission keeps it (the migration changes role grants only).

## 5. The migration (it revokes, so it is not additive)

`2026_11_25_xxxxxx_revoke_insights_view_from_five_roles.php`. **Every earlier permission migration in this area is additive
(`firstOrCreate`, give what is missing, never revoke). This one removes a grant**, which is why it needs the owner's explicit approval
as its own line, and why it ships in the same PR as the catalogue edit.
- `up()`: for each of `resident_doctor`, `ca`, `medical_assistant`, `branch_manager`, `hr_manager`: if the role exists and holds the permission, revoke it; a missing role is logged and skipped; the permission row itself is kept (ten other roles hold it); the
  permission cache is cleared. Idempotent. It changes **role** grants only, never a user's direct grant.
- `down()` (safe): give the same permission back to the same five roles, only where it is missing (skipping a missing role, never touching any other role), then clear the cache. This restores the state before the migration, because all five held it by default.
  One honest limit: if an administrator had deliberately revoked it from one of those roles before this migration, `down()` would give it back; nothing records that, and it is unlikely.
- Ordering: the migration must be dated after the ROLE-1 and MA-1a migrations (they hard-code the permission into their tables), so a fresh `migrate` ends in the right state.
- Deploy: `php artisan migrate`; people lose the Insights link at their next page load (the permission cache is cleared).

## 6. What step 1 deliberately does not do (needs the canvas, then a second note)

The rows "Administrator totals only", "Panel Officer own branch, Panel only", "Store Admin stock only", "Account Assistant view only"
and "a doctor sees their own target and sales" describe **what is shown**, and the current aggregate pages show everything to every
holder, all branches. Those roles keep today's access after step 1 (no regression, and no new exposure), until the role overviews
exist. The replacement the owner wants kept in the canvas is a set of new pages, each of which needs its own narrow permission, in
the pattern the Director and Finance overview already uses (`insights.overview.view.organisation`), for example one for the Panel
overview (own branch), one for the stock overview, one for the Administrator's totals, and one for the doctor's own figures (the
Sales targets work, `sales_targets.*`, is the likely source). I recommend: after step 1, build the role overviews one at a time, and
when the last one is live, revoke `insights.view.organisation` from the roles that no longer need the old aggregate pages (a later,
separate non-additive migration). Doing the narrowing inside the old pages by role name would break the rule that the backend checks
permissions, not role names.

## 7. Existing assertions that would change (for the owner first)

Listed from a search of the tests on `main`; the full suite will confirm at build.
1. `tests/Feature/Insights/TodayInsightsTest::test_all_staff_roles_can_view_the_organisation_insights_page`: the role list loses
   `resident_doctor`, `ca` and `hr_manager` (they now get 403; a short new assertion covers that).
2. `TodayInsightsTest::test_branch_selection_is_organisation_scoped_and_invalid_branches_are_not_found` (line ~99) and
   `tests/Feature/Insights/RangeInsightsTest::test_range_report_uses_an_organisation_scoped_branch_and_rejects_invalid_filters`
   (line ~34): both open Insights as a `ca`; the actor becomes a keeper role (for example `finance_officer`). Setup only; the assertions are about branch scope.
3. `tests/Feature/RoleOneRolesTest`: the Branch Manager table (line 43) loses `'insights.view.organisation'`, and the migration
   test also runs the new migration (as the BM-reports test did).
4. `tests/Feature/Patient/MedicalAssistantRoleTest`: the Medical Assistant table (line 83) loses it, and the same for its migration test if it recreates the role.
5. `tests/Frontend/nav-02-top-bar.test.mjs`: the expected header lists for `resident_doctor` and `hr_manager` lose `'Insights'`.
6. Not expected to change (to confirm): `ClinicManagementTest` (its map is keyed by permission), `DirectorOverviewTest` (guards on `can`),
   `AgentToolGatewayTest` (tool permission names), `AdministratorRoleTest`, `DailyReportExportTest` and `SalesSummaryExportTest` (the
   Branch Manager still downloads once the dependency is removed; any "fresh revocation" case that revokes `insights.view` to
   force a 403 would need a one-line change, which I will list if I find it), the frontend Access Control fixture (a static snapshot).
7. New tests (not changes): the five roles are refused on `/insights/*`; the keepers still open it; the Branch Manager's two downloads work without the permission; the overview opens on its own permission; the migration revokes, is idempotent, skips a missing role and keeps a direct user grant; `down()` restores.

## 8. Questions for the owner

1. Is "No branch money overview" for the Resident Doctor, CA, Medical Assistant, Branch Manager and HR Manager the same as "no Insights page at all"? (Recommended: yes in step 1; the doctor's own target and sales comes with the role overview.)
2. Is it right that Administrator, Panel Officer and Store Admin keep the old org-wide pages until their role overview is built (section 6), instead of being cut off now?
3. Are the downloads and the overview to stand on their own permissions (section 3, items 3 and 4)? Recommended yes.

## 9. Slices

| Slice | What | Who |
|---|---|---|
| INS-1 | Section 3 in one PR: catalogue edit, the revoke migration, the two decouplings, tests (section 7) | Staff Experience |
| INS-2 onward | One role overview at a time (Panel, stock, Administrator totals, doctor own figures), each with its own narrow permission and Daniel's look; then a final revoke of `insights.view.organisation` where no longer needed | owning team per page, after the canvas |
