<?php

namespace App\Http\Requests;

class ReviewEncounterAllergiesRequest extends ClinicalSafetyRequest
{
    public function authorize(): bool
    {
        return $this->authorizeCurrentCare('allergies.review.own', 'reviewAllergies');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'profile_lock_version' => ['required', 'integer', 'min:1'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'clinical_encounter_id' => ['prohibited'],
            'patient_allergy_profile_id' => ['prohibited'],
            'reviewed_by_user_id' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
        ];
    }
}
