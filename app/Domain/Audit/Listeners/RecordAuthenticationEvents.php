<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class RecordAuthenticationEvents
{
    public function __construct(private AuditRecorder $audit) {}

    public function login(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->record('auth.login.succeeded', $event->user, ['guard' => $event->guard], $event->user);
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', $event->user, ['guard' => $event->guard], $event->user);
        }
    }

    public function failed(Failed $event): void
    {
        $this->audit->record('auth.login.failed', null, ['guard' => $event->guard]);
    }
}
