<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `clinic.day_summary`: for a branch-local date (default today), per branch and over one cohort (visits
 * registered that day, Insights In-clinic definitions): visits registered, visits called in ("served"),
 * average waiting and consultation minutes, and per assigned doctor the visits called in and average
 * consultation minutes. `waitingNow` (entries still waiting) is given for today only. Counts and the
 * doctor's display name (contract section 14) only; no patient data.
 */
final class ClinicDaySummaryTool implements AgentTool
{
    use ReadsBranches;

    public function __construct(private ClinicInsightsReportService $reports) {}

    public function code(): string
    {
        return 'clinic.day_summary';
    }

    public function requiredPermission(): string
    {
        return 'insights.view.organisation';
    }

    public function inputSchema(): array
    {
        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        $doctors = collect($this->reports->doctors($requester))->pluck('name', 'id');
        $result = [];
        foreach ($this->branchesFor($requester, $branch) as $each) {
            $date = $this->localDate($each, $input['date'] ?? null);
            $start = CarbonImmutable::parse($date, $each->timezone)->startOfDay()->utc();
            $end = $start->addDay();
            // One cohort for every figure: visits registered on that branch-local date (Insights In-clinic).
            $cohort = DB::table('visits as v')->leftJoin('queue_entries as q', fn ($join) => $join->on('q.visit_id', '=', 'v.id')->on('q.organisation_id', '=', 'v.organisation_id'))
                ->where('v.organisation_id', $requester->organisation_id)->where('v.branch_id', $each->id)
                ->where('v.registered_at', '>=', $start)->where('v.registered_at', '<', $end)->whereIn('v.status', ['registered', 'completed']);
            $time = $this->reports->report($requester, 'in-clinic', $each->code, $date, $date, null)['report']['time'];
            $seen = (clone $cohort)->whereNotNull('q.called_at')->groupBy('v.assigned_doctor_user_id')
                ->selectRaw('v.assigned_doctor_user_id as doctor_id, COUNT(DISTINCT v.id) as seen')->pluck('seen', 'doctor_id');
            $perDoctor = [];
            foreach ($seen as $doctorId => $count) {
                if ($doctorId === '' || ! $doctors->has($doctorId)) { // visits with no assigned doctor group under the '' key
                    continue;
                }
                $serving = $this->reports->report($requester, 'in-clinic', $each->code, $date, $date, (int) $doctorId)['report']['time']['serving'];
                $perDoctor[] = ['doctorId' => (int) $doctorId, 'doctor' => (string) $doctors[$doctorId], 'seen' => (int) $count, 'averageConsultationMinutes' => (int) $serving['average']];
            }
            usort($perDoctor, fn (array $a, array $b): int => strcmp($a['doctor'], $b['doctor']));
            $isToday = $date === CarbonImmutable::now($each->timezone)->toDateString();
            $result[] = [
                'branch' => $each->code,
                'date' => $date,
                'registered' => (int) (clone $cohort)->distinct()->count('v.id'),
                'waitingNow' => $isToday ? (int) DB::table('queue_entries')->where('organisation_id', $requester->organisation_id)->where('branch_id', $each->id)->where('operational_date', $date)->where('status', 'waiting')->count() : null,
                'served' => (int) (clone $cohort)->whereNotNull('q.called_at')->distinct()->count('v.id'),
                'averageWaitMinutes' => (int) $time['waiting']['average'],
                'averageConsultationMinutes' => (int) $time['serving']['average'],
                'doctors' => $perDoctor,
            ];
        }

        return ['scope' => $branch->code ?? 'all', 'branches' => $result];
    }
}
