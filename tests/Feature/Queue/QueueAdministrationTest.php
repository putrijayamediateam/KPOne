<?php

namespace Tests\Feature\Queue;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class QueueAdministrationTest extends QueueTestCase
{
    public function test_fresh_waiting_visit_priority_edits_use_the_projected_queue_version_without_mutating_queue(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $queueUpdatedAt = $entry->updated_at;
        $queueAuditCount = AuditLog::query()->where('event', 'like', 'queue.%')->count();

        $detail = app(VisitDirectoryService::class)->detail($ca, $visit);
        $this->assertSame($entry->lock_version, $detail['queue']['lockVersion']);
        $this->get(route('visits.edit', $visit))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visit.lockVersion', $visit->lock_version)
                ->where('visit.queue.lockVersion', $entry->lock_version)
                ->where('visit.queue.status', QueueEntry::STATUS_WAITING));
        $this->postJson(route('queue.search'))->assertOk();

        $this->patch(route('visits.update', $visit), [
            ...$this->waitingUpdateAttributes($visit, $entry),
            'priority' => 'urgent',
        ])->assertRedirect(route('visits.show', $visit));

        $urgentVisit = $visit->refresh();
        $sameEntry = $entry->refresh();
        $this->assertSame('urgent', $urgentVisit->priority);
        $this->assertSame(2, $urgentVisit->lock_version);
        $this->assertSame($entry->id, $sameEntry->id);
        $this->assertSame($entry->queue_number, $sameEntry->queue_number);
        $this->assertSame(QueueEntry::STATUS_WAITING, $sameEntry->status);
        $this->assertSame(1, $sameEntry->lock_version);
        $this->assertTrue($sameEntry->updated_at->equalTo($queueUpdatedAt));

        $reopened = app(VisitDirectoryService::class)->detail($ca, $urgentVisit);
        $this->assertSame($sameEntry->lock_version, $reopened['queue']['lockVersion']);
        $this->get(route('visits.edit', $urgentVisit))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visit.lockVersion', 2)
                ->where('visit.queue.lockVersion', 1)
                ->where('visit.priority', 'urgent'));

        $this->patch(route('visits.update', $urgentVisit), [
            ...$this->waitingUpdateAttributes($urgentVisit, $sameEntry),
            'priority' => 'normal',
        ])->assertRedirect(route('visits.show', $urgentVisit));

        $this->assertSame('normal', $urgentVisit->refresh()->priority);
        $this->assertSame(3, $urgentVisit->lock_version);
        $this->assertSame(1, $sameEntry->refresh()->lock_version);
        $this->assertSame(QueueEntry::STATUS_WAITING, $sameEntry->status);
        $this->assertSame($queueAuditCount, AuditLog::query()->where('event', 'like', 'queue.%')->count());
    }

    public function test_doctor_calls_own_waiting_patient_and_audit_is_structural(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->selectBranch($doctor);

        $called = app(QueueEntryService::class)->call($doctor, $visit, [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ]);

        $this->assertSame(QueueEntry::STATUS_SERVING, $called->status);
        $this->assertSame($doctor->id, $called->called_by_user_id);
        $audit = AuditLog::query()
            ->where('organisation_id', $this->organisation->id)
            ->where('event', 'queue.called')
            ->firstOrFail();
        $this->assertArrayNotHasKey('queue_number', $audit->metadata);
        $this->assertArrayNotHasKey('doctor_id', $audit->metadata);
    }

    public function test_waiting_visit_can_be_cancelled_atomically_but_serving_visit_cannot(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $waitingVisit = $this->consultationVisit($ca, $doctor);
        $waiting = $this->send($ca, $waitingVisit);
        app(VisitAdministrationService::class)->cancel($waitingVisit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $waitingVisit->lock_version,
            'queue_lock_version' => $waiting->lock_version,
            'cancellation_reason' => 'Synthetic waiting cancellation',
        ], $ca);
        $this->assertSame(QueueEntry::STATUS_REMOVED, $waiting->refresh()->status);

        $servingVisit = $this->consultationVisit($ca, $doctor);
        $serving = $this->send($ca, $servingVisit);
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $servingVisit, [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $servingVisit->lock_version,
            'queue_lock_version' => $serving->lock_version,
        ]);
        $this->selectBranch($ca);

        $this->expectException(AuthorizationException::class);
        app(VisitAdministrationService::class)->cancel($servingVisit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $servingVisit->lock_version,
            'queue_lock_version' => $serving->refresh()->lock_version,
            'cancellation_reason' => 'Synthetic prohibited cancellation',
        ], $ca);
    }

    public function test_waiting_visit_cannot_change_to_otc(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);

        $this->expectException(ValidationException::class);
        app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'visit_type' => 'otc',
            'assigned_doctor_user_id' => null,
            'visit_reason' => null,
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
        ], $ca);
    }

    public function test_serving_visit_cannot_be_edited(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->selectBranch($doctor);
        $entry = app(QueueEntryService::class)->call($doctor, $visit, [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ]);
        $this->selectBranch($ca);

        $this->expectException(AuthorizationException::class);
        app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason' => 'Synthetic prohibited Serving edit',
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
        ], $ca);
    }

    public function test_waiting_visit_can_change_to_an_eligible_temporary_covering_doctor(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $otherBranch = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)
            ->firstOrFail();
        $coveringDoctor = $this->actor('resident_doctor', $otherBranch);
        $coveringProfile = StaffProfile::query()->where('user_id', $coveringDoctor->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($coveringProfile, $this->branch, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $panel = Panel::factory()->create([
            'organisation_id' => $this->organisation->id,
            'code' => 'SYNTH-WAITING',
            'name' => 'Synthetic Waiting Panel',
        ]);
        $queueUpdatedAt = $entry->updated_at;

        $updated = app(VisitAdministrationService::class)->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $coveringDoctor->id,
            'visit_reason' => 'Synthetic temporary coverage reassignment',
            'priority' => 'normal',
            'coverage_type' => 'panel',
            'panel_id' => $panel->id,
            'coverage_member_reference' => 'SYNTH-WAITING-REF',
        ], $ca);

        $this->assertSame($coveringDoctor->id, $updated->assigned_doctor_user_id);
        $this->assertSame('Synthetic temporary coverage reassignment', $updated->visit_reason);
        $this->assertSame('panel', $updated->coverage_type);
        $this->assertSame($panel->id, $updated->panel_id);
        $this->assertSame('SYNTH-WAITING-REF', $updated->coverage_member_reference);
        $this->assertSame(QueueEntry::STATUS_WAITING, $entry->refresh()->status);
        $this->assertSame(1, $entry->lock_version);
        $this->assertTrue($entry->updated_at->equalTo($queueUpdatedAt));
    }

    public function test_queue_removal_audit_does_not_copy_patient_or_cancellation_context(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $entry = $this->send($ca, $visit);
        app(VisitAdministrationService::class)->cancel($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'cancellation_reason' => 'Synthetic private cancellation context',
        ], $ca);

        $metadata = AuditLog::query()->where('event', 'queue.removed')->firstOrFail()->metadata;
        foreach (['patient', 'visit_reason', 'queue_number', 'doctor', 'cancellation_reason'] as $field) {
            $this->assertArrayNotHasKey($field, $metadata);
        }

        $technical = $this->actor('technical_admin');
        $this->actingAs($technical)->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs.data', fn ($logs) => collect($logs)->contains(
                fn (array $log) => $log['subjectType'] === 'Queue record' && $log['subjectId'] === null,
            )));
    }

    /** @return array<string, mixed> */
    private function waitingUpdateAttributes(Visit $visit, QueueEntry $entry): array
    {
        return [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'visit_type' => $visit->visit_type,
            'assigned_doctor_user_id' => $visit->assigned_doctor_user_id,
            'visit_reason' => $visit->visit_reason,
            'priority' => $visit->priority,
            'coverage_type' => $visit->coverage_type,
            'panel_id' => $visit->panel_id,
            'coverage_member_reference' => $visit->coverage_member_reference,
        ];
    }
}
