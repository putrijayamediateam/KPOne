<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;

/**
 * The Sales summary and Payments exports use organisation-level permissions and take a branch code or "all". An account that
 * cannot switch across the organisation (the Branch Manager) may export only the active branch, so the scope is forced.
 */
final class BranchOnlyExportScope
{
    public static function enforce(User $actor, Branch $active, string $scope): void
    {
        abort_if(! $actor->can('branch_context.switch.organisation') && $scope !== $active->code, 403);
    }
}
