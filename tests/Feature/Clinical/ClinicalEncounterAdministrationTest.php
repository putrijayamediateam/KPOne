<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ClinicalEncounterAdministrationTest extends ClinicalTestCase
{
    public function test_aggregate_save_updates_note_vitals_and_ordered_diagnoses_once(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $visitVersion = $visit->lock_version;
        $queueVersion = $queue->lock_version;
        $attributes = $this->aggregate($encounter, [
            'clinical_note' => 'Synthetic private clinical content',
            'diagnoses' => [
                [
                    'diagnosis_text' => 'Synthetic primary diagnosis',
                    'diagnosis_code' => 'SYN-A',
                    'code_system' => 'SYNTHETIC',
                    'is_primary' => true,
                ],
                [
                    'diagnosis_text' => 'Synthetic secondary diagnosis',
                    'diagnosis_code' => null,
                    'code_system' => null,
                    'is_primary' => false,
                ],
            ],
        ]);

        $updated = app(ClinicalEncounterService::class)->update($doctor, $visit, $attributes);

        $this->assertSame(2, $updated->lock_version);
        $this->assertSame('Synthetic private clinical content', $updated->clinical_note);
        $this->assertDatabaseHas('encounter_vital_observations', [
            'clinical_encounter_id' => $encounter->id,
            'systolic_bp' => 120,
            'diastolic_bp' => 80,
            'pulse_bpm' => 72,
            'recorded_by_user_id' => $doctor->id,
            'updated_by_user_id' => $doctor->id,
        ]);
        $this->assertNotNull($encounter->vitalObservation()->firstOrFail()->observed_at);
        $this->assertSame(
            ['Synthetic primary diagnosis', 'Synthetic secondary diagnosis'],
            $encounter->diagnoses()->orderBy('position')->pluck('diagnosis_text')->all(),
        );
        $this->assertSame(1, $encounter->diagnoses()->where('is_primary', true)->count());
        $this->assertSame($visitVersion, $visit->refresh()->lock_version);
        $this->assertSame($queueVersion, $queue->refresh()->lock_version);
        $audit = AuditLog::query()->where('event', 'encounter.updated')->sole();
        $this->assertSame(['clinical_note', 'vitals', 'diagnoses'], $audit->metadata['changed_sections']);
        $encoded = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        foreach (['Synthetic private clinical content', 'Synthetic primary diagnosis', 'SYN-A', '120', '80'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_unchanged_save_does_not_create_misleading_update_event_or_version(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        app(ClinicalEncounterService::class)->update($doctor, $visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => 1,
            'clinical_note' => null,
            'vitals' => array_fill_keys([
                'systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_celsius',
                'spo2_percent', 'weight_kg', 'height_cm',
            ], null),
            'diagnoses' => [],
        ]);

        $this->assertSame(1, $encounter->refresh()->lock_version);
        $this->assertSame(0, AuditLog::query()->where('event', 'encounter.updated')->count());
        $this->assertDatabaseCount('encounter_vital_observations', 0);
    }

    public function test_note_only_save_does_not_create_false_vitals_provenance(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'vitals' => array_fill_keys([
                'systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_celsius',
                'spo2_percent', 'weight_kg', 'height_cm',
            ], null),
            'diagnoses' => [],
        ]));

        $this->assertDatabaseCount('encounter_vital_observations', 0);
        $this->assertSame(2, $encounter->refresh()->lock_version);
    }

    public function test_successive_saves_with_each_fresh_version_are_accepted(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->selectBranch($doctor);

        $this->patch(route('encounters.update', $visit), $this->aggregate($encounter, [
            'clinical_note' => 'Synthetic first saved note',
        ]))->assertRedirect(route('encounters.show', $visit));

        $encounter->refresh();
        $this->patch(route('encounters.update', $visit), $this->aggregate($encounter, [
            'clinical_note' => 'Synthetic second saved note',
        ]))->assertRedirect(route('encounters.show', $visit));

        $this->assertSame('Synthetic second saved note', $encounter->refresh()->clinical_note);
        $this->assertSame(3, $encounter->lock_version);
        $this->assertSame(2, AuditLog::query()->where('event', 'encounter.updated')->count());
    }

    public function test_clearing_all_measurements_removes_the_current_observation(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $encounter = app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        $this->assertNotNull($encounter->vitalObservation()->firstOrFail()->observed_at);

        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'vitals' => array_fill_keys([
                'systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_celsius',
                'spo2_percent', 'weight_kg', 'height_cm',
            ], null),
        ]));

        $this->assertFalse($encounter->vitalObservation()->exists());
    }

    public function test_stale_and_forged_future_versions_are_rejected_without_overwrite(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));

        foreach ([1, 999] as $version) {
            try {
                app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
                    'lock_version' => $version,
                    'clinical_note' => 'Synthetic attempted overwrite',
                ]));
                $this->fail('A non-current clinical version was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lock_version', $exception->errors());
            }
        }

        $this->assertSame('Synthetic clinical note', $encounter->refresh()->clinical_note);
        $this->assertSame(2, $encounter->lock_version);
    }

    public function test_bp_code_pair_spo2_and_single_primary_validation_are_enforced(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $invalid = $this->aggregate($encounter, [
            'vitals' => [
                'systolic_bp' => 120,
                'diastolic_bp' => null,
                'pulse_bpm' => null,
                'temperature_celsius' => null,
                'spo2_percent' => 101,
                'weight_kg' => null,
                'height_cm' => null,
            ],
            'diagnoses' => [
                ['diagnosis_text' => 'Synthetic one', 'diagnosis_code' => 'ONE', 'code_system' => null, 'is_primary' => true],
                ['diagnosis_text' => 'Synthetic two', 'diagnosis_code' => null, 'code_system' => null, 'is_primary' => true],
            ],
        ]);

        try {
            app(ClinicalEncounterService::class)->update($doctor, $visit, $invalid);
            $this->fail('Invalid clinical aggregate was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vitals.diastolic_bp', $exception->errors());
            $this->assertArrayHasKey('vitals.spo2_percent', $exception->errors());
            $this->assertArrayHasKey('diagnoses.0.code_system', $exception->errors());
            $this->assertArrayHasKey('diagnoses', $exception->errors());
        }
        $this->assertSame(1, $encounter->refresh()->lock_version);
    }

    public function test_database_rejects_a_second_primary_diagnosis(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        $primary = $encounter->diagnoses()->firstOrFail();

        $this->expectException(QueryException::class);
        $duplicate = new EncounterDiagnosis;
        $duplicate->forceFill([
            'organisation_id' => $encounter->organisation_id,
            'branch_id' => $encounter->branch_id,
            'clinical_encounter_id' => $encounter->id,
            'diagnosis_text' => 'Synthetic second primary',
            'diagnosis_code' => null,
            'code_system' => null,
            'is_primary' => true,
            'position' => $primary->position + 1,
            'recorded_by_user_id' => $doctor->id,
            'updated_by_user_id' => $doctor->id,
        ])->save();
    }

    public function test_late_audit_failure_rolls_back_entire_clinical_aggregate(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
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
                if ($event === 'encounter.updated') {
                    throw new RuntimeException('Injected clinical audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new ClinicalEncounterService(app(BranchAccessService::class), $failingAudit);

        try {
            $service->update($doctor, $visit, $this->aggregate($encounter));
            $this->fail('Expected injected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected clinical audit failure.', $exception->getMessage());
        }

        $this->assertNull($encounter->refresh()->clinical_note);
        $this->assertSame(1, $encounter->lock_version);
        $this->assertDatabaseCount('encounter_diagnoses', 0);
        $this->assertDatabaseMissing('encounter_vital_observations', [
            'clinical_encounter_id' => $encounter->id,
        ]);
        $this->assertSame(0, AuditLog::query()->where('event', 'encounter.updated')->count());
    }
}
