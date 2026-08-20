<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Access\StaffAccessService;
use App\Models\User;

class StaffPolicy
{
    public function __construct(private StaffAccessService $access) {}

    public function view(User $viewer, User $subject): bool
    {
        return $this->access->canView($viewer, $subject);
    }
}
