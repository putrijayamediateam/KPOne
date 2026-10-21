# RPT-01c — Daily summary

Local stacked branch `feature/rpt-01c-daily-summary`, based on RPT-01c-0 `9d0ee01`. Owner authorised sequential build and local commits only; no push, PR or merge.

## Delivered and definitions

Reports hub → Daily summary: explicit Run, active effectively assigned branch only, yesterday default, maximum 31 local dates, 20-row pages, full-result totals. Reuses the RPT-02a staged multi-select chip and guarded request/download pattern in a reusable branch report shell; RPT-02a remains unchanged. Branch switching clears results/drafts and aborts requests with a generation guard. Localised Generated time on screen; UTC generation in CSV.

One row per local date, including zero dates. Cash and Non-cash each show original, reversed and net receipts. They reuse ClinicInsightsReportService's existing receipt rows and PostedReceiptProjection, with an optional visit-type restriction; existing callers pass no restriction and retain their contracts. Reversals restate the original receipt date, not refund-date cash flow. Historical archived methods remain included. Current classification is used; Not classified counts as Non-cash with visible wording.

Panel is current approved responsibility and Due is approved pay-later remaining, from finalised, non-void invoice finalisation-date cohorts, using FinancialLedger. Due is not due-now debt. Payment-method filters affect receipts only; visit types affect both cohorts. Unallocated receipts cannot match a visit filter.

**Safest narrow Total choice:** original/reversed/net Total columns are receipt-date totals only, never a sum with invoice-date Panel or Due. Column labels, screen and CSV basis explicitly state the different dates. Owner can request a separate invoice-basis total in a later revision; no mixed-cohort total is invented.

## Access, migration and safety

`reports.daily_summary.view.branch` and `.export.branch`: Director, Finance Officer, Administrator only. Download requires both. CA Supervisor, Panel Officer, Doctor, CA and Technical Admin gain nothing. Fresh actor/grants, active organisation/branch and effective assignment are checked in backend; aggregate output contains no patient rows or identifiers.

Migration `2026_10_21_020000_grant_daily_summary_report_permissions.php`: only missing permissions/grants, firstOrCreate, replay-safe, non-revoking down. Reference catalogue agrees. No financial/schema/source mutation. A common request boundary uses a request-owned repeatable-read transaction and writes only values-free view/export audits. Failed audit releases nothing. Audit contains dataset, branch, dates, permitted filter IDs, page and row count, never amounts/content.

Shared CSV rules: synchronous BOM/CRLF, proper quoting, formula-neutralisation including whitespace/control evasions, exact integer-sen two-decimal MYR; private/no-store; no retained file, email or job; shared 10/minute/actor RPT limit; 5,000-data-row hard refusal before release, never truncation. Valid daily summaries have at most 31 rows; a controlled oversized projection regression exercises the common refusal path. The cap bounds output, not source-cohort computation; no production-load claim.

## Tests and changed existing assertions

Three new backend tests cover exact all-role grants/denials, invalid/foreign branch and filters, fresh revocation/expired assignment, additive replay, split/reversed/archived/unclassified receipts, local midnight/New Year, zero dates, retrospective classification, independent method/visit filters, CSV/screen equality, audit refusal, synthetic 5,001-row refusal, formula/Unicode/precision and shared throttling. Full ID-ordered source-row comparison includes populated invoices, payments, allocations, approved responsibility/pay-later, a prepared claim, clinical/dispensary sources and a stock movement. Only the expected audit rows are added; older audits and all source rows remain identical.

Three new frontend tests compile the reusable shell, execute the actual dirty-state expression, and pin permission-filtered actions, request-generation/branch cancellation, local Generated formatting, scroll containment and distinct cohort wording.

**Exactly one changed existing assertion input relative to the parent slice:** `ClinicManagementTest::test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role` adds `/reports/daily-summary => reports.daily_summary.view.branch` to its exact destination map. Reason: authorised hub destination for the three holders. Strict complete-set equality and every existing financial/Insights assertion remain. The MC invalid-duration fixture correction is inherited from slice 1, not changed here.

## Browser evidence

Real authenticated Chrome with a synthetic `.test` Finance user and separate seed DB. Exact 1440 and 390 CSS widths match document width in light/dark, table scrolls internally. Staged method selection disables download; Apply retains invoice-date Panel/Due while changing receipts. Real CSV: cash 25.00 original / 25.00 reversed / 0.00 net, non-cash 40.00 net on 21 October; Panel 30.00 and approved pay-later 20.00 on 20 October. No patient content. Files: `/private/tmp/rpt-overnight-evidence/rpt01c/` (`1440-light.jpg`, `1440-dark.jpg`, `390-light.jpg`, `390-dark.jpg`, `method-filter-dark.jpg`, `synthetic.csv`). Theme restored and viewport reset.

## Gate

Full gate passed: PostgreSQL backend 1,194 tests, 1,192 passed, two existing disabled-public-registration skips, 18,351 assertions; all 384 frontend tests. Pint, PHPStan level 7, TypeScript, ESLint, format, production build, separate-DB migrate:fresh --seed and git diff --check passed. PostgreSQL 18.6 task-owned port 58426, databases `kpone_rpt01c_testing` and separate `kpone_rpt01c_seed_testing`; effective host/port/name verified before requests. No real .env access or new dependency.

## Files changed

ReportPermissions and PermissionCatalogue, ClinicManagementNavigation, BranchReportService, DailySummaryReportService, ClinicInsightsReportService optional receipt filtering, BranchReportController, routes, permission migration, Reports/BranchReport.vue, DailySummaryReportTest, ReportEvidence helper, branch-reports frontend tests, hub expected map, BASELINE/INSIGHTS and this report.

## Open questions

The owner confirmed Total received means Cash + Non-cash, excluding Panel and Due. The 31-day range and 20-row pagination remain the authorised narrow limits; no blocking decisions remain.

## Owner wording follow-up

The net Total header is now **Total received (Cash + Non-cash) (receipt date, MYR)**, and the page/CSV basis explicitly says **Panel and Due are not included in Total received**. Original and reversed Total receipt columns remain explicit. This changes wording only, including the matching totals label; no amount, date cohort, permission or reconciliation changes. Two new wording assertions retain every prior assertion. Follow-up validation: 3 daily-summary backend tests / 110 assertions, all 384 frontend tests, Pint and diff check passed on the verified task-owned PostgreSQL 18 connection. The descendant stock slice also re-runs the full gate with this wording inherited. Evidence: `/private/tmp/rpt-review-followup/`.

## PR preparation after slice 1 merge

Merged canonical `main` at `87df9b1` (PR #145) into this existing branch using a merge commit. **No conflicts occurred**, including in the hub destination assertion. No runtime or test-tree change resulted from the main merge; it records the released ancestry. Relative to current main the exact hub destination map still adds only `/reports/daily-summary => reports.daily_summary.view.branch`, preserving strict complete-set equality for every role. The MC and invoice-note fixture corrections are already in main and are not changed again by this PR.

New full PostgreSQL 18 gate **passed after merging main**: 1,194 backend tests, 1,192 passed, two existing disabled-public-registration skips, 18,351 assertions; all 384 frontend tests passed. Pint, PHPStan level 7 (zero errors), types, lint, format, production build, separate-DB fresh migration/seeding and diff check all passed. Evidence: `/private/tmp/rpt01c-pr-evidence/`. Effective host/port/name verified before requests: task-owned 127.0.0.1:58426, `kpone_rpt01c_testing`; fresh migration/seeding uses separate `kpone_rpt01c_seed_testing`. Existing synthetic Chrome and CSV evidence remains recorded above; no UI or financial changes are introduced by the main merge. Owner authorises pushing this branch and opening only RPT-01c against main; other report PRs remain deferred and no merge is authorised.
