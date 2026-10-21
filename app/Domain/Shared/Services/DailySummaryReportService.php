<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DailySummaryReportService extends BranchReportService
{
    public function key(): string
    {
        return 'daily_summary';
    }

    public function title(): string
    {
        return 'Daily summary';
    }

    public function basis(): string
    {
        return 'Cash, Non-cash and Total use original receipt date, not invoice finalisation; original, reversed and net are explicit. Reversals restate the original receipt date, not refund-date cash flow. Panel is approved responsibility; Due is approved pay-later outstanding, both from the invoice finalisation-date cohort. Total is net receipts only: Total received (Cash + Non-cash). Panel and Due are not included in Total received. Payment-method filters apply to receipt columns only. Visit type filters apply to both cohorts; unallocated receipts cannot match a visit filter. Current classification applies to historical periods; Not classified counts as Non-cash. MYR, two decimals. Empty dates are zero rows. Current records at generation, not a historical snapshot.';
    }

    protected function project(User $actor, Branch $branch, array $filters): array
    {
        $days = [];
        for ($date = CarbonImmutable::parse($filters['from']); $date->toDateString() <= $filters['to']; $date = $date->addDay()) {
            $days[$date->toDateString()] = array_fill(0, 11, 0);
        }
        $methods = PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->get()->keyBy('code');
        foreach (app(ClinicInsightsReportService::class)->branchReceiptRows($actor, $branch, $filters['from'], $filters['to'], $filters['visit_types']) as $row) {
            $method = $methods->get($row['methodCode']);
            if ($filters['methods'] !== [] && (! $method || ! in_array((string) $method->id, $filters['methods'], true))) {
                continue;
            }
            $offset = $method?->reportClassification() === 'cash' ? 0 : 3;
            foreach (['receiptsSen', 'reversedSen', 'netSen'] as $i => $key) {
                $days[$row['date']][$offset + $i] = ($days[$row['date']][$offset + $i] ?? 0) + $row[$key];
                $days[$row['date']][8 + $i] = ($days[$row['date']][8 + $i] ?? 0) + $row[$key];
            }
        }
        $query = Invoice::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('status', 'finalized')
            ->where('finalized_at', '>=', CarbonImmutable::parse($filters['from'], $branch->timezone)->startOfDay()->utc())
            ->where('finalized_at', '<', CarbonImmutable::parse($filters['to'], $branch->timezone)->addDay()->startOfDay()->utc());
        if ($filters['visit_types'] !== []) {
            $query->whereIn('visit_id', DB::table('visits')->where('organisation_id', $actor->organisation_id)->whereIn('visit_type', $filters['visit_types'])->select('id'));
        }
        foreach ($query->cursor() as $invoice) {
            $date = $invoice->finalized_at?->setTimezone($branch->timezone)->toDateString();
            $state = app(FinancialLedger::class)->state($invoice);
            $days[$date][6] = ($days[$date][6] ?? 0) + $state['panel'];
            $days[$date][7] = ($days[$date][7] ?? 0) + $state['deferred'];
        }
        $columns = ['Local date', 'Cash original (receipt date, MYR)', 'Cash reversed (receipt date, MYR)', 'Cash net (receipt date, MYR)', 'Non-cash original (receipt date, MYR)', 'Non-cash reversed (receipt date, MYR)', 'Non-cash net (receipt date, MYR)', 'Panel approved (invoice finalisation date, MYR)', 'Due approved pay-later outstanding (invoice finalisation date, MYR)', 'Total original receipts (receipt date, MYR)', 'Total reversed receipts (receipt date, MYR)', 'Total received (Cash + Non-cash) (receipt date, MYR)'];
        $sums = array_fill(0, 11, 0);
        $rows = [];
        foreach ($days as $date => $values) {
            foreach ($values as $i => $value) {
                $sums[$i] += $value;
            }
            $rows[] = [$date, ...array_map(ReportCsv::money(...), $values)];
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => array_combine(array_slice($columns, 1), array_map(ReportCsv::money(...), $sums))];
    }
}
