<?php

namespace App\Domain\Identity\Services;

use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffAdministrationService
{
    public function __construct(
        private AuditRecorder $audit,
        private StaffAuthorityService $authority,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function updateProfile(User $subject, array $attributes, User $actor): User
    {
        return DB::transaction(function () use ($subject, $attributes, $actor): User {
            $lockedSubject = User::query()
                ->with('staffProfile')
                ->whereKey($subject->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $actor->can('staff.manage.organisation')
                || $actor->is($lockedSubject)
                || ! $this->authority->canManage($actor, $lockedSubject)) {
                throw new AuthorizationException('You may not update this staff member.');
            }

            $validated = Validator::make([
                ...$attributes,
                'email' => Str::lower(trim((string) ($attributes['email'] ?? ''))),
                'staff_number' => $this->nullableTrimmed(
                    is_string($attributes['staff_number'] ?? null) ? $attributes['staff_number'] : null,
                ),
                'job_title' => $this->nullableTrimmed(
                    is_string($attributes['job_title'] ?? null) ? $attributes['job_title'] : null,
                ),
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($lockedSubject->id)],
                'department_id' => [
                    'required',
                    'integer',
                    Rule::exists(Department::class, 'id')->where(
                        fn ($query) => $query
                            ->where('organisation_id', $actor->organisation_id)
                            ->where('is_active', true),
                    ),
                ],
                'staff_number' => ['nullable', 'string', 'max:50', Rule::unique('staff_profiles', 'staff_number')->ignore($lockedSubject->staffProfile?->id)],
                'job_title' => ['nullable', 'string', 'max:255'],
            ])->validate();

            $profile = $lockedSubject->staffProfile;

            if (! $profile) {
                throw ValidationException::withMessages(['staff' => 'The staff profile is missing.']);
            }

            $identityBefore = $lockedSubject->only(['name', 'email']);
            $profileBefore = $profile->only(['department_id', 'staff_number', 'job_title']);
            $emailChanged = $lockedSubject->email !== $validated['email'];

            $lockedSubject->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                ...($emailChanged ? ['email_verified_at' => null] : []),
            ])->save();
            $profile->forceFill([
                'department_id' => $validated['department_id'],
                'staff_number' => $this->nullableTrimmed($validated['staff_number'] ?? null),
                'job_title' => $this->nullableTrimmed($validated['job_title'] ?? null),
            ])->save();

            $lockedSubject->refresh();
            $profile->refresh();
            $identityAfter = ['name' => $lockedSubject->name, 'email' => $lockedSubject->email];
            $profileAfter = [
                'department_id' => $profile->department_id,
                'staff_number' => $profile->staff_number,
                'job_title' => $profile->job_title,
            ];

            if ($identityBefore !== $identityAfter) {
                $this->audit->record('staff.identity.updated', $lockedSubject, [
                    'before' => $identityBefore,
                    'after' => $identityAfter,
                ], $actor);
            }

            if ($profileBefore['department_id'] !== $profileAfter['department_id']) {
                $this->audit->record('staff.department.changed', $lockedSubject, [
                    'before' => ['department_id' => $profileBefore['department_id']],
                    'after' => ['department_id' => $profileAfter['department_id']],
                ], $actor);
            }

            $employmentBefore = collect($profileBefore)->only(['staff_number', 'job_title'])->all();
            $employmentAfter = collect($profileAfter)->only(['staff_number', 'job_title'])->all();

            if ($employmentBefore !== $employmentAfter) {
                $this->audit->record('staff.profile.updated', $lockedSubject, [
                    'before' => $employmentBefore,
                    'after' => $employmentAfter,
                ], $actor);
            }

            return $lockedSubject->refresh()->load(['staffProfile.department', 'roles']);
        });
    }

    public function setActive(User $subject, bool $active, User $actor): User
    {
        $result = DB::transaction(function () use ($subject, $active, $actor) {
            $lockedSubject = User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();

            if (! $actor->can('staff.manage.organisation')
                || $actor->is($lockedSubject)
                || ! $this->authority->canManage($actor, $lockedSubject)) {
                throw new AuthorizationException('You may not change this staff member\'s status.');
            }

            if ($lockedSubject->is_active === $active) {
                return $lockedSubject;
            }

            if ($active && $lockedSubject->staffProfile?->branchAssignments()
                ->effectiveAt()
                ->where('is_primary', true)
                ->count() !== 1) {
                throw ValidationException::withMessages([
                    'is_active' => 'An active account requires exactly one currently effective primary branch.',
                ]);
            }

            $before = $lockedSubject->is_active;
            $lockedSubject->forceFill([
                'is_active' => $active,
                'deactivated_at' => $active ? null : now(),
            ])->save();

            $this->audit->record(
                $active ? 'staff.activated' : 'staff.deactivated',
                $lockedSubject,
                ['before' => ['is_active' => $before], 'after' => ['is_active' => $active]],
                $actor,
            );

            return $lockedSubject->refresh();
        });

        // Keep an already-resolved authentication model coherent for the
        // remainder of the current process; future requests also reload it.
        $subject->refresh();

        return $result;
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
