<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DS-2: the "Try the new layout" switch (Version 2). A rollout choice, not an access right: it only shows or hides the
 * Spaces menu link and the Version 2 look and never grants anything. Owner decision 2026-11-12: offered to every staff
 * account, each person choosing for themselves (default off, so everyone starts in Classic). The waiting-room TV
 * account is a display, not a person at a desk, so it is never offered the switch.
 */
final class SpacePreferenceService
{
    public const NOT_OFFERED_TO_ROLES = [PermissionCatalogue::QUEUE_DISPLAY_ROLE];

    public function __construct(private AuditRecorder $audit) {}

    public function offered(User $user): bool
    {
        return ! $user->hasAnyRole(self::NOT_OFFERED_TO_ROLES);
    }

    /** @return array{offered: bool, enabled: bool, lockVersion: int} */
    public function state(User $user): array
    {
        if (! $this->offered($user)) {
            return ['offered' => false, 'enabled' => false, 'lockVersion' => 0];
        }
        $row = DB::table('user_space_preferences')->where('user_id', $user->id)->first();

        return ['offered' => true, 'enabled' => (bool) ($row->enabled ?? false), 'lockVersion' => (int) ($row->lock_version ?? 0)];
    }

    /** Switching back is always possible: turning off never needs the role check to pass again beyond being the owner. */
    public function set(User $actor, bool $enabled, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $enabled, $expectedVersion): void {
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('user_space_preferences')->where('user_id', $user->id)->lockForUpdate()->first();
            if ($enabled && ! $this->offered($user)) {
                abort(403);
            }
            if ((int) ($row->lock_version ?? 0) !== $expectedVersion) {
                throw ValidationException::withMessages(['enabled' => 'This setting changed in another window. Reload and try again.']);
            }
            if ($row === null) {
                try {
                    DB::table('user_space_preferences')->insert(['user_id' => $user->id, 'enabled' => $enabled, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                } catch (QueryException) {
                    throw ValidationException::withMessages(['enabled' => 'This setting changed in another window. Reload and try again.']);
                }
                $version = 1;
            } else {
                $version = $row->lock_version + 1;
                DB::table('user_space_preferences')->where('user_id', $user->id)->update(['enabled' => $enabled, 'lock_version' => $version, 'updated_at' => now()]);
            }
            $this->audit->record('space_preference.changed', $user, ['enabled' => $enabled, 'record_version' => $version], $user, null, $user->organisation_id);
        }, 3);
    }
}
