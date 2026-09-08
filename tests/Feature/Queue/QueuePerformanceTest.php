<?php

namespace Tests\Feature\Queue;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class QueuePerformanceTest extends QueueTestCase
{
    public function test_queue_poll_omits_stable_metadata_and_reduces_the_representative_query_graph(): void
    {
        try {
            Date::setTestNow('2026-08-25 01:00:00 UTC');
            $ca = $this->actor('ca');
            $doctor = $this->doctor();

            $this->send($ca, $this->consultationVisit($ca, $doctor));

            Date::setTestNow('2026-08-27 01:00:00 UTC');
            $this->send($ca, $this->consultationVisit($ca, $doctor));
            $servingVisit = $this->consultationVisit($ca, $doctor);
            $servingEntry = $this->send($ca, $servingVisit);
            $servingEntry->forceFill([
                'status' => 'serving',
                'called_at' => now()->utc(),
                'called_by_user_id' => $doctor->id,
            ])->save();
            $this->selectBranch($ca);

            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->actingAs($ca)->get(route('queue.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Queue/Index')
                    ->has('snapshot.branch')
                    ->has('snapshot.doctors', 1)
                    ->has('snapshot.waiting.data', 1)
                    ->has('snapshot.carryOver.data', 1)
                    ->has('snapshot.serving', 1));
            $getCount = count(DB::getQueryLog());

            DB::flushQueryLog();
            $this->actingAs($ca)->postJson(route('queue.search'))
                ->assertOk()
                ->assertJsonMissingPath('branch')
                ->assertJsonMissingPath('doctors')
                ->assertJsonPath('scope', 'branch')
                ->assertJsonPath('waiting.data.0.doctorEligible', true)
                ->assertJsonCount(1, 'waiting.data')
                ->assertJsonCount(1, 'carryOver.data')
                ->assertJsonCount(1, 'serving');
            $pollCount = count(DB::getQueryLog());

            DB::flushQueryLog();
            $this->actingAs($ca)->postJson(route('queue.search'), [
                'query' => 'Synthetic Visit Patient',
                'doctor_id' => $doctor->id,
                'priority' => 'normal',
            ])->assertOk();
            $filteredPollCount = count(DB::getQueryLog());

            // Before the split/batched hydration this same fixture used 26 GET
            // queries and 25 poll queries. Keep generous ceilings so framework
            // bookkeeping can vary while redundant per-section hydration cannot
            // silently return.
            $this->assertLessThanOrEqual(20, $getCount);
            $this->assertLessThanOrEqual(19, $pollCount);
            $this->assertLessThan(26, $getCount);
            $this->assertLessThan(25, $pollCount);
            $this->assertLessThanOrEqual(19, $filteredPollCount);
            $this->assertLessThan(25, $filteredPollCount);
            $this->assertContains('queue/search', config('inertia.devtools.except'));
        } finally {
            Date::setTestNow();
        }
    }
}
