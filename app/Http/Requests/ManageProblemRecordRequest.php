<?php

namespace App\Http\Requests;

class ManageProblemRecordRequest extends ClinicalSafetyRequest
{
    public function authorize(): bool
    {
        return $this->authorizeCurrentCare('problems.update.own', 'updateProblems');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => $this->routeIs('encounters.problems.update')
                ? ['required', 'integer', 'min:1']
                : ['prohibited'],
            'condition_text' => ['required', 'string', 'max:500'],
            'condition_code' => ['nullable', 'string', 'max:50', 'required_with:code_system'],
            'code_system' => ['nullable', 'string', 'max:50', 'required_with:condition_code'],
            'onset_date' => ['nullable', 'date_format:Y-m-d'],
            'public_id' => ['prohibited'],
            'organisation_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'status' => ['prohibited'],
            'recorded_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'resolved_by_user_id' => ['prohibited'],
            'entered_in_error_at' => ['prohibited'],
            'entered_in_error_by_user_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'condition_code.required_with' => 'Enter both the condition code and code system, or leave both blank.',
            'code_system.required_with' => 'Enter both the condition code and code system, or leave both blank.',
        ];
    }
}
