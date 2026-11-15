<?php

namespace App\Http\Requests;

class AllergyProfileVersionRequest extends ClinicalSafetyRequest
{
    public function authorize(): bool
    {
        return $this->authorizeCurrentCare('allergies.update.own', 'updateAllergies');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'profile_lock_version' => ['present', 'nullable', 'integer', 'min:1'],
            'organisation_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'status' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'reviewed_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'allergy_profile_lock_version_reviewed' => ['prohibited'],
        ];
    }
}
