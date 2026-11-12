<?php

namespace App\Domain\DepartmentSpace\Explain;

use Carbon\CarbonImmutable;

/**
 * EXPLAIN-1: the rules behind the Queue and Front desk explainers, as pure functions on already-normalised rows (no
 * database, no clock). Every threshold lives here. Nothing is guessed: a figure below its minimum sample is reported
 * as "not enough data", and a driver is only ever said to have "coincided with" a change.
 *
 * An entry is an array: q (queued, branch-local), c (called, branch-local, or null), r (removed, branch-local, or
 * null), s (status), u (urgent), d (assigned doctor id or null).
 *
 * @phpstan-type Entry array{q: CarbonImmutable, c: CarbonImmutable|null, r: CarbonImmutable|null, s: string, u: bool, d: int|null}
 * @phpstan-type Cell array{n: int, sum: int, arrivals: int, doctors: float, mean: float}
 */
final class WaitAnalysis
{
    /** A period needs this many called, non-urgent entries to be compared. */
    public const MIN_PERIOD = 20;

    /** A day-part cell needs this many on each side to be named. */
    public const MIN_CELL = 8;

    /** A cell is named only if it carries this share of the overall change. */
    public const MIN_SHARE_OF_CHANGE = 0.25;

    /** An overall change smaller than this many minutes is "about the same". */
    public const SAME_MINUTES = 3;

    /** A coinciding fact is stated only above these differences. */
    public const ARRIVALS_FACT = 0.20;

    public const DOCTORS_FACT = 1;

    /** Removals needed before a part of the day is named for them. */
    public const MIN_REMOVALS_FOR_PEAK = 5;

    /** Busiest hours: days with arrivals and entries needed. */
    public const MIN_BUSY_DAYS = 5;

    public const MIN_BUSY_ENTRIES = 50;

    public const MAX_NAMED_CELLS = 2;

    /** Day parts by branch-local hour: before 12:00, 12:00 to 16:59, from 17:00. */
    public static function part(CarbonImmutable $at): string
    {
        return $at->hour < 12 ? 'morning' : ($at->hour < 17 ? 'afternoon' : 'evening');
    }

    /**
     * Called entries usable for a wait: called_at is set (whatever the status now) and is no later than the cutoff,
     * and the call was on the same branch-local day as the queueing (a call after midnight is a carry-over, left out).
     *
     * @param  list<Entry>  $entries
     * @return array{used: list<Entry>, urgent: int, carryOver: int}
     */
    public static function calledWaits(array $entries, CarbonImmutable $cutoff): array
    {
        $used = [];
        $urgent = 0;
        $carry = 0;
        foreach ($entries as $entry) {
            if ($entry['c'] === null || $entry['c'] > $cutoff) {
                continue;
            }
            if ($entry['c']->toDateString() !== $entry['q']->toDateString()) {
                $carry++;

                continue;
            }
            if ($entry['u']) {
                $urgent++;

                continue;
            }
            $used[] = $entry;
        }

        return ['used' => $used, 'urgent' => $urgent, 'carryOver' => $carry];
    }

    /** @param  Entry  $entry */
    public static function waitMinutes(array $entry): int
    {
        return max(0, intdiv((int) $entry['q']->diffInSeconds($entry['c'], false), 60));
    }

    /**
     * Mean wait per cell (weekday and part, or part only) with arrivals per day and doctors who had a patient called
     * per day. $dates is the list of branch-local dates the period covers (so arrivals are per day, not per week).
     *
     * @param  list<Entry>  $all  every entry queued in the period (any status), for arrivals
     * @param  list<Entry>  $used  the called, non-urgent entries
     * @param  list<string>  $dates
     * @return array<string, Cell>
     */
    public static function cells(array $all, array $used, array $dates, bool $byWeekday): array
    {
        $key = fn (CarbonImmutable $at): string => ($byWeekday ? $at->isoWeekday().'|' : '').self::part($at);
        $days = [];
        foreach ($dates as $date) {
            $weekday = $byWeekday ? CarbonImmutable::parse($date)->isoWeekday() : 0;
            $days[$weekday] = ($days[$weekday] ?? 0) + 1;
        }
        $perDay = fn (string $cell): int => max(1, $byWeekday ? ($days[(int) explode('|', $cell)[0]] ?? 1) : count($dates));

        $cells = [];
        foreach ($used as $entry) {
            $cell = $key($entry['c']);
            $cells[$cell] ??= ['n' => 0, 'sum' => 0, 'arrivals' => 0, 'doctors' => 0.0, 'mean' => 0.0];
            $cells[$cell]['n']++;
            $cells[$cell]['sum'] += self::waitMinutes($entry);
        }
        $arrivals = [];
        foreach ($all as $entry) {
            $cell = $key($entry['q']);
            $arrivals[$cell] = ($arrivals[$cell] ?? 0) + 1;
        }
        $doctorDays = [];
        foreach ($used as $entry) {
            if ($entry['d'] !== null) {
                $doctorDays[$key($entry['c'])][$entry['c']->toDateString()][$entry['d']] = true;
            }
        }
        foreach ($cells as $cell => &$stat) {
            $stat['arrivals'] = (int) round(($arrivals[$cell] ?? 0) / $perDay($cell));
            $perDate = $doctorDays[$cell] ?? [];
            $stat['doctors'] = $perDate === [] ? 0.0 : (float) (array_sum(array_map('count', $perDate)) / count($perDate));
            $stat['mean'] = $stat['sum'] / $stat['n'];
        }
        unset($stat);

        return $cells;
    }

    /**
     * Compares two periods. The change in the overall mean is split exactly over the cells (the contributions add up
     * to it); the cells that carry most of it and have enough entries on both sides are returned with the two facts
     * that may have coincided with it.
     *
     * @param  array<string, Cell>  $now
     * @param  array<string, Cell>  $before
     * @return array{enough: bool, nNow: int, nBefore: int, meanNow: float, meanBefore: float, change: float, named: list<array{cell: string, contribution: float, wait: int, waitBefore: int, facts: list<string>}>}
     */
    public static function compare(array $now, array $before, int $minNow = self::MIN_PERIOD): array
    {
        $nNow = (int) array_sum(array_column($now, 'n'));
        $nBefore = (int) array_sum(array_column($before, 'n'));
        $meanNow = $nNow > 0 ? array_sum(array_column($now, 'sum')) / $nNow : 0.0;
        $meanBefore = $nBefore > 0 ? array_sum(array_column($before, 'sum')) / $nBefore : 0.0;
        $result = ['enough' => $nNow >= $minNow && $nBefore >= self::MIN_PERIOD, 'nNow' => $nNow, 'nBefore' => $nBefore, 'meanNow' => $meanNow, 'meanBefore' => $meanBefore, 'change' => $meanNow - $meanBefore, 'named' => []];
        if (! $result['enough']) {
            return $result;
        }

        $rows = [];
        foreach (array_unique([...array_keys($now), ...array_keys($before)]) as $cell) {
            $a = $now[$cell] ?? null;
            $b = $before[$cell] ?? null;
            if ($a === null || $b === null || $a['n'] < self::MIN_CELL || $b['n'] < self::MIN_CELL) {
                continue;
            }
            $contribution = ($a['n'] / $nNow) * $a['mean'] - ($b['n'] / $nBefore) * $b['mean'];
            $rows[] = ['cell' => (string) $cell, 'contribution' => $contribution, 'a' => $a, 'b' => $b];
        }
        $sign = $result['change'] >= 0 ? 1 : -1;
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['contribution'] * $sign > 0 && abs($row['contribution']) >= self::MIN_SHARE_OF_CHANGE * abs($result['change'])));
        usort($rows, fn (array $x, array $y): int => abs($y['contribution']) <=> abs($x['contribution']));

        foreach (array_slice($rows, 0, self::MAX_NAMED_CELLS) as $row) {
            $result['named'][] = ['cell' => $row['cell'], 'contribution' => $row['contribution'], 'wait' => (int) round($row['a']['mean']), 'waitBefore' => (int) round($row['b']['mean']), 'facts' => self::facts($row['a'], $row['b'])];
        }

        return $result;
    }

    /**
     * The two facts that may have coincided: doctors who had a patient called, and arrivals. Stated only when they
     * differ by at least one doctor or 20% (and only when the earlier figure is known).
     *
     * @param  Cell  $a
     * @param  Cell  $b
     * @return list<string>
     */
    public static function facts(array $a, array $b): array
    {
        $facts = [];
        $dNow = (int) round($a['doctors']);
        $dBefore = (int) round($b['doctors']);
        if ($a['doctors'] > 0 && $b['doctors'] > 0 && abs($dNow - $dBefore) >= self::DOCTORS_FACT) {
            $facts[] = $dNow.($dNow === 1 ? ' doctor' : ' doctors').' having a patient called instead of '.$dBefore;
        }
        if ($b['arrivals'] > 0) {
            $ratio = ($a['arrivals'] - $b['arrivals']) / $b['arrivals'];
            if (abs($ratio) >= self::ARRIVALS_FACT) {
                $facts[] = (int) round(abs($ratio) * 100).'% '.($ratio > 0 ? 'more' : 'fewer').' arrivals';
            }
        }

        return $facts;
    }

    /** "Tue afternoon" from "2|afternoon"; "the afternoon" from "afternoon". */
    public static function cellLabel(string $cell): string
    {
        if (! str_contains($cell, '|')) {
            return 'the '.$cell;
        }
        [$weekday, $part] = explode('|', $cell);

        return CarbonImmutable::parse('2026-01-05')->addDays((int) $weekday - 1)->format('D').' '.$part;
    }

    /**
     * The median of whole minutes (the lower middle for an even count).
     *
     * @param  list<int>  $values
     */
    public static function median(array $values): int
    {
        sort($values);

        return $values === [] ? 0 : $values[intdiv(count($values) - 1, 2)];
    }

    /** "+22 min", "-5 min", "0 min". */
    public static function signed(int $minutes): string
    {
        return ($minutes > 0 ? '+' : ($minutes < 0 ? '-' : '')).abs($minutes).' min';
    }
}
