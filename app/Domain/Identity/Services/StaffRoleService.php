<?php

namespace App\Domain\Identity\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Access\StaffKind;
use App\Domain\Audit\AccessChangeActorContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffRoleService
{
    public const LOCUM_ONLY = 'A locum sign-in holds the Locum role only, or the Locum and Resident Doctor roles for a locum doctor.';

    public function __construct(
        private StaffAuthorityService $authority,
        private AccessChangeActorContext $actors,
    ) {}

    /** @param list<string> $roles */
    public function sync(User $subject, array $roles, User $actor): User
    {
        $roles = array_values(array_unique($roles));

        if ($roles === []) {
            throw ValidationException::withMessages([
                'roles' => 'At least one KPOne role is required.',
            ]);
        }

        if (array_diff($roles, array_keys(PermissionCatalogue::roles())) !== []) {
            throw ValidationException::withMessages([
                'roles' => 'One or more selected roles are not in the KPOne role catalogue.',
            ]);
        }

        // HR-15a (B.E.R.N.I.S.S for the owner, 2026-11-10): a locum sign-in holds the Locum role only, so a locum account
        // can never gain clinical or other access while it is shown as a locum. HR-22b (the owner's role decision under
        // AGENTS.md, 24 Nov 2026): the one exception is a locum doctor, who holds exactly Locum and Resident Doctor.
        if (in_array(PermissionCatalogue::LOCUM_ROLE, $roles, true) && ! in_array(self::sorted($roles), array_map(self::sorted(...), StaffKind::LOCUM_ROLE_SETS), true)) {
            throw ValidationException::withMessages(['roles' => self::LOCUM_ONLY]);
        }

        return DB::transaction(function () use ($subject, $roles, $actor): User {
            $lockedSubject = User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();

            // A linked locum account keeps exactly the roles HR asked for on its sign-in request.
            $linked = DB::table('hr_locum_sign_ins')->where('linked_user_id', $lockedSubject->id)->where('status', 'done')->value('role_requested');
            if ($linked !== null && self::sorted($roles) !== self::sorted(StaffKind::LOCUM_ROLE_SETS[(string) $linked] ?? StaffKind::LOCUM_ROLE_SETS['locum'])) {
                throw ValidationException::withMessages(['roles' => self::LOCUM_ONLY]);
            }

            if (! $actor->can('access.manage.organisation')
                || $actor->is($lockedSubject)
                || ! $this->authority->canManage($actor, $lockedSubject)
                || ! $this->authority->canAssignRoles($actor, $roles)) {
                throw new AuthorizationException('You may not change this staff member\'s roles.');
            }

            $this->actors->run($actor, fn () => $lockedSubject->syncRoles($roles));

            return $lockedSubject->refresh()->load('roles');
        });
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private static function sorted(array $roles): array
    {
        sort($roles);

        return $roles;
    }
}
