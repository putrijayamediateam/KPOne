<?php

namespace App\Http\Requests;

use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('staff'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'staff_number' => $this->filled('staff_number') ? trim((string) $this->input('staff_number')) : null,
            'job_title' => $this->filled('job_title') ? trim((string) $this->input('job_title')) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $staff */
        $staff = $this->route('staff');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($staff->id)],
            'department_id' => [
                'required',
                'integer',
                Rule::exists(Department::class, 'id')->where(
                    fn ($query) => $query
                        ->where('organisation_id', $this->user()->organisation_id)
                        ->where('is_active', true),
                ),
            ],
            'staff_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('staff_profiles', 'staff_number')->ignore($staff->staffProfile?->id),
            ],
            'job_title' => ['nullable', 'string', 'max:255'],
            'organisation_id' => ['prohibited'],
            'google_subject' => ['prohibited'],
            'password' => ['prohibited'],
            'is_active' => ['prohibited'],
            'last_login_at' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
            'roles' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'assignments' => ['prohibited'],
        ];
    }
}
