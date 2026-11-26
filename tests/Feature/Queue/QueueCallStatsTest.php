<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Display\QueueCallStatsService;
use App\Domain\Queue\Models\QueueCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/** Q-16f: the "Calls today" figures for the TV settings page (owner decision 25 Nov 2026). */
class QueueCallStatsTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc());
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function recordCall(string $service, bool $recall, ?string $room, string $day = '2026-08-25'): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $entry = $this->send($ca, $visit);
        (new QueueCall)->forceFill([
            'organisation_id' => $this->organisation->id,
            'branch_id' => $this->branch->id,
            'queue_entry_id' => $entry->id,
            'queue_series' => 'A',
            'service' => $service,
            'is_recall' => $recall,
            'room_name' => $room,
            'queue_number' => $entry->queue_number,
            'operational_date' => $day,
            'called_by_user_id' => $ca->id,
            'called_at' => now()->utc(),
        ])->save();
    }

    public function test_counts_today_by_service_and_room_and_nothing_else(): void
    {
        $this->recordCall('consultation', false, 'Consultation Room 1');
        $this->recordCall('consultation', true, 'Consultation Room 1');
        $this->recordCall('consultation', false, 'Consultation Room 2');
        $this->recordCall('dispensary', false, null);
        $this->recordCall('consultation', false, 'Consultation Room 1', '2026-08-24');

        $stats = app(QueueCallStatsService::class)->today($this->branch);

        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['recalls']);
        $this->assertSame(['calls' => 2, 'recalls' => 1], $stats['byService']['consultation']);
        $this->assertSame(['calls' => 1, 'recalls' => 0], $stats['byService']['dispensary']);
        $this->assertSame([
            ['room' => 'Consultation Room 1', 'calls' => 2],
            ['room' => 'Consultation Room 2', 'calls' => 1],
            ['room' => 'No room', 'calls' => 1],
        ], $stats['byRoom']);
        $this->assertSame(['byRoom', 'byService', 'operationalDate', 'recalls', 'roomPagination', 'total'], collect($stats)->keys()->sort()->values()->all());
    }

    public function test_the_settings_page_carries_it_and_the_tv_feed_does_not(): void
    {
        $this->recordCall('consultation', false, 'Consultation Room 1');
        $this->actingAs($this->actor('ca_supervisor'));
        $this->get(route('queue-display.settings'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('callsToday.total', 1));

        $this->actingAs($this->actor('queue_display'));
        $this->assertStringNotContainsString('callsToday', json_encode($this->getJson(route('queue-display.feed'))->json()));
    }
}
