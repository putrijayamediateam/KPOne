<?php

namespace Tests\Feature\Visit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Visit\Services\VisitAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class VisitAdministrationTest extends VisitTestCase
{
    public function test_registered_visit_can_be_edited_with_optimistic_locking_and_directional_priority_audit(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient());
        $service = app(VisitAdministrationService::class);
        $updated = $service->update($visit, $this->updateAttributes($visit, ['priority' => 'urgent']), $ca);

        $this->assertSame('urgent', $updated->priority);
        $this->assertSame(2, $updated->lock_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'visit.priority.marked_urgent', 'subject_id' => $visit->id]);

        $returned = $service->update($updated, $this->updateAttributes($updated, ['priority' => 'normal']), $ca);
        $this->assertDatabaseHas('audit_logs', ['event' => 'visit.priority.returned_normal', 'subject_id' => $returned->id]);
    }

    public function test_stale_update_is_rejected(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient());
        app(VisitAdministrationService::class)->update($visit, $this->updateAttributes($visit, ['priority' => 'urgent']), $ca);

        $this->expectException(ValidationException::class);
        app(VisitAdministrationService::class)->update($visit, $this->updateAttributes($visit), $ca);
    }

    public function test_cancellation_is_final_immutable_and_reason_never_enters_audit_metadata(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient());
        $reason = 'Synthetic sensitive cancellation explanation';
        $cancelled = app(VisitAdministrationService::class)->cancel($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'cancellation_reason' => $reason,
        ], $ca);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($reason, $cancelled->cancellation_reason);
        $metadata = AuditLog::query()->where('subject_id', $visit->id)->pluck('metadata')->toJson();
        $this->assertStringNotContainsString($reason, $metadata);

        $this->expectException(AuthorizationException::class);
        app(VisitAdministrationService::class)->update($cancelled, $this->updateAttributes($cancelled), $ca);
    }

    public function test_patient_and_branch_ownership_are_immutable_on_update(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient());
        $this->actingAs($ca)->patch(route('visits.update', $visit), [
            ...$this->updateAttributes($visit),
            'patient_id' => 999,
            'branch_id' => 999,
        ])->assertSessionHasErrors(['patient_id', 'branch_id']);
        $this->assertSame($visit->patient_id, $visit->fresh()->patient_id);
    }

    /** @return array<string, mixed> */
    private function updateAttributes($visit, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $visit->lock_version,
            'visit_type' => $visit->visit_type,
            'assigned_doctor_user_id' => $visit->assigned_doctor_user_id,
            ...($visit->reasonAssignments()->exists() ? ['visit_reason_public_ids' => $visit->reasonAssignments()->with('reason')->get()->pluck('reason.public_id')->all()] : []),
            'priority' => $visit->priority,
            'coverage_type' => $visit->coverage_type,
            'panel_id' => $visit->panel_id,
            'coverage_member_reference' => $visit->coverage_member_reference,
            ...$overrides,
        ];
    }
}
