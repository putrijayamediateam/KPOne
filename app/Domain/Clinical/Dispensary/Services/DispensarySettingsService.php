<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\BranchDispensarySetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * DSP-23 (owner answer 12 = A): the per-branch "doctor checks prepared medicine" switch. The Director and that branch's
 * CA Supervisor turn it on or off; each change is versioned and recorded. A missing row counts as off.
 */
final class DispensarySettingsService
{
    public const PERMISSION = 'dispensary.settings.manage.branch';

    public function __construct(private BranchAccessService $branches, private AuditRecorder $audit) {}

    public function doctorCheckRequired(Branch $branch): bool
    {
        return (bool) BranchDispensarySetting::query()->where('branch_id', $branch->id)->value('doctor_check_required');
    }

    /**
     * Read the switch under a row lock, inside the caller's transaction (Hakim: a stale page can't skip the check).
     * A shared lock (Hakim): the switch cannot change while a Complete reads it, yet Completes at the branch still run
     * side by side; turning the switch takes FOR UPDATE and waits for them. A missing row is off and writes nothing
     * (the migration gives every existing branch an "off" row, so only a branch created later starts without one).
     */
    public function lockedDoctorCheckRequired(Branch $branch): bool
    {
        return (bool) BranchDispensarySetting::query()->where('branch_id', $branch->id)->sharedLock()->value('doctor_check_required');
    }

    /** @return array{doctorCheckRequired: bool, lockVersion: int} */
    public function current(Branch $branch): array
    {
        $row = BranchDispensarySetting::query()->where('branch_id', $branch->id)->first();

        return ['doctorCheckRequired' => (bool) $row?->doctor_check_required, 'lockVersion' => $row ? $row->lock_version : 1];
    }

    /** @param array<string, mixed> $input */
    public function setDoctorCheck(User $actor, array $input): BranchDispensarySetting
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'],
            'doctor_check_required' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'retry_key' => ['required', 'uuid'],
        ])->validate();

        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        if ((int) $data['expected_branch_id'] !== $branch->id) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed. Review and retry.']);
        }
        $on = (bool) $data['doctor_check_required'];

        return DB::transaction(function () use ($actor, $branch, $data, $on): BranchDispensarySetting {
            $locked = User::query()->whereKey($actor->id)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->firstOrFail();
            $locked->load(['roles.permissions', 'permissions']);
            if (! $locked->is_active || ! $locked->can(self::PERMISSION) || (! $locked->hasRole('director') && ! $this->branches->hasEffectiveAssignment($locked, $branch))) {
                throw new AuthorizationException('You may not change this branch\'s Dispensary settings.');
            }

            DB::table('branch_dispensary_settings')->insertOrIgnore([
                'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                'doctor_check_required' => false, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = BranchDispensarySetting::query()->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            if ($row->last_retry_key === $data['retry_key'] && $row->updated_by_user_id === $locked->id && $row->doctor_check_required === $on) {
                return $row;
            }
            if ($row->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['doctor_check_required' => 'These settings changed. Reload and review the latest state.']);
            }
            if ($row->doctor_check_required === $on) {
                return $row;
            }

            $row->forceFill([
                'doctor_check_required' => $on, 'lock_version' => $row->lock_version + 1,
                'updated_by_user_id' => $locked->id, 'last_retry_key' => $data['retry_key'],
            ])->save();
            if ($this->audit->record('dispensary.settings_changed', $row, ['doctor_check_required' => $on, 'record_version' => $row->lock_version], $locked, $branch) === null) {
                abort(503);
            }

            return $row;
        }, 3);
    }
}
