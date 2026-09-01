<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\PatientProblemRecord;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\CurrentClinicalCareService;
use App\Domain\Clinical\Services\PatientProblemService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class ClinicalSafetyProblemTest extends ClinicalTestCase
{
    public function test_problem_create_update_resolve_and_entered_in_error_are_structured_and_versioned(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = app(PatientProblemService::class);

        $record = $service->add($doctor, $visit, $this->problem($visit, 'Synthetic condition alpha'));
        $this->assertSame(PatientProblemRecord::STATUS_ACTIVE, $record->status);
        $this->assertSame(1, $record->lock_version);

        $record = $service->update($doctor, $visit, $record->public_id, [
            ...$this->problem($visit, 'Synthetic condition beta'),
            'lock_version' => $record->lock_version,
        ]);
        $this->assertSame(2, $record->lock_version);

        $record = $service->resolve($doctor, $visit, $record->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $record->lock_version,
            'resolved_date' => now()->toDateString(),
        ]);
        $this->assertSame(PatientProblemRecord::STATUS_RESOLVED, $record->status);
        $this->assertSame(3, $record->lock_version);
        $this->assertNotNull($record->resolved_at);

        $record = $service->enterInError($doctor, $visit, $record->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $record->lock_version,
        ]);
        $this->assertSame(PatientProblemRecord::STATUS_ENTERED_IN_ERROR, $record->status);
        $this->assertSame(4, $record->lock_version);
        $this->assertNotNull($record->entered_in_error_at);
    }

    public function test_stale_and_future_problem_versions_are_rejected(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = app(PatientProblemService::class);
        $record = $service->add($doctor, $visit, $this->problem($visit, 'Synthetic condition'));

        foreach ([0, 2] as $forgedVersion) {
            try {
                $service->update($doctor, $visit, $record->public_id, [
                    ...$this->problem($visit, 'Synthetic changed condition'),
                    'lock_version' => $forgedVersion,
                ]);
                $this->fail("Problem update accepted forged version {$forgedVersion}.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_no_op_problem_edit_does_not_increment_version_or_audit(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = app(PatientProblemService::class);
        $payload = $this->problem($visit, 'Synthetic unchanged condition');
        $record = $service->add($doctor, $visit, $payload);

        $service->update($doctor, $visit, $record->public_id, [
            ...$payload,
            'lock_version' => $record->lock_version,
        ]);

        $this->assertSame(1, $record->refresh()->lock_version);
        $this->assertSame(0, AuditLog::query()->where('event', 'problem.updated')->count());
    }

    public function test_problem_code_pair_and_resolution_date_are_validated(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = app(PatientProblemService::class);

        try {
            $service->add($doctor, $visit, [
                ...$this->problem($visit, 'Synthetic invalid code'),
                'condition_code' => 'SYNTH-1',
                'code_system' => null,
            ]);
            $this->fail('Unpaired condition code was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $record = $service->add($doctor, $visit, [
            ...$this->problem($visit, 'Synthetic dated condition'),
            'onset_date' => '2026-08-20',
        ]);
        $this->expectException(ValidationException::class);
        $service->resolve($doctor, $visit, $record->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $record->lock_version,
            'resolved_date' => '2026-08-19',
        ]);
    }

    public function test_problem_projection_shows_active_then_resolved_and_excludes_entered_in_error(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = app(PatientProblemService::class);
        $active = $service->add($doctor, $visit, $this->problem($visit, 'Synthetic active condition'));
        $resolved = $service->add($doctor, $visit, $this->problem($visit, 'Synthetic resolved condition'));
        $error = $service->add($doctor, $visit, $this->problem($visit, 'Synthetic erroneous condition'));
        $service->resolve($doctor, $visit, $resolved->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $resolved->lock_version,
            'resolved_date' => null,
        ]);
        $service->enterInError($doctor, $visit, $error->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $error->lock_version,
        ]);

        $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit);

        $this->assertSame([$active->public_id], $detail['problems']['active']->pluck('publicId')->all());
        $this->assertSame([$resolved->public_id], $detail['problems']['resolved']->pluck('publicId')->all());
        $this->assertStringNotContainsString('Synthetic erroneous condition', json_encode($detail, JSON_THROW_ON_ERROR));
    }

    public function test_problem_records_cannot_be_deleted_and_do_not_change_clinical_note(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $record = app(PatientProblemService::class)->add(
            $doctor,
            $visit,
            $this->problem($visit, 'Synthetic independent condition'),
        );

        $this->assertNull($encounter->refresh()->clinical_note);
        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_problem_model_rejects_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);
        PatientProblemRecord::query()->create([
            'organisation_id' => 1,
            'patient_id' => 1,
            'condition_text' => 'Browser-controlled condition',
        ]);
    }

    public function test_problem_audits_are_structural_and_contain_no_clinical_values(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        app(PatientProblemService::class)->add(
            $doctor,
            $visit,
            $this->problem($visit, 'Synthetic private condition'),
        );

        $encoded = AuditLog::query()->where('event', 'problem.created')->get()->toJson();
        $this->assertStringNotContainsString('Synthetic private condition', $encoded);
        $this->assertStringNotContainsString($visit->patient->patient_number, $encoded);
    }

    public function test_late_problem_audit_failure_rolls_back_record(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $failingAudit = new class extends AuditRecorder
        {
            public function record(
                string $event,
                ?Model $subject = null,
                array $metadata = [],
                ?User $actor = null,
                ?Branch $branch = null,
                ?int $organisationId = null,
            ): ?AuditLog {
                if ($event === 'problem.created') {
                    throw new RuntimeException('Injected Problem audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };

        try {
            (new PatientProblemService(app(CurrentClinicalCareService::class), $failingAudit))
                ->add($doctor, $visit, $this->problem($visit, 'Synthetic rollback condition'));
            $this->fail('Expected Problem aggregate rollback.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('patient_problem_records', 0);
        }
    }

    /** @return array<string, mixed> */
    private function problem(Visit $visit, string $condition): array
    {
        return [
            'expected_branch_id' => $visit->branch_id,
            'condition_text' => $condition,
            'condition_code' => null,
            'code_system' => null,
            'onset_date' => null,
        ];
    }
}
