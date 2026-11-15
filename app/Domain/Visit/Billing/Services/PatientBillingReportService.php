<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ReportCsv;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Patient-row exception: Billing Detail only. No Insights or clinical-content authority is implied. */
class PatientBillingReportService
{
    public const VIEW = 'reports.patient_billing.view.branch';

    public const EXPORT = 'reports.patient_billing.export.branch';

    public const PAGE_SIZE = 20;

    public const MAX_DAYS = 31;

    private const INVOICE_COLUMNS = ['invoices.id', 'invoices.organisation_id', 'invoices.branch_id', 'invoices.total_sen', 'invoices.subtotal_sen', 'invoices.invoice_number', 'invoices.finalized_at'];

    public const BASIS = 'Branch-local invoice finalisation date; finalised non-void invoices only. Net invoice uses current adjustments. Settlement includes all currently linked receipts regardless of receipt date, less reversals. Panel means approved responsibility, not remittances. Due now excludes approved pay-later outstanding. Current patient names and current records at generation; not an issued snapshot. Method columns are net allocated receipts for this invoice cohort, not receipt-date collections.';

    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private FinancialLedger $ledger, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(User $actor, array $input = [], bool $run = false, bool $download = false): array
    {
        return DB::transaction(function () use ($actor, $input, $run, $download): array {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->is_active && $branch->organisation_id === $actor->organisation_id && DB::table('organisations')->where('id', $actor->organisation_id)->where('is_active', true)->exists(), 403);
            $actor = $this->authority->lock($actor, $branch, self::VIEW);
            $grants = $actor->getAllPermissions()->pluck('name');
            abort_unless($grants->contains(self::VIEW) && (! $download || $grants->contains(self::EXPORT)), 403);
            $options = $this->options($actor, $branch);
            $filters = $this->filters($input, $branch, $options, $run || $download);
            $base = ['branch' => ['id' => $branch->id, 'name' => $branch->name, 'timezone' => $branch->timezone], 'options' => $options, 'filters' => $filters, 'canExport' => $grants->contains(self::EXPORT), 'basis' => self::BASIS, 'result' => null];
            if (! $run && ! $download) {
                return $base;
            }
            $query = $this->query($actor, $branch, $filters);
            $count = (clone $query)->count();
            if ($download && $count > ReportCsv::MAX_ROWS) {
                throw ValidationException::withMessages(['export' => 'More than 5,000 invoices match. Narrow the filters before downloading.']);
            }
            $page = (int) $filters['page'];
            if ($page > max(1, (int) ceil($count / self::PAGE_SIZE))) {
                throw ValidationException::withMessages(['page' => 'This page is unavailable. Run the report again.']);
            }
            $totals = ['netSen' => 0, 'receivedSen' => 0, 'panelSen' => 0, 'deferredSen' => 0, 'dueSen' => 0];
            $methods = [];
            $rows = [];
            $position = 0;
            // Stream invoice evidence; totals cover the whole cohort without loading patient identities.
            foreach ((clone $query)->orderBy('invoices.id')->select(self::INVOICE_COLUMNS)->cursor() as $raw) {
                $invoice = (new Invoice)->newFromBuilder((array) $raw);
                $state = $this->ledger->state($invoice);
                $breakdown = $this->methodAmounts($invoice);
                foreach (['netSen' => 'total', 'receivedSen' => 'self_pay', 'panelSen' => 'panel', 'deferredSen' => 'deferred', 'dueSen' => 'due_now'] as $key => $source) {
                    $totals[$key] += $state[$source];
                }
                foreach ($breakdown as $method) {
                    $id = $method['id'];
                    $methods[$id] ??= ['id' => $id, 'code' => $method['code'], 'name' => $method['name'], 'amountSen' => 0];
                    $methods[$id]['amountSen'] += $method['amountSen'];
                }
            }
            // Fetch only the selected page's personal data; export has already passed the hard cap.
            $selected = (clone $query)->join('patients', 'patients.id', '=', 'invoices.patient_id')->join('visits', 'visits.id', '=', 'invoices.visit_id')
                ->orderBy('invoices.finalized_at')->orderBy('invoices.id')->select([...self::INVOICE_COLUMNS, 'patients.patient_number', 'patients.full_name', 'visits.visit_number']);
            if (! $download) {
                $selected->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE);
            }
            foreach ($selected->get() as $raw) {
                $invoice = (new Invoice)->newFromBuilder((array) $raw);
                $state = $this->ledger->state($invoice);
                $rows[] = ['number' => ($download ? 0 : ($page - 1) * self::PAGE_SIZE) + ++$position, 'finalizedAt' => CarbonImmutable::parse($raw->finalized_at, 'UTC')->setTimezone($branch->timezone)->format('Y-m-d H:i:s'), 'patientNumber' => $raw->patient_number, 'patientName' => $raw->full_name, 'visitNumber' => $raw->visit_number, 'invoiceNumber' => $raw->invoice_number, 'netSen' => $state['total'], 'receivedSen' => $state['self_pay'], 'panelSen' => $state['panel'], 'deferredSen' => $state['deferred'], 'dueSen' => $state['due_now'], 'methods' => $this->methodAmounts($invoice)];
            }
            usort($methods, fn ($a, $b) => [$a['code'], $a['id']] <=> [$b['code'], $b['id']]);
            $generated = now()->utc()->toIso8601String();
            $result = ['generatedAt' => $generated, 'count' => $count, 'page' => $page, 'pages' => max(1, (int) ceil($count / self::PAGE_SIZE)), 'totals' => $totals, 'methods' => $methods, 'rows' => $rows];
            $file = $download ? $this->csv($branch, $filters, $result) : [];
            $audit = $this->audit->record($download ? 'reports.patient_billing.exported' : 'reports.patient_billing.viewed', null, ['dataset' => 'billing_detail', 'branch_id' => $branch->id, 'from' => $filters['from'], 'to' => $filters['to'], 'time_from' => $filters['time_from'], 'time_to' => $filters['time_to'], 'doctors' => $filters['doctors'], 'methods' => $filters['methods'], 'visit_types' => $filters['visit_types'], 'page' => $download ? null : $page, 'row_count' => count($rows)], $actor);
            abort_if($audit === null, 503, 'Unable to record report access. Try again later.');

            return $download ? $file : [...$base, 'result' => $result];
        }, 3);
    }

    /** @return array<string,list<array{value:string,label:string}>> */
    private function options(User $actor, Branch $branch): array
    {
        // Historical checkout actors, including inactive doctors, keep old invoices filterable. No role-name authorization.
        $doctors = DB::table('consultation_checkouts as c')->join('users', 'users.id', '=', 'c.attending_doctor_user_id')
            ->where('c.organisation_id', $actor->organisation_id)->where('c.branch_id', $branch->id)->where('users.organisation_id', $actor->organisation_id)
            ->select('users.id', 'users.name')->distinct()->orderBy('users.name')->get()->map(fn ($r) => ['value' => (string) $r->id, 'label' => (string) $r->name])->values()->all();
        $methods = DB::table('payments')->join('payment_methods as m', 'm.id', '=', 'payments.payment_method_id')->where('payments.organisation_id', $actor->organisation_id)->where('payments.branch_id', $branch->id)
            ->where('m.organisation_id', $actor->organisation_id)->select('m.id', 'm.code', 'm.name')->distinct()->orderBy('m.code')->get()->map(fn ($r) => ['value' => (string) $r->id, 'label' => (string) $r->name])->values()->all();

        return ['doctors' => array_values($doctors), 'methods' => array_values($methods), 'visit_types' => [['value' => 'consultation', 'label' => 'Consultation'], ['value' => 'otc', 'label' => 'OTC']]];
    }

    /** @param array<string,mixed> $input
     * @param  array<string,list<array{value:string,label:string}>>  $options
     * @return array<string,mixed>
     */
    private function filters(array $input, Branch $branch, array $options, bool $required): array
    {
        $yesterday = CarbonImmutable::now($branch->timezone)->subDay()->toDateString();
        $input += ['from' => $yesterday, 'to' => $yesterday, 'time_from' => '00:00', 'time_to' => '23:59', 'doctors' => [], 'methods' => [], 'visit_types' => [], 'page' => 1];
        $filters = Validator::make($input, ['expected_branch_id' => [$required ? 'required' : 'sometimes', 'integer', 'in:'.$branch->id], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'time_from' => ['required', 'date_format:H:i'], 'time_to' => ['required', 'date_format:H:i', 'after_or_equal:time_from'], 'page' => ['required', 'integer', 'min:1'], 'doctors' => ['array', 'max:100'], 'methods' => ['array', 'max:100'], 'visit_types' => ['array', 'max:2'], 'treatment' => ['prohibited'], 'treatments' => ['prohibited'], 'branch' => ['prohibited']])->validate();
        foreach (['doctors', 'methods', 'visit_types'] as $key) {
            Validator::make($filters, [$key.'.*' => ['required', 'string', 'distinct', Rule::in(array_column($options[$key], 'value'))]])->validate();
        }
        if (CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Select no more than 31 local dates.']);
        }
        $filters['expected_branch_id'] = $branch->id;

        return $filters;
    }

    /** @param array<string,mixed> $filters */
    protected function query(User $actor, Branch $branch, array $filters): Builder
    {
        $query = DB::table('invoices')->where('invoices.organisation_id', $actor->organisation_id)->where('invoices.branch_id', $branch->id)->where('invoices.status', 'finalized')
            ->where('invoices.finalized_at', '>=', CarbonImmutable::parse($filters['from'], $branch->timezone)->startOfDay()->utc())
            ->where('invoices.finalized_at', '<', CarbonImmutable::parse($filters['to'], $branch->timezone)->addDay()->startOfDay()->utc());
        $query->whereRaw("to_char(invoices.finalized_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'HH24:MI') BETWEEN ? AND ?", [$branch->timezone, $filters['time_from'], $filters['time_to']]);
        if ($filters['doctors'] !== []) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('consultation_checkouts as c')->whereColumn('c.id', 'invoices.consultation_checkout_id')->whereIn('c.attending_doctor_user_id', $filters['doctors']));
        }
        if ($filters['visit_types'] !== []) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('visits as v')->whereColumn('v.id', 'invoices.visit_id')->whereIn('v.visit_type', $filters['visit_types']));
        }
        if ($filters['methods'] !== []) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->whereColumn('a.invoice_id', 'invoices.id')->where('p.status', 'posted')->whereIn('p.payment_method_id', $filters['methods']));
        }

        return $query;
    }

    /** @return list<array{id:int,code:string,name:string,amountSen:int}> */
    private function methodAmounts(Invoice $invoice): array
    {
        return array_values(DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->leftJoin('payment_reversals as r', 'r.payment_id', '=', 'p.id')->where('a.invoice_id', $invoice->id)->where('p.organisation_id', $invoice->organisation_id)->where('p.branch_id', $invoice->branch_id)
            ->groupBy('m.id', 'm.code', 'm.name')->orderBy('m.code')->selectRaw('m.id, m.code, m.name, SUM(a.amount_sen - COALESCE(r.amount_sen, 0)) as amount')
            ->get()->map(fn ($r) => ['id' => (int) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'amountSen' => (int) $r->amount])->values()->all());
    }

    /** @param array<string,mixed> $filters
     * @param  array<string,mixed>  $result
     * @return array{name:string,csv:string}
     */
    private function csv(Branch $branch, array $filters, array $result): array
    {
        $metadata = [['Report', 'Billing Detail'], ['Branch', $branch->name], ['Branch timezone', $branch->timezone], ['Period from', $filters['from']], ['Period to', $filters['to']], ['Local time from', $filters['time_from']], ['Local time to', $filters['time_to']], ['Doctor IDs', implode('; ', $filters['doctors']) ?: 'all'], ['Payment method IDs', implode('; ', $filters['methods']) ?: 'all'], ['Visit types', implode('; ', $filters['visit_types']) ?: 'all'], ['Generated at UTC', $result['generatedAt']], ['Currency', 'MYR'], ['Basis', self::BASIS], ['Rows', (string) $result['count']]];
        foreach (['netSen' => 'net invoice', 'receivedSen' => 'net patient receipts', 'panelSen' => 'approved Panel responsibility', 'deferredSen' => 'approved pay-later outstanding', 'dueSen' => 'due now'] as $key => $label) {
            $metadata[] = ['Total '.$label.' (MYR)', ReportCsv::money($result['totals'][$key])];
        }
        foreach ($result['methods'] as $method) {
            $metadata[] = ['Total net receipts '.$method['code'].' · '.$method['name'].' (MYR)', ReportCsv::money($method['amountSen'])];
        }
        $headings = ['Row', 'Local invoice finalisation date/time', 'KPOne patient number', 'Patient name (current)', 'Visit number', 'Invoice number', 'Net invoice (MYR)', 'Net patient receipts (MYR)', 'Approved Panel responsibility (MYR)', 'Approved pay-later outstanding (MYR)', 'Due now (MYR)'];
        foreach ($result['methods'] as $method) {
            $headings[] = 'Net receipt '.$method['code'].' · '.$method['name'].' (MYR)';
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            $values = [(string) $row['number'], $row['finalizedAt'], $row['patientNumber'], $row['patientName'], $row['visitNumber'], $row['invoiceNumber']];
            foreach (['netSen', 'receivedSen', 'panelSen', 'deferredSen', 'dueSen'] as $key) {
                $values[] = ReportCsv::money($row[$key]);
            }
            $amounts = array_column($row['methods'], 'amountSen', 'id');
            foreach ($result['methods'] as $method) {
                $values[] = ReportCsv::money($amounts[$method['id']] ?? 0);
            }
            $rows[] = $values;
        }

        return ['name' => 'billing-detail_'.$filters['from'].'_'.$filters['to'].'.csv', 'csv' => ReportCsv::encode($headings, $rows, $metadata)];
    }
}
