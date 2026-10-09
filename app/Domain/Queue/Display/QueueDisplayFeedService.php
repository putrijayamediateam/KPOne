<?php

namespace App\Domain\Queue\Display;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchDisplaySetting;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use Illuminate\Support\Collection;

/**
 * What a waiting-room TV may show: called queue numbers with their room and time, plus the branch's
 * own posters, video and scrolling text. It never includes identifiers or clinical data. A patient's full
 * registered name is included only when the branch has chosen to call by name (owner decision 2026-10-09);
 * the call record itself stays free of patient data and the name is read at display time.
 */
class QueueDisplayFeedService
{
    public const RECENT_CALLS = 8;

    /** @return array<string, mixed> */
    public function feed(Branch $branch): array
    {
        $today = now()->setTimezone($branch->timezone)->toDateString();
        $settings = $this->settings($branch);
        $byName = $settings['callDisplayMode'] === BranchDisplaySetting::MODE_NAME;

        $calls = QueueCall::query()
            ->where('organisation_id', $branch->organisation_id)
            ->where('branch_id', $branch->id)
            ->whereDate('operational_date', $today)
            ->orderByDesc('called_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_CALLS * 4)
            ->get(['id', 'queue_entry_id', 'otc_queue_entry_id', 'queue_series', 'service', 'queue_number', 'room_name', 'called_at', 'is_recall'])
            // A recall is announced again but shown once, at its newest call. A call to a different service
            // or room stays in the list, so the TV keeps the patient's earlier call as history.
            ->unique(fn (QueueCall $call): string => ($call->queue_entry_id ?? 'o'.$call->otc_queue_entry_id).'|'.$call->service.'|'.$call->room_name)
            ->take(self::RECENT_CALLS)
            ->values();
        $names = $byName ? $this->names($calls) : [];

        return [
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'timezone' => $branch->timezone],
            'serverTime' => now()->utc()->toIso8601String(),
            'calls' => $calls->map(function (QueueCall $call) use ($names): array {
                $row = [
                    'id' => $call->id,
                    'number' => QueueNumberFormat::format($call->queue_number, $call->queue_series),
                    'room' => $call->room_name,
                    'service' => $call->service,
                    'calledAt' => $call->called_at->toIso8601String(),
                    'isRecall' => $call->is_recall,
                ];
                if ($names !== []) {
                    $row['name'] = $names[$call->id] ?? null;
                }

                return $row;
            })->all(),
            'settings' => $settings,
        ];
    }

    /** @return array{tickerText: string|null, youtubeVideoId: string|null, posterSeconds: int, callDisplayMode: string, posters: list<array{id: string, url: string}>, lockVersion: int} */
    public function settings(Branch $branch): array
    {
        $settings = BranchDisplaySetting::query()->where('branch_id', $branch->id)->first();

        return [
            'tickerText' => $settings?->ticker_text,
            'youtubeVideoId' => $settings?->youtube_video_id,
            'posterSeconds' => $settings->poster_seconds ?? 10,
            'callDisplayMode' => $settings->call_display_mode ?? BranchDisplaySetting::MODE_NUMBER,
            'posters' => array_values(collect($settings->posters ?? [])
                ->map(fn (array $poster): array => [
                    'id' => $poster['id'],
                    'url' => route('queue-display.posters.show', [$branch->id, $poster['id']], absolute: false),
                ])
                ->all()),
            'lockVersion' => $settings->lock_version ?? 0,
        ];
    }

    /**
     * Full registered names for the listed calls, keyed by call id.
     *
     * @param  Collection<int, QueueCall>  $calls
     * @return array<int, string>
     */
    private function names(Collection $calls): array
    {
        $consultation = QueueEntry::query()
            ->with('visit.patient:id,full_name')
            ->whereIn('id', $calls->pluck('queue_entry_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $otc = OtcQueueEntry::query()
            ->with('visit.patient:id,full_name')
            ->whereIn('id', $calls->pluck('otc_queue_entry_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $names = [];
        foreach ($calls as $call) {
            $entry = $call->queue_entry_id !== null
                ? $consultation->get($call->queue_entry_id)
                : $otc->get((int) $call->otc_queue_entry_id);
            $name = $entry === null ? '' : trim($entry->visit->patient->full_name);
            if ($name !== '') {
                $names[$call->id] = $name;
            }
        }

        return $names;
    }
}
