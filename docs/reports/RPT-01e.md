# RPT-01e — Stock movement summary

Local stacked branch `feature/rpt-01e-stock-movement-summary`, based on RPT-01d `1024201`. No push, PR or merge.

## Scope and definitions

Reports hub → Stock movement summary. One assigned branch, explicit Run, yesterday default, maximum 31 local dates, 20-row pages. One row per retained SKU/item and stock unit, including archived references and locations. SKU grouping prevents adding incompatible pack/unit quantities. No global quantity total across different units.

Quantities only: Item, SKU, stock unit, opening, received, dispensed, adjusted net, transferred net, closing and reconciliation. No cost/valuation, batch/expiry, supplier, actor or dispensing/patient references are selected. The new InventoryMovementProjection extracts the existing retained inventory movement scope; the existing Inventory screen still selects its original fields and preserves its contract. No inventory write path is changed.

Exact integer milliunits produce three-decimal strings without floating-point reporting arithmetic. Movement sign follows source/destination branch membership. Opening is all movements before local period start; closing is all movements before the next local day after the end date. Purchase receipts count as Received; dispensary stock debits as Dispensed. Adjusted includes stocktake gains/losses, adjustments and initial opening entries recorded inside the period. Transfers are signed net, so internal same-branch location transfers cancel. Each row must satisfy **opening + received - dispensed + adjusted + transferred = closing**, or release is refused. Unknown movement types/invalid quantities are refused rather than silently omitted.

Closing is historical retained-ledger quantity, not the current balance after later movements. Legacy balances with no retained movement are not used to invent an opening; only SKUs with branch movement history appear. No stock valuation or new stock/batch tracking is introduced.

## Access, migration and safety

`reports.stock_movement.view.branch` and `.export.branch`: Director, Finance Officer, Administrator. Administrator receives only aggregate catalogue/SKU/unit/quantity data, no patient content or financial valuation. CA Supervisor, Panel Officer, CA, Doctor and Technical Admin receive neither. Backend checks fresh authority/grants, active organisation/branch and effective assignment. Unsupported method/visit filters are hidden and refused; dates/branch only.

Migration `2026_10_21_040000_grant_stock_movement_report_permissions.php`: firstOrCreate, missing grants only, replay-safe, non-revoking down. Catalogue matches. No stock, receipt, clinical or financial schema change.

Shared report boundary: request-owned repeatable-read transaction; values-free filter/count audit before release; failed audit blocks both result and file. Only report audit appends. CSV is UTF-8 BOM/CRLF, properly quoted, formula-neutralised including whitespace/control evasions, exact quantities (not money), 5,000-row hard refusal, shared 10/minute actor RPT limiter, private/no-store, synchronous, no stored file/email/job. No cost fields or MYR amounts are emitted. The output cap does not bound full-history source computation; no production-volume load test is claimed.

## Tests and changed existing assertions

Three new backend tests cover exact all-role grants/denials, scope/filter rejection, fresh revocation/expired assignment, additive replay, audit failure, controlled 5,001-row refusal, shared throttle, CSV BOM/CRLF/quoting/formula/Unicode, exact quantities and full source invariance. Independent stocked fixture: opening 10.000, purchase 5.125, real completed dispense 2.000, net adjustments -0.125, net transfers -0.750, closing **12.250**. Reconciles to the actual stored branch balance before a later boundary receipt. A later receipt changes the live balance but not this historical report. In-branch transfer cancels; other branch receipt is excluded; inactive source location remains included. Earlier opening-entry date is also tested.

Full ordered source comparison includes a posted receipt/allocation, approved Panel responsibility and prepared claim, clinical/dispensary and stock/balance records, including a populated purchase unit cost that the report never selects. Existing audits are byte-identical; only the two expected report audits append. Initial new-fixture expectation exposed that adding a dispensary line is not yet a stock debit; the fixture now completes the real allergy-confirmed dispensing flow, retaining every expected amount.

One new frontend test pins the shared movement projection, exact integer formatting, reconciliation wording and absence of valuation, patient, batch, actor/reference and supplier fields. Existing shell tests remain intact.

**Exactly one existing assertion input changes relative to the parent:** the ClinicManagementTest exact destination map adds `/reports/stock-movement => reports.stock_movement.view.branch`. Reason: authorised hub link for the three holders. All strict allowlists and existing stock/financial assertions remain unchanged; no assertion is weakened. The MC fixture and prior report changes are inherited, not changed here.

## Real Chrome evidence

Synthetic `.test` Finance login, production assets, separate seed DB. Exact 1440/390 CSS px in light/dark, document width matches viewport; internal table scroll only. Screen and real CSV show 10.000 + 5.125 - 2.000 - 0.125 - 0.750 = 12.250, Reconciles Yes. Patient content absence checked. Evidence: `/private/tmp/rpt-overnight-evidence/rpt01e/`, four `{1440,390}-{light,dark}.jpg` captures and `synthetic.csv`. Theme restored and viewport reset.

## Validation

Full gate passed: 1,200 backend tests, 1,198 passed, two existing disabled-public-registration skips, 18,758 assertions; all 386 frontend tests. PostgreSQL 18.6 task-owned 127.0.0.1:58426; `kpone_rpt01e_testing` and separate `kpone_rpt01e_seed_testing`, effective connection verified before requests. Focused report/hub: 12 passed, 1,071 assertions. Pint, PHPStan level 7, types/lint/format, production build, fresh migration/seeding and all 386 frontend tests passed. No .env or dependency changes.

## Files changed

StockMovementReportService, InventoryMovementProjection and unchanged-contract InventoryDirectoryService integration; ReportPermissions, BranchReportController, routes, hub; additive grant migration; StockMovementReportTest and frontend coverage; hub expected map; BASELINE/INSIGHTS and this report.

## Open questions (narrow defaults applied)

- No cross-unit/SKU grand quantity total; no invented legacy opening from a live balance.
- Retained SKUs with history remain visible even if archived or currently zero. Names/units are governed catalogue labels; physical quantities come from retained movements.

## Owner review follow-up — opening entries visibility

Added the visible note **“Adjusted includes opening-balance entries recorded during the selected period.”** directly below the Stock movement results table. The note appears only for this report and only with results; Daily summary, Panel summary and Transaction Detail do not receive it. This is display text only: columns, quantities, movement classification and the independent reconciliation equation/test are unchanged. The owner permitted this note as the alternative to another column.

One new frontend test pins the exact note, Stock-only condition and placement after the table and before pagination. **No existing assertion changes in this follow-up**, and no reconciliation assertion is removed or weakened. The full gate also exposed the existing invoice-note timestamp test creating current-date staff before travelling back to 19 October, before their effective assignment. Its existing clock freeze now precedes fixture creation. Both exact UTC/local timestamp assertions and all permission checks remain unchanged; no application note or access code changes. This is the only changed existing fixture in the follow-up. The inherited Daily summary follow-up changes wording only: its net Total header explicitly says Cash + Non-cash and its page excludes Panel/Due. The stack carries these fixes through ordinary local merge commits without history rewriting.

Follow-up full gate **passed**: 1,200 backend tests, 1,198 passed, two existing disabled-public-registration skips, 18,758 assertions; all 387 frontend tests. Pint, PHPStan level 7, types, lint, format, production build, separate-DB fresh migration/seeding and diff check passed. Focused invoice-note/stock suite: 15 passed, 282 assertions. The initial failure is retained in `stock-backend-initial-failure.log`. The clock-fixture correction is also carried in RPT-01c-0 so the first PR CI is deterministic; updated descendants inherit it. Updated real Chrome evidence: `/private/tmp/rpt-review-followup/stock-{1440,390}-{light,dark}.jpg`, with exact CSS widths and no page overflow. The synthetic row still reconciles to 12.250. Same directory contains effective connection, full gate and fresh-seed logs. The worktree remains separate from the served tree; all connections use the task-owned PostgreSQL 18 cluster and explicitly named databases.
