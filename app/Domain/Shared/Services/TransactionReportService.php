<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Services\PostedReceiptProjection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Receipt rows are the owner's separate patient-level exception, never aggregate Insights. Runs on the shared branch report boundary (BIL-8). */
class TransactionReportService extends BranchReportService
{
    public const VIEW = 'reports.transactions.view.branch';

    public const EXPORT = 'reports.transactions.export.branch';

    public function key(): string
    {
        return 'transactions';
    }

    public function title(): string
    {
        return 'Transaction Detail';
    }

    public function basisLines(): array
    {
        return [
            ['label' => 'Receipts', 'text' => 'Each receipt counts on the day it was taken (branch time), not the day the invoice was finalised. One row per receipt, even when it covers several invoices; received, reversed and net use the same receipts as the receipt reconciliation.'],
            ['label' => 'Reversals', 'text' => 'A reversed receipt is a correction of the record, not proof that cash was refunded. A later reversal is taken off the original receipt day.'],
            ['label' => 'Refunds', 'text' => 'Refunded after completion is money given back on that receipt after the visit was completed (waiting or approved, not rejected), whatever day it was paid out. It does not change received, reversed or net.'],
            ['label' => 'Receipts with no invoice', 'text' => 'Still counted, with the patient hidden and no invoice or visit shown.'],
            ['label' => 'Filters and settings', 'text' => 'A visit type filter keeps a whole receipt when any of its invoices matches, so two filtered lists can share a receipt. Patient names and Cash or Non-cash settings are current (Not classified counts as Non-cash): if a setting changes, older days change too. Payment-method names are as they were on the receipt.'],
            ['label' => 'Notes', 'text' => 'Figures are today\'s records, not a saved copy. Amounts in MYR, two decimals; a period with no receipts has no rows.'],
        ];
    }

    public function basisSummary(): string
    {
        return 'One row per receipt, counted on the day it was taken; names and Cash or Non-cash settings are as they are today.';
    }

    protected function tooManyRowsMessage(): string
    {
        return 'More than 5,000 receipts match. Narrow the filters.';
    }

    /** Kept as Transaction Detail always sent its filters: the method and visit-type lists before the page. */
    protected function filterRules(array $rules): array
    {
        $page = ['page' => $rules['page']];
        unset($rules['page']);
        $first = array_intersect_key($rules, array_flip(['expected_branch_id', 'from', 'to', 'methods', 'methods.*', 'visit_types', 'visit_types.*']));

        return $first + $page + $rules;
    }

    protected function filterDefaults(string $yesterday): array
    {
        return ['from' => $yesterday, 'to' => $yesterday, 'methods' => [], 'visit_types' => [], 'page' => 1];
    }

    protected function extraMetadata(): array
    {
        return [['Currency', 'MYR']];
    }

    /**
     * Count after filters and refuse (cap, unavailable page) before any personal data is prepared; then read patient
     * identity only for the page shown, or for the capped download.
     */
    protected function window(User $actor, Branch $branch, array $filters, int $page, bool $download): array
    {
        $query = $this->query($actor, $branch, $filters);
        $count = (clone $query)->count();
        $this->guardWindow($count, $page, $download);

        return ['count' => $count, ...$this->rows($actor, $branch, $query, $download ? null : $page, $download)];
    }

    /** Never used: reading every receipt's patient without the count, cap and page checks is not allowed here. */
    protected function project(User $actor, Branch $branch, array $filters): array
    {
        throw new LogicException('Transaction Detail always goes through window().');
    }

    /**
     * @param  Builder<Payment>  $query
     * @return array{columns:list<string>,rows:list<list<string>>,totals:array<string,string>}
     */
    private function rows(User $actor, Branch $branch, Builder $query, ?int $page, bool $download): array
    {
        $methods = PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->orderBy('name')->get(['id', 'code', 'name', 'classification'])->keyBy('id');
        $sums = ['Original receipts (MYR)' => 0, 'Reversed receipts (MYR)' => 0, 'Net received (MYR)' => 0, 'Cash net received (MYR)' => 0, 'Non-cash net received (MYR)' => 0];
        foreach ((clone $query)->orderBy('id')->select(['amount_sen', 'status', 'payment_method_id'])->cursor() as $receipt) {
            $method = $methods->get($receipt->payment_method_id);
            abort_unless($method !== null, 409, 'Receipt method evidence requires review.');
            $amounts = PostedReceiptProjection::amounts($receipt->amount_sen, $receipt->status);
            $sums['Original receipts (MYR)'] += $amounts['receiptsSen'];
            $sums['Reversed receipts (MYR)'] += $amounts['reversedSen'];
            $sums['Net received (MYR)'] += $amounts['netSen'];
            $key = $method->reportClassification() === 'cash' ? 'Cash net received (MYR)' : 'Non-cash net received (MYR)';
            $sums[$key] += $amounts['netSen'];
        }
        // BIL-10 (decision C): refunds after completion on these receipts, waiting or approved, not rejected.
        $sums['Refunded after completion (MYR)'] = (int) self::refundsAfterCompletion()->whereIn('payment_id', (clone $query)->select('id'))->sum('amount_sen');
        $totals = array_map(ReportCsv::money(...), $sums);
        $selected = (clone $query)->orderBy('received_at')->orderBy('id');
        if ($page !== null) {
            $selected->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE);
        }
        $columns = ['Row', 'Local receipt date/time', 'KPOne patient number', 'Patient name (current)', 'Visit number(s)', 'Invoice number(s)', 'Payment method (receipt snapshot)', 'Classification (current)', 'Original receipt (MYR)', 'Reversed (MYR)', 'Net received (MYR)', 'Status', 'Refunded after completion (MYR)'];
        if ($download) {
            array_splice($columns, 6, 0, ['Payment method code']);
        }
        $rows = [];
        $position = $page === null ? 0 : ($page - 1) * self::PAGE_SIZE;
        $receipts = $selected->get(['id', 'organisation_id', 'branch_id', 'patient_id', 'payment_method_id', 'received_at', 'method_snapshot', 'amount_sen', 'status']);
        $identities = $this->identities($receipts);
        $refunded = self::refundsAfterCompletion()->whereIn('payment_id', $receipts->pluck('id'))
            ->selectRaw('payment_id, SUM(amount_sen) AS amount')->groupBy('payment_id')->pluck('amount', 'payment_id');
        foreach ($receipts as $receipt) {
            $receipt->setRelation('transactionReportIdentity', $identities[$receipt->id]);
            $method = $methods->get($receipt->payment_method_id);
            abort_unless($method !== null, 409, 'Receipt method evidence requires review.');
            [$number, $name, $visits, $invoices] = $this->identity($receipt);
            $amounts = PostedReceiptProjection::amounts($receipt->amount_sen, $receipt->status);
            $classification = $method->classification === null ? 'Not classified (Non-cash)' : ($method->reportClassification() === 'cash' ? 'Cash' : 'Non-cash');
            $row = [(string) ++$position, $receipt->received_at->setTimezone($branch->timezone)->format('Y-m-d H:i:s'), $number, $name, $visits, $invoices, $receipt->method_snapshot, $classification, ReportCsv::money($amounts['receiptsSen']), ReportCsv::money($amounts['reversedSen']), ReportCsv::money($amounts['netSen']), $receipt->status === 'posted' ? 'Posted' : 'Reversed (recording reversal)', ReportCsv::money((int) ($refunded[$receipt->id] ?? 0))];
            if ($download) {
                array_splice($row, 6, 0, [$method->code]);
            }
            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /** @return Builder<PaymentRefund> */
    private static function refundsAfterCompletion(): Builder
    {
        return PaymentRefund::query()->where('kind', PaymentRefund::KIND_AFTER_COMPLETION)
            ->whereDoesntHave('decision', fn ($decision) => $decision->where('decision', 'rejected'));
    }

    /** @param array<string,mixed> $filters
     * @return Builder<Payment>
     */
    protected function query(User $actor, Branch $branch, array $filters): Builder
    {
        $query = PostedReceiptProjection::query($actor->organisation_id, $branch->id, CarbonImmutable::parse($filters['from'], $branch->timezone)->startOfDay()->utc(), CarbonImmutable::parse($filters['to'], $branch->timezone)->addDay()->startOfDay()->utc(), ['posted', 'reversed']);
        if ($filters['methods'] !== []) {
            $query->whereIn('payment_method_id', $filters['methods']);
        }

        return PostedReceiptProjection::filterVisitTypes($query, $actor->organisation_id, $filters['visit_types']);
    }

    /** Resolve a row only from the batch prepared after the scope and cap checks.
     * @return array{string,string,string,string}
     */
    private function identity(Payment $receipt): array
    {
        $identity = $receipt->getRelation('transactionReportIdentity');

        return $identity;
    }

    /** Bounded to the selected page or the existing 5,000-row export cap.
     * @param  Collection<int, Payment>  $receipts
     * @return array<int,array{string,string,string,string}>
     */
    private function identities(Collection $receipts): array
    {
        if ($receipts->isEmpty()) {
            return [];
        }
        $organisation = $receipts->first()->organisation_id;
        $branch = $receipts->first()->branch_id;
        $allocations = DB::table('payment_allocations')->where('organisation_id', $organisation)->where('branch_id', $branch)
            ->whereIn('payment_id', $receipts->pluck('id'))->orderBy('id')->get(['payment_id', 'invoice_id'])->groupBy('payment_id');
        $ids = $allocations->flatten(1)->pluck('invoice_id')->unique();
        $invoices = DB::table('invoices as i')->join('visits as v', fn ($join) => $join->on('v.id', '=', 'i.visit_id')->on('v.organisation_id', '=', 'i.organisation_id')->on('v.branch_id', '=', 'i.branch_id'))
            ->where('i.organisation_id', $organisation)->where('i.branch_id', $branch)->whereIn('i.id', $ids)->orderBy('i.id')->get(['i.id', 'i.patient_id', 'i.invoice_number', 'v.visit_number'])->keyBy('id');
        // Preserve masking: unallocated receipts do not cause identity lookup or disclosure.
        $allocated = $receipts->filter(fn ($receipt): bool => $allocations->has($receipt->id));
        $patients = DB::table('patients')->where('organisation_id', $organisation)->whereIn('id', $allocated->pluck('patient_id'))->get(['id', 'patient_number', 'full_name'])->keyBy('id');
        $result = [];
        foreach ($receipts as $receipt) {
            $invoiceIds = ($allocations->get($receipt->id) ?? collect())->pluck('invoice_id')->unique();
            if ($invoiceIds->isEmpty()) {
                $result[$receipt->id] = ['—', 'Not shown (no invoice allocation)', '—', '—'];

                continue;
            }
            $linked = $invoices->only($invoiceIds->all())->sortBy('id');
            abort_unless($linked->count() === $invoiceIds->count() && $linked->every(fn ($i): bool => (int) $i->patient_id === $receipt->patient_id), 409, 'Receipt allocation evidence requires review.');
            $patient = $patients->get($receipt->patient_id);
            abort_unless($patient !== null, 409, 'Receipt identity evidence requires review.');
            $result[$receipt->id] = [(string) $patient->patient_number, (string) $patient->full_name, $linked->pluck('visit_number')->unique()->implode('; '), $linked->pluck('invoice_number')->unique()->implode('; ')];
        }

        return $result;
    }
}
