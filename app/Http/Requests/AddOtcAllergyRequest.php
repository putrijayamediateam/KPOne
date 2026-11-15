<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RedirectsValidationFailuresToDispensaryCase;
use Illuminate\Foundation\Http\FormRequest;

/** DSP-5: shape checks only; the allergy service repeats the clinical validation inside the transaction. */
class AddOtcAllergyRequest extends FormRequest
{
    use RedirectsValidationFailuresToDispensaryCase;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'case_lock_version' => ['required', 'integer', 'min:1'],
            'profile_lock_version' => ['present', 'nullable', 'integer', 'min:1'],
            'allergen_text' => ['required', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'in:medication,food,environmental,other'],
            'reaction_text' => ['nullable', 'string', 'max:1000'],
            'severity' => ['nullable', 'string', 'in:mild,moderate,severe'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'allergen_text.required' => 'Enter the allergen.',
            'allergen_text.max' => 'Allergen must be 500 characters or fewer.',
            'reaction_text.max' => 'Reaction must be 1000 characters or fewer.',
        ];
    }
}
