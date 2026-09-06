<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Services\VisitAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class QueueConcurrencyTest extends QueueTestCase
{
    public function test_stale_call_is_rejected_after_first_call(): void
    {
        $ca = $this->actor('ca_supervisor');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $entry = $this->send($ca, $visit);
        $attributes = [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ];
        app(QueueEntryService::class)->call($ca, $visit, $attributes);

        $this->expectException(ValidationException::class);
        app(QueueEntryService::class)->call($ca, $visit, $attributes);
    }

    public function test_stale_visit_edit_is_rejected_after_queue_state_changes(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($supervisor, $doctor);
        $entry = $this->send($supervisor, $visit);
        app(QueueEntryService::class)->call($supervisor, $visit, [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ]);

        $this->expectException(AuthorizationException::class);
        app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => $this->visitReasonIds($visit),
            'priority' => 'urgent',
            'coverage_type' => 'self_pay',
        ], $supervisor);
    }

    public function test_stale_waiting_queue_version_is_rejected_without_mutating_visit(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $staleVersion = $entry->lock_version;
        $entry->forceFill(['lock_version' => $staleVersion + 1])->save();

        try {
            app(VisitAdministrationService::class)->update($visit, [
                'expected_branch_id' => $this->branch->id,
                'lock_version' => $visit->lock_version,
                'queue_lock_version' => $staleVersion,
                'visit_type' => 'consultation',
                'assigned_doctor_user_id' => $doctor->id,
                'visit_reason_public_ids' => $this->visitReasonIds($visit),
                'priority' => 'urgent',
                'coverage_type' => 'self_pay',
            ], $ca);
            $this->fail('The stale Queue version was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('queue_lock_version', $exception->errors());
        }

        $this->assertSame('normal', $visit->refresh()->priority);
        $this->assertSame(1, $visit->lock_version);
        $this->assertSame(QueueEntry::STATUS_WAITING, $entry->refresh()->status);
    }

    public function test_edit_loaded_before_waiting_cancellation_is_rejected_after_queue_removal(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $staleEdit = [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => $this->visitReasonIds($visit),
            'priority' => 'urgent',
            'coverage_type' => 'self_pay',
        ];

        app(VisitAdministrationService::class)->cancel($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'cancellation_reason' => 'Synthetic competing cancellation',
        ], $ca);

        try {
            app(VisitAdministrationService::class)->update($visit, $staleEdit, $ca);
            $this->fail('The edit loaded before Queue removal was accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertSame('cancelled', $visit->refresh()->status);
        $this->assertSame(QueueEntry::STATUS_REMOVED, $entry->refresh()->status);
    }
}
