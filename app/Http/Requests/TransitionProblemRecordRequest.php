<?php

namespace App\Http\Requests;

class TransitionProblemRecordRequest extends ClinicalSafetyRequest
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
            'lock_version' => ['required', 'integer', 'min:1'],
            'resolved_date' => ['nullable', 'date_format:Y-m-d'],
            'organisation_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'status' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'resolved_by_user_id' => ['prohibited'],
            'entered_in_error_at' => ['prohibited'],
            'entered_in_error_by_user_id' => ['prohibited'],
        ];
    }
}
