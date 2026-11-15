<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\CallNextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B1: the queue's operational day is the branch-local date, never the UTC date. Each case runs at the clock edges that have
 * broken tests before (Kuala Lumpur 23:59 and 00:01, and the UTC 16:00 edge, which is Kuala Lumpur midnight).
 */
class QueueMidnightClockTest extends QueueTestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> UTC instant, expected branch-local date */
    public static function edges(): array
    {
        return [
            'KL 23:59:30 (UTC 15:59:30)' => ['2026-11-12 15:59:30', '2026-11-12'],
            'KL 00:00:00 exactly (UTC 16:00:00)' => ['2026-11-12 16:00:00', '2026-11-13'],
            'KL 00:01 (UTC 16:01)' => ['2026-11-12 16:01:00', '2026-11-13'],
            'KL 07:59 (UTC 23:59)' => ['2026-11-12 23:59:00', '2026-11-13'],
            'KL 08:00 (UTC 00:00 next day)' => ['2026-11-13 00:00:00', '2026-11-13'],
        ];
    }

    #[DataProvider('edges')]
    public function test_a_new_entry_belongs_to_the_branch_local_day_and_numbers_start_at_001_each_day(string $utc, string $localDate): void
    {
        Date::setTestNow('2026-01-01 00:00:00 UTC'); // staff assignments must already be effective at every instant below
        $ca = $this->actor('ca');
        $doctor = $this->doctor();

        Date::setTestNow($utc.' UTC');
        $first = $this->send($ca, $this->consultationVisit($ca, $doctor))->refresh();
        $second = $this->send($ca, $this->consultationVisit($ca, $doctor))->refresh();

        $this->assertSame($localDate, $first->operational_date->toDateString());
        $this->assertSame($localDate, $second->operational_date->toDateString());
        $this->assertSame([1, 2], [$first->queue_number, $second->queue_number]);

        // The next branch-local day restarts at 001, whatever the UTC date is.
        Date::setTestNow(CarbonImmutable::parse($localDate.' 12:00:00', 'Asia/Kuala_Lumpur')->addDay()->utc());
        $next = $this->send($ca, $this->consultationVisit($ca, $doctor))->refresh();
        $this->assertSame(1, $next->queue_number);
        $this->assertSame(
            CarbonImmutable::parse($localDate)->addDay()->toDateString(),
            $next->operational_date->toDateString(),
        );
    }

    #[DataProvider('edges')]
    public function test_yesterdays_entry_is_carry_over_and_today_comes_first_at_every_edge(string $utc, string $localDate): void
    {
        Date::setTestNow('2026-01-01 00:00:00 UTC'); // staff assignments must already be effective at every instant below
        $ca = $this->actor('ca');
        $doctor = $this->doctor();

        $yesterdayNoon = CarbonImmutable::parse($localDate.' 12:00:00', 'Asia/Kuala_Lumpur')->subDay()->utc();
        Date::setTestNow($yesterdayNoon);
        $carry = $this->send($ca, $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']));

        Date::setTestNow($utc.' UTC');
        $today = $this->send($ca, $this->consultationVisit($ca, $doctor));

        $this->selectBranch($doctor);
        $block = $this->actingAs($doctor)->postJson(route('queue.search'), [])->assertOk()->json('callNext');
        $this->assertSame(CallNextService::STATE_READY, $block['state']);
        $this->assertSame($today->visit->visit_number, $block['candidate']['visitNumber']);
        $this->assertFalse($block['candidate']['carryOver']);

        $today->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => 'cancelled'])->save();
        $block = $this->actingAs($doctor)->postJson(route('queue.search'), [])->assertOk()->json('callNext');
        $this->assertSame($carry->visit->visit_number, $block['candidate']['visitNumber']);
        $this->assertTrue($block['candidate']['carryOver']);
        $this->assertSame(
            CarbonImmutable::parse($localDate)->subDay()->toDateString(),
            $block['candidate']['operationalDate'],
        );
    }

    #[DataProvider('edges')]
    public function test_the_doctors_room_day_is_the_branch_local_date(string $utc, string $localDate): void
    {
        Date::setTestNow($utc.' UTC');

        $this->assertSame($localDate, app(DoctorRoomService::class)->operationalDate($this->branch));
    }
}
