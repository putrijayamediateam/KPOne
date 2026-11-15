<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlan;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DispensarySafetyValidator
{
    /** @param Collection<int,DispensaryItem> $items */
    public function assertCurrent(ClinicalEncounter $encounter, PatientAllergyProfile $profile, ?ClinicalEncounterAllergyReview $review, TreatmentPlan $plan, DispensaryHandoff $handoff, Collection $items): void
    {
        $visit = $encounter->visit;
        if ($profile->status === PatientAllergyProfile::STATUS_UNKNOWN || $profile->patient_id !== $visit->patient_id || ! $review || $review->organisation_id !== $encounter->organisation_id || $review->branch_id !== $encounter->branch_id || $review->clinical_encounter_id !== $encounter->id || $review->patient_allergy_profile_id !== $profile->id || $review->reviewed_by_user_id !== $encounter->attending_clinician_user_id || $review->allergy_profile_lock_version_reviewed !== $profile->lock_version) {
            throw ValidationException::withMessages(['allergy_safety' => 'Allergy safety changed or is no longer current. Return this case to the attending doctor.']);
        }
        if ($plan->status !== TreatmentPlan::STATUS_READY_FOR_DISPENSING || $handoff->treatment_plan_lock_version_received !== $plan->lock_version || $items->contains(fn (DispensaryItem $item): bool => $item->allergy_profile_version_validated !== $profile->lock_version)) {
            throw ValidationException::withMessages(['allergy_safety' => 'The received Treatment Plan or Allergy safety version is stale. Return this case to the attending doctor.']);
        }
    }
}
