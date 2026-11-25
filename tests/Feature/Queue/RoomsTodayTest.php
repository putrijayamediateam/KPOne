<?php

namespace Tests\Feature\Queue;

use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Q-16d: the read-only Rooms today view (owner decision 25 Nov 2026). */
class RoomsTodayTest extends QueueTestCase
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

    /** @return TestResponse<Response> */
    private function roomsView(User $actor)
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->getJson(route('queue.rooms-today', ['expected_branch_id' => $this->branch->id]));
    }

    public function test_the_board_holder_sees_each_doctors_counts_and_no_patient_data(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $idle = $this->doctor();
        $a = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $a);
        $b = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $b);
        $this->selectBranch($ca);
        $this->actingAs($ca)->post(route('queue.readiness.set', $b->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $b->queueEntry->refresh()->lock_version,
            'reason_code' => 'other_administrative',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $json = $this->roomsView($ca)->assertOk()->json();
        $rows = collect($json['rows'])->keyBy('doctorId');
        $this->assertSame(2, $rows[$doctor->id]['waiting']);
        $this->assertSame(1, $rows[$doctor->id]['notReady']);
        $this->assertSame('free', $rows[$doctor->id]['state']);
        $this->assertSame(0, $rows[$idle->id]['waiting']);
        $this->assertSame(3, $rows[$doctor->id]['heldCap']);
        $this->assertStringNotContainsString($a->patient->full_name, json_encode($json));
        $this->assertStringNotContainsString($a->visit_number, json_encode($json));

        $this->selectBranch($doctor);
        $candidate = $this->actingAs($doctor)->postJson(route('queue.search'), [])->assertOk()->json('callNext.candidate');
        $this->actingAs($doctor)->post(route('queue.call-next'), [
            'expected_branch_id' => $this->branch->id,
            'doctor_user_id' => $doctor->id,
            'visit_number' => $candidate['visitNumber'],
            'visit_lock_version' => $candidate['visitLockVersion'],
            'queue_lock_version' => $candidate['queueLockVersion'],
        ])->assertRedirect();
        $rows = collect($this->roomsView($ca)->json('rows'))->keyBy('doctorId');
        $this->assertSame('serving', $rows[$doctor->id]['state']);
        $this->assertNotNull($rows[$doctor->id]['servingNumber']);
    }

    public function test_a_doctor_with_only_their_own_queue_and_another_branch_are_refused(): void
    {
        $doctor = $this->doctor();
        $this->roomsView($doctor)->assertForbidden();
        $this->selectBranch($this->actor('ca'));
        $this->actingAs($this->actor('ca'))->getJson(route('queue.rooms-today', ['expected_branch_id' => $this->branch->id + 999]))->assertForbidden();
    }
}
