<?php

namespace App\Domain\Queue\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use Carbon\CarbonImmutable;

/** Aggregate, non-identifying queue figures for the public status page. */
class PublicQueueInsightService
{
    private const SAMPLE_DAYS = 14;

    private const MIN_SAMPLES = 5;

    /** @return list<array{name: string, address: string|null, mapUrl: string|null, waiting: int, current: bool}> */
    public function branches(int $organisationId, int $currentBranchId): array
    {
        $waiting = QueueEntry::query()
            ->where('organisation_id', $organisationId)
            ->where('status', QueueEntry::STATUS_WAITING)
            ->selectRaw('branch_id, count(*) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        return array_values(Branch::query()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'public_address', 'public_map_url'])
            ->map(fn (Branch $branch): array => [
                'name' => $branch->name,
                'address' => $branch->public_address,
                'mapUrl' => $branch->public_map_url,
                'waiting' => (int) ($waiting[$branch->id] ?? 0),
                'current' => $branch->id === $currentBranchId,
            ])->all());
    }

    public function patientsAhead(QueueEntry $entry): int
    {
        return QueueEntry::query()
            ->where('branch_id', $entry->branch_id)
            ->where('operational_date', $entry->operational_date)
            ->where('status', QueueEntry::STATUS_WAITING)
            ->where('queued_at', '<', $entry->queued_at)
            ->count();
    }

    /**
     * Typical recent waiting time, widened into a range; null when there is too little history.
     *
     * @return array{minMinutes: int, maxMinutes: int}|null
     */
    public function waitRange(int $branchId, int $ahead): ?array
    {
        if ($ahead < 1) {
            return null;
        }

        $since = CarbonImmutable::now('UTC')->subDays(self::SAMPLE_DAYS);
        $rows = QueueEntry::query()
            ->where('branch_id', $branchId)
            ->whereNotNull('called_at')
            ->where('called_at', '>=', $since)
            ->get(['queued_at', 'called_at']);
        if ($rows->count() < self::MIN_SAMPLES) {
            return null;
        }

        $average = $rows->avg(fn (QueueEntry $row): float => max(0, $row->called_at->diffInSeconds($row->queued_at, true)) / 60);
        $estimate = max(1.0, (float) $average);

        return [
            'minMinutes' => max(1, (int) floor($estimate * 0.7)),
            'maxMinutes' => max(2, (int) ceil($estimate * 1.3)),
        ];
    }
}
