<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type EligibleAllocationRow object{
 *     allocation_id: string,
 *     amount_sen: int|string,
 *     panel_name_snapshot: string,
 *     invoice_number: string,
 *     invoice_total_sen: int|string,
 *     visit_number: string,
 *     visit_type: string,
 *     registered_at: string,
 *     reason: string,
 *     claim_due_days: int|string,
 *     claim_due_rule: string,
 *     claim_due_time: string|null,
 *     claim_due_offset: int|string|null,
 *     patient_name: string,
 *     doctor_name: string|null
 * }
 */
final class PanelClaimDirectoryService
{
    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    private const SUBMITTED = ['prepared', 'submitted', 'queried'];

    private const APPROVED = ['accepted', 'partially_accepted', 'partially_paid'];

    private const TABS = ['all', 'pending', 'overdue', 'submitted', 'approved', 'rejected', 'received'];

    private const INVOICE_TABS = ['pending', 'overdue'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function search(User $actor, int $allocationsPage, int $claimsPage, array $filters = []): array
    {
        return DB::transaction(function () use ($actor, $allocationsPage, $claimsPage, $filters): array {
            [$actor, $branch] = $this->context($actor);
            $today = CarbonImmutable::now($branch->timezone);
            [$from, $to, $range] = $this->dateWindow($filters, $today);
            $base = fn () => $this->claimBase($actor, $branch, $from, $to);
            $invoices = fn (?string $state) => $this->eligibleQuery($actor, $branch, $filters, $today, $from, $to, $state);
            $tab = in_array($filters['tab'] ?? 'all', self::TABS, true) ? (string) ($filters['tab'] ?? 'all') : 'all';
            $onInvoices = in_array($tab, self::INVOICE_TABS, true);

            $eligible = $invoices($onInvoices ? $tab : null)
                ->orderBy('v.registered_at')->orderBy('c.id')
                ->paginate(20, ['*'], 'allocations_page', $allocationsPage);
            $claims = $this->applyListFilters($base(), $filters, $onInvoices ? 'all' : $tab)
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'claims_page', $claimsPage);
            $paymentRemarks = $this->latestPaymentReferences(array_values(array_map(static fn ($id): int => (int) $id, $claims->getCollection()->pluck('id')->all())), $actor, $branch);
            $latest = $this->latestEvents(array_values(array_map(static fn ($id): int => (int) $id, $claims->getCollection()->pluck('id')->all())), $actor, $branch);

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name, 'today' => $today->toDateString()],
                'eligible' => [
                    'data' => $eligible->getCollection()->map(
                        /** @param EligibleAllocationRow $row */
                        fn ($row): array => $this->eligibleRow($row, $branch, $today),
                    )->all(),
                    'total' => $eligible->total(),
                    'currentPage' => $eligible->currentPage(),
                    'lastPage' => $eligible->lastPage(),
                ],
                'summary' => $this->summary($base, $invoices),
                'tabs' => $this->tabCounts($base, $invoices, $filters),
                'panels' => DB::table('panels')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->pluck('name')->all(),
                'filters' => [
                    'tab' => $tab,
                    'range' => $range,
                    'from' => $from?->toDateString(),
                    'to' => $to?->toDateString(),
                    'panel' => (string) ($filters['panel'] ?? ''),
                    'claimNo' => (string) ($filters['claim_no'] ?? ''),
                    'amountMin' => (string) ($filters['amount_min'] ?? ''),
                    'amountMax' => (string) ($filters['amount_max'] ?? ''),
                    'invoiceNo' => (string) ($filters['invoice_no'] ?? ''),
                    'name' => (string) ($filters['name'] ?? ''),
                    'doctor' => (string) ($filters['doctor'] ?? ''),
                    'visitType' => (string) ($filters['visit_type'] ?? ''),
                    'visitFrom' => (string) ($filters['visit_from'] ?? ''),
                    'visitTo' => (string) ($filters['visit_to'] ?? ''),
                    'dueFrom' => (string) ($filters['due_from'] ?? ''),
                    'dueTo' => (string) ($filters['due_to'] ?? ''),
                    'diagnosis' => $actor->can('panel.claims.clinical_filters.branch') ? (string) ($filters['diagnosis'] ?? '') : '',
                    'treatment' => $actor->can('panel.claims.clinical_filters.branch') ? (string) ($filters['treatment'] ?? '') : '',
                ],
                'can' => ['clinicalFilters' => $actor->can('panel.claims.clinical_filters.branch')],
                'claims' => [
                    'data' => $claims->getCollection()->map(fn (PanelClaim $claim): array => [
                        'publicId' => $claim->public_id,
                        'claimNo' => (string) $claim->id,
                        'patientName' => $claim->patient_name_snapshot,
                        'visitNumber' => $claim->visit_number,
                        'invoiceNumber' => $claim->invoice_number,
                        'panelName' => $claim->panel_name_snapshot,
                        'claimAmountSen' => $claim->claim_amount_sen,
                        'status' => $this->status($claim),
                        'createdAt' => $claim->created_at?->setTimezone($branch->timezone)->format('j F Y'),
                        'updatedBy' => $latest[$claim->id]['actor'] ?? null,
                        'remarks' => $paymentRemarks[$claim->id] ?? ($latest[$claim->id]['note'] ?? null),
                        'showUrl' => route('panel-claims.show', $claim),
                    ])->all(),
                    'total' => $claims->total(),
                    'currentPage' => $claims->currentPage(),
                    'lastPage' => $claims->lastPage(),
                ],
            ];
        });
    }

    /**
     * @param  EligibleAllocationRow  $row
     * @return array<string, mixed>
     */
    private function eligibleRow(object $row, Branch $branch, CarbonImmutable $today): array
    {
        $visitAt = CarbonImmutable::parse((string) $row->registered_at, 'UTC')->setTimezone($branch->timezone);
        $due = PanelClaimDueRule::dueAt($row, $visitAt);

        return [
            'allocationId' => $row->allocation_id,
            'patientName' => $row->patient_name,
            'visitNumber' => $row->visit_number,
            'invoiceNumber' => $row->invoice_number,
            'invoiceUrl' => route('billing.show', ['visit' => $row->visit_number]),
            'panelName' => $row->panel_name_snapshot,
            'amountSen' => (int) $row->amount_sen,
            'invoiceTotalSen' => (int) $row->invoice_total_sen,
            'visitType' => (string) $row->visit_type,
            'doctorName' => $row->doctor_name === null ? null : (string) $row->doctor_name,
            'visitDate' => $visitAt->format('j F Y'),
            'dueDate' => $due->format('j F Y'),
            'overdue' => PanelClaimDueRule::isOverdue($row, $due, $today),
            'remarkNote' => (string) $row->reason,
        ];
    }

    /**
     * Approved Panel allocations without a claim yet (the "invoices" Pending and Overdue lists).
     * $state: null = both, 'pending' = not yet due, 'overdue' = past the Panel's due date.
     *
     * @param  array<string, mixed>  $filters
     */
    private function eligibleQuery(User $actor, Branch $branch, array $filters, CarbonImmutable $today, ?CarbonImmutable $from, ?CarbonImmutable $to, ?string $state): Builder
    {
        $zone = $today->getTimezone();
        $dayStart = static fn (string $key): ?string => ($filters[$key] ?? '') === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters[$key], $zone)->startOfDay()->utc()->toDateTimeString();
        $dayEnd = static fn (string $key): ?string => ($filters[$key] ?? '') === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters[$key], $zone)->endOfDay()->utc()->toDateTimeString();
        $query = DB::table('coverage_allocations as c')
            ->join('invoices as i', 'i.id', '=', 'c.invoice_id')
            ->join('visits as v', 'v.id', '=', 'i.visit_id')
            ->join('patients as p', 'p.id', '=', 'i.patient_id')
            ->join('panels as pn', function ($join): void {
                $join->on('pn.id', '=', 'c.panel_id')->on('pn.organisation_id', '=', 'c.organisation_id');
            })
            ->leftJoin('users as d', function ($join): void {
                $join->on('d.id', '=', 'v.assigned_doctor_user_id')->on('d.organisation_id', '=', 'v.organisation_id');
            })
            ->where('c.organisation_id', $actor->organisation_id)
            ->where('c.branch_id', $branch->id)
            ->where('c.status', 'approved')
            ->whereColumn('c.current_invoice_guard', 'i.id')
            ->whereColumn('c.invoice_id', 'i.id')
            ->whereColumn('i.organisation_id', 'c.organisation_id')
            ->whereColumn('i.branch_id', 'c.branch_id')
            ->whereColumn('v.organisation_id', 'i.organisation_id')
            ->whereColumn('v.branch_id', 'i.branch_id')
            ->whereColumn('p.organisation_id', 'i.organisation_id')
            ->whereColumn('p.id', 'i.patient_id')
            ->where('i.status', 'finalized')
            ->where('i.correction_hold', false)
            ->where('i.source_stale', false)
            ->whereColumn('c.amount_sen', '<=', 'i.total_sen')
            ->whereNotExists(fn (Builder $existing) => $existing->selectRaw('1')
                ->from('panel_claims')
                ->whereColumn('panel_claims.coverage_allocation_id', 'c.id'))
            ->select([
                'c.public_id as allocation_id',
                'c.amount_sen',
                'c.panel_name_snapshot',
                'c.reason',
                'pn.claim_due_days',
                'pn.claim_due_rule',
                'pn.claim_due_time',
                'pn.claim_due_offset',
                'i.invoice_number',
                'i.total_sen as invoice_total_sen',
                'v.visit_number',
                'v.visit_type',
                'v.registered_at',
                'p.full_name as patient_name',
                'd.name as doctor_name',
            ]);
        if ($from !== null) {
            $query->where('v.registered_at', '>=', $from->utc()->toDateTimeString());
        }
        if ($to !== null) {
            $query->where('v.registered_at', '<=', $to->utc()->toDateTimeString());
        }
        if (($filters['panel'] ?? '') !== '') {
            $query->where('c.panel_name_snapshot', (string) $filters['panel']);
        }
        if (($filters['invoice_no'] ?? '') !== '') {
            $query->where('i.invoice_number', (string) $filters['invoice_no']);
        }
        if (($filters['name'] ?? '') !== '') {
            $query->whereRaw('lower(p.full_name) like ?', [$this->likeTerm((string) $filters['name'])]);
        }
        if (($filters['doctor'] ?? '') !== '') {
            $query->whereRaw('lower(d.name) like ?', [$this->likeTerm((string) $filters['doctor'])]);
        }
        if (in_array($filters['visit_type'] ?? '', ['consultation', 'otc'], true)) {
            $query->where('v.visit_type', (string) $filters['visit_type']);
        }
        if (($start = $dayStart('visit_from')) !== null) {
            $query->where('v.registered_at', '>=', $start);
        }
        if (($end = $dayEnd('visit_to')) !== null) {
            $query->where('v.registered_at', '<=', $end);
        }
        if (($filters['amount_min'] ?? '') !== '') {
            $query->where('c.amount_sen', '>=', (int) round(((float) $filters['amount_min']) * 100));
        }
        if (($filters['amount_max'] ?? '') !== '') {
            $query->where('c.amount_sen', '<=', (int) round(((float) $filters['amount_max']) * 100));
        }

        $this->applyClinicalFilters($query, 'v.id', $actor, $branch, $filters);

        $dueFrom = ($filters['due_from'] ?? '') === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['due_from'], $zone)->startOfDay();
        $dueTo = ($filters['due_to'] ?? '') === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['due_to'], $zone)->endOfDay();
        if ($state !== null || $dueFrom !== null || $dueTo !== null) {
            // Due dates depend on each Panel's rule (days, daily, weekly or monthly), so they are
            // worked out here and the query is narrowed to the matching allocations.
            $probe = clone $query;
            $probe->columns = null;
            $ids = [];
            foreach ($probe->select(['c.id as allocation_row_id', 'v.registered_at', 'pn.claim_due_days', 'pn.claim_due_rule', 'pn.claim_due_time', 'pn.claim_due_offset'])->get() as $row) {
                $due = PanelClaimDueRule::dueAt($row, CarbonImmutable::parse((string) $row->registered_at, 'UTC')->setTimezone($zone));
                $overdue = PanelClaimDueRule::isOverdue($row, $due, $today);
                if (($state === 'overdue' && ! $overdue) || ($state === 'pending' && $overdue)
                    || ($dueFrom !== null && $due->lt($dueFrom)) || ($dueTo !== null && $due->gt($dueTo))) {
                    continue;
                }
                $ids[] = (int) $row->allocation_row_id;
            }
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('c.id', $ids);
        }

        return $query;
    }

    private function likeTerm(string $value): string
    {
        return '%'.mb_strtolower($this->escapeLike($value)).'%';
    }

    /**
     * Diagnosis and Treatment filters read clinical content, so they are only applied for
     * actors holding panel.claims.clinical_filters.branch (owner decision 2026-10-13). They
     * narrow the list; no diagnosis or treatment text is returned.
     *
     * @param  Builder  $subject  a query whose visit id column is $visitIdColumn
     * @param  array<string, mixed>  $filters
     */
    private function applyClinicalFilters(Builder $subject, string $visitIdColumn, User $actor, Branch $branch, array $filters): void
    {
        if (! $actor->can('panel.claims.clinical_filters.branch')) {
            return;
        }
        if (($filters['diagnosis'] ?? '') !== '') {
            $term = $this->likeTerm((string) $filters['diagnosis']);
            $subject->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('clinical_encounters as ce')
                ->join('encounter_diagnoses as ed', function ($join): void {
                    $join->on('ed.clinical_encounter_id', '=', 'ce.id')->on('ed.organisation_id', '=', 'ce.organisation_id')->on('ed.branch_id', '=', 'ce.branch_id');
                })
                ->whereColumn('ce.visit_id', $visitIdColumn)
                ->where('ce.organisation_id', $actor->organisation_id)->where('ce.branch_id', $branch->id)
                ->where(fn (Builder $any) => $any->whereRaw('lower(ed.diagnosis_text) like ?', [$term])->orWhereRaw('lower(coalesce(ed.diagnosis_code, \'\')) like ?', [$term])));
        }
        if (($filters['treatment'] ?? '') !== '') {
            $term = $this->likeTerm((string) $filters['treatment']);
            $subject->where(fn (Builder $either) => $either
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('clinical_encounters as ce')
                    ->join('treatment_plans as tp', function ($join): void {
                        $join->on('tp.clinical_encounter_id', '=', 'ce.id')->on('tp.organisation_id', '=', 'ce.organisation_id')->on('tp.branch_id', '=', 'ce.branch_id');
                    })
                    ->join('treatment_plan_medicine_orders as mo', function ($join): void {
                        $join->on('mo.treatment_plan_id', '=', 'tp.id')->on('mo.organisation_id', '=', 'tp.organisation_id')->on('mo.branch_id', '=', 'tp.branch_id');
                    })
                    ->whereColumn('ce.visit_id', $visitIdColumn)
                    ->where('ce.organisation_id', $actor->organisation_id)->where('ce.branch_id', $branch->id)
                    ->where('mo.status', 'active')
                    ->whereRaw('lower(mo.medicine_name_snapshot) like ?', [$term]))
                ->orWhereExists(fn (Builder $q) => $q->selectRaw('1')->from('clinical_encounters as ce')
                    ->join('treatment_plans as tp', function ($join): void {
                        $join->on('tp.clinical_encounter_id', '=', 'ce.id')->on('tp.organisation_id', '=', 'ce.organisation_id')->on('tp.branch_id', '=', 'ce.branch_id');
                    })
                    ->join('treatment_plan_service_orders as so', function ($join): void {
                        $join->on('so.treatment_plan_id', '=', 'tp.id')->on('so.organisation_id', '=', 'tp.organisation_id')->on('so.branch_id', '=', 'tp.branch_id');
                    })
                    ->whereColumn('ce.visit_id', $visitIdColumn)
                    ->where('ce.organisation_id', $actor->organisation_id)->where('ce.branch_id', $branch->id)
                    ->where('so.status', 'active')
                    ->whereRaw('lower(so.service_name_snapshot) like ?', [$term])));
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null, 2: string}
     */
    private function dateWindow(array $filters, CarbonImmutable $today): array
    {
        $range = (string) ($filters['range'] ?? 'all');
        $week = $today->startOfWeek(CarbonImmutable::SUNDAY);

        return match ($range) {
            'yesterday' => [$today->subDay()->startOfDay(), $today->subDay()->endOfDay(), $range],
            'last_7_days' => [$today->subDays(6)->startOfDay(), $today->endOfDay(), $range],
            'last_30_days' => [$today->subDays(29)->startOfDay(), $today->endOfDay(), $range],
            'last_90_days' => [$today->subDays(89)->startOfDay(), $today->endOfDay(), $range],
            'this_week' => [$week, $week->addDays(6)->endOfDay(), $range],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth(), $range],
            'this_year' => [$today->startOfYear(), $today->endOfYear(), $range],
            'last_week' => [$week->subWeek(), $week->subDay()->endOfDay(), $range],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth(), $range],
            'custom' => [
                isset($filters['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['from'], $today->getTimezone())->startOfDay() : null,
                isset($filters['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['to'], $today->getTimezone())->endOfDay() : null,
                $range,
            ],
            default => [null, null, 'all'],
        };
    }

    /** @return \Illuminate\Database\Eloquent\Builder<PanelClaim> */
    private function claimBase(User $actor, Branch $branch, ?CarbonImmutable $from, ?CarbonImmutable $to)
    {
        $query = PanelClaim::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id);
        if ($from !== null) {
            $query->where('created_at', '>=', $from->utc());
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to->utc());
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<PanelClaim>  $query
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<PanelClaim>
     */
    private function applyListFilters($query, array $filters, string $tab)
    {
        match ($tab) {
            'submitted' => $query->whereIn('status', self::SUBMITTED),
            'approved' => $query->whereIn('status', self::APPROVED),
            'rejected' => $query->where('status', 'rejected'),
            'received' => $query->where('status', 'paid'),
            default => null,
        };
        if (($filters['panel'] ?? '') !== '') {
            $query->where('panel_name_snapshot', (string) $filters['panel']);
        }
        if (($filters['claim_no'] ?? '') !== '') {
            $query->where('id', (int) $filters['claim_no']);
        }
        if (($filters['invoice_no'] ?? '') !== '') {
            $query->where('invoice_number', (string) $filters['invoice_no']);
        }
        if (($filters['name'] ?? '') !== '') {
            $query->whereRaw('lower(patient_name_snapshot) like ?', [$this->likeTerm((string) $filters['name'])]);
        }
        if (($filters['amount_min'] ?? '') !== '') {
            $query->where('claim_amount_sen', '>=', (int) round(((float) $filters['amount_min']) * 100));
        }
        if (($filters['amount_max'] ?? '') !== '') {
            $query->where('claim_amount_sen', '<=', (int) round(((float) $filters['amount_max']) * 100));
        }

        return $query;
    }

    /**
     * @param  callable(): \Illuminate\Database\Eloquent\Builder<PanelClaim>  $base
     * @param  callable(?string): Builder  $invoices
     * @return array<string, array{count: int, amountSen: int}>
     */
    private function summary(callable $base, callable $invoices): array
    {
        $paid = '(select coalesce(sum(p.amount_sen), 0) from panel_claim_payments p where p.panel_claim_id = panel_claims.id)';
        $approved = $base()->whereIn('status', self::APPROVED);
        $received = $base()->where('status', 'paid');
        $rejected = $base()->where('status', 'rejected');
        $outstanding = $base()->whereIn('status', self::APPROVED)->whereRaw("coalesce(accepted_amount_sen, 0) - {$paid} > 0");
        $figure = static fn (Builder $query): array => ['count' => (clone $query)->count(), 'amountSen' => (int) (clone $query)->sum('c.amount_sen')];

        return [
            'pending' => $figure($invoices('pending')),
            'overdue' => $figure($invoices('overdue')),
            'approved' => ['count' => (clone $approved)->count(), 'amountSen' => (int) (clone $approved)->sum('accepted_amount_sen')],
            'received' => ['count' => (clone $received)->count(), 'amountSen' => (int) (clone $received)->sum('accepted_amount_sen')],
            'rejected' => ['count' => (clone $rejected)->count(), 'amountSen' => (int) (clone $rejected)->sum('claim_amount_sen')],
            'outstanding' => ['count' => (clone $outstanding)->count(), 'amountSen' => (int) (clone $outstanding)->selectRaw("coalesce(sum(coalesce(accepted_amount_sen, 0) - {$paid}), 0) as total")->value('total')],
        ];
    }

    /**
     * Tab counts follow every filter except the tab itself.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Builder<PanelClaim>  $base
     * @param  callable(?string): Builder  $invoices
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function tabCounts(callable $base, callable $invoices, array $filters): array
    {
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = in_array($tab, self::INVOICE_TABS, true)
                ? $invoices($tab)->count()
                : $this->applyListFilters($base(), $filters, $tab)->count();
        }

        return $counts;
    }

    /**
     * The Remarks column on claims shows the Panel payment reference once a remittance exists.
     *
     * @param  list<int>  $claimIds
     * @return array<int, string>
     */
    private function latestPaymentReferences(array $claimIds, User $actor, Branch $branch): array
    {
        if ($claimIds === []) {
            return [];
        }
        $remarks = [];
        $rows = DB::table('panel_claim_payments')
            ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->whereIn('panel_claim_id', $claimIds)->whereNotNull('reference')
            ->orderBy('received_at')->orderBy('id')
            ->get(['panel_claim_id', 'reference']);
        foreach ($rows as $row) {
            $remarks[(int) $row->panel_claim_id] = (string) $row->reference;
        }

        return $remarks;
    }

    /**
     * @param  list<int>  $claimIds
     * @return array<int, array{actor: string|null, note: string|null}>
     */
    private function latestEvents(array $claimIds, User $actor, Branch $branch): array
    {
        if ($claimIds === []) {
            return [];
        }
        $rows = DB::table('panel_claim_events')
            ->join('users', function ($join): void {
                $join->on('users.id', '=', 'panel_claim_events.actor_user_id')
                    ->on('users.organisation_id', '=', 'panel_claim_events.organisation_id');
            })
            ->where('panel_claim_events.organisation_id', $actor->organisation_id)
            ->where('panel_claim_events.branch_id', $branch->id)
            ->whereIn('panel_claim_events.panel_claim_id', $claimIds)
            ->orderBy('panel_claim_events.occurred_at')->orderBy('panel_claim_events.id')
            ->get(['panel_claim_events.panel_claim_id', 'panel_claim_events.event_type', 'panel_claim_events.note', 'panel_claim_events.occurred_at', 'users.name as actor_name']);
        $latest = [];
        foreach ($rows as $row) {
            $id = (int) $row->panel_claim_id;
            $latest[$id] ??= ['actor' => null, 'note' => null];
            $latest[$id]['actor'] = (string) $row->actor_name;
            if ($row->note !== null) {
                $latest[$id]['note'] = (string) $row->note;
            }
        }

        return $latest;
    }

    /** @return array<string, mixed> */
    public function createDetails(User $actor, CoverageAllocation $routeAllocation): array
    {
        return DB::transaction(function () use ($actor, $routeAllocation): array {
            [$actor, $branch] = $this->context($actor);
            $allocation = CoverageAllocation::query()->whereKey($routeAllocation->id)
                ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
            $invoice = Invoice::query()->whereKey($allocation->invoice_id)
                ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
            $visit = Visit::query()->whereKey($invoice->visit_id)
                ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
            abort_unless($allocation->status === 'approved' && (int) $allocation->current_invoice_guard === $invoice->id
                && $invoice->status === 'finalized' && ! $invoice->correction_hold && ! $invoice->source_stale
                && $allocation->amount_sen > 0 && $allocation->amount_sen <= $invoice->total_sen, 422);
            $existing = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->first();
            $patient = DB::table('patients')->where('id', $invoice->patient_id)
                ->where('organisation_id', $actor->organisation_id)->first(['full_name']);
            abort_unless($patient !== null, 422);

            return [
                'existingClaimUrl' => $existing === null ? null : route('panel-claims.show', $existing),
                'branch' => ['id' => $branch->id, 'today' => now()->setTimezone($branch->timezone)->toDateString()],
                'allocation' => [
                    'publicId' => $allocation->public_id, 'lockVersion' => $allocation->lock_version,
                    'invoiceLockVersion' => $invoice->lock_version, 'panelName' => $allocation->panel_name_snapshot,
                    'amountSen' => $allocation->amount_sen, 'patientName' => $patient->full_name,
                    'invoiceNumber' => $invoice->invoice_number, 'invoiceTotalSen' => $invoice->total_sen,
                    'visitDate' => $visit->registered_at->setTimezone($branch->timezone)->toDateString(),
                    'visitDateTime' => $visit->registered_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    'lines' => InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()
                        ->map(static fn (InvoiceLine $line): array => [
                            'id' => $line->id, 'name' => $line->display_name, 'quantity' => (string) $line->quantity,
                            'unitPriceSen' => $line->unit_price_sen, 'totalSen' => $line->line_total_sen,
                        ])->all(),
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    public function show(User $actor, PanelClaim $routeClaim): array
    {
        return DB::transaction(function () use ($actor, $routeClaim): array {
            [$actor, $branch] = $this->context($actor);
            $claim = PanelClaim::query()->whereKey($routeClaim->id)
                ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->with(['lines', 'payments', 'documents'])->firstOrFail();
            $paid = (int) $claim->payments->sum('amount_sen');
            $lineRows = $claim->lines->map(static fn ($line): array => [
                'number' => $line->line_number,
                'type' => $line->line_type,
                'name' => $line->display_name,
                'code' => $line->code_snapshot,
                'unit' => $line->unit_snapshot,
                'quantity' => (string) $line->quantity,
                'unitPriceSen' => $line->unit_price_sen,
                'totalSen' => $line->line_total_sen,
                'adjustment' => false,
            ])->all();
            if ($claim->claim_amount_sen > $claim->invoice_total_sen) {
                $adjustment = $claim->claim_amount_sen - $claim->invoice_total_sen;
                $lineRows[] = [
                    'number' => count($lineRows) + 1,
                    'type' => 'adjustment',
                    'name' => 'Panel claim adjustment',
                    'code' => 'PANEL-ADJUSTMENT',
                    'unit' => 'claim',
                    'quantity' => '1.000',
                    'unitPriceSen' => $adjustment,
                    'totalSen' => $adjustment,
                    'adjustment' => true,
                ];
            }
            $events = DB::table('panel_claim_events')
                ->join('users', function ($join): void {
                    $join->on('users.id', '=', 'panel_claim_events.actor_user_id')
                        ->on('users.organisation_id', '=', 'panel_claim_events.organisation_id');
                })
                ->where('panel_claim_events.panel_claim_id', $claim->id)
                ->where('panel_claim_events.organisation_id', $actor->organisation_id)
                ->where('panel_claim_events.branch_id', $branch->id)
                ->orderBy('panel_claim_events.occurred_at')->orderBy('panel_claim_events.id')
                ->get([
                    'panel_claim_events.event_type',
                    'panel_claim_events.previous_amount_sen',
                    'panel_claim_events.amount_sen',
                    'panel_claim_events.external_reference',
                    'panel_claim_events.note',
                    'panel_claim_events.occurred_at',
                    'users.name as actor_name',
                ])->map(fn (object $event): array => [
                    'type' => (string) $event->event_type,
                    'previousAmountSen' => $event->previous_amount_sen === null ? null : (int) $event->previous_amount_sen,
                    'amountSen' => $event->amount_sen === null ? null : (int) $event->amount_sen,
                    'externalReference' => $event->external_reference === null ? null : (string) $event->external_reference,
                    'note' => $event->note === null ? null : (string) $event->note,
                    'occurredAt' => CarbonImmutable::parse((string) $event->occurred_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    'actor' => (string) $event->actor_name,
                ])->all();
            $payments = $claim->payments->map(fn ($payment): array => [
                'amountSen' => $payment->amount_sen,
                'reference' => $payment->reference,
                'receivedAt' => $payment->received_at->setTimezone($branch->timezone)->format('j M Y'),
            ])->all();

            $panel = DB::table('panels')->where('id', $claim->panel_id)->where('organisation_id', $actor->organisation_id)->first(['claim_due_days', 'claim_due_rule', 'claim_due_time', 'claim_due_offset', 'verification_url']);
            $canSeeIdentity = $actor->can('panel.claims.patient_identity.view.branch');
            $patientRow = DB::table('patients')->where('id', $claim->patient_id)->where('organisation_id', $actor->organisation_id)->first(['mobile_phone']);
            $identifier = $canSeeIdentity
                ? DB::table('patient_identifiers')->where('patient_id', $claim->patient_id)->where('organisation_id', $actor->organisation_id)
                    ->whereNull('retired_at')->orderByDesc('id')->first(['identifier_type', 'normalized_value'])
                : null;
            if ($canSeeIdentity) {
                $this->audit->record('panel_claim.identity_viewed', $claim, ['record_version' => $claim->lock_version], $actor, $branch);
            }
            $submittedEvent = collect($events)->firstWhere('type', 'submitted');
            $lastEvent = collect($events)->last();
            $lastPayment = $claim->payments->last();
            $statusKey = $claim->status;
            $payoutBase = $claim->accepted_amount_sen ?? $claim->claim_amount_sen;
            $documents = $claim->documents->map(fn ($document): array => [
                'publicId' => $document->public_id,
                'name' => $document->original_name,
                'mimeType' => $document->mime_type,
                'sizeBytes' => $document->size_bytes,
                'uploadedAt' => $document->created_at?->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'url' => route('panel-claims.documents.show', [$claim, $document]),
            ])->all();

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name, 'today' => now()->setTimezone($branch->timezone)->toDateString()],
                'claim' => [
                    'publicId' => $claim->public_id,
                    'claimNo' => (string) $claim->id,
                    'lockVersion' => $claim->lock_version,
                    'status' => $this->status($claim),
                    'statusKey' => $statusKey,
                    'remarks' => $claim->remarks,
                    'submittedOn' => is_array($submittedEvent) ? $submittedEvent['occurredAt'] : null,
                    'updatedBy' => is_array($lastEvent) ? $lastEvent['actor'] : null,
                    'shareUrl' => route('panel-claims.show', $claim),
                    'invoiceUrl' => route('billing.show', ['visit' => $claim->visit_number]),
                    'dueRule' => PanelClaimDueRule::describe($panel ?? (object) []),
                    'verificationUrl' => $panel?->verification_url,
                    'receivedOn' => $lastPayment?->received_at->setTimezone($branch->timezone)->format('j F Y'),
                    'amountDueSen' => max(0, $payoutBase - $paid),
                    'identityVisible' => $canSeeIdentity,
                    'patientPhone' => $canSeeIdentity ? $patientRow?->mobile_phone : null,
                    'patientIdentifierType' => $identifier === null ? null : strtoupper((string) $identifier->identifier_type),
                    'patientIdentifier' => $identifier === null ? null : (string) $identifier->normalized_value,
                    'documents' => $documents,
                    'canEditRemarks' => in_array($claim->status, ['prepared', 'submitted', 'queried', 'rejected'], true),
                    'patientName' => $claim->patient_name_snapshot,
                    'memberReference' => $claim->member_reference,
                    'panelName' => $claim->panel_name_snapshot,
                    'branchName' => $claim->branch_name_snapshot,
                    'visitNumber' => $claim->visit_number,
                    'visitDate' => $claim->visit_date->format('j M Y'),
                    'invoiceNumber' => $claim->invoice_number,
                    'invoiceTotalSen' => $claim->invoice_total_sen,
                    'claimAmountSen' => $claim->claim_amount_sen,
                    'adjustmentSen' => max(0, $claim->claim_amount_sen - $claim->invoice_total_sen),
                    'acceptedAmountSen' => $claim->accepted_amount_sen,
                    'paidAmountSen' => $paid,
                    'outstandingSen' => max(0, ($claim->accepted_amount_sen ?? 0) - $paid),
                    'lines' => $lineRows,
                    'events' => $events,
                    'payments' => $payments,
                    'createdAt' => $claim->created_at?->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    'canEditAmount' => in_array($claim->status, ['prepared', 'submitted', 'queried', 'rejected'], true) && $paid === 0,
                    'canSubmit' => in_array($claim->status, ['prepared', 'queried', 'rejected'], true) && $paid === 0,
                    'canRecordQuery' => $claim->status === 'submitted' && $paid === 0,
                    'canRecordDecision' => in_array($claim->status, ['submitted', 'queried'], true) && $paid === 0,
                    'canRecordPayment' => in_array($claim->status, ['accepted', 'partially_accepted', 'partially_paid'], true)
                        && $claim->accepted_amount_sen !== null && $paid < $claim->accepted_amount_sen,
                ],
            ];
        });
    }

    /** Branch-scope check for claim attachments, without recording an identity view. */
    public function assertVisible(User $actor, PanelClaim $claim): void
    {
        [$actor, $branch] = $this->context($actor);
        abort_unless(PanelClaim::query()->whereKey($claim->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->exists(), 404);
    }

    /** @return array{User, Branch} */
    private function context(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $actor = $this->authority->lock($actor, $branch, 'panel.claims.manage.branch');

        return [$actor, $branch];
    }

    private function status(PanelClaim $claim): string
    {
        return match ($claim->status) {
            'prepared' => 'Prepared',
            'submitted' => 'Submitted',
            'queried' => 'Panel query',
            'accepted' => 'Accepted',
            'partially_accepted' => 'Partially accepted',
            'rejected' => 'Rejected',
            'partially_paid' => 'Partially paid',
            'paid' => 'Paid',
            default => 'Unknown',
        };
    }
}
