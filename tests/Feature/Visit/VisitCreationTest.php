<?php

namespace Tests\Feature\Visit;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Domain\Visit\Services\VisitNumberGenerator;
use App\Domain\Visit\Services\VisitReasonService;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class VisitCreationTest extends VisitTestCase
{
    public function test_ca_registers_consultation_with_eligible_temporary_coverage_doctor(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $other = $this->organisation->branches()->where('code', 'PUCHONG')->firstOrFail();
        $doctor->staffProfile->branchAssignments()->update(['branch_id' => $other->id]);
        StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $this->branch, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);

        $visit = $this->register($ca, $this->patient(), [
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason' => 'Synthetic administrative reason',
        ]);

        $this->assertSame('KPV-00000001', $visit->visit_number);
        $this->assertSame($this->branch->id, $visit->branch_id);
        $this->assertSame($doctor->id, $visit->assigned_doctor_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'visit.created', 'subject_id' => $visit->id]);
    }

    public function test_otc_allows_no_doctor_or_reason_and_has_no_medication_semantics(): void
    {
        $visit = $this->register($this->actor(), $this->patient());

        $this->assertSame('otc', $visit->visit_type);
        $this->assertNull($visit->assigned_doctor_user_id);
        $this->assertNull($visit->visit_reason);
        $this->assertFalse(Schema::hasColumn('visits', 'medication_id'));
    }

    public function test_consultation_rejects_inactive_wrong_role_and_unassigned_doctors(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $candidates = [
            $this->actor('resident_doctor')->forceFill(['is_active' => false]),
            $this->actor('ca'),
            $this->actor('resident_doctor', $this->organisation->branches()->where('code', 'PUCHONG')->firstOrFail()),
        ];
        $candidates[0]->save();

        foreach ($candidates as $doctor) {
            try {
                $this->register($ca, $patient, [
                    'idempotency_key' => (string) Str::uuid(),
                    'visit_type' => 'consultation',
                    'assigned_doctor_user_id' => $doctor->id,
                    'visit_reason' => 'Synthetic reason',
                ]);
                $this->fail('Expected an ineligible doctor rejection.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assigned_doctor_user_id', $exception->errors());
            }
        }
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_quick_patient_and_visit_commit_as_one_logical_operation(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $visit = app(VisitRegistrationService::class)->register($ca, [
            ...$this->visitAttributes($this->patient(), ['patient_number' => null]),
            'patient_number' => null,
            'quick_patient' => [
                'full_name' => 'Synthetic Quick Patient',
                'sex' => 'unknown',
                'mobile_phone' => '+60123456789',
                'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-QUICK']],
                'duplicate_override' => true,
            ],
        ]);

        $this->assertSame('Synthetic Quick Patient', $visit->patient->full_name);
        $this->assertDatabaseCount('visits', 1);
    }

    public function test_quick_patient_preserves_phase_1a_soft_duplicate_rules(): void
    {
        $this->patient(null, ['full_name' => 'Synthetic Possible Duplicate', 'date_of_birth' => '1990-01-01']);
        $ca = $this->actor();
        $this->selectBranch($ca);

        try {
            app(VisitRegistrationService::class)->register($ca, [
                'idempotency_key' => (string) Str::uuid(),
                'expected_branch_id' => $this->branch->id,
                'quick_patient' => [
                    'full_name' => 'Synthetic Possible Duplicate',
                    'mobile_phone' => '+60123456789',
                    'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-DUPLICATE']],
                    'date_of_birth' => '1990-01-01',
                    'sex' => 'unknown',
                ],
                'visit_type' => 'otc',
                'priority' => 'normal',
                'coverage_type' => 'self_pay',
            ]);
            $this->fail('Expected Phase 1A duplicate review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quick_patient.duplicate_override', $exception->errors());
        }

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_registration_rejects_ambiguous_existing_and_quick_patient_sources(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($ca);
        $attributes = [
            ...$this->visitAttributes($patient),
            'quick_patient' => [
                'full_name' => 'Synthetic Conflicting Patient',
                'sex' => 'unknown',
                'duplicate_override' => true,
            ],
        ];

        try {
            app(VisitRegistrationService::class)->register($ca, $attributes);
            $this->fail('Expected conflicting Patient sources to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('patient_number', $exception->errors());
            $this->assertArrayHasKey('quick_patient', $exception->errors());
        }

        $this->post(route('registration.store'), $attributes)
            ->assertSessionHasErrors(['patient_number', 'quick_patient']);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('visit_number_counters', 0);
    }

    public function test_late_visit_failure_rolls_back_quick_patient_both_counters_and_audits(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $auditCount = AuditLog::query()->count();
        $failingAudit = new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                if ($event === 'visit.created') {
                    throw new RuntimeException('Injected Visit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new VisitRegistrationService(
            app(BranchAccessService::class),
            app(PatientAdministrationService::class),
            app(VisitDoctorEligibilityService::class),
            app(VisitNumberGenerator::class),
            app(VisitReasonService::class),
            $failingAudit,
        );

        try {
            $service->register($ca, [
                'idempotency_key' => (string) Str::uuid(),
                'expected_branch_id' => $this->branch->id,
                'quick_patient' => ['full_name' => 'Synthetic Atomic Rollback', 'sex' => 'unknown', 'duplicate_override' => true, 'mobile_phone' => '+60123456789', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-ROLLBACK']]],
                'visit_type' => 'otc',
                'priority' => 'normal',
                'coverage_type' => 'self_pay',
            ]);
            $this->fail('Expected injected Visit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected Visit failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('patient_number_counters', 0);
        $this->assertDatabaseCount('visit_number_counters', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_idempotent_retry_returns_same_visit_without_duplicate_audit_or_counter_use(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $key = (string) Str::uuid();
        $first = $this->register($ca, $patient, ['idempotency_key' => $key]);
        $second = $this->register($ca, $patient, ['idempotency_key' => $key]);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('visits', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'visit.created')->count());
        $this->assertDatabaseHas('visit_number_counters', ['next_value' => 2]);
    }

    public function test_idempotent_quick_patient_retry_does_not_create_another_patient(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $key = (string) Str::uuid();
        $attributes = [
            'idempotency_key' => $key,
            'expected_branch_id' => $this->branch->id,
            'quick_patient' => ['full_name' => 'Synthetic Idempotent Quick', 'sex' => 'unknown', 'duplicate_override' => true, 'mobile_phone' => '+60123456789', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-RETRY']]],
            'visit_type' => 'otc',
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
        ];
        $service = app(VisitRegistrationService::class);

        $first = $service->register($ca, $attributes);
        $second = $service->register($ca, $attributes);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('visits', 1);
    }

    public function test_repeat_visit_requires_explicit_confirmation_but_is_not_unique(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $this->register($ca, $patient);

        try {
            $this->register($ca, $patient);
            $this->fail('Expected repeat warning.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_repeat', $exception->errors());
        }

        $this->register($ca, $patient, ['confirm_repeat' => true]);
        $this->assertDatabaseCount('visits', 2);
    }

    public function test_panel_is_same_organisation_active_and_name_is_snapshotted(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic Coverage']);
        $visit = $this->register($this->actor(), $this->patient(), [
            'coverage_type' => 'panel',
            'panel_id' => $panel->id,
            'coverage_member_reference' => 'SYNTH-REF',
        ]);

        $this->assertSame('Synthetic Coverage', $visit->coverage_panel_name_snapshot);
        $panel->forceFill(['name' => 'Changed Later'])->save();
        $this->assertSame('Synthetic Coverage', $visit->fresh()->coverage_panel_name_snapshot);
    }

    public function test_visit_and_panel_models_reject_mass_assignment(): void
    {
        foreach ([fn () => (new Visit)->fill(['organisation_id' => 999]), fn () => (new Panel)->fill(['name' => 'Attack'])] as $write) {
            try {
                $write();
                $this->fail('Protected Visit model accepted mass assignment.');
            } catch (MassAssignmentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_visit_audit_and_global_audit_projection_are_privacy_safe(): void
    {
        $ca = $this->actor();
        $reason = 'Synthetic private registration reason';
        $visit = $this->register($ca, $this->patient(), ['visit_reason' => $reason, 'priority' => 'urgent']);
        $createdMetadata = AuditLog::query()
            ->where('event', 'visit.created')
            ->where('subject_id', $visit->id)
            ->value('metadata');
        $this->assertIsArray($createdMetadata);
        $this->assertArrayNotHasKey('priority', $createdMetadata);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'visit.priority.marked_urgent',
            'subject_id' => $visit->id,
        ]);
        $metadata = AuditLog::query()->where('subject_id', $visit->id)->pluck('metadata')->toJson();
        $this->assertStringNotContainsString($reason, $metadata);
        $this->assertStringNotContainsString($visit->visit_number, $metadata);
        $this->assertStringNotContainsString($visit->patient->patient_number, $metadata);

        $technical = $this->actor('technical_admin');
        $this->actingAs($technical)->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs.data', fn ($logs) => collect($logs)->contains(
                fn (array $log) => $log['subjectType'] === 'Visit record' && $log['subjectId'] === null,
            )));
    }
}
