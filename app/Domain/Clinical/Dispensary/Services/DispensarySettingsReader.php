<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\BranchDispensarySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final class DispensarySettingsReader
{
    public function __construct(private BranchAccessService $branches, private StaffAccessService $staff) {}

    /**
     * One settings-row snapshot keeps the displayed switch and its attribution together.
     *
     * @return array{branch: array{id: int, name: string}, settings: array{doctorCheckRequired: bool, lockVersion: int}, settingsChange: array{changedAt: string, changedBy: string}|null}
     */
    public function snapshot(User $actor, Branch $expectedBranch): array
    {
        $fresh = User::query()->whereKey($actor->id)->first();
        if (! $fresh || ! $fresh->is_active) {
            throw new AuthorizationException('You may not view this branch\'s Dispensary settings.');
        }
        $fresh->load(['roles.permissions', 'permissions']);
        $branch = Branch::query()->whereKey($expectedBranch->id)->where('organisation_id', $fresh->organisation_id)->where('is_active', true)->first();
        if (! $branch || ! $fresh->can(DispensarySettingsService::PERMISSION)
            || $this->branches->activeBranch($fresh)?->id !== $branch->id
            || (! $fresh->hasRole('director') && ! $this->branches->hasEffectiveAssignment($fresh, $branch))) {
            throw new AuthorizationException('You may not view this branch\'s Dispensary settings.');
        }

        $row = BranchDispensarySetting::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->first();
        $change = null;
        // Migration/default rows have timestamps but no attributed successful change.
        if ($row && $row->updated_by_user_id !== null && $row->updated_at !== null && $row->last_retry_key !== null && $row->lock_version > 1) {
            $name = $this->staff->visibleUsers($fresh)->whereKey($row->updated_by_user_id)->value('name');
            $change = [
                'changedAt' => CarbonImmutable::instance($row->updated_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'changedBy' => is_string($name) && $name !== '' ? $name : 'Staff member',
            ];
        }

        return [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'settings' => ['doctorCheckRequired' => (bool) $row?->doctor_check_required, 'lockVersion' => $row ? $row->lock_version : 1],
            'settingsChange' => $change,
        ];
    }
}
