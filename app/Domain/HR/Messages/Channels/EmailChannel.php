<?php

namespace App\Domain\HR\Messages\Channels;

use App\Mail\StaffMessageMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Email through Laravel's own mail settings, to the address on the person's KPOne account (the work address used to
 * sign in), never a personal address from the HR file. Connected only when HR_MESSAGES_EMAIL_ENABLED is true.
 */
final class EmailChannel implements StaffChannel
{
    public function connected(): bool
    {
        return (bool) config('hr-messages.email.connected', false);
    }

    public function send(User $user, string $subject, string $body): ?string
    {
        if (filter_var($user->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('no_address');
        }
        $sent = Mail::to($user->email)->send(new StaffMessageMail($subject, $body));
        $id = $sent?->getMessageId();

        return is_string($id) && $id !== '' ? mb_substr($id, 0, 200) : null;
    }
}
