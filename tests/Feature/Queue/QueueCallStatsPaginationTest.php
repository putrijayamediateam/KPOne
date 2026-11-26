<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Display\QueueCallStatsService;
use App\Domain\Queue\Models\QueueCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class QueueCallStatsPaginationTest extends QueueTestCase
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

    private function calls(array $rooms): void
    {
        $ca = $this->actor();
        $entry = $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
        foreach ($rooms as [$room, $service, $recall]) {
            (new QueueCall)->forceFill([
                'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
                'queue_entry_id' => $entry->id, 'queue_series' => 'A', 'service' => $service,
                'is_recall' => $recall, 'room_name' => $room, 'queue_number' => $entry->queue_number,
                'operational_date' => now($this->branch->timezone)->toDateString(),
                'called_by_user_id' => $ca->id, 'called_at' => now()->utc(),
            ])->save();
        }
    }

    public function test_twenty_six_room_labels_are_paged_without_truncating_totals_and_invalid_pages_clamp(): void
    {
        $rooms = array_map(fn (int $i): array => [sprintf('Synthetic Room %02d', $i), 'consultation', false], range(1, 26));
        $this->calls([...$rooms, ['Synthetic Room 01', 'consultation', true]]);
        $service = app(QueueCallStatsService::class);
        $first = $service->today($this->branch);
        $second = $service->today($this->branch, 2);
        $this->assertCount(25, $first['byRoom']);
        $this->assertSame([['room' => 'Synthetic Room 26', 'calls' => 1]], $second['byRoom']);
        $this->assertSame(['currentPage' => 2, 'lastPage' => 2, 'perPage' => 25, 'total' => 26], $second['roomPagination']);
        foreach ([$first, $second] as $stats) {
            $this->assertSame(27, $stats['total']);
            $this->assertSame(1, $stats['recalls']);
            $this->assertSame(['consultation' => ['calls' => 26, 'recalls' => 1]], $stats['byService']);
        }
        $this->assertSame($second, $service->today($this->branch, 999));
        $this->actingAs($this->actor('ca_supervisor'));
        $this->get(route('queue-display.settings', ['rooms_page' => 2]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('callsToday.roomPagination.currentPage', 2)->has('callsToday.byRoom', 1)->where('callsToday.total', 27));
        $this->getJson(route('queue-display.settings', ['rooms_page' => 0]))->assertUnprocessable();
        Date::setTestNow(CarbonImmutable::parse('2026-08-26 00:00:00', $this->branch->timezone)->utc());
        $empty = $service->today($this->branch, 2);
        $this->assertSame(0, $empty['total']);
        $this->assertSame([], $empty['byRoom']);
        $this->assertSame(['currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'total' => 0], $empty['roomPagination']);
    }

    public function test_null_room_merges_with_literal_no_room_and_service_totals_include_recalls_once(): void
    {
        $this->calls([[null, 'consultation', false], ['No room', 'dispensary', true], [null, 'treatment', false]]);
        $stats = app(QueueCallStatsService::class)->today($this->branch);
        $this->assertSame([['room' => 'No room', 'calls' => 3]], $stats['byRoom']);
        $this->assertSame(1, $stats['roomPagination']['total']);
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['recalls']);
        $this->assertSame(['consultation' => ['calls' => 1, 'recalls' => 0], 'dispensary' => ['calls' => 0, 'recalls' => 1], 'treatment' => ['calls' => 1, 'recalls' => 0]], $stats['byService']);
    }

    public function test_a_call_inserted_after_the_read_statement_cannot_split_totals_from_room_rows(): void
    {
        $this->calls([['Synthetic Before', 'consultation', false]]);
        $original = QueueCall::query()->where('branch_id', $this->branch->id)->sole();
        $inserted = false;
        DB::listen(function ($query) use (&$inserted, $original): void {
            if ($inserted || ! str_contains($query->sql, 'WITH scoped_calls AS MATERIALIZED')) {
                return;
            }
            // Laravel dispatches this after SQL has returned, before the service maps
            // its result. A later statement would see this newly inserted evidence.
            $inserted = true;
            $copy = $original->replicate();
            $copy->room_name = 'Synthetic After';
            $copy->is_recall = true;
            $copy->save();
        });
        $service = app(QueueCallStatsService::class);
        $first = $service->today($this->branch);
        $this->assertTrue($inserted);
        $this->assertSame(1, $first['total']);
        $this->assertSame(0, $first['recalls']);
        $this->assertSame(1, $first['roomPagination']['total']);
        $this->assertSame([['room' => 'Synthetic Before', 'calls' => 1]], $first['byRoom']);
        $next = $service->today($this->branch);
        $this->assertSame(2, $next['total']);
        $this->assertSame(1, $next['recalls']);
        $this->assertSame(2, $next['roomPagination']['total']);
        $this->assertSame($next['total'], array_sum(array_column($next['byRoom'], 'calls')));
    }

    public function test_room_cardinality_does_not_grow_queries_or_the_returned_page(): void
    {
        $service = app(QueueCallStatsService::class);
        $this->calls([['Synthetic Room 01', 'consultation', false]]);
        foreach ([1, 26] as $size) {
            if ($size === 26) {
                $this->calls(array_map(fn (int $i): array => [sprintf('Synthetic Room %02d', $i), 'consultation', false], range(2, 26)));
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $stats = $service->today($this->branch);
                $queries = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame(1, $queries);
            $this->assertCount(min(25, $size), $stats['byRoom']);
            $this->assertSame($size, $stats['total']);
            $this->assertSame($size, $stats['roomPagination']['total']);
        }
    }
}
