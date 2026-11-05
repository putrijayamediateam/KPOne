<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\PostedReceiptProjection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
        return 'Cash, Non-cash and Total use original receipt date, not invoice finalisation; original, reversed and net are explicit. Reversals restate the original receipt date, not refund-date cash flow (see refund columns). Panel is approved responsibility; Due is approved pay-later outstanding, both from the invoice finalisation-date cohort. Total is net receipts only: Total received (Cash + Non-cash). Panel and Due are not included in Total received. Refunds (waiting or approved, not rejected) use payout date and the refund\'s method, and are not deducted from Total received. Cash closing = Cash net + Cash reversed, refund recorded minus Cash refunds paid. Payment-method filters apply to receipt columns only, and to refunds by refund method (refunds paid) or receipt method (refund recorded). Visit type filters apply to both cohorts and to refunds (through the receipt\'s invoice); unallocated receipts cannot match a visit filter. Current classification applies to historical periods; Not classified counts as Non-cash. MYR, two decimals. Empty dates are zero rows. Current records at generation, not a historical snapshot.';
    }

    protected function project(User $actor, Branch $branch, array $filters): array
    {
        $days = [];
        for ($date = CarbonImmutable::parse($filters['from']); $date->toDateString() <= $filters['to']; $date = $date->addDay()) {
            $days[$date->toDateString()] = array_fill(0, 14, 0);
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
        $query->chunkById(500, function ($invoices) use (&$days, $branch): void {
            $states = app(FinancialLedger::class)->states($invoices);
            foreach ($invoices as $invoice) {
                $date = $invoice->finalized_at?->setTimezone($branch->timezone)->toDateString();
                $state = $states[$invoice->id];
                $days[$date][6] = ($days[$date][6] ?? 0) + $state['panel'];
                $days[$date][7] = ($days[$date][7] ?? 0) + $state['deferred'];
            }
        });
        $this->refunds($actor, $branch, $filters, $methods->keyBy('id'), $days);
        $columns = ['Local date', 'Cash original (receipt date, MYR)', 'Cash reversed (receipt date, MYR)', 'Cash net (receipt date, MYR)', 'Non-cash original (receipt date, MYR)', 'Non-cash reversed (receipt date, MYR)', 'Non-cash net (receipt date, MYR)', 'Panel approved (invoice finalisation date, MYR)', 'Due approved pay-later outstanding (invoice finalisation date, MYR)', 'Total original receipts (receipt date, MYR)', 'Total reversed receipts (receipt date, MYR)', 'Total received (Cash + Non-cash) (receipt date, MYR)', 'Cash refunds paid (payout date, MYR)', 'Cash reversed, refund recorded (receipt date, MYR)', 'Non-cash refunds paid (payout date, MYR)'];
        $sums = array_fill(0, 14, 0);
        $rows = [];
        foreach ($days as $date => $values) {
            foreach ($values as $i => $value) {
                $sums[$i] += $value;
            }
            $rows[] = [$date, ...array_map(ReportCsv::money(...), $values)];
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => array_combine(array_slice($columns, 1), array_map(ReportCsv::money(...), $sums))];
    }

    /**
     * BIL-6b: refunds that are waiting or approved (rejected ones never happened). Paid-out amounts go by payout date
     * and the refund's own method; the reversed Cash receipts they refund go by receipt date, so that
     * Cash net + Cash reversed, refund recorded - Cash refunds paid equals the daily cash closing expected figure.
     *
     * @param  array<string, mixed>  $filters  the validated report filters (from, to, methods, visit_types)
     * @param  Collection<int, PaymentMethod>  $methods
     * @param  array<string, array<int, int>>  $days
     */
    private function refunds(User $actor, Branch $branch, array $filters, Collection $methods, array &$days): void
    {
        $start = CarbonImmutable::parse($filters['from'], $branch->timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($filters['to'], $branch->timezone)->addDay()->startOfDay()->utc();
        $open = function () use ($actor, $branch, $filters) {
            $query = PaymentRefund::query()->where('payment_refunds.organisation_id', $actor->organisation_id)
                ->where('payment_refunds.branch_id', $branch->id)
                ->whereDoesntHave('decision', fn ($decision) => $decision->where('decision', 'rejected'));
            if ($filters['visit_types'] !== []) {
                $query->whereIn('payment_refunds.payment_id', PostedReceiptProjection::filterVisitTypes(
                    Payment::query()->where('organisation_id', $actor->organisation_id)->select('payments.id'),
                    $actor->organisation_id,
                    $filters['visit_types'],
                ));
            }

            return $query;
        };
        $wanted = fn (int $methodId): bool => $filters['methods'] === [] || in_array((string) $methodId, $filters['methods'], true);
        $cash = fn (int $methodId): bool => $methods->get($methodId)?->reportClassification() === 'cash';

        foreach ($open()->where('paid_out_at', '>=', $start)->where('paid_out_at', '<', $end)->get(['payment_method_id', 'amount_sen', 'paid_out_at']) as $refund) {
            if (! $wanted((int) $refund->payment_method_id)) {
                continue;
            }
            $date = $refund->paid_out_at->setTimezone($branch->timezone)->toDateString();
            $days[$date][$cash((int) $refund->payment_method_id) ? 11 : 13] += (int) $refund->amount_sen;
        }

        $receipts = $open()->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')
            ->where('payments.status', 'reversed')
            ->where('payments.received_at', '>=', $start)->where('payments.received_at', '<', $end)
            ->get(['payments.payment_method_id as receipt_method_id', 'payments.received_at as receipt_received_at', 'payments.amount_sen as receipt_amount_sen']);
        foreach ($receipts as $refund) {
            $methodId = (int) $refund->getAttribute('receipt_method_id');
            if (! $wanted($methodId) || ! $cash($methodId)) {
                continue;
            }
            $date = CarbonImmutable::parse((string) $refund->getAttribute('receipt_received_at'), 'UTC')->setTimezone($branch->timezone)->toDateString();
            $days[$date][12] += (int) $refund->getAttribute('receipt_amount_sen'); // The receipt amount, as cash closing counts it.
        }
    }
}
