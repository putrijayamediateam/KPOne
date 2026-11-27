<?php

namespace App\Domain\Queue\Display;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueCall;

/**
 * Q-16f (owner decisions 25 Nov 2026): the "Calls today" figures on the waiting-room TV settings page. Counts of today's call
 * records at one branch, by service and by room. Call records hold no patient data, and neither does this. Read only; it is
 * handed to the settings page and never reaches the TV feed.
 */
class QueueCallStatsService
{
    /** @return array{operationalDate: string, total: int, recalls: int, byService: array<string, array{calls: int, recalls: int}>, byRoom: list<array{room: string, calls: int}>} */
    public function today(Branch $branch): array
    {
        $today = now()->setTimezone($branch->timezone)->toDateString();
        $grouped = QueueCall::query()
            ->selectRaw('service, is_recall, room_name, count(*) as total')
            ->where('branch_id', $branch->id)
            ->whereDate('operational_date', $today)
            ->groupBy('service', 'is_recall', 'room_name')
            ->toBase()
            ->get();

        $byService = [];
        $byRoom = [];
        $total = 0;
        $recalls = 0;
        foreach ($grouped as $row) {
            $count = (int) $row->total;
            $byService[$row->service] ??= ['calls' => 0, 'recalls' => 0];
            $byService[$row->service][$row->is_recall ? 'recalls' : 'calls'] += $count;
            $total += $count;
            $recalls += $row->is_recall ? $count : 0;
            $room = $row->room_name ?? 'No room';
            $byRoom[$room] = ($byRoom[$room] ?? 0) + $count;
        }
        ksort($byService);
        ksort($byRoom);

        return [
            'operationalDate' => $today,
            'total' => $total,
            'recalls' => $recalls,
            'byService' => $byService,
            'byRoom' => array_map(fn (string $room, int $calls): array => ['room' => $room, 'calls' => $calls], array_keys($byRoom), array_values($byRoom)),
        ];
    }
}
