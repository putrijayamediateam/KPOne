<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveTreatmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canAny(['treatment_plans.create.own', 'treatment_plans.update.own']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['present', 'nullable', 'integer', 'min:1'],
            'medicines' => ['present', 'array', 'max:30'],
            'medicines.*.public_id' => ['nullable', 'uuid'],
            'medicines.*.catalogue_public_id' => ['nullable', 'uuid'],
            'medicines.*.quantity_ordered' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999.999'],
            'medicines.*.dosage' => ['required', 'string', 'max:255'],
            'medicines.*.frequency' => ['required', 'string', 'max:255'],
            'medicines.*.duration' => ['nullable', 'string', 'max:255'],
            'medicines.*.route' => ['nullable', 'string', 'max:255'],
            'medicines.*.administration_instruction' => ['nullable', 'string', 'max:2000'],
            'medicines.*.indication' => ['nullable', 'string', 'max:2000'],
            'medicines.*.precaution' => ['nullable', 'string', 'max:2000'],
            'services' => ['present', 'array', 'max:30'],
            'services.*.public_id' => ['nullable', 'uuid'],
            'services.*.catalogue_public_id' => ['nullable', 'uuid'],
            'services.*.quantity_ordered' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999.999'],
            'services.*.clinical_instruction' => ['nullable', 'string', 'max:2000'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'clinical_encounter_id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'medicines.*.position' => ['prohibited'],
            'medicines.*.status' => ['prohibited'],
            'medicines.*.medicine_name_snapshot' => ['prohibited'],
            'medicines.*.allergy_profile_version_validated' => ['prohibited'],
            'services.*.position' => ['prohibited'],
            'services.*.status' => ['prohibited'],
            'services.*.service_name_snapshot' => ['prohibited'],
        ];
    }
}
