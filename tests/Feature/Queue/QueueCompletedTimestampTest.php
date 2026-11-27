<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Clinical\ClinicalTestCase;

class QueueCompletedTimestampTest extends ClinicalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(CarbonImmutable::parse('2026-11-26 09:00:00', $this->branch->timezone)->utc());
    }

    /** @return array{User, User, Visit, QueueEntry} */
    private function checkedOut(?User $doctor = null, ?User $ca = null): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        Date::setTestNow(now()->addMinute());
        app(CompleteConsultationService::class)->complete($doctor, $visit, [
            'expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version,
            'lock_version' => null, 'service_deliveries' => [],
        ]);

        return [$doctor, $ca, $visit->refresh(), $queue->refresh()];
    }

    public function test_only_authorized_completed_workload_rows_receive_the_actual_checkout_timestamp(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->checkedOut();
        $directory = app(QueueDirectoryService::class);
        $this->selectBranch($doctor);
        $page = $directory->workload($doctor, $this->branch->id, 'completed');
        $this->assertSame($visit->visit_number, $page['data'][0]['visitNumber']);
        $this->assertSame($queue->removed_at->toIso8601String(), $page['data'][0]['removedAt']);
        $this->assertNotSame($page['data'][0]['calledAt'], $page['data'][0]['removedAt']);
        $this->assertNotSame($page['data'][0]['queuedAt'], $page['data'][0]['removedAt']);
        foreach ([$directory->snapshot($doctor), $directory->poll($doctor)] as $ordinary) {
            $this->assertStringNotContainsString('"removedAt":', json_encode($ordinary, JSON_THROW_ON_ERROR));
        }
        [$doctor, , $heldVisit, $heldQueue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $heldVisit, $heldQueue);
        $this->holdEncounter($doctor, $heldVisit, $heldQueue, $encounter);
        $held = $directory->workload($doctor, $this->branch->id, 'held');
        $this->assertCount(1, $held['data']);
        $this->assertArrayNotHasKey('removedAt', $held['data'][0]);
        $otherDoctor = $this->doctor();
        $this->selectBranch($otherDoctor);
        $this->assertSame(0, $directory->workload($otherDoctor, $this->branch->id, 'completed')['total']);
        $this->selectBranch($ca);
        $this->assertSame($queue->removed_at->toIso8601String(), $directory->workload($ca, $this->branch->id, 'completed')['data'][0]['removedAt']);
        $this->getJson(route('queue.workload', ['expected_branch_id' => $this->branch->id + 1000, 'section' => 'completed']))->assertForbidden();
        $this->selectBranch($this->actor('finance_officer'));
        $this->getJson(route('queue.workload', ['expected_branch_id' => $this->branch->id, 'section' => 'completed']))->assertForbidden();
    }

    public function test_projected_times_keep_exact_branch_local_day_edges(): void
    {
        $start = CarbonImmutable::parse('2026-11-26 00:00:00', $this->branch->timezone);
        Date::setTestNow($start->addMinutes(5)->utc());
        $expected = [];
        foreach ([$start->subSecond(), $start, $start->addDay()->subSecond(), $start->addDay()] as $index => $time) {
            [, , $visit, $queue] = $this->checkedOut();
            // Actual checkout state; only the synthetic read-boundary timestamp is placed at each day edge.
            $queue->forceFill(['removed_at' => $time->utc()])->save();
            if (in_array($index, [1, 2], true)) {
                $expected[$visit->visit_number] = $time->utc()->toIso8601String();
            }
        }
        $this->selectBranch($ca = $this->actor('ca'));
        $page = app(QueueDirectoryService::class)->workload($ca, $this->branch->id, 'completed');
        $this->assertSame(2, $page['total']);
        $this->assertSame($expected, $page['data']->pluck('removedAt', 'visitNumber')->all());
        $this->assertSame('2026-11-25', $start->utc()->toDateString());
    }

    public function test_timestamp_enrichment_has_constant_queries_and_identity_pairing_across_twenty_six_rows(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->checkedOut();
        $expected = [$visit->visit_number => $queue->removed_at->toIso8601String()];
        $directory = app(QueueDirectoryService::class);
        $this->selectBranch($ca);
        $directory->workload($ca, $this->branch->id, 'completed');
        $read = function () use ($directory, $ca): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $page = $directory->workload($ca, $this->branch->id, 'completed');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$page, $count];
        };
        [, $singleQueries] = $read();
        for ($i = 1; $i < 26; $i++) {
            [, , $visit, $queue] = $this->checkedOut($doctor, $ca);
            $expected[$visit->visit_number] = $queue->removed_at->toIso8601String();
        }
        $versions = QueueEntry::query()->pluck('lock_version', 'id')->all();
        $this->selectBranch($ca);
        [$first, $manyQueries] = $read();
        $this->assertSame($singleQueries, $manyQueries, 'Timestamp enrichment adds no per-row query.');
        $this->assertSame(26, $first['total']);
        $this->assertCount(25, $first['data']);
        $last = $directory->workload($ca, $this->branch->id, 'completed', 2);
        $this->assertCount(1, $last['data']);
        $this->assertSame($expected, $first['data']->concat($last['data'])->pluck('removedAt', 'visitNumber')->all());
        $this->assertSame($versions, QueueEntry::query()->pluck('lock_version', 'id')->all());
    }
}
