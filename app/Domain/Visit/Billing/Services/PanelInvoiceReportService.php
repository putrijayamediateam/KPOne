<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Panel invoices: finalized Invoices with an approved Panel responsibility, for one Panel and a date range,
 * with a CSV download. Branch-scoped. No clinical text is returned; the Treatment filter only narrows rows
 * and is available to actors holding panel.claims.clinical_filters.branch.
 */
final class PanelInvoiceReportService
{
    public const PERMISSION = 'panel.invoices.export.branch';

    public const MAX_ROWS = 5000;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly BranchAccessService $branches,
        private readonly TransactionalActorAuthority $authority,
        private readonly AuditRecorder $audit,
        private readonly PanelClaimDirectoryService $directory,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input, int $page): array
    {
        return DB::transaction(function () use ($actor, $input, $page): array {
            [$actor, $branch] = $this->context($actor);
            $today = CarbonImmutable::now($branch->timezone);
            $filters = $this->filters($input, $today);
            $panels = DB::table('panels')->where('organisation_id', $actor->organisation_id)->orderBy('name')->pluck('name')->map(fn ($n): string => (string) $n)->all();
            $base = [
                'branch' => ['id' => $branch->id, 'name' => $branch->name],
                'filters' => $filters['echo'],
                'panels' => $panels,
                'can' => ['clinicalFilters' => $actor->can('panel.claims.clinical_filters.branch')],
                'maxRows' => self::MAX_ROWS,
                'downloadUrl' => null,
            ];
            if (! $filters['ready']) {
                return $base + ['ready' => false, 'rows' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1, 'truncated' => false];
            }
            [$rows, $truncated] = $this->rows($actor, $branch, $filters);
            $total = count($rows);
            $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
            $current = min(max(1, $page), $lastPage);

            return $base + [
                'ready' => true,
                'rows' => array_slice($rows, ($current - 1) * self::PER_PAGE, self::PER_PAGE),
                'total' => $total, 'currentPage' => $current, 'lastPage' => $lastPage, 'truncated' => $truncated,
                'downloadUrl' => route('panel-invoices.download', $filters['query']),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: list<list<string>>, 2: int}
     */
    public function csv(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            [$actor, $branch] = $this->context($actor);
            $filters = $this->filters($input, CarbonImmutable::now($branch->timezone));
            if (! $filters['ready']) {
                throw ValidationException::withMessages(['panel' => 'Choose a date range and a Panel before downloading.']);
            }
            [$rows] = $this->rows($actor, $branch, $filters);
            // Filters and counts only: the rows themselves never enter the audit trail.
            $this->audit->record('panel_invoices.exported', null, [
                'row_count' => count($rows),
                'from' => $filters['echo']['from'], 'to' => $filters['echo']['to'],
                'panel' => $filters['echo']['panel'], 'visit_type' => $filters['echo']['visitType'],
                'time_filter' => $filters['echo']['timeFrom'] !== '' || $filters['echo']['timeTo'] !== '',
                'doctor_filter' => $filters['echo']['doctor'] !== '',
                'treatment_filter' => $filters['echo']['treatment'] !== '' && $actor->can('panel.claims.clinical_filters.branch'),
            ], $actor, $branch);

            $table = [['Invoice number', 'Visit number', 'Visit date', 'Visit time', 'Patient name', 'Member reference', 'Panel', 'Doctor', 'Visit type', 'Invoice total (RM)', 'Panel amount (RM)', 'Claim number', 'Claim status']];
            foreach ($rows as $row) {
                $table[] = [
                    $row['invoiceNumber'], $row['visitNumber'], $row['visitDate'], $row['visitTime'], $row['patientName'],
                    $row['memberReference'], $row['panelName'], $row['doctorName'], $row['visitType'],
                    number_format($row['invoiceTotalSen'] / 100, 2, '.', ''), number_format($row['panelAmountSen'] / 100, 2, '.', ''),
                    $row['claimNo'], $row['claimStatus'],
                ];
            }

            return ['panel-invoices-'.CarbonImmutable::now($branch->timezone)->format('Ymd').'.csv', $table, count($rows)];
        });
    }

    /** A spreadsheet reads a leading = + - @ as a formula, so such cells are neutralised. */
    public static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function rows(User $actor, Branch $branch, array $filters): array
    {
        $zone = $branch->timezone;
        $query = DB::table('coverage_allocations as c')
            ->join('invoices as i', 'i.id', '=', 'c.invoice_id')
            ->join('visits as v', 'v.id', '=', 'i.visit_id')
            ->join('patients as p', 'p.id', '=', 'i.patient_id')
            ->leftJoin('users as d', function ($join): void {
                $join->on('d.id', '=', 'v.assigned_doctor_user_id')->on('d.organisation_id', '=', 'v.organisation_id');
            })
            ->leftJoin('panel_claims as pc', 'pc.coverage_allocation_id', '=', 'c.id')
            ->where('c.organisation_id', $actor->organisation_id)->where('c.branch_id', $branch->id)
            ->where('c.status', 'approved')
            ->whereColumn('c.current_invoice_guard', 'i.id')->whereColumn('c.invoice_id', 'i.id')
            ->whereColumn('i.organisation_id', 'c.organisation_id')->whereColumn('i.branch_id', 'c.branch_id')
            ->whereColumn('v.organisation_id', 'i.organisation_id')->whereColumn('v.branch_id', 'i.branch_id')
            ->whereColumn('p.organisation_id', 'i.organisation_id')->whereColumn('p.id', 'i.patient_id')
            ->where('i.status', 'finalized')
            ->where('c.panel_name_snapshot', $filters['echo']['panel'])
            ->where('v.registered_at', '>=', $filters['fromAt']->utc()->toDateTimeString())
            ->where('v.registered_at', '<=', $filters['toAt']->utc()->toDateTimeString());
        if ($filters['echo']['visitType'] !== '') {
            $query->where('v.visit_type', $filters['echo']['visitType']);
        }
        if ($filters['echo']['doctor'] !== '') {
            $query->whereRaw('lower(d.name) like ?', [$this->directory->likeTerm($filters['echo']['doctor'])]);
        }
        $this->directory->applyClinicalFilters($query, 'v.id', $actor, $branch, ['treatment' => $filters['echo']['treatment']]);

        $found = $query->orderBy('v.registered_at')->orderBy('c.id')->limit(self::MAX_ROWS + 1)->get([
            'i.invoice_number', 'v.visit_number', 'v.visit_type', 'v.registered_at', 'p.full_name as patient_name',
            'c.member_reference', 'c.panel_name_snapshot', 'c.amount_sen', 'i.total_sen', 'd.name as doctor_name',
            'pc.id as claim_id', 'pc.status as claim_status',
        ]);
        $truncated = $found->count() > self::MAX_ROWS;
        $rows = [];
        foreach ($found->take(self::MAX_ROWS) as $row) {
            $at = CarbonImmutable::parse((string) $row->registered_at, 'UTC')->setTimezone($zone);
            $minutes = $at->hour * 60 + $at->minute;
            if (($filters['timeFromMinutes'] !== null && $minutes < $filters['timeFromMinutes']) || ($filters['timeToMinutes'] !== null && $minutes > $filters['timeToMinutes'])) {
                continue;
            }
            $rows[] = [
                'invoiceNumber' => (string) $row->invoice_number, 'visitNumber' => (string) $row->visit_number,
                'visitDate' => $at->format('j M Y'), 'visitTime' => $at->format('g:i A'),
                'patientName' => (string) $row->patient_name, 'memberReference' => (string) ($row->member_reference ?? ''),
                'panelName' => (string) $row->panel_name_snapshot, 'doctorName' => (string) ($row->doctor_name ?? ''),
                'visitType' => $row->visit_type === 'otc' ? 'OTC' : 'Consultation',
                'invoiceTotalSen' => (int) $row->total_sen, 'panelAmountSen' => (int) $row->amount_sen,
                'claimNo' => $row->claim_id === null ? '' : (string) $row->claim_id,
                'claimStatus' => $row->claim_status === null ? 'No claim' : ucfirst(str_replace('_', ' ', (string) $row->claim_status)),
            ];
        }

        return [$rows, $truncated];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ready: bool, echo: array<string, string>, query: array<string, string>, fromAt: CarbonImmutable, toAt: CarbonImmutable, timeFromMinutes: int|null, timeToMinutes: int|null}
     */
    private function filters(array $input, CarbonImmutable $today): array
    {
        $clean = Validator::make($input, [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'time_from' => ['nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'], 'time_to' => ['nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'panel' => ['nullable', 'string', 'max:255'], 'doctor' => ['nullable', 'string', 'max:100'],
            'visit_type' => ['nullable', 'in:consultation,otc'], 'treatment' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $text = static fn (string $key): string => trim((string) ($clean[$key] ?? ''));
        $from = $text('from') === '' ? $today->startOfYear() : CarbonImmutable::createFromFormat('!Y-m-d', $text('from'), $today->getTimezone())->startOfDay();
        $to = $text('to') === '' ? $today->endOfYear() : CarbonImmutable::createFromFormat('!Y-m-d', $text('to'), $today->getTimezone())->endOfDay();
        $minutes = static fn (string $v): ?int => $v === '' ? null : ((int) substr($v, 0, 2)) * 60 + (int) substr($v, 3, 2);
        $echo = [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'timeFrom' => $text('time_from'), 'timeTo' => $text('time_to'),
            'panel' => $text('panel'), 'doctor' => $text('doctor'), 'visitType' => $text('visit_type'), 'treatment' => $text('treatment'),
        ];
        $query = array_filter([
            'from' => $echo['from'], 'to' => $echo['to'], 'time_from' => $echo['timeFrom'], 'time_to' => $echo['timeTo'],
            'panel' => $echo['panel'], 'doctor' => $echo['doctor'], 'visit_type' => $echo['visitType'], 'treatment' => $echo['treatment'],
        ], static fn (string $v): bool => $v !== '');

        return [
            'ready' => $echo['panel'] !== '', 'echo' => $echo, 'query' => $query, 'fromAt' => $from, 'toAt' => $to,
            'timeFromMinutes' => $minutes($text('time_from')), 'timeToMinutes' => $minutes($text('time_to')),
        ];
    }

    /** @return array{0: User, 1: Branch} */
    private function context(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $actor = $this->authority->lock($actor, $branch, self::PERMISSION);

        return [$actor, $branch];
    }
}
