# Insights

Insights is a read-only, aggregate reporting area. The currently authorised reports are **Today**, **Sales**,
**In-clinic**, **Payments**, **Inventory**, and **Patients**. They use existing KPOne records and introduce no
new patient, appointment, payment, inventory, or clinical workflow.

The implementation merged in PR #43 as `a08d099`; it is not production-approved. The authoritative project
status and outstanding release gates are in [BASELINE.md](./BASELINE.md).

## Access and privacy

Every active staff role is granted `insights.view.organisation`. The backend permission protects all Insights
routes; hiding a menu item is not an authorization boundary. The branch selector is organisation-wide and
offers All branches and the three clinic branches. Doctor filtering is available only on reports whose source
data supports it.

Reports return aggregates and catalogue/provider ranking labels, not patient names, patient numbers, identity
documents, or individual financial records. Patient-level outstanding-balance rankings are intentionally not
available to the all-role report. Patient drill-down remains excluded. RPT-01a, RPT-01b and RPT-02a are merged in PRs #141, #142 and #143. None is production-approved.

## Filters and comparison

Today has no date filter and refreshes automatically every minute. It compares the selected day with yesterday.

Range reports default to yesterday. Branch and supported doctor filters use the existing KPOne operational
dropdown. The date-range picker supports Yesterday, Last 7 days, Last 30 days, Last 90 days, This week, This
month, This year, Last week, Last month, and a custom start/end date. Weeks start on Monday. Selecting a preset
or completing a custom range immediately applies the range. Date ranges are inclusive and limited to one year.
Each report compares the chosen range with the immediately preceding range of equal duration. Calendar dates
are interpreted in the branch timezone and converted to UTC query boundaries.

The ranking search fields filter the rows already returned for their individual tables; they do not search
patient records or issue additional requests. Sales services, medicine, and provider rankings are loaded
independently and each is capped at 100 rows. If a ranking exceeds that cap, the report identifies the
truncation and explains that search covers only the rows shown.

## Metrics and calculation boundaries

- **Sales:** net finalized invoice payable (original invoice subtotal plus an active surcharge or minus an active discount) grouped by local invoice-finalization date; drafts and voided invoices are
  excluded. New/returning classification uses the prior completed-visit rule. Service and medicine rankings
  use original invoice-line snapshots, labelled **gross item sales**. Provider ranking uses net invoice payable and the attending doctor recorded at checkout.
- **In-clinic:** visit, queue, checkout, and hold timestamps provide the duration measures. Waiting time is
  queued-to-called; serving time is called-to-checkout less recorded On Hold intervals; total in-clinic time is
  registration-to-completion. Current-day in-progress visits use the current time as an endpoint, including the
  open interval of a current hold when subtracting held time. Historical
  weekday/hour occupancy is visit volume, not a staff rota or capacity measure.
- **Payments:** aggregates net self-pay and Panel invoice payable, posted receipts, approved panel allocations, and
  outstanding balances. It does not expose a patient-level debt list.
- **Inventory:** reports supported low-stock, upcoming-expiry, recorded loss/damage, and medicine-sales
  measures. Inventory value and cost of sold stock are unavailable because unit receipt costs and a valuation
  method are not recorded.
- **Patients:** reports aggregate demographics and visit frequency. Appointment-versus-walk-in is unavailable
  because KPOne has no appointment records.

Package ranking is shown as unsupported because the current billing catalogue has no package line type.
Unsupported values must remain clearly unavailable; they must not be inferred from unrelated records.

All current data and preview accounts are synthetic. No real-patient production approval is granted by this
feature.

## Release gates

The owner completed a browser walkthrough on 2026-10-02 against a dedicated PostgreSQL 18.6 acceptance database,
using one completed synthetic visit and non-zero data across the six reports. All report tabs and the branch/date
filters were reviewed, and the owner confirmed the results. The exact merged candidate `f1dac42` passed
PostgreSQL 18.6 (743 tests, 741 passed, 2 expected Fortify skips, 0 failures, 8,747 assertions), fresh
migration/seeding, and post-merge CI after PR #43. Production use also requires the project's security,
privacy/PDPA, legal, recovery, and access-governance gates.

The aggregate queue figures on the public QR status page (PR #47) are a separate patient-facing feature, not an
Insights report; they are recorded in [BASELINE.md](./BASELINE.md).

## Invoice adjustments: net sales and gross item values

Today and Sales headline totals, new/returning sales, sales per patient, branch and day/hour totals, and
doctor invoice-sales rankings use adjusted invoice payable. Payments report invoice-sales and patient
outstanding totals use the same payable. Demographic/visit counts do not change. Finalized invoices retain
their original line prices and original subtotal/total in storage.

The full filtered invoice cohort has an uncapped reconciliation, independent of the 100-row ranking cap:
**gross item sales (all lines) + invoice surcharges − invoice discounts = net invoice sales**. This includes
consultation, service and medicine lines; consultation remains in the service ranking. Medicine/service
rankings, including Inventory medicine sales, retain original gross item values. No proportional discount
is allocated to a medicine, service or provider's individual invoice line.

Reversed invoice adjustments have zero effect. Adjustments recorded on a later date restate the invoice's
existing finalisation-date cohort, interpreted in the selected branch's timezone; adjustment timestamps do
not create a new sales cohort. The same rule applies to comparison periods.

Receipts and Panel remittances remain actual recorded money received, using their existing receipt/remittance
date rules. Approved Panel responsibility and pay-later remain distinct from cash. Claim-only adjustments
remain separate from invoice adjustments and never change invoice sales or patient payable.

Reports select only numeric adjustment effects. Private adjustment reasons are never included in report
payloads, claim projections, print views or exports. Existing aggregate-only Insights access is unchanged;
Administrator and Technical Admin gain no patient detail or invoice access. RPT-01a authorises only the aggregate Sales summary export described below.


## RPT-01a: Sales summary CSV (merged PR #141)

The owner authorised one aggregate Sales summary CSV, guarded by the new
`reports.sales.export.organisation` permission for Director, Finance Officer and Administrator.
Existing Insights viewing does not grant downloads. Administrator gains no patient, billing or print access.
The organisation permission permits selected or all active branches of that organisation, independently of
which branch is selected for day-to-day work. The export rechecks current actor authority and grants.

Download summary CSV uses the applied Sales filters and the existing report calculations. It includes current
full-cohort gross item sales, invoice discounts/surcharges, net invoice sales, new/returning net sales,
aggregate patients and net sales per patient. Only the existing total/patient/per-patient comparison metrics
are included for the previous period. It contains no patient identities, individual invoices, line rankings,
provider table, notes, Bill to, adjustment reasons or clinical content. PS-03 Panel invoices remains a separate,
unchanged patient-level exception with a different date basis and its existing permission.

CSV is UTF-8 with BOM, proper quoting and formula-neutralised text. Exact money uses two decimals without
thousands separators; counts are numeric. Scope, branch timezones, doctor filter, generation time in UTC,
period dates, metric and unit are explicit columns. Calendar boundaries follow each branch's timezone.
The download represents current results for those filters at generation, not a frozen copy of an earlier
screen. App requests use a repeatable-read transaction on PostgreSQL. Changing an unapplied control disables
the action until filters are applied. Tables and their local search fields are not included.

The bounded CSV is prepared in memory and streamed, with no stored generated file, public link, email or
background job. More than 5,000 data rows is refused before release; the fixed summary currently has 11 rows.
The existing one-year range limit remains. The shared RPT limiter is 10 downloads/minute per actor across
filter variations; PS-03's limiter is unchanged. Private/no-store responses are used. `reports.sales.exported`
records only dataset, allowed filters/filter-presence flags and row count in existing actor/organisation/time
audit context, never rows or values. Audit failure refuses release. The event records preparation, not proof
that a browser completed or opened a download. Existing financial/stock/claim rows and versions never change.

RPT-01b is pre-authorised as a separate PR for daily net sales and receipts by payment method, aggregates only.
It is not implemented by RPT-01a. Other report families, patient-level exports, Panel ageing, stock valuation,
XLSX/PDF, retained exports and asynchronous/email delivery remain deferred pending owner decisions.

## RPT-01b: Daily sales and receipt-method CSV (merged PR #142)

Two aggregate-only downloads, separate from the RPT-01a summary:

- **Sales → Download daily CSV:** one row per local calendar date, including zero days, from the
  existing `dailyTrend` projection. `Net invoice sales (MYR)` uses the invoice finalisation-date cohort
  and current adjusted payable. `Distinct patients` counts patient records once per local finalisation
  date in that same cohort, deduplicated across invoices and selected branches. The on-screen daily
  trend displays this count. A patient can count again on another date, so daily counts need not sum
  to the period distinct count. Zero-value finalised invoices count; empty export dates have zero patients.
  Later adjustments restate the original finalisation date; draft and
  voided invoices are excluded. The chart still omits absent dates; the export makes those zeros explicit.
- **Payments → Download receipts by payment method CSV:** the new receipt-date reconciliation table
  and CSV share `dailyReceipts` in `ClinicInsightsReportService`. One row per date and governed method
  code, with receipt-time name snapshots, original receipts, reversed receipts and net received. Empty
  days are zeros for methods observed in the period; a completely empty period uses `(none)` per date.
  The screen hides rows only when receipts, reversed and net are all zero; the CSV retains the full
  date/method matrix. Fully reversed receipts remain visible. Archived methods are retained. Different snapshots for the same method are listed together, not split
  into different methods. For all branches, each receipt is dated in its own branch timezone before
  aggregation. A doctor filter follows allocated invoices' attending doctor; it is not the receipt recorder.

**Receipt basis and existing-screen difference.** `PostedReceiptProjection` is the shared receipt-date
cohort used by manual terminal reconciliation and the new report. Reconciliation sums currently posted
**receipt amounts**; it does not require a finalised invoice join. The new table adds the amounts of
reversed receipts explicitly, attributed to their **original `received_at` date**. Net equals currently
posted receipt amounts for that cohort. A later recording reversal restates that original date; this is
**not refund-date cash flow**, and a reversed receipt is not evidence of a cash refund. Existing immutable
terminal-close revisions retain their historical recorded totals; a new export may differ after later
receipts or reversals. Method subtotals reconcile by method code, combining receipt-name snapshots.

The existing Payments received card and Payment method ranking are preserved: they sum **posted
allocations against finalised invoices**. They can differ from receipt amounts (for example an unallocated
receipt in a legacy fixture). The new screen text and file header state the distinction; no existing
financial calculation is silently replaced. Panel claim remittances remain a separate measure and are
excluded from this receipt export. The two CSVs never combine sales and receipts in one amount column.

**Access.** Daily sales reuses `reports.sales.export.organisation`. Receipt download adds
`reports.payments.export.organisation`, also requiring `insights.view.organisation`. Both default to
Director, Finance Officer and Administrator. Technical Admin and other roles gain no export permission.
Administrator gains no patient, print or payment-write access. Backend checks fresh actor/grants, active
organisation and branch context, then allows selected/all active branches within that organisation.
Migration `2026_10_20_000300_grant_payments_export_permission` adds only missing grants; repeat-safe,
no-op down, no financial/source schema change. The reference catalogue agrees.

**CSV and audit.** UTF-8 BOM, CRLF, quoted/neutralised text (including whitespace/control evasions), exact
integer-sen two-decimal MYR values. A CSV header block states dataset, scope, branch timezones, applied
period, generated UTC time, currency, doctor filter and explicit basis. Header rows are separate from data
rows for the 5,000-data-row hard refusal; no truncation or partial release. The existing 366-calendar-day
maximum remains. RPT exports share one 10/minute actor limiter across datasets and filter variants.
Applied dates are pinned in the URL; dirty filters disable the actions. Private/no-store, synchronous
memory-only preparation; no retained file, email or job. Request-owned repeatable-read transaction;
actor/grant locks only. Runtime writes only the required audit event. `reports.sales.exported` /
`reports.payments.exported` records dataset, branch scope, dates and row count, never values or arbitrary
query fields. Audit refusal releases no file. An audit proves response preparation, not completion of a
browser download. No change to source locks, versions or financial facts.

**Boundaries.** Patient-level billing/transaction exports, Panel ageing, stock valuation, XLSX/PDF,
asynchronous/email delivery and other report families remain deferred. New tests compare full ordered
source rows before/after both exports with populated claims, stock and receipt evidence; one existing exact daily-trend assertion is extended with its four distinct patients, preserving its
exact sales amount and all other financial assertions. Candidate CSV and frontend contracts are extended
for the added column and display-only filtering; none is weakened. No real patient data or production approval.


## RPT-02a: separate patient Billing Detail exception (merged PR #143)

The owner explicitly authorises a dedicated Billing Detail page and CSV at `/reports/billing-detail`.
This is not an expansion of aggregate Insights payloads or RPT-01 permissions. Only Director and Finance
Officer receive `reports.patient_billing.view.branch` and `reports.patient_billing.export.branch`.
Export requires both permissions. CA Supervisor, Panel Officer, Doctor, Administrator, Technical Admin and
other roles receive neither. Every request checks fresh authority and an effective assignment to the active
branch, including accounts that otherwise switch organisation-wide. The additive migration creates missing
permissions/grants only; its `down()` never removes grants.

The page starts empty, then explicitly runs an audited result with 20 invoices per page and full-cohort totals.
One row represents one finalised, non-void invoice, selected by branch-local finalisation date and optional
local time, attending-checkout doctor, visit type and payment-method filters. Filters use searchable staged
multi-select chips (OR within, AND across); no Treatment/diagnosis filter is authorised. The maximum period
is 31 local dates, default yesterday. Existing Insights filters and downloads are unchanged.

Names are current patient names. Identifiers are KPOne patient, visit and invoice numbers, never NRIC/passport,
contacts or member references. No clinical text, drug names, notes, Bill to or adjustment reasons are returned.
The existing FinancialLedger supplies current adjusted payable, net allocated patient receipts, approved Panel
responsibility, approved pay-later outstanding and due now. Net payable equals those four settlement components.
Settlement includes linked receipts from any receipt date; it is not receipt-date cash flow. Method filtering
selects invoices with a currently posted receipt in a selected method, retaining the whole invoice settlement;
such invoice cohorts can overlap. Historical checkout doctors and archived used methods remain selectable.

The CSV pivots net allocated receipts into one amount column per method used by the filtered invoice cohort,
including zero-net reversed methods, with zeroes for a row lacking that method. Codes are stable identifiers;
method names are current catalogue labels. Header totals include every financial column and every method.
Screen generation time is presented in the active branch timezone, explicitly labelled; CSV generation remains UTC. Payment-method labels on screen show names only; codes remain in CSV headers. Screen and CSV identify their own generation time; downloads deliberately re-read current data without a signed
fingerprint refusal. They can differ after intervening changes. Neither is an immutable issued document.

Both responses are private/no-store and prepared in a request-owned PostgreSQL repeatable-read transaction.
The only write is a values-free audit event (`reports.patient_billing.viewed` or `.exported`), with dataset,
branch/date/time, permitted filter IDs, page and row count only. Failed audit refuses release. Each successful
result page is audited; opening the empty shell is not. Downloads use the shared 10/minute/actor RPT limiter;
result requests use 60/minute/actor. More than 5,000 export data rows is refused before preparing identities,
never truncated. CSV reuses the BOM/CRLF/quoting/formula-neutralising encoder and exact integer-sen MYR formatting.
No generated file, email or background job is stored. Browser downloads remain under the recipient's control.

Transaction Detail (RPT-02b) is a separate PR. Cash/Non-cash classification, a Payment Methods classification
setting, Treatment filtering, Panel ageing, valuation, patient rankings, XLSX/PDF and retained/asynchronous
exports remain deferred. Existing real-patient production and privacy release gates remain applicable.

## RPT-01c-0: current payment-method classification

Owner authorised Cash/Non-cash classification for subsequent reporting slices. Existing retained CASH methods default to Cash; other retained methods default to Non-cash. A missing classification is displayed as Not classified and counted as Non-cash. A later classification decision applies to reports generated thereafter, including historical periods, without changing any stored receipt or payment. Existing RPT-01a/01b and RPT-02a output contracts remain unchanged. See [slice handoff](reports/RPT-01c-0.md).

## RPT-01c: Daily summary

Separate branch report, not an expansion of Insights. Cash/Non-cash and Total use receipt dates with original/reversed/net columns; Panel and approved pay-later outstanding use invoice finalisation dates. Method filters affect receipts only. Director, Finance Officer and Administrator hold narrow view/export permissions; no patient rows. Existing aggregate exports remain unchanged. [Definitions, validation and open Total decision](reports/RPT-01c.md).

## RPT-01d: Panel summary

The owner now authorises this separate aggregate branch report and fixed ageing buckets, superseding the earlier deferral for this slice only. It is not an Insights/patient drill-down expansion. Director, Finance Officer and Panel Officer receive narrow view/export permission. Date cohort, current accepted outstanding, fully rejected measure and first-submission/unclaimed ageing are explicit in [the handoff](reports/RPT-01d.md). No patient content, tax identifiers or changed claims.

## RPT-01e: quantities-only stock movement summary

Separate assigned-branch report for Director, Finance Officer and Administrator. Uses the shared retained movement scope, exact milliunits and historical opening/closing reconciliation. No patient, batch/reference, supplier, cost or valuation fields. Stock valuation stays deferred. [Definitions and gate](reports/RPT-01e.md).
