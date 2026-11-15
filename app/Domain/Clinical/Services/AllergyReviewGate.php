<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class AllergyReviewGate
{
    public function assertCurrent(
        CurrentClinicalCareContext $care,
        ?PatientAllergyProfile $profile,
        ?ClinicalEncounterAllergyReview $review,
    ): void {
        if (! $profile || $profile->status === PatientAllergyProfile::STATUS_UNKNOWN) {
            throw ValidationException::withMessages([
                'allergy_review' => 'Record an explicit current Allergy Profile before ordering medicine.',
            ]);
        }

        if ($profile->organisation_id !== $care->actor->organisation_id
            || $profile->patient_id !== $care->patient->id
            || ! $review
            || $review->organisation_id !== $care->actor->organisation_id
            || $review->branch_id !== $care->branch->id
            || $review->clinical_encounter_id !== $care->encounter->id
            || $review->patient_allergy_profile_id !== $profile->id
            || $review->reviewed_by_user_id !== $care->actor->id) {
            throw new AuthorizationException('The current clinical allergy review is not valid for this Encounter.');
        }

        if ($review->allergy_profile_lock_version_reviewed !== $profile->lock_version) {
            throw ValidationException::withMessages([
                'allergy_review' => 'The Allergy Profile changed after it was reviewed. Review the current profile before ordering medicine.',
            ]);
        }
    }
}
