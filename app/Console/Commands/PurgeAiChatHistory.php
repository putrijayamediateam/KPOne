<?php

namespace App\Console\Commands;

use App\Domain\Agents\Chat\AiChatMessage;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** CHAT-1: empties the encrypted text and figures of chatbox messages older than the retention period (owner, 2026-11-24: 90 days). Rows and counts stay. */
class PurgeAiChatHistory extends Command
{
    protected $signature = 'ai-chat:purge';

    protected $description = 'Empty the text and figures of AI chatbox messages past the retention period.';

    public function handle(AuditRecorder $audit): int
    {
        $cutoff = now()->subDays((int) config('ai_chat.history_days', 90));
        $purged = 0;
        AiChatMessage::query()->whereNull('purged_at')->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(200, function ($messages) use (&$purged): void {
            DB::transaction(function () use ($messages, &$purged): void {
                foreach ($messages as $message) {
                    // The database allows exactly this update: text and figures to NULL and the purge time, once.
                    DB::table('ai_chat_messages')->where('id', $message->id)->whereNull('purged_at')->update(['body' => null, 'figures' => null, 'purged_at' => now()]);
                    $purged++;
                }
            });
        });
        if ($purged > 0) {
            $audit->record('ai_chat.history_purged', null, ['messages' => $purged, 'days' => (int) config('ai_chat.history_days', 90)], null, null, User::query()->orderBy('id')->value('organisation_id'));
        }
        $this->components->info('Emptied '.$purged.' chat messages.');

        return self::SUCCESS;
    }
}
