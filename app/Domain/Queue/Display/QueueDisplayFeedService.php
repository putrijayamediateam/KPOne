<?php

namespace App\Domain\Queue\Display;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchDisplaySetting;
use App\Domain\Queue\Models\QueueCall;

/**
 * What a waiting-room TV may show: called queue numbers with their room and time, plus the branch's
 * own posters, video and scrolling text. It never includes patient names, identifiers or clinical data.
 */
class QueueDisplayFeedService
{
    public const RECENT_CALLS = 8;

    /** @return array<string, mixed> */
    public function feed(Branch $branch): array
    {
        $today = now()->setTimezone($branch->timezone)->toDateString();

        return [
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'timezone' => $branch->timezone],
            'serverTime' => now()->utc()->toIso8601String(),
            'calls' => QueueCall::query()
                ->where('organisation_id', $branch->organisation_id)
                ->where('branch_id', $branch->id)
                ->whereDate('operational_date', $today)
                ->orderByDesc('called_at')
                ->orderByDesc('id')
                ->limit(self::RECENT_CALLS * 4)
                ->get(['id', 'queue_entry_id', 'queue_number', 'room_name', 'called_at', 'is_recall'])
                // A recall is announced again but shown once, at its newest call.
                ->unique('queue_entry_id')
                ->take(self::RECENT_CALLS)
                ->map(fn (QueueCall $call): array => [
                    'id' => $call->id,
                    'number' => sprintf('%03d', $call->queue_number),
                    'room' => $call->room_name,
                    'calledAt' => $call->called_at->toIso8601String(),
                    'isRecall' => $call->is_recall,
                ])
                ->values()
                ->all(),
            'settings' => $this->settings($branch),
        ];
    }

    /** @return array{tickerText: string|null, youtubeVideoId: string|null, posterSeconds: int, posters: list<array{id: string, url: string}>, lockVersion: int} */
    public function settings(Branch $branch): array
    {
        $settings = BranchDisplaySetting::query()->where('branch_id', $branch->id)->first();

        return [
            'tickerText' => $settings?->ticker_text,
            'youtubeVideoId' => $settings?->youtube_video_id,
            'posterSeconds' => $settings?->poster_seconds ?? 10,
            'posters' => collect($settings?->posters ?? [])
                ->map(fn (array $poster): array => [
                    'id' => $poster['id'],
                    'url' => route('queue-display.posters.show', [$branch->id, $poster['id']], absolute: false),
                ])
                ->values()
                ->all(),
            'lockVersion' => $settings?->lock_version ?? 0,
        ];
    }
}
