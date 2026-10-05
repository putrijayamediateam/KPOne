<?php

namespace Tests\Feature;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\PublicQueueInsightService;
use Tests\Feature\Queue\QueueTestCase;

class PublicQueueInsightTest extends QueueTestCase
{
    private function entry(array $attributes = []): QueueEntry
    {
        $ca = $this->actor('ca', $this->branch);
        $visit = $this->consultationVisit($ca, $this->doctor(), $attributes['visit'] ?? []);
        unset($attributes['visit']);
        $entry = $this->send($ca, $visit);
        $entry->forceFill($attributes)->save();

        return $entry->refresh();
    }

    public function test_ahead_count_and_branch_waiting_figures_are_aggregate_only(): void
    {
        $first = $this->entry(['queued_at' => now()->utc()->subMinutes(20)]);
        $second = $this->entry(['queued_at' => now()->utc()->subMinutes(10)]);
        $service = new PublicQueueInsightService;

        $this->assertSame(0, $service->patientsAhead($first));
        $this->assertSame(1, $service->patientsAhead($second));

        $branches = $service->branches($first->organisation_id, $first->branch_id);
        $current = collect($branches)->firstWhere('current', true);
        $this->assertSame(2, $current['waiting']);
        $this->assertSame(['name', 'address', 'mapUrl', 'waiting', 'current'], array_keys($current));
    }

    public function test_wait_range_needs_enough_recent_history_and_is_a_range(): void
    {
        $entry = $this->entry();
        $service = new PublicQueueInsightService;
        $this->assertNull($service->waitRange($entry->branch_id, 2));

        foreach (range(1, 5) as $i) {
            $this->entry([
                'status' => QueueEntry::STATUS_REMOVED,
                'queued_at' => now()->utc()->subMinutes(40),
                'called_at' => now()->utc()->subMinutes(20),
                'called_by_user_id' => $entry->queued_by_user_id,
                'removed_at' => now()->utc(),
                'removal_reason' => 'completed',
            ]);
        }

        $range = $service->waitRange($entry->branch_id, 2);
        $this->assertSame(['minMinutes' => 14, 'maxMinutes' => 26], $range);
        $this->assertNull($service->waitRange($entry->branch_id, 0));
    }
}
