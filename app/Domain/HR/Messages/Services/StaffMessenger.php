<?php

namespace App\Domain\HR\Messages\Services;

use App\Domain\HR\Messages\MessageTemplates;
use App\Jobs\SendStaffMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HR-14: what HR services call when something a staff member should hear about has happened. Nothing is sent inside
 * the HR transaction: the message is queued to go after the commit, and a rolled-back change sends nothing. A bad
 * template call or a failed queue write is logged and never breaks the HR change. A queue worker must run
 * (`php artisan queue:work`) for anything to be sent.
 */
final class StaffMessenger
{
    /**
     * @param  array<string, string>  $params
     * @param  array<string, int>  $subjectIds
     */
    public function notify(int $organisationId, int $userId, string $template, array $params, array $subjectIds): void
    {
        try {
            MessageTemplates::render($template, $params);
        } catch (\Throwable $e) {
            Log::warning('HR staff message not queued: bad template call', ['template' => $template, 'user_id' => $userId, 'error' => $e::class]);

            return;
        }
        // Queuing runs after the commit; if the queue write fails, the HR change has already saved, so log it and
        // never show the person an error for an action that succeeded.
        DB::afterCommit(function () use ($organisationId, $userId, $template, $params, $subjectIds): void {
            try {
                SendStaffMessage::dispatch($organisationId, $userId, $template, $params, $subjectIds);
            } catch (\Throwable $e) {
                Log::warning('HR staff message not queued', ['template' => $template, 'user_id' => $userId, 'error' => $e::class]);
            }
        });
    }
}
