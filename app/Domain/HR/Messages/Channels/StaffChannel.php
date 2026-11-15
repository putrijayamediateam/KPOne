<?php

namespace App\Domain\HR\Messages\Channels;

use App\Models\User;

/** One way of reaching a staff member. A channel that is not connected never sends. */
interface StaffChannel
{
    public function connected(): bool;

    /**
     * Sends one rendered message to the person and returns the provider's message id, if it gives one. Throws on
     * failure; the caller records the failure and never lets it touch HR data.
     */
    public function send(User $user, string $subject, string $body): ?string;
}
