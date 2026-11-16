<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Attendance\Services\AttendanceTotals;
use App\Domain\HR\Records\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Things that changed after a month's regular run was locked and that no later run picks up by itself. They are listed
 * on the locked run and offered on a draft adjustment of that run; nothing is paid or taken automatically. Reads only.
 *
 * - overtime (G2a): overtime approved after the lock that no locked run paid;
 * - leave_cancelled (G2b): unpaid leave a locked run took off pay, cancelled after the lock and not yet given back;
 * - leave_approved (G2b): unpaid leave approved after the lock that no locked run took off pay (monthly pay only);
 * - hours (G2b): an hourly person whose worked hours for the month are no longer what the locked runs paid for.
 *
 * An item stops being late once a locked run of that month has its line (or, for hours, pays for the new total).
 */
final class LateAfterLock
{
    public const KINDS = ['overtime', 'leave_cancelled', 'leave_approved', 'hours'];

    public function __construct(private AttendanceTotals $attendance) {}

    /**
     * @return list<array{kind: string, sourceId: int, id: string, workerId: int, worker: string, name: string, date: string,
     *     minutes: int, halves: int, wasMinutes: int, nowMinutes: int, at: string, inRunId: ?int}>
     */
    public function forRegularRun(int $organisationId, \stdClass $run): array
    {
        if ($run->kind !== 'regular' || $run->status !== 'locked') {
            return [];
        }
        $start = CarbonImmutable::parse((string) $run->month)->startOfMonth();
        $from = $start->toDateString();
        $to = $start->endOfMonth()->toDateString();
        $people = DB::table('hr_payroll_run_people as p')->leftJoin('hr_pay_profiles as f', 'f.id', '=', 'p.profile_id')
            ->where('p.run_id', $run->id)->get(['p.worker_id', 'f.pay_type'])->keyBy('worker_id');
        $workers = Worker::query()->with('user')->whereIn('id', $people->keys()->all())->whereNotNull('user_id')->get()->keyBy('user_id');
        if ($workers->isEmpty()) {
            return [];
        }
        $payType = fn (Worker $w): ?string => $people->get($w->id)?->pay_type;
        $inRuns = DB::table('hr_payroll_late_items as i')->join('hr_payroll_runs as r', 'r.id', '=', 'i.run_id')
            ->where('i.organisation_id', $organisationId)->where('r.status', '<>', 'locked')
            ->get(['i.kind', 'i.overtime_request_id', 'i.leave_request_id', 'i.worker_id', 'i.run_id'])
            ->mapWithKeys(fn (\stdClass $i): array => [self::key((string) $i->kind, (int) ($i->overtime_request_id ?? $i->leave_request_id ?? $i->worker_id)) => (int) $i->run_id]);

        $out = [];
        foreach ($this->overtime($organisationId, $workers, $from, $to) as $o) {
            $out[] = $this->item('overtime', (int) $o->id, (string) $o->public_id, $workers->get($o->user_id), (string) $o->local_date, $o->decided_at, $inRuns,
                ['minutes' => (int) $o->minutes_approved]);
        }
        $monthly = $workers->filter(fn (Worker $w): bool => $payType($w) === 'monthly');
        foreach ($this->leave($organisationId, $monthly, $from, $to) as $l) {
            $kind = $l->status === 'cancelled' ? 'leave_cancelled' : 'leave_approved';
            $out[] = $this->item($kind, (int) $l->id, (string) $l->public_id, $workers->get($l->user_id), (string) $l->start_date,
                $kind === 'leave_cancelled' ? $l->cancelled_at : $l->decided_at, $inRuns, ['halves' => (int) $l->halves]);
        }
        $hourly = $workers->filter(fn (Worker $w): bool => $payType($w) === 'hourly');
        foreach ($this->hours($organisationId, $run, $hourly, $start) as $userId => $h) {
            $worker = $workers->get($userId);
            if ($worker instanceof Worker) {
                $out[] = $this->item('hours', (int) $worker->id, (string) $worker->public_id, $worker, $from, $h['at'], $inRuns,
                    ['wasMinutes' => $h['was'], 'nowMinutes' => $h['now']]);
            }
        }
        $out = array_values(array_filter($out, fn (array $i): bool => $i['workerId'] !== 0));
        usort($out, fn (array $a, array $b): int => [$a['name'], $a['sort'], $a['kind']] <=> [$b['name'], $b['sort'], $b['kind']]);

        return array_map(function (array $i): array {
            unset($i['sort']);

            return $i;
        }, $out);
    }

    /**
     * The worked minutes now and the minutes the locked runs of the month already paid for, for one hourly person.
     *
     * @return array{was: int, now: int}|null null when no locked run of the month recorded the minutes it paid for
     */
    public function hoursFor(int $organisationId, \stdClass $regular, Worker $worker): ?array
    {
        $start = CarbonImmutable::parse((string) $regular->month)->startOfMonth();
        $was = $this->paidMinutes($regular, [(int) $worker->id])[(int) $worker->id] ?? null;
        if ($was === null || $worker->user_id === null) {
            return null;
        }
        $now = $this->attendance->worked($organisationId, [(int) $worker->user_id], $start, $start->endOfMonth()->startOfDay())[(int) $worker->user_id]['minutes'] ?? 0;

        return ['was' => $was, 'now' => (int) $now];
    }

    /**
     * For each record, the month label ("November 2026") when the person's regular run for that month is already
     * locked. With $pay set, only for people the locked run paid that way (monthly for leave, hourly for hours).
     * One query for the whole list.
     *
     * @param  array<int, array{userId: int, date: string}>  $requests  keyed by record id
     * @return array<int, string>
     */
    public function lockedMonths(int $organisationId, array $requests, ?string $pay = null): array
    {
        if ($requests === []) {
            return [];
        }
        $months = array_values(array_unique(array_map(fn (array $r): string => CarbonImmutable::parse($r['date'])->startOfMonth()->toDateString(), $requests)));
        $query = DB::table('hr_payroll_runs as r')->join('hr_payroll_run_people as p', 'p.run_id', '=', 'r.id')
            ->join('hr_workers as w', 'w.id', '=', 'p.worker_id')
            ->where('r.organisation_id', $organisationId)->where('r.kind', 'regular')->where('r.status', 'locked')
            ->whereIn('r.month', $months)->whereIn('w.user_id', array_values(array_unique(array_column($requests, 'userId'))));
        if ($pay !== null) {
            $query->join('hr_pay_profiles as f', 'f.id', '=', 'p.profile_id')->where('f.pay_type', $pay);
        }
        $locked = $query->get(['w.user_id', 'r.month'])->map(fn (\stdClass $row): string => $row->user_id.'|'.CarbonImmutable::parse((string) $row->month)->toDateString())->flip();
        $out = [];
        foreach ($requests as $id => $r) {
            $month = CarbonImmutable::parse($r['date'])->startOfMonth();
            if ($locked->has($r['userId'].'|'.$month->toDateString())) {
                $out[$id] = $month->format('F Y');
            }
        }

        return $out;
    }

    /**
     * Suresh m1: hourly people of a locked regular run whose paid minutes no locked run of the month recorded (runs
     * prepared before G2b). Hours changed after the lock cannot be detected for them.
     */
    public function hoursUnknown(\stdClass $run): int
    {
        if ($run->kind !== 'regular' || $run->status !== 'locked') {
            return 0;
        }
        $hourly = DB::table('hr_payroll_run_people as p')->join('hr_pay_profiles as f', 'f.id', '=', 'p.profile_id')
            ->where('p.run_id', $run->id)->where('f.pay_type', 'hourly')->pluck('p.worker_id')->map(fn ($v): int => (int) $v)->all();

        return count(array_diff($hourly, array_keys($this->paidMinutes($run, array_values($hourly)))));
    }

    /**
     * For each leave request id, the month label when a locked run took it off pay and none gave it back (G2b): if it
     * is cancelled now, the money goes back only on an adjustment run.
     *
     * @param  list<int>  $leaveIds
     * @return array<int, string>
     */
    public function takenOffPay(int $organisationId, array $leaveIds): array
    {
        if ($leaveIds === []) {
            return [];
        }
        $rows = DB::table('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->where('r.organisation_id', $organisationId)->where('r.status', 'locked')->where('l.source_kind', 'leave')
            ->whereIn('l.source_id', $leaveIds)->get(['l.source_id', 'l.section', 'r.month']);
        $out = [];
        foreach ($rows->groupBy('source_id') as $id => $lines) {
            $first = $lines->firstWhere('section', 'deduction');
            if ($first instanceof \stdClass && ! $lines->contains('section', 'earnings')) {
                $out[(int) $id] = CarbonImmutable::parse((string) $first->month)->format('F Y');
            }
        }

        return $out;
    }

    public static function key(string $kind, int $sourceId): string
    {
        return $kind.':'.$sourceId;
    }

    /**
     * @param  Collection<int|string, Worker>  $workers
     * @return Collection<int, \stdClass>
     */
    private function overtime(int $organisationId, Collection $workers, string $from, string $to): Collection
    {
        return DB::table('hr_overtime_requests as o')->where('o.organisation_id', $organisationId)->where('o.status', 'approved')
            ->whereIn('o.user_id', $workers->keys()->all())->whereBetween('o.local_date', [$from, $to])
            ->whereNotExists(function ($q) use ($organisationId): void {
                $q->selectRaw('1')->from('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
                    ->where('r.organisation_id', $organisationId)->where('r.status', 'locked')
                    ->where('l.source_kind', 'overtime')->whereColumn('l.source_id', 'o.id');
            })
            ->orderBy('o.local_date')->orderBy('o.id')->get(['o.id', 'o.public_id', 'o.user_id', 'o.local_date', 'o.minutes_approved', 'o.decided_at']);
    }

    /**
     * Unpaid leave starting in the month: cancelled after a locked run took it off pay (and none gave it back), or
     * approved with no locked run line at all.
     *
     * @param  Collection<int|string, Worker>  $workers
     * @return Collection<int, \stdClass>
     */
    private function leave(int $organisationId, Collection $workers, string $from, string $to): Collection
    {
        if ($workers->isEmpty()) {
            return collect();
        }
        $locked = fn (?string $section) => function ($q) use ($organisationId, $section): void {
            $q->selectRaw('1')->from('hr_payroll_lines as l')->join('hr_payroll_runs as r', 'r.id', '=', 'l.run_id')
                ->where('r.organisation_id', $organisationId)->where('r.status', 'locked')->where('l.source_kind', 'leave')
                ->whereColumn('l.source_id', 'q.id');
            if ($section !== null) {
                $q->where('l.section', $section);
            }
        };

        return DB::table('hr_leave_requests as q')->join('hr_leave_types as t', 't.id', '=', 'q.leave_type_id')
            ->where('q.organisation_id', $organisationId)->where('t.is_paid', false)->whereIn('q.user_id', $workers->keys()->all())
            ->whereBetween('q.start_date', [$from, $to])
            ->where(function ($w) use ($locked): void {
                $w->where(fn ($c) => $c->where('q.status', 'cancelled')->whereExists($locked('deduction'))->whereNotExists($locked('earnings')))
                    ->orWhere(fn ($a) => $a->where('q.status', 'approved')->whereNotExists($locked(null)));
            })
            ->orderBy('q.start_date')->orderBy('q.id')
            ->get(['q.id', 'q.public_id', 'q.user_id', 'q.start_date', 'q.halves', 'q.status', 'q.decided_at', 'q.cancelled_at']);
    }

    /**
     * Hourly people whose worked minutes for the month differ from what the locked runs paid for. Runs locked before
     * G2b kept no minutes, so their people are not listed.
     *
     * @param  Collection<int|string, Worker>  $workers
     * @return array<int, array{was: int, now: int, at: mixed}> keyed by user id
     */
    private function hours(int $organisationId, \stdClass $run, Collection $workers, CarbonImmutable $start): array
    {
        if ($workers->isEmpty()) {
            return [];
        }
        $paid = $this->paidMinutes($run, array_values($workers->map(fn (Worker $w): int => (int) $w->id)->all()));
        $known = $workers->filter(fn (Worker $w): bool => array_key_exists((int) $w->id, $paid));
        if ($known->isEmpty()) {
            return [];
        }
        $userIds = array_values($known->keys()->map(fn ($v): int => (int) $v)->all());
        $now = $this->attendance->worked($organisationId, $userIds, $start, $start->endOfMonth()->startOfDay());
        $corrected = DB::table('hr_attendance_corrections as c')
            ->join('hr_clock_sessions as s', 's.id', '=', DB::raw('COALESCE(c.clock_session_id, c.created_session_id)'))
            ->where('c.organisation_id', $organisationId)->where('c.status', 'approved')->whereIn('c.user_id', $userIds)
            ->whereBetween('s.local_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->groupBy('c.user_id')->selectRaw('c.user_id, max(c.decided_at) as at')->pluck('at', 'user_id');
        $out = [];
        foreach ($known as $userId => $worker) {
            $minutes = (int) ($now[(int) $userId]['minutes'] ?? 0);
            if ($minutes !== $paid[(int) $worker->id]) {
                $out[(int) $userId] = ['was' => $paid[(int) $worker->id], 'now' => $minutes, 'at' => $corrected->get($userId)];
            }
        }

        return $out;
    }

    /**
     * Per worker, the worked minutes the latest locked run of the month (the regular run or one of its adjustments)
     * recorded as paid for.
     *
     * @param  list<int>  $workerIds
     * @return array<int, int>
     */
    private function paidMinutes(\stdClass $regular, array $workerIds): array
    {
        return DB::table('hr_payroll_run_people as p')->join('hr_payroll_runs as r', 'r.id', '=', 'p.run_id')
            ->where('r.status', 'locked')->where(fn ($q) => $q->where('r.id', $regular->id)->orWhere('r.adjusts_run_id', $regular->id))
            ->whereIn('p.worker_id', $workerIds)->whereNotNull('p.worked_minutes')
            ->orderBy('r.locked_at')->orderBy('r.id')->get(['p.worker_id', 'p.worked_minutes'])
            ->mapWithKeys(fn (\stdClass $p): array => [(int) $p->worker_id => (int) $p->worked_minutes])->all();
    }

    /**
     * @param  Collection<string, int>  $inRuns
     * @param  array<string, int>  $extra
     * @return array{kind: string, sourceId: int, id: string, workerId: int, worker: string, name: string, date: string, sort: string,
     *     minutes: int, halves: int, wasMinutes: int, nowMinutes: int, at: string, inRunId: ?int}
     */
    private function item(string $kind, int $sourceId, string $publicId, ?Worker $worker, string $date, mixed $at, Collection $inRuns, array $extra): array
    {
        $key = self::key($kind, $sourceId);
        $day = CarbonImmutable::parse($date);

        return ['kind' => $kind, 'sourceId' => $sourceId, 'id' => $publicId, 'workerId' => $worker === null ? 0 : (int) $worker->id, 'worker' => $worker === null ? '' : (string) $worker->public_id,
            'name' => $worker?->name() ?? '', 'date' => $kind === 'hours' ? $day->format('F Y') : $day->format('j M Y'), 'sort' => $day->toDateString(),
            'minutes' => $extra['minutes'] ?? 0, 'halves' => $extra['halves'] ?? 0, 'wasMinutes' => $extra['wasMinutes'] ?? 0, 'nowMinutes' => $extra['nowMinutes'] ?? 0,
            'at' => $at === null ? '' : CarbonImmutable::parse((string) $at)->toIso8601String(), 'inRunId' => $inRuns->has($key) ? (int) $inRuns->get($key) : null];
    }
}
