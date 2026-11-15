<?php

namespace App\Domain\Organisation\Policies;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function __construct(private BranchAccessService $access) {}

    public function view(User $user, Branch $branch): bool
    {
        return $this->access->canView($user, $branch);
    }
}
