<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyProfileVersion;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatientAllergyService
{
    public function __construct(
        private CurrentClinicalCareService $care,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function declareNoKnown(User $actor, Visit $visit, array $attributes): PatientAllergyProfile
    {
        $validated = $this->validateVersion($attributes);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $branch, $validated): PatientAllergyProfile {
            $care = $this->care->lock($actor, $visit, $branch, 'allergies.update.own');
            [$profile, $records] = $this->lockAggregate($care);
            $this->assertExpectedVersion($profile, $validated['profile_lock_version']);

            if ($records->contains('status', PatientAllergyRecord::STATUS_ACTIVE)) {
                throw ValidationException::withMessages([
                    'allergy_profile' => 'No known allergies can only be recorded when there are no active Allergy Records.',
                ]);
            }

            if ($profile?->status === PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES) {
                return $profile;
            }

            $now = now()->utc();
            if (! $profile) {
                $profile = new PatientAllergyProfile;
                $profile->forceFill([
                    'organisation_id' => $care->actor->organisation_id,
                    'patient_id' => $care->patient->id,
                    'status' => PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES,
                    'reviewed_at' => $now,
                    'reviewed_by_user_id' => $care->actor->id,
                    'updated_by_user_id' => $care->actor->id,
                    'lock_version' => 1,
                ])->save();
            } else {
                $profile->forceFill([
                    'status' => PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES,
                    'reviewed_at' => $now,
                    'reviewed_by_user_id' => $care->actor->id,
                    'updated_by_user_id' => $care->actor->id,
                    'lock_version' => $profile->lock_version + 1,
                ])->save();
            }

            $this->recordVersion($profile, $care->actor);
            $this->assertConsistency($profile, 0);
            $this->auditProfileMutation($profile, $care, ['status']);

            return $profile->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function add(User $actor, Visit $visit, array $attributes): PatientAllergyRecord
    {
        $validated = $this->validateAllergy($attributes, false);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $branch, $validated): PatientAllergyRecord {
            $care = $this->care->lock($actor, $visit, $branch, 'allergies.update.own');

            return $this->createRecord($care->actor, $care->branch, $care->patient, $validated, false);
        }, 3);
    }

    /**
     * DSP-5: a CA adds an allergy record for the patient of an open OTC Dispensary case. Add only: editing and
     * entered-in-error stay with the doctor. Called by DispensaryService inside its transaction, after it has locked
     * the actor, case, patient and allergy profile; this method re-checks the case itself so it cannot be used as
     * a shortcut. Like the doctor's add it bumps the profile version, so no encounter review stays current; it never
     * writes an encounter review.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addForOtcCase(User $lockedActor, Branch $branch, DispensaryCase $case, array $attributes): PatientAllergyRecord
    {
        if (DB::transactionLevel() < 1
            || ! $lockedActor->can('allergies.record_otc.branch')
            || $branch->organisation_id !== $lockedActor->organisation_id
            || $case->organisation_id !== $lockedActor->organisation_id
            || $case->branch_id !== $branch->id
            || $case->case_type !== DispensaryCase::TYPE_OTC
            || $case->status !== DispensaryCase::STATUS_DISPENSING
            || $case->current_handler_user_id !== $lockedActor->id) {
            throw new AuthorizationException('Add OTC allergies through the Dispensary case you are handling.');
        }
        $patient = Patient::query()->whereKey(Visit::query()->whereKey($case->visit_id)->value('patient_id'))
            ->where('organisation_id', $lockedActor->organisation_id)->firstOrFail();

        return $this->createRecord($lockedActor, $branch, $patient, $this->validateAllergy($attributes, false), true);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Visit $visit, string $publicId, array $attributes): PatientAllergyRecord
    {
        $validated = $this->validateAllergy($attributes, true);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $publicId, $branch, $validated): PatientAllergyRecord {
            $care = $this->care->lock($actor, $visit, $branch, 'allergies.update.own');
            [$profile, $records] = $this->lockAggregate($care);
            $this->assertExpectedVersion($profile, $validated['profile_lock_version']);
            $record = $this->activeRecord($records, $publicId);
            $changes = [
                'allergen_text' => $validated['allergen_text'],
                'category' => $validated['category'],
                'reaction_text' => $validated['reaction_text'],
                'severity' => $validated['severity'],
            ];

            $before = [
                'allergen_text' => $record->allergen_text,
                'category' => $record->category,
                'reaction_text' => $record->reaction_text,
                'severity' => $record->severity,
            ];
            if ($before === $changes) {
                return $record;
            }

            $profile->forceFill([
                'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES,
                'reviewed_at' => now()->utc(),
                'reviewed_by_user_id' => $care->actor->id,
                'updated_by_user_id' => $care->actor->id,
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            $record->forceFill([
                ...$changes,
                'updated_by_user_id' => $care->actor->id,
            ])->save();

            $this->recordVersion($profile, $care->actor);
            $this->assertConsistency(
                $profile,
                $records->where('status', PatientAllergyRecord::STATUS_ACTIVE)->count(),
            );
            $this->auditProfileMutation($profile, $care, ['allergy_records']);
            $this->audit->record('allergy_record.updated', $record, [
                'record_version' => $profile->lock_version,
                'changed_sections' => ['allergy_details'],
            ], $care->actor, $care->branch, $care->actor->organisation_id);

            return $record->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function enterInError(User $actor, Visit $visit, string $publicId, array $attributes): PatientAllergyRecord
    {
        $validated = $this->validateVersion($attributes);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $publicId, $branch, $validated): PatientAllergyRecord {
            $care = $this->care->lock($actor, $visit, $branch, 'allergies.update.own');
            [$profile, $records] = $this->lockAggregate($care);
            $this->assertExpectedVersion($profile, $validated['profile_lock_version']);
            $record = $this->activeRecord($records, $publicId);
            $remaining = $records
                ->where('status', PatientAllergyRecord::STATUS_ACTIVE)
                ->where('id', '!=', $record->id)
                ->count();
            $now = now()->utc();
            $status = $remaining > 0
                ? PatientAllergyProfile::STATUS_HAS_ALLERGIES
                : PatientAllergyProfile::STATUS_UNKNOWN;

            $record->forceFill([
                'status' => PatientAllergyRecord::STATUS_ENTERED_IN_ERROR,
                'updated_by_user_id' => $care->actor->id,
                'entered_in_error_at' => $now,
                'entered_in_error_by_user_id' => $care->actor->id,
            ])->save();
            $profile->forceFill([
                'status' => $status,
                'reviewed_at' => $remaining > 0 ? $now : null,
                'reviewed_by_user_id' => $remaining > 0 ? $care->actor->id : null,
                'updated_by_user_id' => $care->actor->id,
                'lock_version' => $profile->lock_version + 1,
            ])->save();

            $this->recordVersion($profile, $care->actor);
            $this->assertConsistency($profile, $remaining);
            $this->auditProfileMutation($profile, $care, ['allergy_records', 'status']);
            $this->audit->record('allergy_record.entered_in_error', $record, [
                'record_version' => $profile->lock_version,
            ], $care->actor, $care->branch, $care->actor->organisation_id);

            return $record->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function review(User $actor, Visit $visit, array $attributes): ClinicalEncounterAllergyReview
    {
        $validated = $this->validateVersion($attributes);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $branch, $validated): ClinicalEncounterAllergyReview {
            $care = $this->care->lock($actor, $visit, $branch, 'allergies.review.own');
            [$profile] = $this->lockAggregate($care);
            $this->assertExpectedVersion($profile, $validated['profile_lock_version']);
            if (! $profile || $profile->status === PatientAllergyProfile::STATUS_UNKNOWN) {
                throw ValidationException::withMessages([
                    'allergy_review' => 'Record allergies or explicitly declare no known allergies before reviewing this profile.',
                ]);
            }

            PatientAllergyProfileVersion::query()
                ->where('patient_allergy_profile_id', $profile->id)
                ->where('organisation_id', $profile->organisation_id)
                ->where('version', $profile->lock_version)
                ->firstOrFail();
            $review = ClinicalEncounterAllergyReview::query()
                ->where('clinical_encounter_id', $care->encounter->id)
                ->lockForUpdate()
                ->first();

            if ($review
                && $review->patient_allergy_profile_id === $profile->id
                && $review->allergy_profile_lock_version_reviewed === $profile->lock_version
                && $review->reviewed_by_user_id === $care->actor->id) {
                return $review;
            }

            $review ??= new ClinicalEncounterAllergyReview;
            $review->forceFill([
                'organisation_id' => $care->actor->organisation_id,
                'branch_id' => $care->branch->id,
                'clinical_encounter_id' => $care->encounter->id,
                'patient_allergy_profile_id' => $profile->id,
                'allergy_profile_lock_version_reviewed' => $profile->lock_version,
                'reviewed_by_user_id' => $care->actor->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            $this->audit->record('encounter.allergy_reviewed', $care->encounter, [
                'reviewed_profile_version' => $profile->lock_version,
            ], $care->actor, $care->branch, $care->actor->organisation_id);

            return $review->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateVersion(array $attributes): array
    {
        return Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'profile_lock_version' => ['present', 'nullable', 'integer', 'min:1'],
        ])->validate();
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateAllergy(array $attributes, bool $requiresProfile): array
    {
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'profile_lock_version' => [$requiresProfile ? 'required' : 'present', 'nullable', 'integer', 'min:1'],
            'allergen_text' => ['required', 'string', 'max:500'],
            'category' => ['nullable', 'string', Rule::in(['medication', 'food', 'environmental', 'other'])],
            'reaction_text' => ['nullable', 'string', 'max:1000'],
            'severity' => ['nullable', 'string', Rule::in(['mild', 'moderate', 'severe'])],
        ])->validate();

        $validated['allergen_text'] = trim($validated['allergen_text']);
        if ($validated['allergen_text'] === '') {
            throw ValidationException::withMessages(['allergen_text' => 'Enter the allergen.']);
        }
        $validated['reaction_text'] = $this->nullableTrim($validated['reaction_text'] ?? null);
        $validated['category'] = $this->nullableTrim($validated['category'] ?? null);
        $validated['severity'] = $this->nullableTrim($validated['severity'] ?? null);

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function createRecord(User $actor, Branch $branch, Patient $patient, array $validated, bool $fromOtc): PatientAllergyRecord
    {
        [$profile, $records] = $this->lockAggregateFor($actor->organisation_id, $patient->id);
        $this->assertExpectedVersion($profile, $validated['profile_lock_version']);
        $now = now()->utc();

        if (! $profile) {
            $profile = new PatientAllergyProfile;
            $profile->forceFill([
                'organisation_id' => $actor->organisation_id,
                'patient_id' => $patient->id,
                'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES,
                'reviewed_at' => $now,
                'reviewed_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'lock_version' => 1,
            ])->save();
        } else {
            // DSP-5: a CA adding at OTC does not take over a profile that already lists allergies; its
            // reviewed-by stays with whoever last reviewed it. (A new, unknown or "no known" profile needs a reviewer.)
            $keepReview = $fromOtc && $profile->status === PatientAllergyProfile::STATUS_HAS_ALLERGIES && $profile->reviewed_at !== null;
            $profile->forceFill([
                'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES,
                'reviewed_at' => $keepReview ? $profile->reviewed_at : $now,
                'reviewed_by_user_id' => $keepReview ? $profile->reviewed_by_user_id : $actor->id,
                'updated_by_user_id' => $actor->id,
                'lock_version' => $profile->lock_version + 1,
            ])->save();
        }

        $record = new PatientAllergyRecord;
        $record->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $actor->organisation_id,
            'patient_allergy_profile_id' => $profile->id,
            'allergen_text' => $validated['allergen_text'],
            'category' => $validated['category'],
            'reaction_text' => $validated['reaction_text'],
            'severity' => $validated['severity'],
            'status' => PatientAllergyRecord::STATUS_ACTIVE,
            'recorded_at' => $now,
            'recorded_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'entered_in_error_at' => null,
            'entered_in_error_by_user_id' => null,
        ])->save();

        $this->recordVersion($profile, $actor);
        $activeCount = $records->where('status', PatientAllergyRecord::STATUS_ACTIVE)->count() + 1;
        $this->assertConsistency($profile, $activeCount);
        $this->auditProfileMutationBy($profile, $actor, $branch, ['allergy_records', 'status']);
        $this->audit->record('allergy_record.created', $record, [
            'record_version' => $profile->lock_version,
        ], $actor, $branch, $actor->organisation_id);

        return $record->refresh();
    }

    /** @return array{PatientAllergyProfile|null, Collection<int, PatientAllergyRecord>} */
    private function lockAggregate(CurrentClinicalCareContext $care): array
    {
        return $this->lockAggregateFor($care->actor->organisation_id, $care->patient->id);
    }

    /** @return array{PatientAllergyProfile|null, Collection<int, PatientAllergyRecord>} */
    private function lockAggregateFor(int $organisationId, int $patientId): array
    {
        $profile = PatientAllergyProfile::query()
            ->where('organisation_id', $organisationId)
            ->where('patient_id', $patientId)
            ->lockForUpdate()
            ->first();
        $records = $profile
            ? PatientAllergyRecord::query()
                ->where('patient_allergy_profile_id', $profile->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
            : new Collection;

        return [$profile, $records];
    }

    private function assertExpectedVersion(?PatientAllergyProfile $profile, mixed $expected): void
    {
        if ((! $profile && $expected !== null)
            || ($profile && (int) $expected !== $profile->lock_version)) {
            throw ValidationException::withMessages([
                'profile_lock_version' => 'The Allergy Profile changed after it was opened. Review the latest profile.',
            ]);
        }
    }

    /** @param Collection<int, PatientAllergyRecord> $records */
    private function activeRecord(Collection $records, string $publicId): PatientAllergyRecord
    {
        $record = $records->first(fn (PatientAllergyRecord $candidate): bool => $candidate->public_id === $publicId
            && $candidate->status === PatientAllergyRecord::STATUS_ACTIVE);

        abort_unless($record instanceof PatientAllergyRecord, 404);

        return $record;
    }

    private function recordVersion(PatientAllergyProfile $profile, User $actor): void
    {
        $version = new PatientAllergyProfileVersion;
        $version->forceFill([
            'organisation_id' => $profile->organisation_id,
            'patient_allergy_profile_id' => $profile->id,
            'version' => $profile->lock_version,
            'resulting_status' => $profile->status,
            'changed_at' => now()->utc(),
            'changed_by_user_id' => $actor->id,
        ])->save();
    }

    private function assertConsistency(PatientAllergyProfile $profile, int $activeCount): void
    {
        if (($profile->status === PatientAllergyProfile::STATUS_HAS_ALLERGIES && $activeCount < 1)
            || ($profile->status !== PatientAllergyProfile::STATUS_HAS_ALLERGIES && $activeCount !== 0)) {
            throw ValidationException::withMessages([
                'allergy_profile' => 'The Allergy Profile is inconsistent with its active Allergy Records.',
            ]);
        }
    }

    /** @param list<string> $changedSections */
    private function auditProfileMutation(
        PatientAllergyProfile $profile,
        CurrentClinicalCareContext $care,
        array $changedSections,
    ): void {
        $this->auditProfileMutationBy($profile, $care->actor, $care->branch, $changedSections);
    }

    /** @param list<string> $changedSections */
    private function auditProfileMutationBy(PatientAllergyProfile $profile, User $actor, Branch $branch, array $changedSections): void
    {
        $this->audit->record('allergy_profile.updated', $profile, [
            'record_version' => $profile->lock_version,
            'changed_sections' => $changedSections,
        ], $actor, $branch, $actor->organisation_id);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
