<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\QueueEntry;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/** Q-16e: the board's average wait (owner decision 25 Nov 2026). */
class QueueAverageWaitTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-25 01:00:00 UTC');
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
}
