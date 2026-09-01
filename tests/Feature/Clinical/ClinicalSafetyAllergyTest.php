<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyProfileVersion;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Services\AllergyReviewGate;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\CurrentClinicalCareService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class ClinicalSafetyAllergyTest extends ClinicalTestCase
{
    public function test_missing_profile_is_unknown_and_zero_rows_do_not_imply_no_known_allergies(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);

        $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit);

        $this->assertSame('unknown', $detail['allergies']['status']);
        $this->assertNull($detail['allergies']['profileLockVersion']);
        $this->assertSame([], $detail['allergies']['records']->all());
        $this->assertDatabaseCount('patient_allergy_profiles', 0);
    }

    public function test_explicit_no_known_declaration_creates_provenance_and_one_version(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);

        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, $this->version(null, $visit));

        $this->assertSame(PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES, $profile->status);
        $this->assertNotNull($profile->reviewed_at);
        $this->assertSame($doctor->id, $profile->reviewed_by_user_id);
        $this->assertSame(1, $profile->lock_version);
        $this->assertDatabaseCount('patient_allergy_records', 0);
        $this->assertDatabaseHas('patient_allergy_profile_versions', [
            'patient_allergy_profile_id' => $profile->id,
            'version' => 1,
            'resulting_status' => PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES,
        ]);
    }

    public function test_repeated_no_known_declaration_is_idempotent_and_active_allergy_blocks_it(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, $this->version(null, $visit));
        app(PatientAllergyService::class)->declareNoKnown(
            $doctor,
            $visit,
            $this->version($profile->lock_version, $visit),
        );
        $this->assertSame(1, $profile->versions()->count());

        app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version($profile->lock_version, $visit),
            ...$this->allergy('Synthetic declaration blocker'),
        ]);
        $profile->refresh();

        $this->expectException(ValidationException::class);
        app(PatientAllergyService::class)->declareNoKnown(
            $doctor,
            $visit,
            $this->version($profile->lock_version, $visit),
        );
    }

    public function test_first_allergy_and_allergy_after_no_known_derive_has_allergies(): void
    {
        foreach ([false, true] as $declareFirst) {
            [$doctor, , $visit, $queue] = $this->servingFixture();
            $this->startEncounter($doctor, $visit, $queue);
            $profile = null;
            if ($declareFirst) {
                $profile = app(PatientAllergyService::class)->declareNoKnown(
                    $doctor,
                    $visit,
                    $this->version(null, $visit),
                );
            }

            $record = app(PatientAllergyService::class)->add($doctor, $visit, [
                ...$this->version($profile?->lock_version, $visit),
                ...$this->allergy('Synthetic allergen '.($declareFirst ? 'after' : 'first')),
            ]);
            $current = PatientAllergyProfile::query()->findOrFail($record->patient_allergy_profile_id);

            $this->assertSame(PatientAllergyProfile::STATUS_HAS_ALLERGIES, $current->status);
            $this->assertSame($declareFirst ? 2 : 1, $current->lock_version);
            $this->assertSame(1, $current->allergyRecords()->where('status', 'active')->count());
            $this->assertSame($current->lock_version, $current->versions()->count());
        }
    }

    public function test_meaningful_edit_increments_once_and_no_op_does_not_increment(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $record = app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version(null, $visit),
            ...$this->allergy('Synthetic allergen'),
        ]);
        $profile = $record->profile;

        app(PatientAllergyService::class)->update($doctor, $visit, $record->public_id, [
            ...$this->version($profile->lock_version, $visit),
            ...$this->allergy('Synthetic allergen', 'Synthetic reaction'),
        ]);
        $profile->refresh();
        $this->assertSame(2, $profile->lock_version);

        app(PatientAllergyService::class)->update($doctor, $visit, $record->public_id, [
            ...$this->version($profile->lock_version, $visit),
            ...$this->allergy('Synthetic allergen', 'Synthetic reaction'),
        ]);
        $this->assertSame(2, $profile->refresh()->lock_version);
        $this->assertSame(2, PatientAllergyProfileVersion::query()->where('patient_allergy_profile_id', $profile->id)->count());
    }

    public function test_entering_final_active_allergy_in_error_returns_unknown_never_no_known(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $record = app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version(null, $visit),
            ...$this->allergy('Synthetic mistaken allergen'),
        ]);

        app(PatientAllergyService::class)->enterInError(
            $doctor,
            $visit,
            $record->public_id,
            $this->version($record->profile->lock_version, $visit),
        );
        $profile = $record->profile->refresh();

        $this->assertSame(PatientAllergyProfile::STATUS_UNKNOWN, $profile->status);
        $this->assertNull($profile->reviewed_at);
        $this->assertNull($profile->reviewed_by_user_id);
        $this->assertSame(2, $profile->lock_version);
        $this->assertDatabaseHas('patient_allergy_records', [
            'id' => $record->id,
            'status' => PatientAllergyRecord::STATUS_ENTERED_IN_ERROR,
        ]);
    }

    public function test_erroring_one_of_multiple_active_allergies_remains_has_allergies(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $first = app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version(null, $visit),
            ...$this->allergy('Synthetic first allergen'),
        ]);
        $second = app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version($first->profile->lock_version, $visit),
            ...$this->allergy('Synthetic second allergen'),
        ]);

        app(PatientAllergyService::class)->enterInError(
            $doctor,
            $visit,
            $first->public_id,
            $this->version($second->profile->lock_version, $visit),
        );

        $profile = $second->profile->refresh();
        $this->assertSame(PatientAllergyProfile::STATUS_HAS_ALLERGIES, $profile->status);
        $this->assertSame(1, $profile->allergyRecords()->where('status', 'active')->count());
        $this->assertNotNull($profile->reviewed_at);
    }

    public function test_review_records_exact_version_is_idempotent_and_mutation_makes_it_stale(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, $this->version(null, $visit));

        $review = app(PatientAllergyService::class)->review(
            $doctor,
            $visit,
            $this->version($profile->lock_version, $visit),
        );
        $retry = app(PatientAllergyService::class)->review(
            $doctor,
            $visit,
            $this->version($profile->lock_version, $visit),
        );
        $this->assertSame($review->id, $retry->id);
        $this->assertSame(1, AuditLog::query()->where('event', 'encounter.allergy_reviewed')->count());

        $record = app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version($profile->lock_version, $visit),
            ...$this->allergy('Synthetic newly recorded allergen'),
        ]);
        $profile->refresh();
        $this->assertNotSame($review->allergy_profile_lock_version_reviewed, $profile->lock_version);

        $careService = app(CurrentClinicalCareService::class);
        $branch = $careService->activeBranch($doctor, $visit, $this->version($profile->lock_version, $visit));
        try {
            DB::transaction(function () use ($careService, $doctor, $visit, $branch, $profile, $review): void {
                $care = $careService->lock($doctor, $visit, $branch, 'allergies.review.own');
                app(AllergyReviewGate::class)->assertCurrent($care, $profile->refresh(), $review->refresh());
            });
            $this->fail('The reusable future medication gate accepted a stale Allergy Review.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame($encounter->id, $review->clinical_encounter_id);
        $this->assertSame($record->patient_allergy_profile_id, $review->patient_allergy_profile_id);
    }

    public function test_unknown_cannot_be_reviewed_and_stale_profile_version_is_rejected(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);

        try {
            app(PatientAllergyService::class)->review($doctor, $visit, $this->version(null, $visit));
            $this->fail('UNKNOWN Allergy Profile was reviewed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, $this->version(null, $visit));
        app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version($profile->lock_version, $visit),
            ...$this->allergy('Synthetic changed allergen'),
        ]);

        $this->expectException(ValidationException::class);
        app(PatientAllergyService::class)->review(
            $doctor,
            $visit,
            $this->version($profile->lock_version, $visit),
        );
    }

    public function test_reusable_future_gate_accepts_both_explicit_reviewed_profile_states(): void
    {
        foreach (['no_known_allergies', 'has_allergies'] as $state) {
            [$doctor, , $visit, $queue] = $this->servingFixture();
            $this->startEncounter($doctor, $visit, $queue);
            if ($state === 'no_known_allergies') {
                $profile = app(PatientAllergyService::class)->declareNoKnown(
                    $doctor,
                    $visit,
                    $this->version(null, $visit),
                );
            } else {
                $record = app(PatientAllergyService::class)->add($doctor, $visit, [
                    ...$this->version(null, $visit),
                    ...$this->allergy('Synthetic reviewed allergen'),
                ]);
                $profile = $record->profile;
            }
            $review = app(PatientAllergyService::class)->review(
                $doctor,
                $visit,
                $this->version($profile->lock_version, $visit),
            );
            $careService = app(CurrentClinicalCareService::class);
            $branch = $careService->activeBranch($doctor, $visit, $this->version($profile->lock_version, $visit));
            DB::transaction(function () use ($careService, $doctor, $visit, $branch, $profile, $review): void {
                $care = $careService->lock($doctor, $visit, $branch, 'allergies.review.own');
                app(AllergyReviewGate::class)->assertCurrent($care, $profile->refresh(), $review->refresh());
            });

            $this->assertSame($profile->lock_version, $review->allergy_profile_lock_version_reviewed);
        }
    }

    public function test_audit_metadata_contains_no_allergy_values(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version(null, $visit),
            ...$this->allergy('Synthetic private allergen', 'Synthetic private reaction'),
        ]);

        $encoded = AuditLog::query()
            ->whereIn('event', ['allergy_profile.updated', 'allergy_record.created'])
            ->get()
            ->toJson();
        $this->assertStringNotContainsString('Synthetic private allergen', $encoded);
        $this->assertStringNotContainsString('Synthetic private reaction', $encoded);
        $this->assertStringNotContainsString($visit->patient->patient_number, $encoded);

        $technical = $this->actor('technical_admin');
        $this->selectBranch($technical);
        $this->get(route('audit-logs.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs.data', function ($logs): bool {
                $clinicalSafety = collect($logs)->whereIn('event', [
                    'allergy_profile.updated',
                    'allergy_record.created',
                ]);

                return $clinicalSafety->isNotEmpty()
                    && $clinicalSafety->every(fn (array $log): bool => $log['subjectType'] === 'Clinical allergy record'
                        && $log['subjectId'] === null
                        && $log['actor'] === 'Clinical user'
                        && $log['roleNames'] === []);
            }))
            ->assertDontSee('Synthetic private allergen')
            ->assertDontSee('Synthetic private reaction');
    }

    public function test_allergy_records_are_not_physically_deletable(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $record = app(PatientAllergyService::class)->add($doctor, $visit, [
            ...$this->version(null, $visit),
            ...$this->allergy('Synthetic retained allergen'),
        ]);

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_allergy_models_reject_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);
        PatientAllergyProfile::query()->create([
            'organisation_id' => 1,
            'patient_id' => 1,
            'status' => PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES,
        ]);
    }

    public function test_late_audit_failure_rolls_back_profile_record_version_and_review(): void
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
                if (in_array($event, ['allergy_record.created', 'encounter.allergy_reviewed'], true)) {
                    throw new RuntimeException('Injected Allergy audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new PatientAllergyService(app(CurrentClinicalCareService::class), $failingAudit);

        try {
            $service->add($doctor, $visit, [
                ...$this->version(null, $visit),
                ...$this->allergy('Synthetic rollback allergen'),
            ]);
            $this->fail('Expected Allergy aggregate rollback.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('patient_allergy_profiles', 0);
            $this->assertDatabaseCount('patient_allergy_profile_versions', 0);
            $this->assertDatabaseCount('patient_allergy_records', 0);
        }

        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, $this->version(null, $visit));
        try {
            $service->review($doctor, $visit, $this->version($profile->lock_version, $visit));
            $this->fail('Expected Encounter Allergy Review rollback.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('clinical_encounter_allergy_reviews', 0);
        }
    }

    /** @return array<string, mixed> */
    private function version(?int $version, Visit $visit): array
    {
        return [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $version,
        ];
    }

    /** @return array<string, mixed> */
    private function allergy(string $allergen, ?string $reaction = null): array
    {
        return [
            'allergen_text' => $allergen,
            'category' => 'medication',
            'reaction_text' => $reaction,
            'severity' => 'mild',
        ];
    }
}
