<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ManageAllergyRequest extends ClinicalSafetyRequest
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
            'allergen_text' => ['required', 'string', 'max:500'],
            'category' => ['nullable', 'string', Rule::in(['medication', 'food', 'environmental', 'other'])],
            'reaction_text' => ['nullable', 'string', 'max:1000'],
            'severity' => ['nullable', 'string', Rule::in(['mild', 'moderate', 'severe'])],
            'public_id' => ['prohibited'],
            'organisation_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'patient_allergy_profile_id' => ['prohibited'],
            'status' => ['prohibited'],
            'recorded_at' => ['prohibited'],
            'recorded_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'entered_in_error_at' => ['prohibited'],
            'entered_in_error_by_user_id' => ['prohibited'],
        ];
    }
}
