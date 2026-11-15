<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class DirectorBootstrapService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Bootstrap the first Director for the existing Klinik Putrijaya
     * organisation.
     *
     * This is a one-time bootstrap boundary for the first Director only.
     * Runtime staff, role and branch mutations must continue through the
     * normal identity/access services.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function bootstrap(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $organisation = Organisation::query()
                ->where('code', 'KLINIK_PUTRIJAYA')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($organisation === null) {
                throw ValidationException::withMessages([
                    'organisation' => 'The active KLINIK_PUTRIJAYA organisation was not found.',
                ]);
            }

            $existingDirector = User::query()
                ->where('organisation_id', $organisation->id)
                ->where('is_active', true)
                ->role(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)
                ->exists();

            if ($existingDirector) {
                throw ValidationException::withMessages([
                    'role' => 'An active Director already exists for this organisation.',
                ]);
            }

            $validated = $this->validateAttributes($attributes);

            $department = Department::query()
                ->where('organisation_id', $organisation->id)
                ->where('code', 'LEADERSHIP')
                ->where('is_active', true)
                ->first();

            if ($department === null) {
                throw ValidationException::withMessages([
                    'department' => 'The active Leadership department was not found.',
                ]);
            }

            $branch = Branch::query()
                ->where('id', $validated['branch_id'])
                ->where('organisation_id', $organisation->id)
                ->where('is_active', true)
                ->first();

            if ($branch === null) {
                throw ValidationException::withMessages([
                    'branch_id' => 'The selected branch is not an active branch of this organisation.',
                ]);
            }

            $role = Role::query()
                ->where('name', PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)
                ->where('guard_name', 'web')
                ->first();

            if ($role === null) {
                throw ValidationException::withMessages([
                    'role' => 'The Director role was not found in the permission catalogue.',
                ]);
            }

            $user = new User;
            $user->organisation_id = $organisation->id;
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->is_active = true;

            if ($validated['credential_strategy'] === 'password') {
                $user->password = $validated['password'];
            }

            $user->save();

            $profile = new StaffProfile;
            $profile->user_id = $user->id;
            $profile->department_id = $department->id;
            $profile->staff_number = $validated['staff_number'];
            $profile->job_title = $validated['job_title'];
            $profile->save();

            $user->syncRoles([$role->name]);

            $assignment = new StaffBranchAssignment;
            $assignment->forceFill([
                'staff_profile_id' => $profile->id,
                'branch_id' => $branch->id,
                'is_primary' => true,
                'assignment_type' => 'permanent',
                'valid_from' => now()->toDateString(),
                'valid_until' => null,
            ]);
            $assignment->save();

            $this->audit->record(
                event: 'staff.director_bootstrapped',
                subject: $user,
                metadata: [
                    'organisation_code' => $organisation->code,
                    'department_id' => $department->id,
                    'branch_id' => $branch->id,
                    'staff_number' => $profile->staff_number,
                    'credential_strategy' => $validated['credential_strategy'],
                    'role' => PermissionCatalogue::PROTECTED_AUTHORITY_ROLE,
                ],
                actor: null,
                branch: $branch,
                organisationId: $organisation->id,
            );

            return $user->load([
                'organisation',
                'staffProfile.department',
                'staffProfile.branchAssignments.branch',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     name: string,
     *     email: string,
     *     staff_number: string,
     *     job_title: string,
     *     branch_id: int,
     *     credential_strategy: string,
     *     password: string|null,
     * }
     */
    private function validateAttributes(array $attributes): array
    {
        $credentialStrategy = $attributes['credential_strategy'] ?? 'google_only';

        if (! in_array($credentialStrategy, ['google_only', 'password'], true)) {
            throw ValidationException::withMessages([
                'credential_strategy' => 'Credential strategy must be google_only or password.',
            ]);
        }

        $email = mb_strtolower(trim((string) ($attributes['email'] ?? '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'A valid email address is required.',
            ]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email address has already been taken.',
            ]);
        }

        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Name is required.',
            ]);
        }

        $staffNumber = trim((string) ($attributes['staff_number'] ?? ''));

        if ($staffNumber === '') {
            throw ValidationException::withMessages([
                'staff_number' => 'Staff number is required.',
            ]);
        }

        if (StaffProfile::query()->where('staff_number', $staffNumber)->exists()) {
            throw ValidationException::withMessages([
                'staff_number' => 'The staff number has already been taken.',
            ]);
        }

        $branchId = (int) ($attributes['branch_id'] ?? 0);

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch_id' => 'A branch is required.',
            ]);
        }

        $jobTitle = trim((string) ($attributes['job_title'] ?? 'Director'));

        if ($credentialStrategy === 'password') {
            $password = (string) ($attributes['password'] ?? '');
            $passwordConfirmation = (string) ($attributes['password_confirmation'] ?? '');

            if (
                strlen($password) < 12
                || ! preg_match('/[a-z]/', $password)
                || ! preg_match('/[A-Z]/', $password)
                || ! preg_match('/[0-9]/', $password)
                || ! preg_match('/[^A-Za-z0-9]/', $password)
                || $password !== $passwordConfirmation
            ) {
                throw ValidationException::withMessages([
                    'password' => 'Password must be at least 12 characters and include uppercase, lowercase, number and symbol, with matching confirmation.',
                ]);
            }
        }

        return [
            'name' => $name,
            'email' => $email,
            'staff_number' => $staffNumber,
            'job_title' => $jobTitle,
            'branch_id' => $branchId,
            'credential_strategy' => $credentialStrategy,
            'password' => $credentialStrategy === 'password'
                ? $password
                : null,
        ];
    }
}
