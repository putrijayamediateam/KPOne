<?php

namespace App\Domain\Organisation\BusinessDetails;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;

final class BusinessDetailsAccess
{
    public const VIEW = 'catalogue.settings.manage.organisation';

    public const MANAGE = 'business_details.manage.organisation';

    public function __construct(private TransactionalActorAuthority $authority) {}

    /** @return array{User, Organisation, Branch} */
    public function lock(User $actor, mixed $expectedBranch, bool $editing = false): array
    {
        $branch = $this->authority->branch($actor, $expectedBranch);
        $organisation = Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $actor = $this->authority->lock($actor, $branch, self::VIEW);
        // The lock helper reloads roles.permissions and direct permissions from the locked rows.
        // Spatie's cached permission-to-role lookup can outlive a grant revocation.
        $grants = $actor->getAllPermissions()->pluck('name');
        abort_unless($grants->contains(self::VIEW) && (! $editing || $grants->contains(self::MANAGE)), 403);
        $branch = Branch::query()->whereKey($branch->id)->where('organisation_id', $organisation->id)->where('is_active', true)->lockForUpdate()->firstOrFail();

        return [$actor, $organisation, $branch];
    }
}
