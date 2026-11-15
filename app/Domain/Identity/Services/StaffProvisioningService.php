<?php

namespace App\Domain\Identity\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StaffProvisioningService
{
    public function __construct(
        private BranchAssignmentService $assignments,
        private StaffRoleService $roles,
        private StaffAuthorityService $authority,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function provision(User $actor, array $attributes): User
    {
        if (! $actor->can('staff.manage.organisation') || ! $actor->can('access.manage.organisation')) {
            throw new AuthorizationException('You may not provision staff accounts.');
        }

        $validated = $this->validate($actor, $attributes);

        if (! $this->authority->canAssignRoles($actor, $validated['roles'])) {
            throw new AuthorizationException('You may not assign one or more selected roles.');
        }

        return DB::transaction(function () use ($actor, $validated): User {
            $user = new User;
            $user->forceFill([
                'organisation_id' => $actor->organisation_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['credential_strategy'] === 'password'
                    ? ($validated['password'] ?? throw ValidationException::withMessages([
                        'password' => 'A bootstrap password is required for password access.',
                    ]))
                    : null,
                'is_active' => $validated['is_active'],
                'deactivated_at' => $validated['is_active'] ? null : now(),
            ])->save();

            $profile = new StaffProfile;
            $profile->forceFill([
                'user_id' => $user->id,
                'department_id' => $validated['department_id'],
                'staff_number' => $this->nullableTrimmed($validated['staff_number'] ?? null),
                'job_title' => $this->nullableTrimmed($validated['job_title'] ?? null),
            ])->save();

            $this->roles->sync($user, $validated['roles'], $actor);

            foreach ($validated['assignments'] as $assignment) {
                $branch = Branch::query()->findOrFail($assignment['branch_id']);
                $this->assignments->create($profile, $branch, [
                    'assignment_type' => $assignment['assignment_type'],
                    'is_primary' => $assignment['is_primary'],
                    'valid_from' => $assignment['valid_from'],
                    'valid_until' => $assignment['valid_until'] ?? null,
                ], $actor);
            }

            $effectivePrimaries = $profile->branchAssignments()
                ->effectiveAt()
                ->where('is_primary', true)
                ->count();

            if ($effectivePrimaries !== 1) {
                throw ValidationException::withMessages([
                    'assignments' => 'Provisioned staff must have exactly one currently effective primary branch.',
                ]);
            }

            $this->audit->record('staff.created', $user, [
                'after' => [
                    'organisation_id' => $user->organisation_id,
                    'department_id' => $profile->department_id,
                    'staff_number' => $profile->staff_number,
                    'is_active' => $user->is_active,
                    'credential_strategy' => $validated['credential_strategy'],
                    'roles' => $validated['roles'],
                    'branch_ids' => array_column($validated['assignments'], 'branch_id'),
                ],
            ], $actor);

            return $user->refresh()->load([
                'staffProfile.department',
                'staffProfile.branchAssignments.branch',
                'roles',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *   name:string,email:string,credential_strategy:string,password?:string,password_confirmation?:string,
     *   department_id:int,staff_number:string|null,job_title:string|null,is_active:bool,roles:list<string>,
     *   assignments:list<array{branch_id:int,assignment_type:string,is_primary:bool,valid_from:string,valid_until:string|null}>
     * }
     */
    private function validate(User $actor, array $attributes): array
    {
        $attributes['email'] = Str::lower(trim((string) ($attributes['email'] ?? '')));
        $attributes['staff_number'] = $this->nullableTrimmed(
            is_string($attributes['staff_number'] ?? null) ? $attributes['staff_number'] : null,
        );
        $attributes['job_title'] = $this->nullableTrimmed(
            is_string($attributes['job_title'] ?? null) ? $attributes['job_title'] : null,
        );
        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'credential_strategy' => ['required', Rule::in(['password', 'google_only'])],
            'password' => [
                'required_if:credential_strategy,password',
                'nullable',
                'string',
                Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
                'confirmed',
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists(Department::class, 'id')->where(
                    fn ($query) => $query
                        ->where('organisation_id', $actor->organisation_id)
                        ->where('is_active', true),
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
                    fn ($query) => $query
                        ->where('organisation_id', $actor->organisation_id)
                        ->where('is_active', true),
                ),
            ],
            'assignments.*.assignment_type' => ['required', Rule::in(['permanent', 'temporary'])],
            'assignments.*.is_primary' => ['required', 'boolean'],
            'assignments.*.valid_from' => ['required', 'date_format:Y-m-d'],
            'assignments.*.valid_until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $validator->after(function ($validator) use ($attributes): void {
            $rawAssignments = $attributes['assignments'] ?? [];
            $assignments = is_array($rawAssignments) ? $rawAssignments : [];
            $today = now()->toDateString();
            $currentPrimaryCount = 0;

            foreach ($assignments as $assignment) {
                if (! is_array($assignment) || ! ($assignment['is_primary'] ?? false)) {
                    continue;
                }

                $from = $assignment['valid_from'] ?? null;
                $until = $assignment['valid_until'] ?? null;

                if (is_string($from)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1
                    && $from <= $today
                    && (! is_string($until)
                        || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1 && $until >= $today))) {
                    $currentPrimaryCount++;
                }
            }

            if ($currentPrimaryCount !== 1) {
                $validator->errors()->add(
                    'assignments',
                    'Exactly one branch assignment must be currently effective and primary.',
                );
            }

            foreach ($assignments as $index => $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                $from = $assignment['valid_from'] ?? null;
                $until = $assignment['valid_until'] ?? null;

                if (($assignment['assignment_type'] ?? null) === 'temporary' && blank($until)) {
                    $validator->errors()->add("assignments.{$index}.valid_until", 'Temporary assignments require an end date.');
                }

                if (is_string($from) && is_string($until) && $until < $from) {
                    $validator->errors()->add("assignments.{$index}.valid_until", 'The end date must be on or after the start date.');
                }
            }
        });

        $validated = $validator->validate();
        $roles = [];
        $rawRoles = $validated['roles'] ?? [];

        foreach (is_array($rawRoles) ? $rawRoles : [] as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }

        $assignments = [];

        $rawAssignments = $validated['assignments'] ?? [];

        foreach (is_array($rawAssignments) ? $rawAssignments : [] as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $assignments[] = [
                'branch_id' => (int) $assignment['branch_id'],
                'assignment_type' => (string) $assignment['assignment_type'],
                'is_primary' => (bool) $assignment['is_primary'],
                'valid_from' => (string) $assignment['valid_from'],
                'valid_until' => isset($assignment['valid_until']) && $assignment['valid_until'] !== ''
                    ? (string) $assignment['valid_until']
                    : null,
            ];
        }

        $result = [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'credential_strategy' => (string) $validated['credential_strategy'],
            'department_id' => (int) $validated['department_id'],
            'staff_number' => isset($validated['staff_number']) ? (string) $validated['staff_number'] : null,
            'job_title' => isset($validated['job_title']) ? (string) $validated['job_title'] : null,
            'is_active' => (bool) $validated['is_active'],
            'roles' => $roles,
            'assignments' => $assignments,
        ];

        if (isset($validated['password'])) {
            $result['password'] = (string) $validated['password'];
        }

        if (isset($validated['password_confirmation'])) {
            $result['password_confirmation'] = (string) $validated['password_confirmation'];
        }

        return $result;
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
