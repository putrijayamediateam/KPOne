<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffBranchAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccess', $this->route('staff'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assignment_type' => ['required', Rule::in(['permanent', 'temporary'])],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'branch_id' => ['prohibited'],
            'is_primary' => ['prohibited'],
            'staff_profile_id' => ['prohibited'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('assignment_type') === 'temporary' && blank($this->input('valid_until'))) {
                $validator->errors()->add('valid_until', 'Temporary assignments require an end date.');
            }
        }];
    }
}
