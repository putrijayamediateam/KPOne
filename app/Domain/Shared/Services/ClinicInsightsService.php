<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ClinicInsightsService
{
    /** @return list<array{code: string, name: string}> */
    public function todayBranches(User $actor): array
    {
        $branches = Branch::query()
            ->with('organisation:id,name')
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return array_values($branches->map(static fn (Branch $branch): array => [
            'code' => $branch->code,
            'name' => $branch->organisation->name.' '.$branch->name,
        ])->all());
    }

    /** @return array<string, mixed> */
    public function today(User $actor, string $branchCode = 'all'): array
    {
        $branches = Branch::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        if ($branchCode !== 'all') {
            $branches = $branches->where('code', $branchCode)->values();
            abort_if($branches->isEmpty(), 404);
        }

        $now = CarbonImmutable::now('UTC');
        $periods = $branches->mapWithKeys(function (Branch $branch) use ($now): array {
            $localTodayStart = $now->setTimezone($branch->timezone)->startOfDay();
            $todayStart = $localTodayStart->utc();
            $tomorrowStart = $localTodayStart->addDay()->utc();
            $yesterdayStart = $localTodayStart->subDay()->utc();

            return [$branch->id => [
                'branch' => $branch,
                'todayStart' => $todayStart,
                'tomorrowStart' => $tomorrowStart,
                'yesterdayStart' => $yesterdayStart,
            ]];
        })->all();

        $todaySales = $this->salesSummary($actor, $periods, 'today');
        $previousSales = $this->salesSummary($actor, $periods, 'yesterday');
        $salesTrend = $this->salesTrend($actor, $periods);
        $services = $this->rankings($actor, $periods, ['consultation', 'service']);
        $medicines = $this->rankings($actor, $periods, ['medicine']);
        $providers = $this->providerRankings($actor, $periods);
        $todayVisits = $this->visitSamples($actor, $periods, 'today', $now);
        $previousVisits = $this->visitSamples($actor, $periods, 'yesterday', $now);
        $todayTime = $this->timeSummary($todayVisits);
        $previousTime = $this->timeSummary($previousVisits);

        $todayPatientCount = $this->patientCount($actor, $periods, 'today');
        $previousPatientCount = $this->patientCount($actor, $periods, 'yesterday');

        return [
            'date' => $now->setTimezone($branches->first()->timezone ?? 'Asia/Kuala_Lumpur')->format('j M Y'),
            'generatedAt' => $now->toIso8601String(),
            'sales' => [
                'totalSen' => $todaySales['totalSen'],
                'newSen' => $todaySales['newSen'],
                'returningSen' => $todaySales['returningSen'],
                'patientCount' => $todayPatientCount,
                'averagePerPatientSen' => $todayPatientCount === 0 ? 0 : (int) round($todaySales['totalSen'] / $todayPatientCount),
                'previousTotalSen' => $previousSales['totalSen'],
                'previousPatientCount' => $previousPatientCount,
                'previousAveragePerPatientSen' => $previousPatientCount === 0 ? 0 : (int) round($previousSales['totalSen'] / $previousPatientCount),
            ],
            'salesTrend' => $salesTrend,
            'rankings' => [
                'services' => $services,
                'medicines' => $medicines,
                'packages' => [],
                'providers' => $providers,
            ],
            'time' => [
                'inClinic' => $this->timeComparison($todayTime['inClinic'], $previousTime['inClinic']),
                'waiting' => $this->timeComparison($todayTime['waiting'], $previousTime['waiting']),
                'serving' => $this->timeComparison($todayTime['serving'], $previousTime['serving']),
            ],
        ];
    }

    /**
     * @param  array<int, array{branch: Branch, todayStart: CarbonImmutable, tomorrowStart: CarbonImmutable, yesterdayStart: CarbonImmutable}>  $periods
     * @return array{totalSen: int, newSen: int, returningSen: int}
     */
    private function salesSummary(User $actor, array $periods, string $period): array
    {
        $query = DB::table('invoices')
            ->where('organisation_id', $actor->organisation_id)
            ->where('status', 'finalized');
        $this->applyPeriod($query, $periods, 'finalized_at', $period);
        $total = (int) $query->sum('total_sen');

        if ($period !== 'today') {
            return ['totalSen' => $total, 'newSen' => 0, 'returningSen' => 0];
        }

        $newSales = 0;
        $returningSales = 0;
        foreach ($periods as $branchId => $periodData) {
            $branchQuery = DB::table('invoices')
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branchId)
                ->where('status', 'finalized')
                ->where('finalized_at', '>=', $periodData['todayStart'])
                ->where('finalized_at', '<', $periodData['tomorrowStart']);

            $previouslyAttended = DB::table('visits as previous')
                ->selectRaw('1')
                ->whereColumn('previous.patient_id', 'invoices.patient_id')
                ->whereColumn('previous.id', '<>', 'invoices.visit_id')
                ->where('previous.organisation_id', $actor->organisation_id)
                ->where('previous.status', 'completed')
                ->whereColumn('previous.completed_at', '<', 'invoices.finalized_at');

            $returningSales += (int) (clone $branchQuery)->whereExists($previouslyAttended)->sum('total_sen');
            $newSales += (int) $branchQuery->whereNotExists($previouslyAttended)->sum('total_sen');
        }

        return ['totalSen' => $total, 'newSen' => $newSales, 'returningSen' => $returningSales];
    }

    /**
     * @param  array<int, array{branch: Branch, todayStart: CarbonImmutable, tomorrowStart: CarbonImmutable, yesterdayStart: CarbonImmutable}>  $periods
     * @return list<array{hour: int, salesSen: int}>
     */
    private function salesTrend(User $actor, array $periods): array
    {
        $invoices = DB::table('invoices')
            ->where('organisation_id', $actor->organisation_id)
            ->where('status', 'finalized')
            ->whereNotNull('finalized_at');
        $this->applyPeriod($invoices, $periods, 'finalized_at', 'today');

        $salesByHour = array_fill(0, 24, 0);
        foreach ($invoices->get(['branch_id', 'finalized_at', 'total_sen']) as $invoice) {
            $branch = $periods[$invoice->branch_id]['branch'];
            $hour = CarbonImmutable::parse($invoice->finalized_at, 'UTC')->setTimezone($branch->timezone)->hour;
            $salesByHour[$hour] += (int) $invoice->total_sen;
        }

        return array_map(
            static fn (int $hour, int $salesSen): array => ['hour' => $hour, 'salesSen' => $salesSen],
            array_keys($salesByHour),
            array_values($salesByHour),
        );
    }

    /**
     * @param  array<int, array{branch: Branch, todayStart: CarbonImmutable, tomorrowStart: CarbonImmutable, yesterdayStart: CarbonImmutable}>  $periods
     * @param  list<string>  $types
     * @return list<array{name: string, code: string, unit: string, salesSen: int, unitsSold: float, patients: int}>
     */
    private function rankings(User $actor, array $periods, array $types): array
    {
        $query = DB::table('invoice_lines as lines')
            ->join('invoices', 'invoices.id', '=', 'lines.invoice_id')
            ->where('invoices.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized')
            ->whereIn('lines.line_type', $types);
        $this->applyPeriod($query, $periods, 'invoices.finalized_at', 'today', 'invoices.branch_id');

        return array_values($query->selectRaw('lines.display_name as name, lines.code_snapshot as code, lines.unit_snapshot as unit')
            ->selectRaw('SUM(lines.line_total_sen) as sales_sen, SUM(lines.quantity) as units_sold, COUNT(DISTINCT invoices.patient_id) as patients')
            ->groupBy('lines.display_name', 'lines.code_snapshot', 'lines.unit_snapshot')
            ->orderByDesc('sales_sen')
            ->orderBy('name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'unit' => (string) $row->unit,
                'salesSen' => (int) $row->sales_sen,
                'unitsSold' => (float) $row->units_sold,
                'patients' => (int) $row->patients,
            ])->all());
    }

    /**
     * @param  array<int, array{branch: Branch, todayStart: CarbonImmutable, tomorrowStart: CarbonImmutable, yesterdayStart: CarbonImmutable}>  $periods
     * @return list<array{name: string, salesSen: int, patients: int}>
     */
    private function providerRankings(User $actor, array $periods): array
    {
        $query = DB::table('invoices')
            ->join('consultation_checkouts', 'consultation_checkouts.id', '=', 'invoices.consultation_checkout_id')
            ->join('users', 'users.id', '=', 'consultation_checkouts.attending_doctor_user_id')
            ->where('invoices.organisation_id', $actor->organisation_id)
            ->where('invoices.status', 'finalized');
        $this->applyPeriod($query, $periods, 'invoices.finalized_at', 'today', 'invoices.branch_id');

        return array_values($query->selectRaw('users.id, users.name, SUM(invoices.total_sen) as sales_sen, COUNT(DISTINCT invoices.patient_id) as patients')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('sales_sen')
            ->orderBy('users.name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'salesSen' => (int) $row->sales_sen,
                'patients' => (int) $row->patients,
            ])->all());
    }

    /**
     * @param  array<int, array{branch: Branch, todayStart: CarbonImmutable, tomorrowStart: CarbonImmutable, yesterdayStart: CarbonImmutable}>  $periods
     */
    private function patientCount(User $actor, array $periods, string $period): int
    {
        $query = DB::table('visits')
            ->where('organisation_id', $actor->organisation_id)
            ->whereIn('status', ['registered', 'completed']);
        $this->applyPeriod($query, $periods, 'registered_at', $period);

        return (int) $query->distinct()->count('patient_id');
    }

    /**
     * @param  array<int, array{branch: Branch, todayStart: CarbonImmutable, tomorrowStart: CarbonImmutable, yesterdayStart: CarbonImmutable}>  $periods
     * @return list<array{registeredAt: CarbonImmutable, timezone: string, inClinic: int|null, waiting: int|null, serving: int|null}>
     */
    private function visitSamples(User $actor, array $periods, string $period, CarbonImmutable $now): array
    {
        $query = DB::table('visits')
            ->leftJoin('queue_entries', 'queue_entries.visit_id', '=', 'visits.id')
            ->leftJoin('clinical_encounters', 'clinical_encounters.visit_id', '=', 'visits.id')
            ->leftJoin('consultation_checkouts', 'consultation_checkouts.current_visit_guard', '=', 'visits.id')
            ->where('visits.organisation_id', $actor->organisation_id)
            ->whereIn('visits.status', $period === 'today' ? ['registered', 'completed'] : ['completed']);
        $this->applyPeriod($query, $periods, 'visits.registered_at', $period, 'visits.branch_id');

        $visits = $query->get([
            'visits.id',
            'visits.branch_id',
            'visits.registered_at',
            'visits.completed_at',
            'queue_entries.queued_at',
            'queue_entries.called_at',
            'clinical_encounters.id as encounter_id',
            'consultation_checkouts.checked_out_at',
        ]);
        $encounterIds = $visits->pluck('encounter_id')->filter()->unique()->values();
        $holds = $encounterIds->isEmpty() ? collect() : DB::table('consultation_holds')
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->get(['clinical_encounter_id', 'held_at', 'resumed_at'])
            ->groupBy('clinical_encounter_id');

        return array_values($visits->map(function (object $visit) use ($periods, $holds, $now, $period): array {
            $timezone = $periods[$visit->branch_id]['branch']->timezone;
            $registeredAt = CarbonImmutable::parse($visit->registered_at, 'UTC');
            $completedAt = $visit->completed_at === null ? null : CarbonImmutable::parse($visit->completed_at, 'UTC');
            $inClinicEnd = $completedAt ?? ($period === 'today' ? $now : null);
            $inClinic = $inClinicEnd === null ? null : $this->minutesBetween($registeredAt, $inClinicEnd);

            $queuedAt = $visit->queued_at === null ? null : CarbonImmutable::parse($visit->queued_at, 'UTC');
            $calledAt = $visit->called_at === null ? null : CarbonImmutable::parse($visit->called_at, 'UTC');
            $waitingEnd = $calledAt ?? ($period === 'today' && $queuedAt !== null ? $now : null);
            $waiting = $queuedAt === null || $waitingEnd === null ? null : $this->minutesBetween($queuedAt, $waitingEnd);

            $checkedOutAt = $visit->checked_out_at === null ? null : CarbonImmutable::parse($visit->checked_out_at, 'UTC');
            $servingEnd = $checkedOutAt ?? ($period === 'today' && $calledAt !== null ? $now : null);
            $serving = $calledAt === null || $servingEnd === null ? null : $this->minutesBetween($calledAt, $servingEnd);
            if ($serving !== null) {
                foreach ($holds->get($visit->encounter_id, collect()) as $hold) {
                    $heldAt = CarbonImmutable::parse($hold->held_at, 'UTC');
                    $resumedAt = $hold->resumed_at === null
                        ? ($period === 'today' ? $now : null)
                        : CarbonImmutable::parse($hold->resumed_at, 'UTC');
                    if ($resumedAt !== null) {
                        $serving = max(0, $serving - $this->minutesBetween($heldAt, $resumedAt));
                    }
                }
            }

            return [
                'registeredAt' => $registeredAt,
                'timezone' => $timezone,
                'inClinic' => $inClinic,
                'waiting' => $waiting,
                'serving' => $serving,
            ];
        })->all());
    }

    /**
     * @param  list<array{registeredAt: CarbonImmutable, timezone: string, inClinic: int|null, waiting: int|null, serving: int|null}>  $visits
     * @return array{
     *     inClinic: array{average: int, longest: int, longestAt: string|null, trend: list<array{hour: int, minutes: int}>},
     *     waiting: array{average: int, longest: int, longestAt: string|null, trend: list<array{hour: int, minutes: int}>},
     *     serving: array{average: int, longest: int, longestAt: string|null, trend: list<array{hour: int, minutes: int}>}
     * }
     */
    private function timeSummary(array $visits): array
    {
        $result = [];
        foreach (['inClinic', 'waiting', 'serving'] as $metric) {
            $samples = array_values(array_filter($visits, static fn (array $visit): bool => $visit[$metric] !== null));
            $minutes = array_map(static fn (array $sample): int => $sample[$metric] ?? 0, $samples);
            $total = array_sum($minutes);
            $longestSample = null;
            foreach ($samples as $sample) {
                if ($longestSample === null || $sample[$metric] > $longestSample[$metric]) {
                    $longestSample = $sample;
                }
            }
            $hourGroups = array_fill(0, 24, []);
            foreach ($samples as $sample) {
                $hourGroups[$sample['registeredAt']->setTimezone($sample['timezone'])->hour][] = $sample[$metric];
            }

            $result[$metric] = [
                'average' => $minutes === [] ? 0 : (int) round($total / count($minutes)),
                'longest' => $longestSample === null ? 0 : (int) round($longestSample[$metric] ?? 0),
                'longestAt' => $longestSample === null
                    ? null
                    : $longestSample['registeredAt']->setTimezone($longestSample['timezone'])->format('j M, g:i A'),
                'trend' => array_map(
                    static fn (int $hour, array $values): array => ['hour' => $hour, 'minutes' => $values === [] ? 0 : (int) round(array_sum($values) / count($values))],
                    array_keys($hourGroups),
                    array_values($hourGroups),
                ),
            ];
        }

        return $result;
    }

    /**
     * @param  array{average: int, longest: int, longestAt: string|null, trend: list<array{hour: int, minutes: int}>}  $current
     * @param  array{average: int, longest: int, longestAt: string|null, trend: list<array{hour: int, minutes: int}>}  $previous
     * @return array<string, mixed>
     */
    private function timeComparison(array $current, array $previous): array
    {
        return [...$current, 'previousAverage' => $previous['average'], 'previousLongest' => $previous['longest']];
    }

    private function minutesBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return max(0, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60));
    }

    /**
     * @param  array<int, array{branch: Branch, todayStart: CarbonImmutable, tomorrowStart: CarbonImmutable, yesterdayStart: CarbonImmutable}>  $periods
     */
    private function applyPeriod(Builder $query, array $periods, string $column, string $period, string $branchColumn = 'branch_id'): void
    {
        if ($periods === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $ranges) use ($periods, $column, $period, $branchColumn): void {
            foreach ($periods as $branchId => $periodData) {
                $start = $period === 'today'
                    ? $periodData['todayStart']
                    : $periodData['yesterdayStart'];
                $end = $period === 'today'
                    ? $periodData['tomorrowStart']
                    : $periodData['todayStart'];
                $ranges->orWhere(function (Builder $branchRange) use ($branchId, $column, $start, $end, $branchColumn): void {
                    $branchRange->where($branchColumn, $branchId)
                        ->where($column, '>=', $start)
                        ->where($column, '<', $end);
                });
            }
        });
    }
}
