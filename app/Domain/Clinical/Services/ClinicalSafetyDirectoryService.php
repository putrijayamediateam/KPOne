<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\PatientProblemRecord;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClinicalSafetyDirectoryService
{
    /** @return array<string, mixed>|null */
    public function allergies(User $actor, ClinicalEncounter $encounter): ?array
    {
        if (Gate::forUser($actor)->denies('viewAllergies', $encounter)) {
            return null;
        }
        $this->assertCurrentCare($actor, $encounter);

        return DB::transaction(
            fn (): array => $this->lockedAllergyProjection($actor, $encounter),
            3,
        );
    }

    /** @return array<string, mixed>|null */
    public function problems(User $actor, ClinicalEncounter $encounter): ?array
    {
        if (Gate::forUser($actor)->denies('viewProblems', $encounter)) {
            return null;
        }
        $this->assertCurrentCare($actor, $encounter);

        $active = PatientProblemRecord::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('patient_id', $encounter->visit->patient_id)
            ->where('status', PatientProblemRecord::STATUS_ACTIVE)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
        $resolved = PatientProblemRecord::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('patient_id', $encounter->visit->patient_id)
            ->where('status', PatientProblemRecord::STATUS_RESOLVED)
            ->orderByDesc('resolved_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $project = fn (PatientProblemRecord $record): array => [
            'publicId' => $record->public_id,
            'condition' => $record->condition_text,
            'conditionCode' => $record->condition_code,
            'codeSystem' => $record->code_system,
            'status' => $record->status,
            'onsetDate' => $record->onset_date?->toDateString(),
            'resolvedDate' => $record->resolved_date?->toDateString(),
            'lockVersion' => $record->lock_version,
        ];

        return [
            'active' => $active->map($project)->values(),
            'resolved' => $resolved->map($project)->values(),
            'canUpdate' => Gate::forUser($actor)->allows('updateProblems', $encounter),
        ];
    }

    private function assertCurrentCare(User $actor, ClinicalEncounter $encounter): void
    {
        abort_unless(
            $encounter->organisation_id === $actor->organisation_id
            && $encounter->status === ClinicalEncounter::STATUS_IN_PROGRESS
            && $encounter->attending_clinician_user_id === $actor->id
            && $encounter->visit->assigned_doctor_user_id === $actor->id
            && $encounter->visit->status === Visit::STATUS_REGISTERED
            && $encounter->visit->visit_type === 'consultation'
            && $encounter->visit->queueEntry?->status === QueueEntry::STATUS_SERVING,
            404,
        );
    }

    /** @return array<string, mixed> */
    private function lockedAllergyProjection(User $actor, ClinicalEncounter $encounter): array
    {
        $profile = PatientAllergyProfile::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('patient_id', $encounter->visit->patient_id)
            ->sharedLock()
            ->first();
        $records = $profile
            ? PatientAllergyRecord::query()
                ->where('patient_allergy_profile_id', $profile->id)
                ->where('status', PatientAllergyRecord::STATUS_ACTIVE)
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get()
            : new Collection;
        $review = $profile
            ? ClinicalEncounterAllergyReview::query()
                ->where('clinical_encounter_id', $encounter->id)
                ->where('patient_allergy_profile_id', $profile->id)
                ->first()
            : null;

        return [
            'status' => $profile->status ?? PatientAllergyProfile::STATUS_UNKNOWN,
            'profileLockVersion' => $profile?->lock_version,
            'reviewedAt' => $profile?->reviewed_at?->toIso8601String(),
            'records' => $records->map(fn (PatientAllergyRecord $record): array => [
                'publicId' => $record->public_id,
                'allergen' => $record->allergen_text,
                'category' => $record->category,
                'reaction' => $record->reaction_text,
                'severity' => $record->severity,
                'recordedAt' => $record->recorded_at->toIso8601String(),
            ])->values(),
            'encounterReview' => $review ? [
                'reviewedAt' => $review->reviewed_at->toIso8601String(),
                'reviewedVersion' => $review->allergy_profile_lock_version_reviewed,
                'isCurrent' => $review->allergy_profile_lock_version_reviewed === $profile->lock_version,
            ] : null,
            'canUpdate' => Gate::forUser($actor)->allows('updateAllergies', $encounter),
            'canReview' => Gate::forUser($actor)->allows('reviewAllergies', $encounter),
        ];
    }
}
