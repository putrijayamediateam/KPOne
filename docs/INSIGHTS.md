# Insights

Insights is a read-only, aggregate reporting area. The currently authorised reports are **Today**, **Sales**,
**In-clinic**, **Payments**, **Inventory**, and **Patients**. They use existing KPOne records and introduce no
new patient, appointment, payment, inventory, or clinical workflow.

The implementation is in the current isolated worktree and is not yet merged or production-approved. The
authoritative project status and outstanding release gates are in [BASELINE.md](./BASELINE.md).

## Access and privacy

Every active staff role is granted `insights.view.organisation`. The backend permission protects all Insights
routes; hiding a menu item is not an authorization boundary. The branch selector is organisation-wide and
offers All branches and the three clinic branches. Doctor filtering is available only on reports whose source
data supports it.

Reports return aggregates and catalogue/provider ranking labels, not patient names, patient numbers, identity
documents, or individual financial records. Patient-level outstanding-balance rankings are intentionally not
available to the all-role report. No exports or patient drill-down are included.

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

- **Sales:** finalized invoice value grouped by local invoice-finalization date; drafts and voided invoices are
  excluded. New/returning classification uses the prior completed-visit rule. Service and medicine rankings
  use invoice-line snapshots, and provider ranking uses the attending doctor recorded at checkout.
- **In-clinic:** visit, queue, checkout, and hold timestamps provide the duration measures. Waiting time is
  queued-to-called; serving time is called-to-checkout less recorded On Hold intervals; total in-clinic time is
  registration-to-completion. Current-day in-progress visits use the current time as an endpoint, including the
  open interval of a current hold when subtracting held time. Historical
  weekday/hour occupancy is visit volume, not a staff rota or capacity measure.
- **Payments:** aggregates self-pay and panel invoice value, posted receipts, approved panel allocations, and
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

Before release consideration, run the full quality gate and PostgreSQL 18 suite against a disposable test
database, complete an independent review, and perform browser UAT against synthetic transactions. A browser
smoke check on the freshly seeded empty database confirms the screens, filters, charts, and empty states render;
it does not replace owner UAT with non-zero synthetic data. Production use also requires the project's security,
privacy/PDPA, legal, recovery, and access-governance gates.
