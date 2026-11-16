<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Records\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * G2a (owner, 16 Nov 2026): overtime approved after its month's regular run was locked. Such overtime falls in no later
 * run, so it is listed on the locked run and offered on a draft adjustment of that run; it is never paid automatically.
 * An item stops being late once a locked run of that month has its line. Reads only.
 */
final class LateAfterLock
{
    /**
     * Approved overtime, in the run's month, of people in the locked regular run, that no locked run has paid.
     *
     * @return list<array{overtimeId: int, id: string, workerId: int, worker: string, name: string, date: string, minutes: int, approvedAt: string, inRunId: ?int}>
     */
    public function forRegularRun(int $organisationId, \stdClass $run): array
    {
        if ($run->kind !== 'regular' || $run->status !== 'locked') {
            return [];
        }
        $start = CarbonImmutable::parse((string) $run->month)->startOfMonth();
        $workers = Worker::query()->with('user')->whereIn('id', DB::table('hr_payroll_run_people')->where('run_id', $run->id)->pluck('worker_id')->all())
            ->whereNotNull('user_id')->get()->keyBy('user_id');
        if ($workers->isEmpty()) {
            return [];
        }
        $rows = DB::table('hr_overtime_requests as o')->where('o.organisation_id', $organisationId)->where('o.status', 'approved')
            ->whereIn('o.user_id', $workers->keys()->all())
            ->whereBetween('o.local_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->whereNotExists(function ($q) use ($organisationId): void {
                $q->selectRaw('1')->from('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
                    ->where('r.organisation_id', $organisationId)->where('r.status', 'locked')
                    ->where('l.source_kind', 'overtime')->whereColumn('l.source_id', 'o.id');
            })
            ->orderBy('o.local_date')->orderBy('o.id')->get(['o.id', 'o.public_id', 'o.user_id', 'o.local_date', 'o.minutes_approved', 'o.decided_at']);
        $inRuns = DB::table('hr_payroll_late_items as i')->join('hr_payroll_runs as r', 'r.id', '=', 'i.run_id')
            ->where('i.organisation_id', $organisationId)->where('r.status', '<>', 'locked')
            ->whereIn('i.overtime_request_id', $rows->pluck('id')->all())->pluck('i.run_id', 'i.overtime_request_id');
        $out = [];
        foreach ($rows as $o) {
            $worker = $workers->get($o->user_id);
            if (! $worker instanceof Worker) {
                continue;
            }
            $out[] = ['overtimeId' => (int) $o->id, 'id' => (string) $o->public_id, 'workerId' => (int) $worker->id, 'worker' => (string) $worker->public_id,
                'name' => $worker->name(), 'date' => CarbonImmutable::parse((string) $o->local_date)->format('j M Y'), 'minutes' => (int) $o->minutes_approved,
                'approvedAt' => $o->decided_at === null ? '' : CarbonImmutable::parse((string) $o->decided_at)->toIso8601String(),
                'inRunId' => $inRuns->has($o->id) ? (int) $inRuns->get($o->id) : null];
        }

        return $out;
    }

    /**
     * For each request, the month label ("November 2026") when the person's regular run for that month is already
     * locked, so overtime approved now will not be paid by it. One query for the whole list.
     *
     * @param  array<int, array{userId: int, date: string}>  $requests  keyed by request id
     * @return array<int, string>
     */
    public function lockedMonths(int $organisationId, array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $months = array_values(array_unique(array_map(fn (array $r): string => CarbonImmutable::parse($r['date'])->startOfMonth()->toDateString(), $requests)));
        $locked = DB::table('hr_payroll_runs as r')->join('hr_payroll_run_people as p', 'p.run_id', '=', 'r.id')
            ->join('hr_workers as w', 'w.id', '=', 'p.worker_id')
            ->where('r.organisation_id', $organisationId)->where('r.kind', 'regular')->where('r.status', 'locked')
            ->whereIn('r.month', $months)->whereIn('w.user_id', array_values(array_unique(array_column($requests, 'userId'))))
            ->get(['w.user_id', 'r.month'])->map(fn (\stdClass $row): string => $row->user_id.'|'.CarbonImmutable::parse((string) $row->month)->toDateString())->flip();
        $out = [];
        foreach ($requests as $id => $r) {
            $month = CarbonImmutable::parse($r['date'])->startOfMonth();
            if ($locked->has($r['userId'].'|'.$month->toDateString())) {
                $out[$id] = $month->format('F Y');
            }
        }

        return $out;
    }
}
