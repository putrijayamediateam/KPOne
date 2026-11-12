<?php

namespace App\Domain\DepartmentSpace\Explain;

use App\Domain\Access\BranchAccessService;
use App\Domain\DepartmentSpace\SpaceDisplay;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * EXPLAIN-1: the four Queue and Front desk explainers of the Ask panel. Fixed rules and sentence templates, no AI
 * service: each answer compares periods in the branch's own queue data and says how many patients it rests on. A
 * cause is never claimed, only what "coincided with" a change. Both permissions are checked on every call and only
 * the active branch is read. The answer holds times and counts, never a name, a queue number or an id.
 *
 * @phpstan-import-type Entry from WaitAnalysis
 *
 * @phpstan-type Answer array{sentence: string, columns: list<string>, rows: list<list<string>>}
 */
final class FrontDeskExplainers
{
    public function __construct(private BranchAccessService $branches, private QueueWaitData $data) {}

    /** The two permissions a person needs for any of these questions. */
    public static function permitted(User $actor): bool
    {
        return $actor->can('visits.view.branch') && $actor->can('queue.view.branch');
    }

    /** @return Answer */
    public function waitsWeek(User $actor): array
    {
        [$branch, $tz, $now] = $this->context($actor);
        $weekStart = $now->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $lastStart = $weekStart->subDays(7);
        $lastCut = $now->subDays(7);
        $entries = $this->data->entries($branch->id, $tz, $lastStart, $now);
        $thisAll = array_values(array_filter($entries, fn (array $e): bool => $e['q'] >= $weekStart));
        $lastAll = array_values(array_filter($entries, fn (array $e): bool => $e['q'] < $weekStart && $e['q'] <= $lastCut));

        $this_ = $this->period($thisAll, $now, $this->dates($weekStart, $now), true);
        $last = $this->period($lastAll, $lastCut, $this->dates($lastStart, $lastCut), true);
        $cmp = WaitAnalysis::compare($this_['cells'], $last['cells']);
        $tail = $this->tail($thisAll, $now, [$this_, $last], 'this week', 'last week');
        if (! $cmp['enough']) {
            return $this->notEnough('compare waits', $cmp['nNow'].' patients called this week, '.$cmp['nBefore'].' last week', WaitAnalysis::MIN_PERIOD.' are needed in each', $tail);
        }

        return $this->comparison($cmp, 'Average wait was', 'this week', 'last week', $cmp['nNow'].' this week, '.$cmp['nBefore'].' last week, same days and time', $tail, 'Day and time', 'This week', 'Last week', 'All days so far', 'this week', 'last week');
    }

    /** @return Answer */
    public function waitsToday(User $actor): array
    {
        [$branch, $tz, $now] = $this->context($actor);
        $todayStart = $now->startOfDay();
        $windows = [];
        for ($k = 1; $k <= 4; $k++) {
            $windows[] = [$todayStart->subDays(7 * $k), $now->subDays(7 * $k)];
        }
        $entries = $this->data->entries($branch->id, $tz, $windows[3][0], $now);
        $today = array_values(array_filter($entries, fn (array $e): bool => $e['q'] >= $todayStart));
        $base = [];
        $dates = [];
        foreach ($windows as [$from, $to]) {
            $day = array_values(array_filter($entries, fn (array $e): bool => $e['q'] >= $from && $e['q'] <= $to));
            if ($day !== []) {
                $dates[] = $from->toDateString();
                $base = [...$base, ...$day];
            }
        }
        $weekday = $now->format('l');
        $thisP = $this->period($today, $now, [$todayStart->toDateString()], false);
        $tail = $this->tail($today, $now, [$thisP], 'today', null);
        if (count($dates) < 3) {
            return $this->notEnough('say how today compares', count($dates).' of the last 4 '.$weekday.'s have data', '3 are needed', $tail);
        }
        // Each baseline day is cut at the same clock time as today, so its calls are cut there too.
        $baseUsed = [];
        foreach ($windows as [$from, $to]) {
            $day = array_values(array_filter($base, fn (array $e): bool => $e['q'] >= $from && $e['q'] <= $to));
            $baseUsed[] = WaitAnalysis::calledWaits($day, $to);
        }
        $usedBase = array_merge(...array_column($baseUsed, 'used'));
        $baseCells = WaitAnalysis::cells($base, $usedBase, $dates, false);
        $cmp = WaitAnalysis::compare($thisP['cells'], $baseCells, WaitAnalysis::MIN_CELL);
        $units = count($dates);
        if (! $cmp['enough']) {
            return $this->notEnough('say how today compares', $cmp['nNow'].' patients called today so far, '.$cmp['nBefore'].' across the last '.$units.' '.$weekday.'s', '8 today and '.WaitAnalysis::MIN_PERIOD.' before are needed', $tail);
        }

        return $this->comparison($cmp, 'Today\'s average wait is', 'today so far', 'a usual '.$weekday.' at this time', $cmp['nNow'].' today so far, '.$cmp['nBefore'].' over the last '.$units.' '.$weekday.'s', $tail, 'Part of day', 'Today', 'Usual', 'All day so far', 'today', 'usually');
    }

    /** @return Answer */
    public function busiestHours(User $actor): array
    {
        [$branch, $tz, $now] = $this->context($actor);
        $todayStart = $now->startOfDay();
        $from = $todayStart->subDays(28);
        $entries = $this->data->entries($branch->id, $tz, $from, $todayStart->subSecond());
        $days = [];
        foreach ($entries as $e) {
            $days[$e['q']->toDateString()] = true;
        }
        if (count($days) < WaitAnalysis::MIN_BUSY_DAYS || count($entries) < WaitAnalysis::MIN_BUSY_ENTRIES) {
            return $this->notEnough('show busy hours', count($days).' days with arrivals and '.count($entries).' patients in the last 4 weeks', 'at least '.WaitAnalysis::MIN_BUSY_DAYS.' days and '.WaitAnalysis::MIN_BUSY_ENTRIES.' patients are needed', '');
        }
        $arrivals = [];
        $doctorHours = [];
        foreach ($entries as $e) {
            $arrivals[$e['q']->hour] = ($arrivals[$e['q']->hour] ?? 0) + 1;
            if ($e['c'] !== null && $e['d'] !== null) {
                $doctorHours[$e['c']->hour][$e['c']->toDateString().'|'.$e['d']] = true;
            }
        }
        $used = WaitAnalysis::calledWaits($entries, $now)['used'];
        $waits = [];
        foreach ($used as $e) {
            $waits[$e['q']->hour][] = WaitAnalysis::waitMinutes($e);
        }
        $perDay = fn (int $hour): float => ($arrivals[$hour] ?? 0) / count($days);
        $hours = array_keys($arrivals);
        usort($hours, fn (int $a, int $b): int => $perDay($b) <=> $perDay($a) ?: $a <=> $b);
        $top = array_slice($hours, 0, 3);
        $label = fn (int $h): string => sprintf('%02d:00', $h);
        $named = array_map(fn (int $h): string => $label($h).' ('.(int) round($perDay($h)).($h === $top[0] ? ' arrivals a day' : '').')', $top);
        $sentence = 'Busiest hours over the last 4 weeks: '.$this->joinList($named).'.';

        $totalDoctorHours = array_sum(array_map('count', $doctorHours));
        $overall = $totalDoctorHours > 0 ? array_sum($arrivals) / $totalDoctorHours : null;
        $rows = [];
        $firstWait = null;
        foreach ($top as $h) {
            $dh = isset($doctorHours[$h]) ? count($doctorHours[$h]) : 0;
            $wait = isset($waits[$h]) && count($waits[$h]) >= WaitAnalysis::MIN_CELL ? (int) round(array_sum($waits[$h]) / count($waits[$h])) : null;
            if ($h === $top[0]) {
                $firstWait = $wait;
            }
            $rows[] = [$label($h), (int) round($perDay($h)).' arrivals a day', $wait === null ? 'Too few for an average wait' : $wait.' min average wait', $dh > 0 ? number_format($arrivals[$h] / $dh, 1).' per doctor' : 'Doctors unknown'];
        }
        $first = $top[0];
        $dh = isset($doctorHours[$first]) ? count($doctorHours[$first]) : 0;
        if ($dh > 0 && $overall !== null) {
            $sentence .= ' At '.$label($first).', '.number_format($arrivals[$first] / $dh, 1).' patients arrived per doctor who had a patient called, against '.number_format($overall, 1).' across all hours';
            $sentence .= $firstWait !== null ? ', and the average wait was '.$firstWait.' min.' : '.';
        }
        $sentence .= ' Doctors are those who had at least one patient called in that hour, not a roster.';

        return ['sentence' => $sentence, 'columns' => ['Hour', 'Arrivals a day', 'Average wait', 'Arrivals per doctor'], 'rows' => $rows];
    }

    /** @return Answer */
    public function leftWaiting(User $actor): array
    {
        [$branch, $tz, $now] = $this->context($actor);
        $weekStart = $now->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $lastStart = $weekStart->subDays(7);
        $lastCut = $now->subDays(7);
        $entries = $this->data->entries($branch->id, $tz, $lastStart, $now);
        $thisAll = array_values(array_filter($entries, fn (array $e): bool => $e['q'] >= $weekStart));
        $lastAll = array_values(array_filter($entries, fn (array $e): bool => $e['q'] < $weekStart && $e['q'] <= $lastCut));
        $removed = fn (array $all, CarbonImmutable $cut): array => array_values(array_filter($all, fn (array $e): bool => $e['s'] === 'removed' && $e['c'] === null && $e['r'] !== null && $e['r'] <= $cut));
        $a = $removed($thisAll, $now);
        $b = $removed($lastAll, $lastCut);
        $noun = fn (int $n): string => $n === 1 ? '1 patient was' : $n.' patients were';
        $share = fn (int $n, int $total): string => $total >= WaitAnalysis::MIN_PERIOD ? ' ('.(int) round($n / $total * 100).'% of '.$total.')' : '';

        if ($a === [] && $b === []) {
            return ['sentence' => 'Nobody was removed or cancelled while waiting this week or last week (same days and time). KPOne does not record why a patient leaves.', 'columns' => [], 'rows' => []];
        }
        $sentence = ($a === [] ? 'Nobody was' : $noun(count($a))).' removed or cancelled while waiting this week'.$share(count($a), count($thisAll)).', against '.count($b).' last week'.$share(count($b), count($lastAll)).' (same days and time).';
        $parts = ['morning', 'afternoon', 'evening'];
        $count = fn (array $list, string $part): int => count(array_filter($list, fn (array $e): bool => WaitAnalysis::part($e['r']) === $part));
        $rows = [];
        if (count($a) >= WaitAnalysis::MIN_REMOVALS_FOR_PEAK) {
            $peak = $parts[0];
            foreach ($parts as $part) {
                if ($count($a, $part) > $count($a, $peak)) {
                    $peak = $part;
                }
            }
            $sentence .= ' Most ('.$count($a, $peak).') were in the '.$peak.'.';
            foreach ($parts as $part) {
                $rows[] = [ucfirst($part), $count($a, $part).' this week', $count($b, $part).' last week'];
            }
        } else {
            $sentence .= ' Too few to show a pattern.';
        }
        if ($a !== []) {
            $waited = array_map(fn (array $e): int => max(0, intdiv((int) $e['q']->diffInSeconds($e['r'], false), 60)), $a);
            $sentence .= ' Half of them had waited '.WaitAnalysis::median($waited).' min or less before they left.';
        }
        $sentence .= ' KPOne does not record why a patient leaves.';

        return ['sentence' => $sentence, 'columns' => $rows === [] ? [] : ['Part of day', 'This week', 'Last week'], 'rows' => $rows];
    }

    /** @return array{0: Branch, 1: string, 2: CarbonImmutable} */
    private function context(User $actor): array
    {
        abort_unless(self::permitted($actor), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_if($branch === null, 403);
        $tz = $branch->timezone;

        return [$branch, $tz, CarbonImmutable::now($tz)];
    }

    /**
     * @param  list<Entry>  $all
     * @param  list<string>  $dates
     * @return array{all: list<Entry>, used: list<Entry>, urgent: int, carryOver: int, cells: array<string, array{n: int, sum: int, arrivals: int, doctors: float, mean: float}>}
     */
    private function period(array $all, CarbonImmutable $cutoff, array $dates, bool $byWeekday): array
    {
        $waits = WaitAnalysis::calledWaits($all, $cutoff);

        return ['all' => $all, 'used' => $waits['used'], 'urgent' => $waits['urgent'], 'carryOver' => $waits['carryOver'], 'cells' => WaitAnalysis::cells($all, $waits['used'], $dates, $byWeekday)];
    }

    /** @return list<string> */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * What a good day must not hide: patients still waiting now, urgent patients counted apart, and calls on a later
     * day left out.
     *
     * @param  list<Entry>  $current
     * @param  list<array{urgent: int, carryOver: int}>  $periods
     */
    private function tail(array $current, CarbonImmutable $now, array $periods, string $label, ?string $other): string
    {
        $text = '';
        $waiting = array_values(array_filter($current, fn (array $e): bool => $e['s'] === 'waiting' && $e['c'] === null));
        if ($waiting !== []) {
            $longest = max(array_map(fn (array $e): int => max(0, intdiv((int) $e['q']->diffInSeconds($now, false), 60)), $waiting));
            $text .= ' '.(count($waiting) === 1 ? '1 patient is' : count($waiting).' patients are').' still waiting now, the longest for '.SpaceDisplay::duration($longest).'.';
        }
        $urgent = array_sum(array_column($periods, 'urgent'));
        if ($urgent > 0) {
            $text .= ' Urgent patients ('.implode(', ', array_filter([$periods[0]['urgent'].' '.$label, isset($periods[1]) && $other !== null ? $periods[1]['urgent'].' '.$other : null])).') are left out of these averages.';
        }
        $carry = array_sum(array_column($periods, 'carryOver'));
        if ($carry > 0) {
            $text .= ' '.($carry === 1 ? '1 patient who was called on a later day was' : $carry.' patients who were called on a later day were').' left out.';
        }

        return $text;
    }

    /** @return Answer */
    private function notEnough(string $what, string $counts, string $need, string $tail): array
    {
        return ['sentence' => 'Not enough data to '.$what.' ('.$counts.'; '.$need.').'.$tail, 'columns' => [], 'rows' => []];
    }

    /**
     * @param  array{nNow: int, nBefore: int, meanNow: float, meanBefore: float, change: float, named: list<array{cell: string, contribution: float, wait: int, waitBefore: int, facts: list<string>}>}  $cmp
     * @return Answer
     */
    private function comparison(array $cmp, string $verb, string $nowLabel, string $beforeLabel, string $counted, string $tail, string $c1, string $c2, string $c3, string $overallLabel, string $w2, string $w3): array
    {
        $now = (int) round($cmp['meanNow']);
        $before = (int) round($cmp['meanBefore']);
        $change = $now - $before;
        $abs = abs($change);
        if ($abs < WaitAnalysis::SAME_MINUTES) {
            $sentence = 'Waits were about the same '.$nowLabel.': '.$now.' min against '.$before.' min in '.$beforeLabel.'. Patients counted: '.$counted.'.';
        } else {
            $sentence = $verb.' '.$abs.' min '.($change > 0 ? 'longer' : 'shorter').' '.$nowLabel.' ('.$now.' min) than '.$beforeLabel.' ('.$before.' min). Patients counted: '.$counted.'.';
            if ($cmp['named'] === []) {
                $sentence .= ' No single time of day explains it; the change was spread out.';
            } else {
                $parts = array_map(fn (array $c): string => WaitAnalysis::cellLabel($c['cell']).' ('.WaitAnalysis::signed($c['wait'] - $c['waitBefore']).'; '.($c['facts'] === [] ? 'no change in doctors or arrivals coincided with it' : 'coincided with '.implode(' and ', $c['facts'])).')', $cmp['named']);
                $sentence .= ' Biggest '.(count($parts) === 1 ? 'change' : 'changes').': '.$this->joinList($parts).'.';
            }
        }
        $table = [[$overallLabel, $now.' min '.$w2, $before.' min '.$w3, WaitAnalysis::signed($change)]];
        foreach ($cmp['named'] as $c) {
            $table[] = [ucfirst(WaitAnalysis::cellLabel($c['cell'])), $c['wait'].' min '.$w2, $c['waitBefore'].' min '.$w3, WaitAnalysis::signed($c['wait'] - $c['waitBefore'])];
        }

        return ['sentence' => $sentence.$tail, 'columns' => [$c1, $c2, $c3, 'Change'], 'rows' => $table];
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $items
     */
    private function joinList(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
