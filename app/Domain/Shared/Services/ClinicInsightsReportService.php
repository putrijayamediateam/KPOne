<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type InsightPeriod array{branch: Branch, start: CarbonImmutable, end: CarbonImmutable, previousStart: CarbonImmutable, previousEnd: CarbonImmutable}
 * @phpstan-type InsightPeriods array<int, InsightPeriod>
 * @phpstan-type VisitSample array{id: int, branch_id: int, registered_at: string, completed_at: string|null, status: string, priority: string, queued_at: string|null, called_at: string|null, encounter_id: int|null, checked_out_at: string|null}
 */
class ClinicInsightsReportService
{
    private const MAX_RANGE_DAYS = 366;

    /** @return list<array{code: string, name: string}> */
    public function branches(User $actor): array
    {
        return array_values(Branch::query()
            ->with('organisation:id,name')
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(static fn (Branch $branch): array => [
                'code' => $branch->code,
                'name' => $branch->organisation->name.' '.$branch->name,
            ])->all());
    }

    /** @return list<array{id: int, name: string, branch: string}> */
    public function doctors(User $actor): array
    {
        return array_values(DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('staff_profiles', 'staff_profiles.user_id', '=', 'users.id')
            ->leftJoin('staff_branch_assignments as assignments', function ($join): void {
                $join->on('assignments.staff_profile_id', '=', 'staff_profiles.id')
                    ->where('assignments.is_primary', true);
            })
            ->leftJoin('branches', 'branches.id', '=', 'assignments.branch_id')
            ->where('users.organisation_id', $actor->organisation_id)
            ->where('users.is_active', true)
            ->where('roles.name', 'resident_doctor')
            ->selectRaw('users.id, users.name, branches.name as branch_name')
            ->distinct()
            ->orderBy('users.name')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'branch' => (string) ($row->branch_name ?? ''),
            ])->all());
    }

    /** @return array<string, mixed> */
    public function report(
        User $actor,
        string $section,
        string $branchCode,
        string $from,
        string $to,
        ?int $doctorId,
    ): array {
        $branches = Branch::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        if ($branchCode !== 'all') {
            $branches = $branches->where('code', $branchCode)->values();
            abort_if($branches->isEmpty(), 404);
        }
        if ($doctorId !== null) {
            abort_unless(DB::table('users')
                ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('users.id', $doctorId)
                ->where('users.organisation_id', $actor->organisation_id)
                ->where('users.is_active', true)
                ->where('model_has_roles.model_type', User::class)
                ->where('roles.name', 'resident_doctor')
                ->exists(), 404);
        }

        $fromDate = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = CarbonImmutable::createFromFormat('!Y-m-d', $to);
        abort_if($fromDate === null || $toDate === null || $toDate->lt($fromDate), 422);
        abort_if($fromDate->diffInDays($toDate) >= self::MAX_RANGE_DAYS, 422, 'Insights date ranges cannot exceed one year.');

        $previousTo = $fromDate->subDay();
        $previousFrom = $previousTo->subDays($fromDate->diffInDays($toDate));
        $periods = $this->periods($branches, $fromDate, $toDate);
        $previousPeriods = $this->periods($branches, $previousFrom, $previousTo);

        $payload = match ($section) {
            'sales' => $this->sales($actor, $periods, $previousPeriods, $doctorId),
            'in-clinic' => $this->inClinic($actor, $periods, $previousPeriods, $doctorId),
            'payments' => $this->payments($actor, $periods, $doctorId),
            'inventory' => $this->inventory($actor, $branches, $periods),
            'patients' => $this->patients($actor, $periods, $fromDate, $toDate, $doctorId),
            default => abort(404),
        };

        return [
            'section' => $section,
            'branch' => $branchCode,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'previousFrom' => $previousFrom->toDateString(),
            'previousTo' => $previousTo->toDateString(),
            'doctor' => $doctorId,
            'report' => $payload,
        ];
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return InsightPeriods
     */
    private function periods(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $branches->mapWithKeys(static function (Branch $branch) use ($from, $to): array {
            $start = $from->setTimezone($branch->timezone)->startOfDay()->utc();
            $end = $to->setTimezone($branch->timezone)->addDay()->startOfDay()->utc();
            $days = $from->diffInDays($to) + 1;

            return [$branch->id => [
                'branch' => $branch,
                'start' => $start,
                'end' => $end,
                'previousStart' => $start->subDays($days),
                'previousEnd' => $start,
            ]];
        })->all();
    }

    /**
     * @param  InsightPeriods  $periods
     */
    private function scopePeriod(Builder $query, array $periods, string $dateColumn, string $branchColumn): void
    {
        if ($periods === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function (Builder $ranges) use ($periods, $dateColumn, $branchColumn): void {
            foreach ($periods as $branchId => $period) {
                $ranges->orWhere(fn (Builder $range) => $range
                    ->where($branchColumn, $branchId)
                    ->where($dateColumn, '>=', $period['start'])
                    ->where($dateColumn, '<', $period['end']));
            }
        });
    }

    /**
     * @param  InsightPeriods  $periods
     */
    private function invoiceQuery(User $actor, array $periods, ?int $doctorId = null): Builder
    {
        $query = DB::table('invoices')
            ->where('invoices.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized');
        $this->scopePeriod($query, $periods, 'invoices.finalized_at', 'invoices.branch_id');

        if ($doctorId !== null) {
            $query->whereExists(fn (Builder $checkout) => $checkout
                ->selectRaw('1')
                ->from('consultation_checkouts')
                ->whereColumn('consultation_checkouts.id', 'invoices.consultation_checkout_id')
                ->where('consultation_checkouts.attending_doctor_user_id', $doctorId));
        }

        return $query;
    }

    /**
     * @param  InsightPeriods  $periods
     * @param  InsightPeriods  $previous
     * @return array<string, mixed>
     */
    private function sales(User $actor, array $periods, array $previous, ?int $doctorId): array
    {
        $current = $this->invoiceQuery($actor, $periods, $doctorId);
        $prior = $this->invoiceQuery($actor, $previous, $doctorId);
        $total = (int) (clone $current)->sum('invoices.total_sen');
        $patientCount = (int) (clone $current)->distinct()->count('invoices.patient_id');
        $previousTotal = (int) (clone $prior)->sum('invoices.total_sen');
        $previousPatients = (int) (clone $prior)->distinct()->count('invoices.patient_id');

        $returning = DB::table('invoices')
            ->where('invoices.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized');
        $this->scopePeriod($returning, $periods, 'invoices.finalized_at', 'invoices.branch_id');
        if ($doctorId !== null) {
            $returning->whereExists(fn (Builder $checkout) => $checkout->selectRaw('1')->from('consultation_checkouts')
                ->whereColumn('consultation_checkouts.id', 'invoices.consultation_checkout_id')
                ->where('consultation_checkouts.attending_doctor_user_id', $doctorId));
        }
        $priorVisit = DB::table('visits as previous')
            ->selectRaw('1')
            ->whereColumn('previous.patient_id', 'invoices.patient_id')
            ->whereColumn('previous.id', '<>', 'invoices.visit_id')
            ->where('previous.organisation_id', $actor->organisation_id)
            ->where('previous.status', 'completed')
            ->whereColumn('previous.completed_at', '<', 'invoices.finalized_at');
        $returningTotal = (int) (clone $returning)->whereExists($priorVisit)->sum('invoices.total_sen');
        $newTotal = $total - $returningTotal;

        $rows = (clone $current)->get(['invoices.branch_id', 'invoices.finalized_at', 'invoices.total_sen']);
        $days = [];
        foreach ($rows as $row) {
            $branch = $periods[$row->branch_id]['branch'];
            $key = CarbonImmutable::parse($row->finalized_at, 'UTC')->setTimezone($branch->timezone)->toDateString();
            $days[$key] = ($days[$key] ?? 0) + (int) $row->total_sen;
        }

        $loadRankings = function (array $lineTypes) use ($actor, $periods, $doctorId): Collection {
            $query = DB::table('invoice_lines as lines')
                ->join('invoices', 'invoices.id', '=', 'lines.invoice_id')
                ->where('invoices.organisation_id', $actor->organisation_id)
                ->where('invoices.status', 'finalized')
                ->whereIn('lines.line_type', $lineTypes);
            $this->scopePeriod($query, $periods, 'invoices.finalized_at', 'invoices.branch_id');
            if ($doctorId !== null) {
                $query->whereExists(fn (Builder $checkout) => $checkout->selectRaw('1')->from('consultation_checkouts')
                    ->whereColumn('consultation_checkouts.id', 'invoices.consultation_checkout_id')
                    ->where('consultation_checkouts.attending_doctor_user_id', $doctorId));
            }

            return $query
                ->selectRaw('lines.line_type, lines.display_name as name, SUM(lines.line_total_sen) as sales_sen, SUM(lines.quantity) as units_sold, COUNT(DISTINCT invoices.patient_id) as patients')
                ->groupBy('lines.line_type', 'lines.display_name')
                ->orderByDesc('sales_sen')
                ->limit(101)
                ->get();
        };
        $serviceRows = $loadRankings(['consultation', 'service']);
        $medicineRows = $loadRankings(['medicine']);
        $mapRanking = static fn (object $row): array => [
            'name' => (string) data_get($row, 'name'),
            'salesSen' => (int) data_get($row, 'sales_sen'),
            'unitsSold' => (float) data_get($row, 'units_sold'),
            'patients' => (int) data_get($row, 'patients'),
        ];
        $limitRankingRows = fn (Collection $rows): array => [
            'rows' => $rows->take(100)->map($mapRanking)->values()->all(),
            'truncated' => $rows->count() > 100,
        ];
        $services = $limitRankingRows($serviceRows);
        $medicines = $limitRankingRows($medicineRows);

        $providersQuery = DB::table('invoices')
            ->join('consultation_checkouts', 'consultation_checkouts.id', '=', 'invoices.consultation_checkout_id')
            ->join('users', 'users.id', '=', 'consultation_checkouts.attending_doctor_user_id')
            ->where('invoices.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized');
        $this->scopePeriod($providersQuery, $periods, 'invoices.finalized_at', 'invoices.branch_id');
        if ($doctorId !== null) {
            $providersQuery->where('users.id', $doctorId);
        }
        $providers = $providersQuery
            ->selectRaw('users.name, SUM(invoices.total_sen) as sales_sen, COUNT(DISTINCT invoices.patient_id) as patients')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('sales_sen')
            ->limit(101)
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'salesSen' => (int) $row->sales_sen,
                'patients' => (int) $row->patients,
            ]);
        $providersTruncated = $providers->count() > 100;
        $providers = $providers->take(100)->values()->all();

        return [
            'kind' => 'sales',
            'summary' => [
                'totalSen' => $total,
                'newSen' => $newTotal,
                'returningSen' => $returningTotal,
                'patients' => $patientCount,
                'perPatientSen' => $patientCount === 0 ? 0 : (int) round($total / $patientCount),
                'previousTotalSen' => $previousTotal,
                'previousPatients' => $previousPatients,
                'previousPerPatientSen' => $previousPatients === 0 ? 0 : (int) round($previousTotal / $previousPatients),
            ],
            'dailyTrend' => collect($days)->sortKeys()->map(static fn (int $value, string $date): array => ['date' => $date, 'salesSen' => $value])->values()->all(),
            'services' => $services['rows'],
            'medicines' => $medicines['rows'],
            'rankingTruncated' => [
                'services' => $services['truncated'],
                'medicines' => $medicines['truncated'],
                'providers' => $providersTruncated,
            ],
            'packages' => [],
            'providers' => $providers,
        ];
    }

    /**
     * @param  InsightPeriods  $periods
     * @param  InsightPeriods  $previous
     * @return array<string, mixed>
     */
    private function inClinic(User $actor, array $periods, array $previous, ?int $doctorId): array
    {
        $currentVisits = $this->visitRows($actor, $periods, $doctorId);
        $previousVisits = $this->visitRows($actor, $previous, $doctorId);
        $now = CarbonImmutable::now('UTC');
        $currentTime = $this->visitTimeSummary($currentVisits, $periods, $now);
        $previousTime = $this->visitTimeSummary($previousVisits, $previous, $now);
        $daily = [];
        $hourly = array_fill(0, 24, 0);
        $priority = ['urgent' => 0, 'normal' => 0];
        $occupancy = [];
        foreach ($currentVisits as $visit) {
            $registered = CarbonImmutable::parse($visit['registered_at'], 'UTC')->setTimezone($periods[$visit['branch_id']]['branch']->timezone);
            $daily[$registered->toDateString()] = ($daily[$registered->toDateString()] ?? 0) + 1;
            $hourly[$registered->hour]++;
            $priority[$visit['priority'] === 'urgent' ? 'urgent' : 'normal']++;
            $weekday = (int) $registered->dayOfWeek;
            $occupancy[$weekday][$registered->hour] = ($occupancy[$weekday][$registered->hour] ?? 0) + 1;
        }

        return [
            'kind' => 'in-clinic',
            'time' => [
                'inClinic' => $this->compareTime($currentTime['inClinic'], $previousTime['inClinic']),
                'waiting' => $this->compareTime($currentTime['waiting'], $previousTime['waiting']),
                'serving' => $this->compareTime($currentTime['serving'], $previousTime['serving']),
            ],
            'dailyVisits' => collect($daily)->sortKeys()->map(static fn (int $count, string $date): array => ['date' => $date, 'patients' => $count])->values()->all(),
            'hourlyPatients' => array_map(static fn (int $hour, int $count): array => ['hour' => $hour, 'patients' => $count], array_keys($hourly), array_values($hourly)),
            'priority' => $priority,
            'doctorOccupancy' => $occupancy,
        ];
    }

    /**
     * @param  InsightPeriods  $periods
     * @return iterable<VisitSample>
     */
    private function visitRows(User $actor, array $periods, ?int $doctorId): iterable
    {
        $query = DB::table('visits')
            ->leftJoin('queue_entries', 'queue_entries.visit_id', '=', 'visits.id')
            ->leftJoin('consultation_checkouts', 'consultation_checkouts.visit_id', '=', 'visits.id')
            ->leftJoin('clinical_encounters', 'clinical_encounters.visit_id', '=', 'visits.id')
            ->where('visits.organisation_id', $actor->organisation_id)
            ->whereIn('visits.status', ['registered', 'completed']);
        $this->scopePeriod($query, $periods, 'visits.registered_at', 'visits.branch_id');
        if ($doctorId !== null) {
            $query->where('visits.assigned_doctor_user_id', $doctorId);
        }

        return $query->selectRaw('visits.id, visits.branch_id, visits.registered_at, visits.completed_at, visits.status, visits.priority, queue_entries.queued_at, queue_entries.called_at, clinical_encounters.id as encounter_id, MAX(consultation_checkouts.checked_out_at) as checked_out_at')
            ->groupBy('visits.id', 'visits.branch_id', 'visits.registered_at', 'visits.completed_at', 'visits.status', 'visits.priority', 'queue_entries.queued_at', 'queue_entries.called_at', 'clinical_encounters.id')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) data_get($row, 'id'),
                'branch_id' => (int) data_get($row, 'branch_id'),
                'registered_at' => (string) data_get($row, 'registered_at'),
                'completed_at' => data_get($row, 'completed_at') === null ? null : (string) data_get($row, 'completed_at'),
                'status' => (string) data_get($row, 'status'),
                'priority' => (string) data_get($row, 'priority'),
                'queued_at' => data_get($row, 'queued_at') === null ? null : (string) data_get($row, 'queued_at'),
                'called_at' => data_get($row, 'called_at') === null ? null : (string) data_get($row, 'called_at'),
                'encounter_id' => data_get($row, 'encounter_id') === null ? null : (int) data_get($row, 'encounter_id'),
                'checked_out_at' => data_get($row, 'checked_out_at') === null ? null : (string) data_get($row, 'checked_out_at'),
            ]);
    }

    /**
     * @param  iterable<VisitSample>  $visits
     * @param  InsightPeriods  $periods
     * @return array<string, array{average: int, longest: int, longestAt: string|null, trend: list<array{date: string, minutes: int}>}>
     */
    private function visitTimeSummary(iterable $visits, array $periods, CarbonImmutable $now): array
    {
        $visits = collect($visits);
        $values = ['inClinic' => [], 'waiting' => [], 'serving' => []];
        $encounterIds = $visits->pluck('encounter_id')->filter()->unique()->values();
        $holds = $encounterIds->isEmpty()
            ? collect()
            : DB::table('consultation_holds')
                ->whereIn('clinical_encounter_id', $encounterIds)
                ->get(['clinical_encounter_id', 'held_at', 'resumed_at'])
                ->groupBy('clinical_encounter_id');
        foreach ($visits as $visit) {
            $registered = CarbonImmutable::parse($visit['registered_at'], 'UTC');
            $completed = $visit['completed_at'] === null ? null : CarbonImmutable::parse($visit['completed_at'], 'UTC');
            $queued = $visit['queued_at'] === null ? null : CarbonImmutable::parse($visit['queued_at'], 'UTC');
            $called = $visit['called_at'] === null ? null : CarbonImmutable::parse($visit['called_at'], 'UTC');
            $checkout = $visit['checked_out_at'] === null ? null : CarbonImmutable::parse($visit['checked_out_at'], 'UTC');
            $timezone = $periods[$visit['branch_id']]['branch']->timezone;
            $registeredLocal = $registered->setTimezone($timezone);
            $registeredDate = $registeredLocal->toDateString();
            $isOngoingToday = $visit['status'] === 'registered'
                && $registeredDate === $now->setTimezone($timezone)->toDateString();
            $inClinicEnd = $completed ?? ($isOngoingToday ? $now : null);
            if ($inClinicEnd !== null) {
                $values['inClinic'][] = ['minutes' => $this->minutes($registered, $inClinicEnd), 'date' => $registeredDate];
            }
            $waitingEnd = $called ?? ($isOngoingToday && $queued !== null ? $now : null);
            if ($queued !== null && $waitingEnd !== null) {
                $values['waiting'][] = ['minutes' => $this->minutes($queued, $waitingEnd), 'date' => $registeredDate];
            }
            $servingEnd = $checkout ?? ($isOngoingToday && $called !== null ? $now : null);
            if ($called !== null && $servingEnd !== null) {
                $servingMinutes = $this->minutes($called, $servingEnd);
                foreach ($holds->get($visit['encounter_id'], collect()) as $hold) {
                    $heldAt = CarbonImmutable::parse((string) data_get($hold, 'held_at'), 'UTC');
                    $resumedAt = data_get($hold, 'resumed_at') === null
                        ? ($isOngoingToday ? $now : null)
                        : CarbonImmutable::parse((string) data_get($hold, 'resumed_at'), 'UTC');
                    if ($resumedAt === null) {
                        continue;
                    }
                    $servingMinutes = max(
                        0,
                        $servingMinutes - $this->minutes($heldAt, $resumedAt),
                    );
                }
                $values['serving'][] = ['minutes' => $servingMinutes, 'date' => $registeredDate];
            }
        }

        return array_map(static function (array $samples): array {
            $minutes = array_column($samples, 'minutes');
            $longest = $samples === [] ? null : collect($samples)->sortByDesc('minutes')->first();
            $byDate = [];
            foreach ($samples as $sample) {
                $byDate[$sample['date']][] = $sample['minutes'];
            }

            return [
                'average' => $minutes === [] ? 0 : (int) round(array_sum($minutes) / count($minutes)),
                'longest' => $minutes === [] ? 0 : max($minutes),
                'longestAt' => $longest['date'] ?? null,
                'trend' => array_values(collect($byDate)->sortKeys()->map(static fn (array $values, string $date): array => [
                    'date' => $date,
                    'minutes' => (int) round(array_sum($values) / count($values)),
                ])->all()),
            ];
        }, $values);
    }

    /**
     * @param  array{average: int, longest: int, longestAt: string|null, trend: list<array{date: string, minutes: int}>}  $current
     * @param  array{average: int, longest: int, longestAt: string|null, trend: list<array{date: string, minutes: int}>}  $previous
     * @return array{average: int, longest: int, longestAt: string|null, trend: list<array{date: string, minutes: int}>, previousAverage: int, previousLongest: int, previousLongestAt: string|null, previousTrend: list<array{date: string, minutes: int}>}
     */
    private function compareTime(array $current, array $previous): array
    {
        return [
            ...$current,
            'previousAverage' => $previous['average'],
            'previousLongest' => $previous['longest'],
            'previousLongestAt' => $previous['longestAt'],
            'previousTrend' => $previous['trend'],
        ];
    }

    private function minutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return max(0, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60));
    }

    /**
     * @param  InsightPeriods  $periods
     * @return array<string, mixed>
     */
    private function payments(User $actor, array $periods, ?int $doctorId): array
    {
        $invoices = $this->invoiceQuery($actor, $periods, $doctorId);
        $selfPay = (int) (clone $invoices)->whereExists(fn (Builder $visit) => $visit->selectRaw('1')->from('visits')
            ->whereColumn('visits.id', 'invoices.visit_id')->where('visits.coverage_type', 'self_pay'))->sum('invoices.total_sen');
        $panelSales = (int) (clone $invoices)->whereExists(fn (Builder $visit) => $visit->selectRaw('1')->from('visits')
            ->whereColumn('visits.id', 'invoices.visit_id')->where('visits.coverage_type', 'panel'))->sum('invoices.total_sen');
        $paid = DB::table('payments')
            ->join('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->where('payments.organisation_id', $actor->organisation_id)
            ->where('payments.status', 'posted')
            ->where('invoices.status', 'finalized');
        $this->scopePeriod($paid, $periods, 'payments.received_at', 'payments.branch_id');
        if ($doctorId !== null) {
            $paid->whereExists(fn (Builder $checkout) => $checkout->selectRaw('1')->from('consultation_checkouts')
                ->whereColumn('consultation_checkouts.id', 'invoices.consultation_checkout_id')
                ->where('consultation_checkouts.attending_doctor_user_id', $doctorId));
        }
        $methodRows = (clone $paid)->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->selectRaw('payments.method_snapshot as name, SUM(payment_allocations.amount_sen) as amount_sen')
            ->groupBy('payments.method_snapshot')
            ->orderByDesc('amount_sen')
            ->get()
            ->map(static fn (object $row): array => ['name' => (string) $row->name, 'amountSen' => (int) $row->amount_sen])
            ->all();
        $received = (int) (clone $paid)->sum('payment_allocations.amount_sen');
        $outstanding = DB::table('invoices')
            ->where('invoices.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized');
        $this->scopePeriod($outstanding, $periods, 'invoices.finalized_at', 'invoices.branch_id');
        if ($doctorId !== null) {
            $outstanding->whereExists(fn (Builder $checkout) => $checkout->selectRaw('1')->from('consultation_checkouts')
                ->whereColumn('consultation_checkouts.id', 'invoices.consultation_checkout_id')
                ->where('consultation_checkouts.attending_doctor_user_id', $doctorId));
        }
        $dueExpression = <<<'SQL'
            invoices.total_sen
            - COALESCE((SELECT SUM(ca.amount_sen) FROM coverage_allocations ca WHERE ca.invoice_id = invoices.id AND ca.status = 'approved'), 0)
            - COALESCE((SELECT SUM(pa.amount_sen) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE pa.invoice_id = invoices.id AND p.status = 'posted'), 0)
        SQL;
        $outstandingTotal = (int) ((clone $outstanding)->selectRaw("SUM({$dueExpression}) as due_sen")->value('due_sen') ?? 0);
        $outstandingCount = (clone $outstanding)->whereRaw("({$dueExpression}) > 0")->count();
        $panelRows = DB::table('coverage_allocations')
            ->join('invoices', 'invoices.id', '=', 'coverage_allocations.invoice_id')
            ->where('coverage_allocations.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized');
        $this->scopePeriod($panelRows, $periods, 'invoices.finalized_at', 'coverage_allocations.branch_id');
        if ($doctorId !== null) {
            $panelRows->whereExists(fn (Builder $checkout) => $checkout->selectRaw('1')->from('consultation_checkouts')
                ->whereColumn('consultation_checkouts.id', 'invoices.consultation_checkout_id')
                ->where('consultation_checkouts.attending_doctor_user_id', $doctorId));
        }

        return [
            'kind' => 'payments',
            'summary' => [
                'selfPaySalesSen' => $selfPay,
                'panelSalesSen' => $panelSales,
                'receivedSen' => $received,
                'outstandingSen' => max(0, $outstandingTotal),
                'outstandingInvoices' => $outstandingCount,
            ],
            'paymentMethods' => $methodRows,
            'panels' => $panelRows->selectRaw('coverage_allocations.panel_name_snapshot as name, SUM(coverage_allocations.amount_sen) as billed_sen, SUM(CASE WHEN coverage_allocations.status = ? THEN coverage_allocations.amount_sen ELSE 0 END) as approved_sen, SUM(CASE WHEN coverage_allocations.status = ? THEN coverage_allocations.amount_sen ELSE 0 END) as rejected_sen', ['approved', 'rejected'])
                ->groupBy('coverage_allocations.panel_name_snapshot')
                ->orderByDesc('billed_sen')
                ->limit(50)
                ->get()
                ->map(static fn (object $row): array => [
                    'name' => (string) $row->name,
                    'billedSen' => (int) $row->billed_sen,
                    'approvedSen' => (int) $row->approved_sen,
                    'rejectedSen' => (int) $row->rejected_sen,
                ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @param  InsightPeriods  $periods
     * @return array<string, mixed>
     */
    private function inventory(User $actor, Collection $branches, array $periods): array
    {
        $branchIds = $branches->pluck('id')->all();
        $balances = DB::table('inventory_stock_balances as balances')
            ->join('inventory_locations as locations', 'locations.id', '=', 'balances.inventory_location_id')
            ->join('inventory_skus as skus', 'skus.id', '=', 'balances.inventory_sku_id')
            ->join('inventory_items as items', 'items.id', '=', 'skus.inventory_item_id')
            ->leftJoin('inventory_reorder_levels as levels', function ($join): void {
                $join->on('levels.inventory_location_id', '=', 'balances.inventory_location_id')
                    ->on('levels.inventory_sku_id', '=', 'balances.inventory_sku_id');
            })
            ->where('balances.organisation_id', $actor->organisation_id)
            ->where('locations.organisation_id', $actor->organisation_id)
            ->whereIn('locations.branch_id', $branchIds)
            ->selectRaw('balances.inventory_location_id, balances.inventory_sku_id, skus.sku_code, items.generic_name, SUM(balances.quantity) as quantity, MAX(COALESCE(levels.reorder_level, 0)) as reorder_level')
            ->groupBy('balances.inventory_location_id', 'balances.inventory_sku_id', 'skus.sku_code', 'items.generic_name')
            ->get();
        $low = $balances->filter(static fn (object $row): bool => (float) $row->reorder_level > 0 && (float) $row->quantity <= (float) $row->reorder_level)->count();
        $expiryEnd = CarbonImmutable::now('UTC')->addDays(30)->toDateString();
        $expiring = DB::table('inventory_batches as batches')
            ->join('inventory_stock_balances as balances', 'balances.inventory_batch_id', '=', 'batches.id')
            ->join('inventory_locations as locations', 'locations.id', '=', 'balances.inventory_location_id')
            ->where('batches.organisation_id', $actor->organisation_id)
            ->whereIn('locations.branch_id', $branchIds)
            ->where('batches.status', 'available')
            ->where('batches.expiry_date', '>=', now()->toDateString())
            ->where('batches.expiry_date', '<=', $expiryEnd)
            ->where('balances.quantity', '>', 0)
            ->distinct('batches.id')
            ->count('batches.id');
        $stocktakeLoss = DB::table('stock_movements')
            ->join('inventory_locations as source_locations', 'source_locations.id', '=', 'stock_movements.source_location_id')
            ->where('stock_movements.organisation_id', $actor->organisation_id)
            ->where('stock_movements.movement_type', 'stocktake_loss')
            ->whereIn('source_locations.branch_id', $branchIds)
            ->tap(fn (Builder $query) => $this->scopePeriod($query, $periods, 'stock_movements.occurred_at', 'source_locations.branch_id'))
            ->sum('stock_movements.quantity');
        $damageAdjustments = DB::table('stock_movements')
            ->join('inventory_locations as source_locations', 'source_locations.id', '=', 'stock_movements.source_location_id')
            ->join('inventory_adjustments as adjustments', 'adjustments.public_id', '=', 'stock_movements.reference_public_id')
            ->where('stock_movements.organisation_id', $actor->organisation_id)
            ->where('stock_movements.movement_type', 'adjustment_out')
            ->where('stock_movements.reference_type', 'inventory_adjustment')
            ->where('adjustments.reason_code', 'damage')
            ->whereIn('source_locations.branch_id', $branchIds)
            ->tap(fn (Builder $query) => $this->scopePeriod($query, $periods, 'stock_movements.occurred_at', 'source_locations.branch_id'))
            ->sum('stock_movements.quantity');
        $wastage = (float) $stocktakeLoss + (float) $damageAdjustments;
        $medicines = DB::table('invoice_lines as lines')
            ->join('invoices', 'invoices.id', '=', 'lines.invoice_id')
            ->where('invoices.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized')
            ->where('lines.line_type', 'medicine');
        $this->scopePeriod($medicines, $periods, 'invoices.finalized_at', 'invoices.branch_id');

        return [
            'kind' => 'inventory',
            'summary' => [
                'lowStockItems' => $low,
                'expiringBatchesNext30Days' => $expiring,
                'wastageUnits' => $wastage,
                'inventoryValueAvailable' => false,
                'costOfInventorySoldAvailable' => false,
            ],
            'medicineSales' => $medicines->selectRaw('lines.display_name as name, SUM(lines.line_total_sen) as sales_sen, SUM(lines.quantity) as units_sold')
                ->groupBy('lines.display_name')
                ->orderByDesc('sales_sen')
                ->limit(100)
                ->get()
                ->map(static fn (object $row): array => ['name' => (string) $row->name, 'salesSen' => (int) $row->sales_sen, 'unitsSold' => (float) $row->units_sold])
                ->all(),
        ];
    }

    /**
     * @param  InsightPeriods  $periods
     * @return array<string, mixed>
     */
    private function patients(User $actor, array $periods, CarbonImmutable $from, CarbonImmutable $to, ?int $doctorId): array
    {
        $query = DB::table('visits')
            ->join('patients', 'patients.id', '=', 'visits.patient_id')
            ->where('visits.organisation_id', $actor->organisation_id)
            ->whereIn('visits.status', ['registered', 'completed']);
        $this->scopePeriod($query, $periods, 'visits.registered_at', 'visits.branch_id');
        if ($doctorId !== null) {
            $query->where('visits.assigned_doctor_user_id', $doctorId);
        }
        $patientRows = $query->selectRaw('patients.id, patients.date_of_birth, patients.sex, COUNT(visits.id) as visits')
            ->groupBy('patients.id', 'patients.date_of_birth', 'patients.sex')
            ->get();
        $ageBands = ['0-17' => 0, '18-25' => 0, '26-40' => 0, '41-59' => 0, '60+' => 0, 'Unknown' => 0];
        $sexCounts = ['male' => 0, 'female' => 0, 'other/unknown' => 0];
        $visitFrequency = ['Once' => 0, 'Twice' => 0, '3 times' => 0, '4+ times' => 0];
        $referenceDate = $to->endOfDay();
        foreach ($patientRows as $row) {
            $age = $row->date_of_birth === null ? null : CarbonImmutable::parse($row->date_of_birth)->diffInYears($referenceDate);
            $band = $age === null ? 'Unknown' : match (true) {
                $age <= 17 => '0-17',
                $age <= 25 => '18-25',
                $age <= 40 => '26-40',
                $age <= 59 => '41-59',
                default => '60+',
            };
            $ageBands[$band]++;
            $sexCounts[in_array($row->sex, ['male', 'female'], true) ? $row->sex : 'other/unknown']++;
            $frequency = (int) $row->visits;
            $visitFrequency[$frequency === 1 ? 'Once' : ($frequency === 2 ? 'Twice' : ($frequency === 3 ? '3 times' : '4+ times'))]++;
        }

        return [
            'kind' => 'patients',
            'summary' => [
                'patients' => $patientRows->count(),
                'visits' => (int) $patientRows->sum('visits'),
                'appointmentsAvailable' => false,
            ],
            'age' => $ageBands,
            'gender' => $sexCounts,
            'visitFrequency' => $visitFrequency,
            'walkInVsAppointment' => null,
        ];
    }
}
