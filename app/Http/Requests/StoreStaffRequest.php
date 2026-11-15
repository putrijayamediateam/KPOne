<?php

namespace App\Http\Requests;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'staff_number' => $this->filled('staff_number') ? trim((string) $this->input('staff_number')) : null,
            'job_title' => $this->filled('job_title') ? trim((string) $this->input('job_title')) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organisationId = $this->user()->organisation_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'credential_strategy' => ['required', Rule::in(['password', 'google_only'])],
            'password' => [
                'exclude_unless:credential_strategy,password',
                'required_if:credential_strategy,password',
                'string',
                Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
                'confirmed',
            ],
            'password_confirmation' => [
                'exclude_unless:credential_strategy,password',
                'required_if:credential_strategy,password',
                'string',
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists(Department::class, 'id')->where(
                    fn ($query) => $query->where('organisation_id', $organisationId)->where('is_active', true),
                ),
            ],
            'staff_number' => ['nullable', 'string', 'max:50', Rule::unique('staff_profiles', 'staff_number')],
            'job_title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(array_keys(PermissionCatalogue::roles()))],
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.branch_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(Branch::class, 'id')->where(
                    fn ($query) => $query->where('organisation_id', $organisationId)->where('is_active', true),
                ),
            ],
            'assignments.*.assignment_type' => ['required', Rule::in(['permanent', 'temporary'])],
            'assignments.*.is_primary' => ['required', 'boolean'],
            'assignments.*.valid_from' => ['required', 'date_format:Y-m-d'],
            'assignments.*.valid_until' => ['nullable', 'date_format:Y-m-d'],
            'organisation_id' => ['prohibited'],
            'google_subject' => ['prohibited'],
            'last_login_at' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rawAssignments = $this->input('assignments', []);
            $assignments = is_array($rawAssignments) ? $rawAssignments : [];
            $today = now()->toDateString();
            $effectivePrimaries = 0;

            foreach ($assignments as $assignment) {
                if (! is_array($assignment) || ! filter_var($assignment['is_primary'] ?? false, FILTER_VALIDATE_BOOL)) {
                    continue;
                }

                $from = $assignment['valid_from'] ?? null;
                $until = $assignment['valid_until'] ?? null;

                if (is_string($from)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1
                    && $from <= $today
                    && (! is_string($until)
                        || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1 && $until >= $today))) {
                    $effectivePrimaries++;
                }
            }

            if ($effectivePrimaries !== 1) {
                $validator->errors()->add(
                    'assignments',
                    'Exactly one branch assignment must be currently effective and primary.',
                );
            }

            foreach ($assignments as $index => $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                if (($assignment['assignment_type'] ?? null) === 'temporary'
                    && blank($assignment['valid_until'] ?? null)) {
                    $validator->errors()->add(
                        "assignments.{$index}.valid_until",
                        'Temporary assignments require an end date.',
                    );
                }

                if (is_string($assignment['valid_from'] ?? null)
                    && is_string($assignment['valid_until'] ?? null)
                    && $assignment['valid_until'] < $assignment['valid_from']) {
                    $validator->errors()->add(
                        "assignments.{$index}.valid_until",
                        'The end date must be on or after the start date.',
                    );
                }
            }
        }];
    }
}
