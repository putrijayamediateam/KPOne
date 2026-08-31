<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('visit'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'visit_type' => ['required', 'in:consultation,otc'],
            'assigned_doctor_user_id' => ['nullable', 'integer', 'required_if:visit_type,consultation'],
            'visit_reason' => ['nullable', 'string', 'max:500', 'required_if:visit_type,consultation'],
            'priority' => ['required', 'in:normal,urgent'],
            'coverage_type' => ['required', 'in:self_pay,panel'],
            'panel_id' => ['nullable', 'integer', 'required_if:coverage_type,panel'],
            'coverage_member_reference' => ['nullable', 'string', 'max:100'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'patient_number' => ['prohibited'],
            'visit_number' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'assigned_doctor_user_id.required_if' => 'Please select a doctor for this consultation.',
            'visit_reason.required_if' => 'Please enter a reason for this consultation.',
            'visit_reason.max' => 'The Visit reason must be 500 characters or fewer.',
            'panel_id.required_if' => 'Please select a Panel for this Visit.',
            'coverage_member_reference.max' => 'The Panel member reference must be 100 characters or fewer.',
            'lock_version.required' => 'This Visit needs to be reloaded before it can be changed.',
        ];
    }
}
