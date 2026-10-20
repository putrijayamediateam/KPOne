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
available to the all-role report. Patient drill-down remains excluded. RPT-01a is authorised as described below; it is not merged or production-approved.

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


## RPT-01a: Sales summary CSV (authorised candidate)

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
