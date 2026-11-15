<?php

namespace App\Http\Requests;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStaffBranchAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccess', $this->route('staff'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists(Branch::class, 'id')->where(
                    fn ($query) => $query
                        ->where('organisation_id', $this->user()->organisation_id)
                        ->where('is_active', true),
                ),
            ],
            'assignment_type' => ['required', Rule::in(['permanent', 'temporary'])],
            'is_primary' => ['required', 'boolean'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
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
