<?php

namespace App\Domain\Access;

use App\Models\User;

final class BillingWorkAccess
{
    public function panel(User $actor): bool
    {
        return $this->summary($actor) && $actor->can('panel.work.view.branch');
    }

    public function finance(User $actor): bool
    {
        return $this->summary($actor) && $actor->can('finance.work.view.branch');
    }

    private function summary(User $actor): bool
    {
        return $actor->can('billing.summary.branch') || $actor->can('billing.view.branch');
    }
}
