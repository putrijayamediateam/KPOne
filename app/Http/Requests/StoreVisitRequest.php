<?php

namespace App\Http\Requests;

use App\Domain\Visit\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Visit::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'expected_branch_id' => ['required', 'integer'],
            'patient_number' => [
                'nullable', 'string', 'max:20', 'required_without:quick_patient',
                Rule::prohibitedIf(fn (): bool => $this->filled('quick_patient')),
            ],
            'quick_patient' => [
                'nullable', 'array', 'required_without:patient_number',
                Rule::prohibitedIf(fn (): bool => $this->filled('patient_number')),
            ],
            'quick_patient.full_name' => ['required_with:quick_patient', 'string', 'max:255'],
            'quick_patient.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'quick_patient.sex' => ['required_with:quick_patient', 'in:female,male,indeterminate,unknown'],
            'quick_patient.mobile_phone' => ['required_with:quick_patient', 'nullable', 'string', 'max:64'],
            'quick_patient.phone_country' => ['sometimes', 'string', 'size:2'],
            'quick_patient.identifiers' => ['required_with:quick_patient', 'nullable', 'array', 'size:1'],
            'quick_patient.identifiers.*.identifier_type' => ['required', 'in:nric,passport'],
            'quick_patient.identifiers.*.issuing_country_code' => ['nullable', 'string', 'size:2'],
            'quick_patient.identifiers.*.value' => ['required', 'string', 'max:100'],
            'quick_patient.duplicate_override' => ['nullable', 'boolean'],
            'visit_type' => ['required', 'in:consultation,otc'],
            'assigned_doctor_user_id' => ['nullable', 'integer', 'required_if:visit_type,consultation'],
            'visit_reason' => ['prohibited'],
            'visit_reason_public_ids' => ['nullable', 'array', 'max:5', 'required_if:visit_type,consultation'],
            'visit_reason_public_ids.*' => ['required', 'uuid', 'distinct'],
            'priority' => ['required', 'in:normal,urgent'],
            'coverage_type' => ['required', 'in:self_pay,panel'],
            'panel_id' => ['nullable', 'integer', 'required_if:coverage_type,panel'],
            'coverage_member_reference' => ['nullable', 'string', 'max:100'],
            'confirm_repeat' => ['nullable', 'boolean'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'visit_number' => ['prohibited'],
            'status' => ['prohibited'],
            'registered_at' => ['prohibited'],
            'registered_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by_user_id' => ['prohibited'],
            'lock_version' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'This Registration form has expired. Please start a new Registration.',
            'idempotency_key.uuid' => 'This Registration form has expired. Please start a new Registration.',
            'patient_number.required_without' => 'Please select a Patient or use Quick create.',
            'patient_number.prohibited' => 'Choose either an existing Patient or Quick create, not both.',
            'quick_patient.required_without' => 'Please select a Patient or use Quick create.',
            'quick_patient.prohibited' => 'Choose either an existing Patient or Quick create, not both.',
            'quick_patient.full_name.required_with' => 'Please enter the Patient name.',
            'quick_patient.sex.required_with' => 'Please select the Patient Gender, or choose Unknown.',
            'quick_patient.mobile_phone.required_with' => 'Nombor telefon diperlukan.',
            'quick_patient.mobile_phone.string' => 'Masukkan nombor telefon yang sah untuk negara yang dipilih.',
            'quick_patient.mobile_phone.max' => 'Masukkan nombor telefon yang sah untuk negara yang dipilih.',
            'quick_patient.identifiers.required_with' => 'Pilih satu No. IC atau Passport.',
            'quick_patient.identifiers.0.value.required' => $this->input('quick_patient.identifiers.0.identifier_type') === 'passport' ? 'No. Passport tidak lengkap.' : 'No. IC mesti mempunyai 12 digit.',
            'assigned_doctor_user_id.required_if' => 'Please select a doctor for this consultation.',
            'visit_reason_public_ids.required_if' => 'Select at least one Visit Reason.',
            'visit_reason_public_ids.max' => 'A Visit can have up to 5 reasons.',
            'panel_id.required_if' => 'Please select a Panel for this Visit.',
            'coverage_member_reference.max' => 'The Panel member reference must be 100 characters or fewer.',
        ];
    }
}
