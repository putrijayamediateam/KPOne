<?php

namespace App\Domain\Organisation\Website;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;

final class WebsiteAccess
{
    public function __construct(private TransactionalActorAuthority $authority) {}

    /**
     * Call inside a transaction.
     *
     * @return array{User, Branch}
     */
    public function lock(User $actor, mixed $expected, string $permission): array
    {
        $branch = $this->authority->branch($actor, $expected);
        Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $actor = $this->authority->lock($actor, $branch, $permission);
        abort_unless($actor->getAllPermissions()->pluck('name')->contains($permission), 403);
        $branch = Branch::query()->whereKey($branch->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();

        return [$actor, $branch];
    }
}
