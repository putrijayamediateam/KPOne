<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartClinicalEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('encounters.start.own');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'queue_entry_id' => ['prohibited'],
            'queue_number' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'attending_clinician_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'clinical_note' => ['prohibited'],
            'started_at' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'lock_version' => ['prohibited'],
            'vitals' => ['prohibited'],
            'diagnoses' => ['prohibited'],
        ];
    }
}
