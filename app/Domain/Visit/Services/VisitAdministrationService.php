<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitAdministrationService
{
    public function __construct(
        private BranchAccessService $branches,
        private VisitDoctorEligibilityService $doctors,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function update(Visit $visit, array $attributes, User $actor): Visit
    {
        Gate::forUser($actor)->authorize('update', $visit);
        $branch = $this->assertActiveBranch($actor, $visit, $attributes);
        $validated = $this->validateUpdate($attributes);

        return DB::transaction(function () use ($visit, $validated, $actor, $branch): Visit {
            $this->lockActor($actor, $branch, 'visits.update.branch');
            Patient::query()->whereKey($visit->patient_id)->lockForUpdate()->firstOrFail();
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->assertMutable($locked, (int) $validated['lock_version']);

            [, , $effectiveDate] = $this->branchDay($branch);
            $doctor = null;
            if ($validated['assigned_doctor_user_id'] !== null) {
                $doctor = $this->doctors->lockAndValidate($validated['assigned_doctor_user_id'], $branch, $effectiveDate);
            }
            $panel = $this->lockPanel($actor, $validated);
            $before = $locked->only([
                'visit_type', 'priority', 'visit_reason', 'assigned_doctor_user_id', 'coverage_type',
                'panel_id', 'coverage_panel_name_snapshot', 'coverage_member_reference',
            ]);
            $locked->forceFill([
                'visit_type' => $validated['visit_type'],
                'priority' => $validated['priority'],
                'visit_reason' => $validated['visit_reason'],
                'assigned_doctor_user_id' => $doctor?->id,
                'coverage_type' => $validated['coverage_type'],
                'panel_id' => $panel?->id,
                'coverage_panel_name_snapshot' => $panel?->name,
                'coverage_member_reference' => $validated['coverage_type'] === 'panel'
                    ? $validated['coverage_member_reference'] : null,
                'updated_by_user_id' => $actor->id,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $this->recordChanges($locked, $before, $actor);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function cancel(Visit $visit, array $attributes, User $actor): Visit
    {
        Gate::forUser($actor)->authorize('cancel', $visit);
        $branch = $this->assertActiveBranch($actor, $visit, $attributes);
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ])->validate();
        $reason = trim($validated['cancellation_reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['cancellation_reason' => 'A cancellation reason is required.']);
        }

        return DB::transaction(function () use ($visit, $validated, $reason, $actor, $branch): Visit {
            $this->lockActor($actor, $branch, 'visits.cancel.branch');
            Patient::query()->whereKey($visit->patient_id)->lockForUpdate()->firstOrFail();
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->assertMutable($locked, (int) $validated['lock_version']);
            $locked->forceFill([
                'status' => Visit::STATUS_CANCELLED,
                'cancelled_at' => now()->utc(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by_user_id' => $actor->id,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->audit->record('visit.cancelled', $locked, [
                'record_version' => $locked->lock_version,
            ], $actor, $branch);

            return $locked->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validateUpdate(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'visit_type' => ['required', Rule::in(['consultation', 'otc'])],
            'assigned_doctor_user_id' => ['nullable', 'integer', 'required_if:visit_type,consultation'],
            'visit_reason' => ['nullable', 'string', 'max:500', 'required_if:visit_type,consultation'],
            'priority' => ['required', Rule::in(['normal', 'urgent'])],
            'coverage_type' => ['required', Rule::in(['self_pay', 'panel'])],
            'panel_id' => ['nullable', 'integer', 'required_if:coverage_type,panel'],
            'coverage_member_reference' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $validated['visit_reason'] = $this->nullableTrim($validated['visit_reason'] ?? null);
        $validated['coverage_member_reference'] = $this->nullableTrim($validated['coverage_member_reference'] ?? null);
        $validated['assigned_doctor_user_id'] = isset($validated['assigned_doctor_user_id'])
            ? (int) $validated['assigned_doctor_user_id'] : null;
        $validated['panel_id'] = isset($validated['panel_id']) ? (int) $validated['panel_id'] : null;
        if ($validated['visit_type'] === 'consultation' && $validated['visit_reason'] === null) {
            throw ValidationException::withMessages(['visit_reason' => 'A Visit reason is required for Consultation.']);
        }
        if ($validated['coverage_type'] === 'self_pay') {
            $validated['panel_id'] = null;
            $validated['coverage_member_reference'] = null;
        }

        return $validated;
    }

    /** @param array<string, mixed> $attributes */
    private function assertActiveBranch(User $actor, Visit $visit, array $attributes): Branch
    {
        $active = $this->branches->activeBranch($actor);
        if (! $active || $active->id !== $visit->branch_id || (int) ($attributes['expected_branch_id'] ?? 0) !== $active->id) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed or does not own this Visit. Review and retry.',
            ]);
        }

        return $active;
    }

    private function lockActor(User $actor, Branch $branch, string $permission): User
    {
        $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
        if ($profile) {
            StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->lockForUpdate()
                ->get();
            $locked->unsetRelation('staffProfile');
        }
        if (! $locked->is_active || ! $locked->can($permission) || ! $this->branches->canSelect($locked, $branch)) {
            throw new AuthorizationException('You may not change this Visit.');
        }

        return $locked;
    }

    private function assertMutable(Visit $visit, int $expectedVersion): void
    {
        if ($visit->status !== Visit::STATUS_REGISTERED) {
            throw ValidationException::withMessages(['visit' => 'A cancelled Visit is immutable.']);
        }
        if ($visit->lock_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'lock_version' => 'This Visit changed after it was opened. Reload and review the latest details.',
            ]);
        }
    }

    /** @param array<string, mixed> $validated */
    private function lockPanel(User $actor, array $validated): ?Panel
    {
        if ($validated['coverage_type'] !== 'panel') {
            return null;
        }

        $panel = Panel::query()->whereKey($validated['panel_id'])
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)->lockForUpdate()->first();
        if (! $panel) {
            throw ValidationException::withMessages(['panel_id' => 'Select an active Panel for this organisation.']);
        }

        return $panel;
    }

    /** @param array<string, mixed> $before */
    private function recordChanges(Visit $visit, array $before, User $actor): void
    {
        $changed = array_keys(array_filter($visit->only(array_keys($before)), fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $this->audit->record('visit.details.updated', $visit, [
                'changed_fields' => array_values(array_diff($changed, ['visit_reason', 'coverage_member_reference'])),
                'sensitive_fields_changed' => array_values(array_intersect($changed, ['visit_reason', 'coverage_member_reference'])),
                'record_version' => $visit->lock_version,
            ], $actor, $visit->branch);
        }
        if ($before['assigned_doctor_user_id'] !== $visit->assigned_doctor_user_id) {
            $this->audit->record('visit.doctor.changed', $visit, ['record_version' => $visit->lock_version], $actor, $visit->branch);
        }
        if ($before['priority'] !== $visit->priority) {
            $event = $visit->priority === 'urgent' ? 'visit.priority.marked_urgent' : 'visit.priority.returned_normal';
            $this->audit->record($event, $visit, ['record_version' => $visit->lock_version], $actor, $visit->branch);
        }
        if ($before['coverage_type'] !== $visit->coverage_type || $before['panel_id'] !== $visit->panel_id) {
            $this->audit->record('visit.coverage.changed', $visit, [
                'coverage_type' => $visit->coverage_type,
                'record_version' => $visit->lock_version,
            ], $actor, $visit->branch);
        }
    }

    /** @return array{0: mixed, 1: mixed, 2: string} */
    private function branchDay(Branch $branch): array
    {
        $local = now()->setTimezone($branch->timezone);

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc(), $local->toDateString()];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
