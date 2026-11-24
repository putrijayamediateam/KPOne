<?php

namespace App\Console\Commands;

use App\Domain\Agents\Ask\AiAskRequest;
use App\Domain\Agents\Ask\AskRequestService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** CHAT-2: expires pending "Ask through Claude" requests past their time, and empties the question and answer text after 90 days. Rows stay. */
class PurgeAiAskRequests extends Command
{
    protected $signature = 'ai-ask:purge';

    protected $description = 'Expire old pending Ask through Claude requests and empty the text of those past the retention period.';

    public function handle(AskRequestService $asks, AuditRecorder $audit): int
    {
        $expired = $asks->expireAllDue();
        $cutoff = now()->subDays((int) config('ai_chat.history_days', 90));
        $purged = 0;
        AiAskRequest::query()->whereNull('purged_at')->where('created_at', '<', $cutoff)->where('status', '!=', 'pending')->orderBy('id')->chunkById(200, function ($rows) use (&$purged): void {
            DB::transaction(function () use ($rows, &$purged): void {
                foreach ($rows as $row) {
                    // The database allows exactly this update: the text to NULL and the purge time, once.
                    $purged += DB::table('ai_ask_requests')->where('id', $row->id)->whereNull('purged_at')->update(['question' => null, 'answer' => null, 'purged_at' => now()]);
                }
            });
        });
        if ($purged > 0) {
            $audit->record('ai_ask.purged', null, ['requests' => $purged, 'days' => (int) config('ai_chat.history_days', 90)], null, null, User::query()->orderBy('id')->value('organisation_id'));
        }
        $this->components->info('Expired '.$expired.' and emptied '.$purged.' ask requests.');

        return self::SUCCESS;
    }
}
