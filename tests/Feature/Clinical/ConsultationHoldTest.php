<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ConsultationHold;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConsultationHoldTest extends ClinicalTestCase
{
    public function test_hold_keeps_the_same_serving_queue_entry_and_is_exactly_once(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $attributes = $this->holdAttributes($visit->refresh(), $queue->refresh(), $encounter->refresh());

        $first = app(ConsultationHoldService::class)->hold($doctor, $visit, $attributes);
        $second = app(ConsultationHoldService::class)->hold($doctor, $visit, $attributes);

        $this->assertTrue($first->is($second));
        $this->assertNull($first->resumed_at);
        $this->assertSame(QueueEntry::STATUS_SERVING, $queue->refresh()->status);
        $this->assertDatabaseCount('queue_entries', 1);
        $this->assertDatabaseCount('consultation_holds', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'consultation.held')->count());

        $snapshot = app(QueueDirectoryService::class)->snapshot($doctor);
        $this->assertTrue($snapshot['serving'][0]['isHeld']);
        $this->assertSame($visit->visit_number, $snapshot['serving'][0]['visitNumber']);
    }

    public function test_changed_hold_replay_fails_without_another_transition(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $attributes = $this->holdAttributes($visit, $queue, $encounter);
        app(ConsultationHoldService::class)->hold($doctor, $visit, $attributes);

        try {
            app(ConsultationHoldService::class)->hold($doctor, $visit, [
                ...$attributes,
                'queue_lock_version' => $attributes['queue_lock_version'] + 1,
            ]);
            $this->fail('Changed hold replay unexpectedly succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('consultation_holds', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'consultation.held')->count());
    }

    public function test_hold_idempotency_key_cannot_replay_across_visits(): void
    {
        [$doctor, $ca, $firstVisit, $firstQueue] = $this->servingFixture();
        $firstEncounter = $this->startEncounter($doctor, $firstVisit, $firstQueue);
        $firstAttributes = $this->holdAttributes($firstVisit, $firstQueue, $firstEncounter);
        app(ConsultationHoldService::class)->hold($doctor, $firstVisit, $firstAttributes);

        $secondVisit = $this->consultationVisit($ca, $doctor);
        $secondQueue = $this->send($ca, $secondVisit);
        $this->selectBranch($doctor);
        $secondQueue = app(QueueEntryService::class)->call($doctor, $secondVisit, [
            'expected_branch_id' => $secondVisit->branch_id,
            'visit_lock_version' => $secondVisit->lock_version,
            'queue_lock_version' => $secondQueue->lock_version,
        ]);
        $secondEncounter = $this->startEncounter($doctor, $secondVisit->refresh(), $secondQueue->refresh());

        try {
            app(ConsultationHoldService::class)->hold($doctor, $secondVisit, [
                ...$this->holdAttributes($secondVisit, $secondQueue, $secondEncounter),
                'idempotency_key' => $firstAttributes['idempotency_key'],
            ]);
            $this->fail('A hold idempotency key was replayed against another Visit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('consultation_holds', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'consultation.held')->count());
    }

    public function test_held_patient_allows_another_call_and_resume_waits_for_the_active_patient(): void
    {
        [$doctor, $ca, $firstVisit, $firstQueue] = $this->servingFixture();
        $firstEncounter = $this->startEncounter($doctor, $firstVisit, $firstQueue);
        app(ConsultationHoldService::class)->hold(
            $doctor,
            $firstVisit,
            $this->holdAttributes($firstVisit, $firstQueue, $firstEncounter),
        );

        $secondVisit = $this->consultationVisit($ca, $doctor);
        $secondQueue = $this->send($ca, $secondVisit);
        $this->selectBranch($doctor);
        $secondQueue = app(QueueEntryService::class)->call($doctor, $secondVisit, [
            'expected_branch_id' => $secondVisit->branch_id,
            'visit_lock_version' => $secondVisit->lock_version,
            'queue_lock_version' => $secondQueue->lock_version,
        ]);
        $secondEncounter = $this->startEncounter($doctor, $secondVisit->refresh(), $secondQueue->refresh());

        try {
            app(ConsultationHoldService::class)->resume(
                $doctor,
                $firstVisit->refresh(),
                $this->resumeAttributes($firstVisit, $firstQueue, $firstEncounter),
            );
            $this->fail('Resume unexpectedly replaced an active consultation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hold', $exception->errors());
        }

        app(ConsultationHoldService::class)->hold(
            $doctor,
            $secondVisit->refresh(),
            $this->holdAttributes($secondVisit, $secondQueue, $secondEncounter),
        );
        $resumed = app(ConsultationHoldService::class)->resume(
            $doctor,
            $firstVisit->refresh(),
            $this->resumeAttributes($firstVisit, $firstQueue, $firstEncounter),
        );

        $this->assertNotNull($resumed->resumed_at);
        $this->assertSame(2, ConsultationHold::query()->count());
        $this->assertSame(2, QueueEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'consultation.resumed')->count());
    }

    public function test_doctor_cannot_call_a_second_patient_until_current_consultation_is_held(): void
    {
        [$doctor, $ca] = $this->servingFixture();
        $nextVisit = $this->consultationVisit($ca, $doctor);
        $nextQueue = $this->send($ca, $nextVisit);
        $this->selectBranch($doctor);

        $this->expectException(ValidationException::class);
        app(QueueEntryService::class)->call($doctor, $nextVisit, [
            'expected_branch_id' => $nextVisit->branch_id,
            'visit_lock_version' => $nextVisit->lock_version,
            'queue_lock_version' => $nextQueue->lock_version,
        ]);
    }

    /** @return array<string, mixed> */
    private function holdAttributes(Visit $visit, QueueEntry $queue, ClinicalEncounter $encounter): array
    {
        return [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function resumeAttributes(Visit $visit, QueueEntry $queue, ClinicalEncounter $encounter): array
    {
        return [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
