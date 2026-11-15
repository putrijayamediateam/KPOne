# RPT-02b — Transaction Detail

PR candidate `feature/rpt-02b-transaction-detail` against canonical main `2b56ce3`. Owner authorised this push and PR; no merge.

## Scope and definitions

Reports hub → Transaction Detail. Explicit Run, one currently assigned branch, maximum 31 local dates, yesterday default, 20-row pages with stable receipt timestamp/id ordering and full-result totals. Searchable staged Payment method and Visit type chips reuse the guarded report shell; dirty filters disable export. Existing RPT-02a and aggregate report output contracts stay unchanged.

One row per retained posted or recording-reversed receipt, not an invoice-finalisation row. Columns: row, local receipt timestamp, KPOne patient number, current patient name, allocated visit/invoice numbers, receipt-snapshot payment-method name, current Cash/Non-cash classification, original receipt, reversed amount, net received, explicit status. Method code is CSV-only. No NRIC/passport, contact, diagnosis, drug, clinical note, invoice note, Bill to, claim/member reference or adjustment reason is selected or returned.

PostedReceiptProjection now shares the existing optional visit-type allocation predicate and original/reversed/net arithmetic with ClinicInsightsReportService. No independent financial SQL or new receipt definition. Whole receipt amounts reconcile to the existing RPT-01b daily receipt projection and posted terminal reconciliation. A recording reversal restates the original receipt date and is not proof of a cash refund. Voiding an invoice cannot erase its retained reversed receipt evidence. The older allocation-based Insights summary card remains a different measure for unallocated payments, as already documented by RPT-01b; this report follows the retained whole-receipt projection, not that card.

Archived methods remain selectable. The method name is the receipt snapshot, while governed code and classification are current reference data. Missing classification is explicitly Not classified (Non-cash). Reclassification restates later-generated historical reports without mutating receipts. Full-result totals state original, reversed, net, Cash net and Non-cash net; no invoice sales/Panel/debt column is combined with receipt amounts.

Unallocated legacy receipts remain in monetary totals but patient identity is masked and no visit/invoice is invented. This conservative default avoids expanding the authorised invoice-linked patient cohort while preserving reconciliation. An allocated receipt must have matching tenant/branch invoices and patient before identity is released; inconsistent evidence refuses release. Visit-type filters select receipts having any linked invoice of that type and retain the whole receipt. Separately filtered cohorts can overlap. Empty periods contain no artificial patient rows. Doctor, clinical/treatment, organisation-wide and time-of-day filters are not introduced in this slice.

## Permissions, migration and safety

New `reports.transactions.view.branch` and `.export.branch`: Finance Officer and Director **only**. No new grant to Administrator, Technical Admin, CA Supervisor, Panel Officer, CA or Doctor. Runtime uses fresh loaded permission grants, active organisation/branch and effective assignment, never role names. Export needs both permissions. Additive migration `2026_10_21_050000_grant_transaction_report_permissions.php` uses firstOrCreate, grants only missing permissions and never revokes in down; reference catalogue matches.

Request-owned PostgreSQL repeatable-read transaction; the only persisted change is the values-free `reports.transactions.viewed` / `.exported` audit. Metadata: dataset, branch id, dates, permitted method/type IDs, page and released row count. No amounts, identities or row content. Audit failure blocks result and file. Opening the empty shell loads no patient rows and is not audited; every released result page is audited. View prepares at most 20 identities, while sums use financial-only fields over the filtered cohort. Export counts after filters and refuses over 5,000 before identity preparation, never truncates.

Synchronous private/no-store CSV, UTF-8 BOM, CRLF, proper quoting, shared formula-neutralisation including whitespace/control evasions, exact integer-sen MYR strings. Shared ten downloads/minute/actor across all RPT exports; 60 result requests/minute. No retained file, email or job. Screen generation is branch-local and labelled; CSV generation is UTC. Screen and file identify their own generation time and may differ after intervening changes; no issued snapshot is claimed. The data-row cap does not impose a source-query workload cap or constitute production load testing.

## Tests and changed existing assertions

Four new backend tests cover all role grants/denials; initial shell; foreign branch/organisation, invalid dates, methods/type and forbidden filters; fresh view/export revocation; expired assignment; additive replay/non-revoking down; audit failure; controlled 5,001-row refusal before identity preparation; shared throttle; CSV BOM/CRLF/quoting/Unicode/formula evasions and exact high-value sen formatting; 20+1 pagination with full totals; actual independent reversal and void.

Populated fixture includes a real invoice adjustment, two receipt methods, an independent reversal, approved Panel allocation and prepared claim, stock and clinical/dispensary sources, invoice note and Bill to. It proves local-midnight/New Year separation, archived and unclassified methods, current patient names, masked unallocated identity, other-branch exclusion, payment/visit filters, current reclassification and screen/CSV equality (apart from CSV-only code and required formula neutralisation). Original 66.23, reversed 25.00, net 41.23 reconciles to the existing aggregate receipt projection. Full ID-ordered raw comparison of every public source table except session/cache/audit proves all financial, allocation, responsibility, claims, notes, Bill to, stock/balance, dispensing and clinical sources remain byte-identical around report requests; only the expected report audit appends and all previous audits remain identical.

The first expanded fixture attempted an adjustment after receipts, which existing billing rules correctly refused. It now creates the adjustment before payment, retaining the expected financial results and every assertion. No runtime billing rule changes.

One new frontend test pins the shared projection, cap-before-identity ordering, bounded pages, audit, masked unallocated identity, code-only-in-CSV and forbidden field exclusion. Existing staged chips, request/branch clearing, local generation, permission visibility and scrolling tests remain intact.

**Exactly one existing assertion input changes relative to the parent:** ClinicManagementTest's strict expected destination map adds `/reports/transactions => reports.transactions.view.branch`. Reason: the authorised hub destination for the two holders. No old money, reversal, privacy or access assertion is removed or weakened. Prior slice changes are inherited. Existing daily receipt tests verify that extracting the common helper preserves their output.

## Real Chrome evidence

Task-owned synthetic `.test` Finance login and separate seed DB. Captured exact 1440 and 390 CSS px, light/dark; document width equals viewport with internal table scrolling, no page overflow. Dirty-date change disables Download. Actual browser-downloaded CSV shows two receipts, original 65.00, reversed 25.00, net 40.00, matching the screen; UTC generation and Asia/Kuala_Lumpur receipt timestamps are distinct. Evidence `/private/tmp/rpt-overnight-evidence/rpt02b/`: four `{1440,390}-{light,dark}.jpg` captures, `synthetic.csv`, effective-connection log and gate logs. Theme and temporary viewport restored.

## Validation

Focused transaction/aggregate/hub suite: 24 passed, 1,364 assertions. Full gate passed: **1,204 backend tests, 1,202 passed, two existing disabled-public-registration skips, 18,913 assertions; all 387 frontend tests passed**. Pint, PHPStan level 7, types, lint, format, production build, separate-DB fresh migration/seeding and git diff --check all passed. PostgreSQL 18.6 task-owned 127.0.0.1:58426, `kpone_rpt02b_testing` plus separate `kpone_rpt02b_seed_testing`. Effective host/port/name verified before first requests; fixture verified actual database/port/version before Chrome. No .env opened/created, dependency change, push, PR or merge.

## Files changed

TransactionReportService; shared PostedReceiptProjection arithmetic/visit predicate and unchanged-contract ClinicInsightsReportService caller; ReportPermissions, BranchReportController, routes and hub; additive grants migration; TransactionReportTest and frontend coverage; one hub map addition; BASELINE/INSIGHTS and this report.

## Open questions (safe narrow defaults applied)

- Unallocated legacy receipt identities stay masked while amounts reconcile. A future decision to release those identities would need separate patient-access approval.
- One row per whole receipt and semicolon-separated linked invoice/visit references. Visit-type cohorts can overlap; no proportional allocation or fabricated split.
- Current classification and receipt-snapshot method name; no historical classification snapshot is invented.
- Only method and non-clinical visit-type chips in this slice. Additional doctor/time filters remain separate; treatment/clinical filtering is not added.

## Owner review follow-up — inherited display fixes

Inherited the Daily summary Total wording, the Stock-only opening-entry note and the assertion-preserving invoice-note clock-fixture correction from the updated preceding branches. No Transaction Detail financial projection, permissions, columns or source mutation changed. The shared frontend file had two independent appended tests at the merge point; both the existing Transaction Detail regression and the new Stock note regression are retained unchanged.

Integration validation after merging: **31 report/notes/hub backend tests passed, 1,513 assertions; all 388 frontend tests passed**. Original full Transaction Detail gate remains recorded above. Updated stack display/fixture changes have passed full stock and first-slice PostgreSQL gates. Follow-up static/type/lint/format/build results are retained in `/private/tmp/rpt-review-followup/transaction-*.log`. Owner authorises pushing this branch only; its PR remains deferred until separately requested. No merge is authorised.

## PR preparation after slices 1–4 merged

Merged canonical main **2b56ce3** (includes #145–#148) with ordinary merge commit **3ad5b4b**. **No conflicts occurred**, including the hub destination assertion, shared report files and docs/INSIGHTS.md. Main contributed documentation updates only; runtime/test files are identical to the pre-merge Transaction Detail branch. Earlier MC/invoice-note fixture corrections are already in main and are not new changes here. Relative to current main, the sole changed existing assertion remains the strict hub map addition described above. No test weakened or newly skipped.

Post-merge full gate **passed**: **1,204 backend tests, 1,202 passed, two existing disabled-public-registration skips, 18,913 assertions; all 388 frontend tests passed**. Pint, PHPStan level 7, TypeScript, ESLint, Prettier, production build, separate-DB fresh migration/seeding and diff check passed. Effective and actual task-owned PG18.6 connection verified before requests: 127.0.0.1:58426, kpone_rpt02b_testing; separate kpone_rpt02b_seed_testing. Refreshed logs, exact diff and PR body: /private/tmp/rpt02b-pr-evidence/. Existing exact 1440/390 light/dark Chrome captures and actual downloaded synthetic CSV remain applicable because the merge changes no UI/runtime code. Only this branch is pushed and only RPT-02b is opened for this request; no merge authorised.
