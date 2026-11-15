<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EndStaffBranchAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccess', $this->route('staff'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'valid_until' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
