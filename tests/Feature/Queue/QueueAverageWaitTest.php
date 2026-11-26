<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueWaitStatsService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/** Q-16e: the board's average wait (owner decision 25 Nov 2026). */
class QueueAverageWaitTest extends QueueTestCase
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

    private function stats(User $actor)
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->getJson(route('queue.average-wait', ['expected_branch_id' => $this->branch->id]));
    }

    /** Called entries with the given waits in minutes, bypassing the call flow (evidence rows only). */
    private function called(User $ca, User $doctor, array $minutes): void
    {
        foreach ($minutes as $wait) {
            $visit = $this->consultationVisit($ca, $doctor);
            $entry = $this->send($ca, $visit);
            QueueEntry::query()->whereKey($entry->id)->toBase()->update(['status' => QueueEntry::STATUS_REMOVED, 'called_at' => now()->utc()->addMinutes($wait), 'called_by_user_id' => $doctor->id, 'removed_at' => now()->utc()->addMinutes($wait + 1), 'removal_reason' => 'sent_to_dispensary']);
        }
    }

    public function test_no_figure_below_three_called_patients_then_the_average_of_the_waits(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->called($ca, $doctor, [10, 20]);
        $this->stats($ca)->assertOk()->assertJson(['averageWaitMinutes' => null, 'samples' => 2]);

        $this->called($ca, $doctor, [30]);
        $this->stats($ca)->assertOk()->assertJson(['averageWaitMinutes' => 20, 'samples' => 3]);
        $this->assertSame(['averageWaitMinutes', 'operationalDate', 'samples'], collect($this->stats($ca)->json())->keys()->sort()->values()->all());
    }

    public function test_a_doctor_with_only_their_own_queue_gets_their_own_average_and_other_roles_are_refused(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $other = $this->doctor();
        $this->called($ca, $doctor, [10, 10, 10]);
        $this->called($ca, $other, [40, 40, 40]);

        $this->stats($doctor)->assertOk()->assertJson(['averageWaitMinutes' => 10, 'samples' => 3]);
        $this->stats($ca)->assertOk()->assertJson(['averageWaitMinutes' => 25, 'samples' => 6]);
        $this->stats($this->actor('finance_officer'))->assertForbidden();
    }

    public function test_daily_statistics_use_one_scalar_aggregate_and_exclude_uncalled_rows(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->called($ca, $doctor, [0, 1, 1]);
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->selectBranch($doctor);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $stats = app(QueueWaitStatsService::class)->forActiveBranch($doctor, $this->branch->id);
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'queue_entries'));
        DB::disableQueryLog();
        $this->assertSame(['averageWaitMinutes' => 1, 'samples' => 3, 'operationalDate' => '2026-08-25'], $stats);
        $this->assertCount(1, $queries);
        $sql = strtolower($queries->sole()['query']);
        $this->assertStringContainsString('count(*) as samples', $sql);
        $this->assertStringContainsString('avg(abs(extract(epoch', $sql);
        $this->assertStringContainsString('assigned_doctor_user_id', $sql);
    }
}
