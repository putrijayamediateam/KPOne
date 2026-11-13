<?php

namespace Tests\Unit;

use App\Domain\DepartmentSpace\Explain\WaitAnalysis;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** EXPLAIN-1: the explainer rules are pure and pinned, so a threshold change shows up as a failing test. */
final class WaitAnalysisTest extends TestCase
{
    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Asia/Kuala_Lumpur');
    }

    /** @return array{q: CarbonImmutable, c: CarbonImmutable|null, r: CarbonImmutable|null, s: string, u: bool, d: int|null} */
    private function entry(string $queued, ?string $called, bool $urgent = false, ?int $doctor = 1, string $status = 'removed'): array
    {
        return ['q' => $this->at($queued), 'c' => $called === null ? null : $this->at($called), 'r' => null, 's' => $status, 'u' => $urgent, 'd' => $doctor];
    }

    public function test_day_parts_follow_the_agreed_boundaries(): void
    {
        $this->assertSame('morning', WaitAnalysis::part($this->at('2026-11-10 11:59')));
        $this->assertSame('afternoon', WaitAnalysis::part($this->at('2026-11-10 12:00')));
        $this->assertSame('afternoon', WaitAnalysis::part($this->at('2026-11-10 16:59')));
        $this->assertSame('evening', WaitAnalysis::part($this->at('2026-11-10 17:00')));
    }

    public function test_called_means_called_at_is_set_whatever_the_status_and_carry_over_urgent_and_late_calls_are_left_out(): void
    {
        $cut = $this->at('2026-11-10 15:00');
        $entries = [
            $this->entry('2026-11-10 09:00', '2026-11-10 09:10', status: 'removed'),
            $this->entry('2026-11-10 09:00', '2026-11-10 09:20', status: 'serving'),
            $this->entry('2026-11-10 09:00', '2026-11-10 09:05', urgent: true),
            $this->entry('2026-11-09 23:50', '2026-11-10 00:10'),
            $this->entry('2026-11-10 14:50', '2026-11-10 15:30'),
            $this->entry('2026-11-10 09:00', null, status: 'waiting'),
        ];
        $result = WaitAnalysis::calledWaits($entries, $cut);

        $this->assertCount(2, $result['used']);
        $this->assertSame(1, $result['urgent']);
        $this->assertSame(1, $result['carryOver']);
        $this->assertSame([10, 20], array_map(fn (array $e): int => WaitAnalysis::waitMinutes($e), $result['used']));
    }

    public function test_a_period_needs_twenty_and_a_cell_needs_eight_on_each_side(): void
    {
        $cell = fn (int $n, int $wait): array => ['n' => $n, 'sum' => $n * $wait, 'arrivals' => $n, 'doctors' => 2.0, 'mean' => (float) $wait];

        $this->assertFalse(WaitAnalysis::compare(['1|morning' => $cell(19, 30)], ['1|morning' => $cell(30, 10)])['enough']);
        $this->assertFalse(WaitAnalysis::compare(['1|morning' => $cell(30, 30)], ['1|morning' => $cell(19, 10)])['enough']);
        $this->assertTrue(WaitAnalysis::compare(['1|morning' => $cell(20, 30)], ['1|morning' => $cell(20, 10)])['enough']);
        $this->assertTrue(WaitAnalysis::compare(['1|morning' => $cell(8, 30)], ['1|morning' => $cell(20, 10)], 8)['enough']);

        $small = WaitAnalysis::compare(['1|morning' => $cell(20, 30), '2|morning' => $cell(7, 90)], ['1|morning' => $cell(20, 10), '2|morning' => $cell(20, 10)]);
        $this->assertSame(['1|morning'], array_column($small['named'], 'cell'), 'a cell with 7 entries is never named');
    }

    public function test_the_contributions_add_up_to_the_change_and_only_cells_with_a_quarter_of_it_are_named(): void
    {
        $cell = fn (int $n, int $wait, float $doctors = 2.0): array => ['n' => $n, 'sum' => $n * $wait, 'arrivals' => $n, 'doctors' => $doctors, 'mean' => (float) $wait];
        $now = ['2|afternoon' => $cell(10, 40), '4|afternoon' => $cell(10, 30)];
        $before = ['2|afternoon' => $cell(10, 10), '4|afternoon' => $cell(10, 10)];
        $cmp = WaitAnalysis::compare($now, $before);

        $this->assertEqualsWithDelta(25.0, $cmp['change'], 0.0001);
        $this->assertEqualsWithDelta($cmp['change'], array_sum(array_column($cmp['named'], 'contribution')), 0.0001);
        $this->assertSame(['2|afternoon', '4|afternoon'], array_column($cmp['named'], 'cell'));

        $spread = WaitAnalysis::compare(
            ['1|morning' => $cell(10, 20), '2|morning' => $cell(10, 20), '3|morning' => $cell(10, 20)],
            ['1|morning' => $cell(10, 10), '2|morning' => $cell(10, 10), '3|morning' => $cell(10, 10)],
        );
        $this->assertCount(2, $spread['named'], 'never more than two cells are named');
    }

    public function test_a_cell_that_moved_the_other_way_is_not_named(): void
    {
        $cell = fn (int $n, int $wait): array => ['n' => $n, 'sum' => $n * $wait, 'arrivals' => $n, 'doctors' => 2.0, 'mean' => (float) $wait];
        $cmp = WaitAnalysis::compare(['1|morning' => $cell(20, 50), '2|morning' => $cell(20, 5)], ['1|morning' => $cell(20, 10), '2|morning' => $cell(20, 30)]);

        $this->assertSame(['1|morning'], array_column($cmp['named'], 'cell'));
    }

    public function test_facts_are_stated_only_at_one_doctor_or_twenty_percent_and_never_as_a_cause(): void
    {
        $a = fn (int $arrivals, float $doctors): array => ['n' => 10, 'sum' => 100, 'arrivals' => $arrivals, 'doctors' => $doctors, 'mean' => 10.0];

        $this->assertSame([], WaitAnalysis::facts($a(10, 3.0), $a(10, 3.0)));
        $this->assertSame([], WaitAnalysis::facts($a(11, 3.0), $a(10, 3.0)));
        $this->assertSame(['20% more arrivals'], WaitAnalysis::facts($a(12, 3.0), $a(10, 3.0)));
        $this->assertSame(['20% fewer arrivals'], WaitAnalysis::facts($a(8, 3.0), $a(10, 3.0)));
        $this->assertSame(['only 2 doctors calling patients instead of 3'], WaitAnalysis::facts($a(10, 2.0), $a(10, 3.0)));
        $this->assertSame(['only 1 doctor calling patients instead of 2', '30% more arrivals'], WaitAnalysis::facts($a(13, 1.0), $a(10, 2.0)));
        $this->assertSame([], WaitAnalysis::facts($a(10, 0.0), $a(10, 3.0)), 'unknown doctors are never stated');
    }

    public function test_cells_count_arrivals_and_doctors_per_day_and_use_the_call_time_for_the_wait_part(): void
    {
        $used = [
            $this->entry('2026-11-10 11:50', '2026-11-10 12:10', doctor: 1),
            $this->entry('2026-11-10 13:00', '2026-11-10 13:20', doctor: 2),
            $this->entry('2026-11-10 13:30', '2026-11-10 13:40', doctor: 2),
        ];
        $cells = WaitAnalysis::cells($used, $used, ['2026-11-10'], true);

        $this->assertSame(['2|afternoon'], array_keys($cells));
        $this->assertSame(3, $cells['2|afternoon']['n']);
        $this->assertSame(50, $cells['2|afternoon']['sum']);
        $this->assertSame(2, $cells['2|afternoon']['arrivals'], 'arrivals follow the queued time: 11:50 was a morning arrival');
        $this->assertSame(2.0, $cells['2|afternoon']['doctors']);
    }

    public function test_small_helpers(): void
    {
        $this->assertSame('+22 min', WaitAnalysis::signed(22));
        $this->assertSame('-5 min', WaitAnalysis::signed(-5));
        $this->assertSame('0 min', WaitAnalysis::signed(0));
        $this->assertSame(10, WaitAnalysis::median([30, 10, 5, 50]));
        $this->assertSame(0, WaitAnalysis::median([]));
        $this->assertSame('Tue afternoon', WaitAnalysis::cellLabel('2|afternoon'));
        $this->assertSame('the evening', WaitAnalysis::cellLabel('evening'));
    }
}
