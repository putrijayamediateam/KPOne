<?php

namespace App\Domain\Visit\Assignment;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Assignment\Models\BranchAutoAssignSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * AUTO-1: the Director's per-branch switch for automatic doctor assignment (off by default). Permission and branch are
 * checked again under lock; the row carries a lock version. Switching it off changes nothing already assigned.
 */
final class AutoAssignSettingService
{
    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private AuditRecorder $audit) {}

    /** @return array{canManage: bool, enabled: bool, lockVersion: int, branchName: string|null}|null */
    public function panel(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $actor->is_active || ! $actor->can(AutoAssignPermissions::MANAGE_BRANCH)) {
            return null;
        }
        $row = BranchAutoAssignSetting::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->first();

        return ['canManage' => true, 'enabled' => $row?->enabled ?? false, 'lockVersion' => $row?->lock_version ?? 0, 'branchName' => $branch->name];
    }

    /** @param array<string, mixed> $input expected_branch_id, enabled, lock_version */
    public function save(User $actor, array $input): void
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'],
        ])->validate();
        DB::transaction(function () use ($actor, $data): void {
            $branch = $this->authority->branch($actor, $data['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, AutoAssignPermissions::MANAGE_BRANCH);
            $row = BranchAutoAssignSetting::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            if (($row?->lock_version ?? 0) !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This setting changed after the page was opened. Reload and review it before saving.']);
            }
            $enabled = (bool) $data['enabled'];
            if ($row === null) {
                $row = new BranchAutoAssignSetting;
                $row->forceFill(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'enabled' => $enabled, 'lock_version' => 1, 'updated_by_user_id' => $actor->id])->save();
            } else {
                $row->forceFill(['enabled' => $enabled, 'lock_version' => $row->lock_version + 1, 'updated_by_user_id' => $actor->id])->save();
            }
            $this->audit->record('registration.auto_assign.changed', $row, ['enabled' => $enabled, 'record_version' => $row->lock_version], $actor, $branch);
        }, 3);
    }
}
