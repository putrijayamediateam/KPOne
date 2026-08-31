<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClinicalEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('encounters.update.own');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'clinical_note' => ['nullable', 'string', 'max:20000'],
            'vitals' => ['required', 'array'],
            'vitals.systolic_bp' => ['nullable', 'integer', 'min:1', 'required_with:vitals.diastolic_bp'],
            'vitals.diastolic_bp' => ['nullable', 'integer', 'min:1', 'required_with:vitals.systolic_bp'],
            'vitals.pulse_bpm' => ['nullable', 'integer', 'min:1'],
            'vitals.temperature_celsius' => ['nullable', 'numeric', 'gt:0'],
            'vitals.spo2_percent' => ['nullable', 'numeric', 'between:0,100'],
            'vitals.weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'vitals.height_cm' => ['nullable', 'numeric', 'gt:0'],
            'vitals.observed_at' => ['prohibited'],
            'vitals.recorded_by_user_id' => ['prohibited'],
            'vitals.updated_by_user_id' => ['prohibited'],
            'diagnoses' => ['present', 'array', 'max:20'],
            'diagnoses.*.diagnosis_text' => ['required', 'string', 'max:500'],
            'diagnoses.*.diagnosis_code' => ['nullable', 'string', 'max:50', 'required_with:diagnoses.*.code_system'],
            'diagnoses.*.code_system' => ['nullable', 'string', 'max:50', 'required_with:diagnoses.*.diagnosis_code'],
            'diagnoses.*.is_primary' => ['required', 'boolean'],
            'diagnoses.*.id' => ['prohibited'],
            'diagnoses.*.position' => ['prohibited'],
            'diagnoses.*.recorded_by_user_id' => ['prohibited'],
            'diagnoses.*.updated_by_user_id' => ['prohibited'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'queue_entry_id' => ['prohibited'],
            'queue_number' => ['prohibited'],
            'queue_status' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'attending_clinician_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'started_at' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vitals.systolic_bp.required_with' => 'Enter both systolic and diastolic blood pressure, or leave both blank.',
            'vitals.diastolic_bp.required_with' => 'Enter both systolic and diastolic blood pressure, or leave both blank.',
            'diagnoses.*.diagnosis_code.required_with' => 'Enter both the diagnosis code and code system, or leave both blank.',
            'diagnoses.*.code_system.required_with' => 'Enter both the diagnosis code and code system, or leave both blank.',
        ];
    }
}
