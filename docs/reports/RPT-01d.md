# RPT-01d — Panel summary

Local branch `feature/rpt-01d-panel-summary` based on RPT-01c `683c1cd`. No push, PR or merge.

## Scope and definitions

Aggregate one row per Panel with qualifying invoices, selected by branch-local invoice finalisation date (31 dates maximum). Current approved responsibility on retained finalised, non-void invoices is the common cohort; claim and unclaimed counts partition those invoices. Includes invoices on correction hold, while retaining the existing tenant/current-allocation/payable checks. It does not create a claim or change its eligibility.

Current Panel name only, no patient name/number/identifier, invoice/visit reference, member reference, diagnosis, treatment, notes, Bill to or claim remarks. Columns: approved invoice count/responsibility, claimed count/current claim amount, unclaimed count/responsibility, recorded acceptance, current full rejection, remitted and accepted outstanding, plus separate four-bucket claimed-outstanding and unclaimed-responsibility ageing. Claim amounts may differ from approved invoice responsibility after claim-only edits; stages overlap and must not be added together.

Uses the shared approved-allocation query extracted from PanelClaimEligibility; existing claim-creation query adds its unchanged hold/stale/no-existing-claim restrictions. A numeric PanelClaimAmounts helper shares the existing claim-detail accepted-minus-remitted rule; the old detail output is unchanged. No independent replacement financial ledger or source mutation.

Claimed means a submitted event exists. Never-submitted prepared packs remain unclaimed. First submitted date is retained for ageing across resubmission, as the existing claim detail does. Age is branch-local today minus that date; unclaimed age starts at invoice finalisation. Fixed inclusive buckets 0–30, 31–60, 61–90, over 90. Outstanding means max(accepted minus remitted, zero), not a promise that an unaccepted claim will be paid. Rejected matches the existing current fully-rejected measure, excluding a partial-decision remainder. These conservative choices are explicit in the screen/CSV and open for owner review below.

## Access and migration

`reports.panel_summary.view.branch` and `.export.branch`: Director, Finance Officer and Panel Officer only. Administrator, Technical Admin, CA Supervisor, CA and Doctor receive neither. Panel Officer already has branch Claims, approved allocation/financial summary and claim-date access; this report derives aggregates from those same records and grants no additional patient/source content. No patient drill-down. Fresh backend actor/grant/organisation/assigned-branch checks apply; foreign context is refused.

Migration `2026_10_21_030000_grant_panel_summary_report_permissions.php` creates missing permissions and grants only, repeat-safe with non-revoking down. Catalogue matches. Shared BranchReportService gives explicit Run, 20 rows/page, full totals, branch-local Generated time and CSV UTC. Unsupported method/visit filters are hidden and rejected; existing Daily summary behaviour remains unchanged. Shared shell fixes the new one-row case to “1 row”.

## Audit, CSV and source invariance

View/export audits contain only dataset, branch, dates, filter IDs (empty here), page and row count; no names or amounts. Request-owned repeatable-read transaction; failed audit releases nothing. CSV is BOM/CRLF, properly quoted and formula-neutralised, exact two-decimal MYR, hard refusal above 5,000 data rows, shared 10/minute actor RPT limiter, private/no-store, no retained file/email/job.

Tests compare all ID-ordered source rows and prior audits before/after every screen/CSV generation, including four populated invoices/approved allocations, submitted/partially accepted/remitted and rejected claims, unclaimed and prepared-only allocations, clinical/dispensary evidence and a stock movement. Only the expected report audit appends. Re-submission/acceptance occurs solely in the synthetic fixture and is separately snapshotted before the subsequent read.

## Tests and every changed existing assertion

Three new backend tests cover every role's exact grants/denials, scope/filter rejection, fresh revocation, expired assignment, additive replay, audit failure, controlled 5,001-row refusal, CSV formula/Unicode/precision, shared throttle, all age boundaries (0/30/31/60/61/90/91), first-submission retention after resubmission, screen/CSV equality and full source invariance. Existing Claims/hub plus new tests: 44 passed, 3,070 assertions. One new frontend test pins shared eligibility/measures, no patient projection, unsupported-filter hiding and singular row wording; the existing shell/dirty-state tests stay intact.

**One existing assertion input changes relative to the parent:** ClinicManagementTest's exact role/destination map adds `/reports/panel-summary => reports.panel_summary.view.branch`. Reason: authorised destination for the three approved holders. Exact-set checks and all existing claim/financial assertions remain unchanged. No assertion is weakened or removed; the MC fixture correction is inherited, not changed here.

## Browser evidence

Real Chrome, synthetic `.test` Finance login, production assets, separate seed database. 1440/390 CSS px light/dark captures: `/private/tmp/rpt-overnight-evidence/rpt01d/{1440,390}-{light,dark}.jpg`. No page overflow; table scrolls internally. One Panel row: responsibility 50.00, claimed 30.00, unclaimed 20.00, accepted 25.00, remitted 10.00, accepted outstanding 15.00. Real downloaded `synthetic.csv` matches; patient content absence checked. Theme restored and viewport reset.

## Validation

Full gate passed: 1,197 backend tests, 1,195 passed, two existing disabled-public-registration skips, 18,607 assertions; all 385 frontend tests. Pint, PHPStan level 7, TypeScript, lint, format, production build, separate-DB fresh migration/seeding and git diff --check passed. PostgreSQL 18.6 task-owned 127.0.0.1:58426, `kpone_rpt01d_testing` and separate `kpone_rpt01d_seed_testing`, effective connection verified before requests. No .env or new dependency. All static/frontend/build and fresh migration/seeding checks pass, frontend 385/385. The final frontend-only singular wording fix was followed by repeat types/lint/format/build/frontend checks.

## Files changed

PanelSummaryReportService; shared PanelClaimAmounts and PanelClaimEligibility extraction; unchanged-valued claim-detail integration; common BranchReportService unsupported-filter handling; BranchReportController, routes, ReportPermissions, hub; additive permission migration; report shell; new PanelSummaryReportTest and frontend coverage; shared synthetic invoice fixture helper; hub expected map; BASELINE/INSIGHTS and this report.

## Open questions (safest narrow choices applied)

- Date range selects the common invoice-finalisation cohort; current claim state is reported at generation, aged as of branch-local today.
- Outstanding is accepted less remitted only; pending/unaccepted claimed amounts are not accepted receivables.
- Rejected is the existing fully-rejected current measure, not a new partial-rejection remainder.
- Prepared but never submitted is unclaimed; age uses first submission, never reset by resubmission. Panels with no qualifying invoices are omitted.
