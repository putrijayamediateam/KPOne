<?php

namespace App\Domain\Agents\Proposals;

use App\Models\User;

/**
 * Optional, for a `PreparesWork` class: a plain-words warning shown before the approver chooses "Approve and create ..."
 * (for example "An open purchase request or purchase order already includes this item for this location."). A warning
 * never blocks; the domain's own refusals still come from `prepare()`. Read only.
 */
interface WarnsBeforePreparing
{
    public function warning(User $approver, AiProposal $proposal): ?string;
}
