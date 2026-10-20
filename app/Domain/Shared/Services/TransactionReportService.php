<?php

namespace App\Domain\Shared\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PostedReceiptProjection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Receipt rows are the owner's separate patient-level exception, never aggregate Insights. */
class TransactionReportService
{
    public const VIEW = 'reports.transactions.view.branch';

    public const EXPORT = 'reports.transactions.export.branch';

    public const PAGE_SIZE = 20;

    public const BASIS = 'Original receipt date in the branch timezone, not invoice finalisation. Whole receipt amounts use the same posted/reversed cohort as receipt reconciliation: original, recording-reversed and net are explicit. A recording reversal is not evidence of a cash refund; later reversals restate the original receipt date. Current patient names and governed classification; payment-method names are receipt snapshots. Not classified counts as Non-cash. One row per receipt, even if several invoice references exist. Unallocated receipts remain included for money reconciliation but patient identity is masked and no invoice/visit is invented. Visit-type filters select any linked invoice and keep the whole receipt; separately filtered cohorts can overlap. Current records at generation, not an issued snapshot. MYR, two decimals. Empty periods have no patient rows.';

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(User $actor, array $input = [], bool $run = false, bool $download = false): array
    {
        return DB::transaction(function () use ($actor, $input, $run, $download): array {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $branch = app(BranchAccessService::class)->activeBranch($actor);
            abort_unless($branch && $branch->is_active && DB::table('organisations')->where('id', $branch->organisation_id)->where('is_active', true)->exists(), 403);
            $actor = app(TransactionalActorAuthority::class)->lock($actor, $branch, self::VIEW);
            $grants = $actor->getAllPermissions()->pluck('name');
            abort_unless($grants->contains(self::VIEW) && (! $download || $grants->contains(self::EXPORT)), 403);
            $methods = PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->orderBy('name')->get(['id', 'code', 'name', 'classification'])->keyBy('id');
            $options = $methods->map(fn ($m): array => ['value' => (string) $m->id, 'label' => $m->name])->values()->all();
            $yesterday = CarbonImmutable::now($branch->timezone)->subDay()->toDateString();
            $filters = Validator::make($input + ['from' => $yesterday, 'to' => $yesterday, 'methods' => [], 'visit_types' => [], 'page' => 1], [
                'expected_branch_id' => [$run || $download ? 'required' : 'sometimes', 'integer', 'in:'.$branch->id], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
                'methods' => ['array', 'max:100'], 'methods.*' => ['string', 'distinct', Rule::in(array_column($options, 'value'))], 'visit_types' => ['array', 'max:2'], 'visit_types.*' => ['string', 'distinct', Rule::in(['consultation', 'otc'])], 'page' => ['required', 'integer', 'min:1'],
                'branch' => ['prohibited'], 'doctor' => ['prohibited'], 'treatment' => ['prohibited'], 'diagnosis' => ['prohibited'],
            ])->validate();
            if (CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) >= 31) {
                throw ValidationException::withMessages(['to' => 'Choose at most 31 local dates.']);
            }
            $filters['expected_branch_id'] = $branch->id;
            $base = ['title' => 'Transaction Detail', 'basis' => self::BASIS, 'endpoint' => '/reports/transactions', 'branch' => ['id' => $branch->id, 'name' => $branch->name, 'timezone' => $branch->timezone], 'canExport' => $grants->contains(self::EXPORT), 'options' => ['methods' => $options, 'visit_types' => [['value' => 'consultation', 'label' => 'Consultation'], ['value' => 'otc', 'label' => 'Medicine-only (OTC)']]], 'filters' => $filters, 'result' => null];
            if (! $run && ! $download) {
                return $base;
            }
            $query = $this->query($actor, $branch, $filters);
            $count = (clone $query)->count();
            // Count after filters, before personal-data preparation or streaming.
            if ($download && $count > ReportCsv::MAX_ROWS) {
                throw ValidationException::withMessages(['export' => 'More than 5,000 receipts match. Narrow the filters.']);
            }
            $page = (int) $filters['page'];
            $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
            if (! $download && $page > $pages) {
                throw ValidationException::withMessages(['page' => 'This page is unavailable. Run the report again.']);
            }
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
            $totals = array_map(ReportCsv::money(...), $sums);
            $selected = (clone $query)->orderBy('received_at')->orderBy('id');
            if (! $download) {
                $selected->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE);
            }
            $columns = ['Row', 'Local receipt date/time', 'KPOne patient number', 'Patient name (current)', 'Visit number(s)', 'Invoice number(s)', 'Payment method (receipt snapshot)', 'Classification (current)', 'Original receipt (MYR)', 'Reversed (MYR)', 'Net received (MYR)', 'Status'];
            if ($download) {
                array_splice($columns, 6, 0, ['Payment method code']);
            }
            $rows = [];
            $position = $download ? 0 : ($page - 1) * self::PAGE_SIZE;
            foreach ($selected->get(['id', 'organisation_id', 'branch_id', 'patient_id', 'payment_method_id', 'received_at', 'method_snapshot', 'amount_sen', 'status']) as $receipt) {
                $method = $methods->get($receipt->payment_method_id);
                abort_unless($method !== null, 409, 'Receipt method evidence requires review.');
                [$number, $name, $visits, $invoices] = $this->identity($receipt);
                $amounts = PostedReceiptProjection::amounts($receipt->amount_sen, $receipt->status);
                $classification = $method->classification === null ? 'Not classified (Non-cash)' : ($method->reportClassification() === 'cash' ? 'Cash' : 'Non-cash');
                $row = [(string) ++$position, $receipt->received_at->setTimezone($branch->timezone)->format('Y-m-d H:i:s'), $number, $name, $visits, $invoices, $receipt->method_snapshot, $classification, ReportCsv::money($amounts['receiptsSen']), ReportCsv::money($amounts['reversedSen']), ReportCsv::money($amounts['netSen']), $receipt->status === 'posted' ? 'Posted' : 'Reversed (recording reversal)'];
                if ($download) {
                    array_splice($row, 6, 0, [$method->code]);
                }
                $rows[] = $row;
            }
            $generated = now()->utc()->toIso8601String();
            $file = null;
            if ($download) {
                $metadata = [['Report', 'Transaction Detail'], ['Branch', $branch->name], ['Timezone', $branch->timezone], ['From', $filters['from']], ['To', $filters['to']], ['Generated at UTC', $generated], ['Currency', 'MYR'], ['Basis', self::BASIS], ['Payment method IDs', implode('; ', $filters['methods']) ?: 'all'], ['Visit types', implode('; ', $filters['visit_types']) ?: 'all'], ['Rows', (string) $count]];
                foreach ($totals as $label => $value) {
                    $metadata[] = ['Total '.$label, $value];
                }
                $file = ['name' => 'transactions_'.$filters['from'].'_'.$filters['to'].'.csv', 'csv' => ReportCsv::encode($columns, $rows, $metadata)];
            }
            abort_if(app(AuditRecorder::class)->record('reports.transactions'.($download ? '.exported' : '.viewed'), null, ['dataset' => 'transactions', 'branch_id' => $branch->id, 'from' => $filters['from'], 'to' => $filters['to'], 'methods' => $filters['methods'], 'visit_types' => $filters['visit_types'], 'page' => $download ? null : $page, 'row_count' => count($rows)], $actor) === null, 503, 'Unable to audit report access. Try again later.');

            return $download ? $file : [...$base, 'result' => ['generatedAt' => $generated, 'count' => $count, 'page' => $page, 'pages' => $pages, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals]];
        }, 3);
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

    /** @return array{string,string,string,string} */
    private function identity(Payment $receipt): array
    {
        $ids = DB::table('payment_allocations')->where('organisation_id', $receipt->organisation_id)->where('branch_id', $receipt->branch_id)->where('payment_id', $receipt->id)->pluck('invoice_id')->unique();
        if ($ids->isEmpty()) {
            return ['—', 'Not shown (no invoice allocation)', '—', '—'];
        }
        $invoices = DB::table('invoices as i')->join('visits as v', fn ($join) => $join->on('v.id', '=', 'i.visit_id')->on('v.organisation_id', '=', 'i.organisation_id')->on('v.branch_id', '=', 'i.branch_id'))
            ->where('i.organisation_id', $receipt->organisation_id)->where('i.branch_id', $receipt->branch_id)->whereIn('i.id', $ids)->orderBy('i.id')->get(['i.patient_id', 'i.invoice_number', 'v.visit_number']);
        abort_unless($invoices->count() === $ids->count() && $invoices->every(fn ($i): bool => (int) $i->patient_id === $receipt->patient_id), 409, 'Receipt allocation evidence requires review.');
        $patient = DB::table('patients')->where('organisation_id', $receipt->organisation_id)->where('id', $receipt->patient_id)->first(['patient_number', 'full_name']);
        abort_unless($patient !== null, 409, 'Receipt identity evidence requires review.');

        return [(string) $patient->patient_number, (string) $patient->full_name, $invoices->pluck('visit_number')->unique()->implode('; '), $invoices->pluck('invoice_number')->unique()->implode('; ')];
    }
}
