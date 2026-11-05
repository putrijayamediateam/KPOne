<?php

namespace App\Domain\HR\Roster\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Roster\Models\MinimumStaffing;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-1 minimum staffing per branch, session and role (Resident Doctor, CA, CA Supervisor). A warning on the planner
 * only; it never blocks sending or approving a week. Versioned with lock_version and audited by changed keys only.
 */
final class MinimumStaffingService
{
    public const MAX = 20;

    public function __construct(private RosterAuthority $authority, private AuditRecorder $audit) {}

    /** @return array<string, array<string, int>> session => role => minimum (zeros filled in) */
    public function minimums(Branch $branch): array
    {
        $stored = MinimumStaffing::query()->where('branch_id', $branch->id)->first()?->minimums ?? [];

        return $this->normalise($stored);
    }

    public function lockVersion(Branch $branch): int
    {
        return (int) (MinimumStaffing::query()->where('branch_id', $branch->id)->value('lock_version') ?? 0);
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input): MinimumStaffing
    {
        $values = $this->parse($input['minimums'] ?? null);

        return DB::transaction(function () use ($actor, $input, $values): MinimumStaffing {
            $actor = $this->authority->lock($actor, RosterPermissions::MANAGE);
            $branch = $this->authority->branch($actor, $input['branch_id'] ?? null);
            DB::table('hr_minimum_staffing')->insertOrIgnore([['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                'minimums' => json_encode($this->normalise([]), JSON_THROW_ON_ERROR), 'lock_version' => 0, 'updated_by_user_id' => $actor->id,
                'created_at' => now(), 'updated_at' => now()]]);
            $row = MinimumStaffing::query()->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            if (filter_var($input['expected_lock_version'] ?? null, FILTER_VALIDATE_INT) !== $row->lock_version) {
                throw ValidationException::withMessages(['minimums' => 'Minimum staffing was changed by someone else. Reload to see the latest values.']);
            }
            $before = $this->normalise($row->minimums);
            $changed = [];
            foreach ($values as $session => $roles) {
                foreach ($roles as $role => $count) {
                    if ($before[$session][$role] !== $count) {
                        $changed[] = "{$session}.{$role}";
                    }
                }
            }
            $row->forceFill(['minimums' => $values, 'lock_version' => $row->lock_version + 1, 'updated_by_user_id' => $actor->id])->save();
            $this->audit->record('hr.roster.minimum_staffing_updated', $row, ['branch_id' => $branch->id, 'changed_fields' => $changed], $actor, $branch);

            return $row;
        }, 3);
    }

    /**
     * @param  array<mixed>  $stored
     * @return array<string, array<string, int>>
     */
    private function normalise(array $stored): array
    {
        $out = [];
        foreach (array_keys(RosterRules::SESSIONS) as $session) {
            foreach (array_keys(RosterRules::STAFFING_ROLES) as $role) {
                $value = is_array($stored[$session] ?? null) ? ($stored[$session][$role] ?? 0) : 0;
                $out[$session][$role] = is_int($value) ? $value : 0;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, int>> */
    private function parse(mixed $input): array
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages(['minimums' => 'Enter a minimum from 0 to 20 for each box.']);
        }
        $out = [];
        $errors = [];
        foreach (array_keys(RosterRules::SESSIONS) as $session) {
            foreach (array_keys(RosterRules::STAFFING_ROLES) as $role) {
                $raw = is_array($input[$session] ?? null) ? ($input[$session][$role] ?? 0) : 0;
                $value = $raw === '' || $raw === null ? 0 : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX]]);
                if ($value === false) {
                    $errors["minimums.{$session}.{$role}"] = 'Enter a number from 0 to 20.';
                    $value = 0;
                }
                $out[$session][$role] = (int) $value;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
