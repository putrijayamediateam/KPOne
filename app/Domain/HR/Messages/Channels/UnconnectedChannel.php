<?php

namespace App\Domain\HR\Messages\Channels;

use App\Models\User;
use RuntimeException;

/**
 * SMS and WhatsApp in this version: no provider has been chosen yet (owner, 2026-11-10), so the channel is shown as
 * "Not connected", nobody can choose it and nothing is ever sent through it.
 */
final class UnconnectedChannel implements StaffChannel
{
    public function connected(): bool
    {
        return false;
    }

    public function send(User $user, string $subject, string $body): ?string
    {
        throw new RuntimeException('not_connected');
    }
}
