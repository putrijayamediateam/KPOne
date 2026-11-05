<?php

namespace Tests\Feature\Queue;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\CallNextService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Q-4 Call next (owner decisions 2026-11-05). */
class CallNextTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 09:00 in Kuala Lumpur, so "today" never depends on the hour the suite runs.
        Date::setTestNow('2026-08-25 01:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function block(User $actor, ?int $doctorId = null): ?array
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->postJson(route('queue.search'), array_filter(['doctor_id' => $doctorId]))
            ->assertOk()->json('callNext');
    }

    /** @param array<string, mixed> $candidate */
    private function callNext(User $actor, int $doctorId, array $candidate): TestResponse
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->post(route('queue.call-next'), [
            'expected_branch_id' => $this->branch->id,
            'doctor_user_id' => $doctorId,
            'visit_number' => $candidate['visitNumber'],
            'visit_lock_version' => $candidate['visitLockVersion'],
            'queue_lock_version' => $candidate['queueLockVersion'],
        ]);
    }

    private function flag(User $ca, Visit $visit, QueueEntry $entry): void
    {
        $this->selectBranch($ca);
        $this->actingAs($ca)->post(route('queue.readiness.set', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $entry->refresh()->lock_version,
            'reason_code' => 'other_administrative',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
    }

    public function test_today_comes_first_urgent_before_normal_and_carry_over_only_when_today_is_empty(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        Date::setTestNow('2026-08-24 01:00:00 UTC');
        $carry = $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']);
        $this->send($ca, $carry);
        Date::setTestNow('2026-08-25 01:00:00 UTC');
        $early = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $early);
        Date::setTestNow('2026-08-25 01:05:00 UTC');
        $urgent = $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']);
        $this->send($ca, $urgent);
        $other = $this->consultationVisit($ca, $this->doctor(), ['priority' => 'urgent']);
        $this->send($ca, $other);

        $block = $this->block($doctor);
        $this->assertSame('own', $block['mode']);
        $this->assertSame(CallNextService::STATE_READY, $block['state']);
        $this->assertSame($urgent->visit_number, $block['candidate']['visitNumber']);
        $this->assertFalse($block['candidate']['carryOver']);

        foreach ([$urgent, $early] as $visit) {
            $visit->queueEntry->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => 'cancelled'])->save();
        }
        $block = $this->block($doctor);
        $this->assertSame($carry->visit_number, $block['candidate']['visitNumber']);
        $this->assertTrue($block['candidate']['carryOver']);
        $this->assertSame('2026-08-24', $block['candidate']['operationalDate']);
    }

    public function test_entries_with_an_active_not_ready_flag_are_skipped(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $first = $this->consultationVisit($ca, $doctor);
        $firstEntry = $this->send($ca, $first);
        Date::setTestNow('2026-08-25 01:01:00 UTC');
        $second = $this->consultationVisit($ca, $doctor);
        $secondEntry = $this->send($ca, $second);

        $this->flag($ca, $first, $firstEntry);
        $this->assertSame($second->visit_number, $this->block($doctor)['candidate']['visitNumber']);

        $this->flag($ca, $second, $secondEntry);
        $block = $this->block($doctor);
        $this->assertSame(CallNextService::STATE_ALL_NOT_READY, $block['state']);
        $this->assertNull($block['candidate']);
        $this->assertSame('Everyone waiting is marked not ready to call.', $block['reason']);
    }

    public function test_nobody_waiting(): void
    {
        $doctor = $this->doctor();
        $block = $this->block($doctor);

        $this->assertSame(CallNextService::STATE_NONE_WAITING, $block['state']);
        $this->assertSame('Nobody is waiting.', $block['reason']);
    }

    public function test_the_doctor_calls_their_own_next_patient_with_the_ordinary_call_in(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $candidate = $this->block($doctor)['candidate'];

        $this->callNext($doctor, $doctor->id, $candidate)
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(QueueEntry::STATUS_SERVING, $visit->queueEntry()->sole()->status);
        $this->assertSame(1, QueueCall::query()->count());
        $audit = AuditLog::query()->where('event', 'queue.called')->sole();
        $this->assertSame($doctor->id, $audit->actor_user_id);
        // The doctor is now busy, so the next block says so without naming anyone.
        $block = $this->block($doctor);
        $this->assertSame(CallNextService::STATE_BUSY, $block['state']);
        $this->assertNull($block['candidate']);
    }

    public function test_a_patient_who_is_no_longer_next_is_refused_and_nothing_is_called(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $normal = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $normal);
        $stale = $this->block($doctor)['candidate'];
        Date::setTestNow('2026-08-25 01:02:00 UTC');
        $urgent = $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']);
        $this->send($ca, $urgent);

        $this->callNext($doctor, $doctor->id, $stale)
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasErrors(['next_changed' => 'The next patient changed. Check the patient shown and call again.']);
        $this->assertSame(0, QueueCall::query()->count());
        $this->assertSame(QueueEntry::STATUS_WAITING, $normal->queueEntry()->sole()->status);

        // The same patient with an older version is also "changed", never a silent call.
        $fresh = $this->block($doctor)['candidate'];
        $this->assertSame($urgent->visit_number, $fresh['visitNumber']);
        $this->callNext($doctor, $doctor->id, [...$fresh, 'queueLockVersion' => $fresh['queueLockVersion'] + 1])
            ->assertSessionHasErrors('next_changed');
        $this->assertSame(0, QueueCall::query()->count());

        $urgent->queueEntry->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => 'cancelled'])->save();
        $normal->queueEntry->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => 'cancelled'])->save();
        $this->callNext($doctor, $doctor->id, $fresh)
            ->assertSessionHasErrors(['next_none' => 'Nobody is waiting who can be called now.']);
    }

    public function test_a_branch_caller_chooses_a_doctor_first_and_calls_that_doctors_next_patient(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $director = $this->actor('director');

        $block = $this->block($director);
        $this->assertSame('branch', $block['mode']);
        $this->assertSame(CallNextService::STATE_NO_DOCTOR, $block['state']);
        $this->assertNull($block['candidate']);

        $block = $this->block($director, $doctor->id);
        $this->assertSame(CallNextService::STATE_READY, $block['state']);
        $this->assertSame($doctor->name, $block['doctorName']);
        $this->callNext($director, $doctor->id, $block['candidate'])->assertSessionHasNoErrors();
        $this->assertSame(QueueEntry::STATUS_SERVING, $visit->queueEntry()->sole()->status);
        $this->assertSame($director->id, AuditLog::query()->where('event', 'queue.called')->sole()->actor_user_id);
    }

    public function test_who_may_use_call_next(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $otherDoctor);
        $this->send($ca, $visit);

        // A CA sees the board but cannot call: no block, and the route is refused.
        $this->assertNull($this->block($ca));
        $candidate = $this->block($otherDoctor)['candidate'];
        $this->callNext($ca, $otherDoctor->id, $candidate)->assertForbidden();
        // A doctor can never call another doctor's next patient.
        $this->callNext($doctor, $otherDoctor->id, $candidate)->assertForbidden();
        $this->assertSame(0, QueueCall::query()->count());
    }

    public function test_the_room_rule_and_the_busy_rule_still_apply(): void
    {
        $director = $this->actor('director');
        $room = app(QueueDisplayAdministrationService::class)->createRoom($director, $this->branch, [
            'kind' => 'consultation', 'name' => 'Consultation Room 2', 'sort_order' => 2,
        ]);
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);

        $block = $this->block($doctor);
        $this->assertSame(CallNextService::STATE_NO_ROOM, $block['state']);
        $this->assertSame('Choose "My room today" before calling.', $block['reason']);
        $this->assertSame('This doctor has not chosen a room for today.', $this->block($director, $doctor->id)['reason']);

        $this->selectBranch($doctor);
        app(DoctorRoomService::class)->select($doctor, $room->id, $this->branch->id);
        $block = $this->block($doctor);
        $this->assertSame('Consultation Room 2', $block['candidate']['roomName']);
        $this->callNext($doctor, $doctor->id, $block['candidate'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Called '.$block['candidate']['queueNumber'].' to Consultation Room 2.']);

        // Busy: a stale confirm for a second patient is refused by the ordinary Call In rule.
        Date::setTestNow('2026-08-25 01:03:00 UTC');
        $second = $this->consultationVisit($ca, $doctor);
        $secondEntry = $this->send($ca, $second);
        $this->callNext($doctor, $doctor->id, [
            'visitNumber' => $second->visit_number,
            'visitLockVersion' => $second->refresh()->lock_version,
            'queueLockVersion' => $secondEntry->lock_version,
        ])->assertSessionHasErrors('queue');
        $this->assertSame(QueueEntry::STATUS_WAITING, $secondEntry->refresh()->status);
    }
}
