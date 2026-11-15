<?php

namespace App\Domain\HR\Messages\Services;

use App\Domain\HR\Messages\MessageTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HR-14: what HR services call when something a staff member should hear about has happened. Nothing is sent inside
 * the HR transaction: the message goes right after the commit (no queue, no worker), and a rolled-back change sends
 * nothing. A bad template call or a failed send is logged or recorded and never breaks the HR change.
 */
final class StaffMessenger
{
    public function __construct(private MessageDelivery $delivery) {}

    /**
     * @param  array<string, string>  $params
     * @param  array<string, int>  $subjectIds
     */
    public function notify(int $organisationId, int $userId, string $template, array $params, array $subjectIds): void
    {
        try {
            MessageTemplates::render($template, $params);
        } catch (\Throwable $e) {
            Log::warning('HR staff message not sent: bad template call', ['template' => $template, 'user_id' => $userId, 'error' => $e::class]);

            return;
        }
        // Sent right after the commit, with no queue or worker (owner, 2026-11-10: "Send directly, no worker"). A mail
        // failure is recorded on the delivery row; nothing here can turn a saved HR change into an error.
        DB::afterCommit(function () use ($organisationId, $userId, $template, $params, $subjectIds): void {
            try {
                $this->delivery->deliver($organisationId, $userId, $template, $params, $subjectIds);
            } catch (\Throwable $e) {
                Log::warning('HR staff message not sent', ['template' => $template, 'user_id' => $userId, 'error' => $e::class]);
            }
        });
    }
}
