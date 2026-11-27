<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Models\QueueEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Q-16e (owner decisions 25 Nov 2026): the board's "average wait" figure. Today's consultation patients at the active branch who
 * have been called, the average of queued-to-called minutes. A doctor with only queue.view.own gets the average of their own
 * patients; a board holder gets the branch's. Fewer than three samples give no figure (nothing is estimated). Counts and minutes only.
 */
class QueueWaitStatsService
{
    /** Below this many called patients the average is not shown. */
    public const MIN_SAMPLES = 3;

    public function __construct(private BranchAccessService $branches) {}

    /** @return array{averageWaitMinutes: int|null, samples: int, operationalDate: string} */
    public function forActiveBranch(User $actor, int $expectedBranchId): array
    {
        $branch = $this->branches->activeBranch($actor);
        $branchHolder = $actor->can('queue.view.branch');
        if (! $branch || $branch->id !== $expectedBranchId || ! $actor->is_active || (! $branchHolder && ! $actor->can('queue.view.own'))) {
            throw new AuthorizationException('You may not view the queue for this branch.');
        }
        $today = now()->setTimezone($branch->timezone)->toDateString();
        $query = QueueEntry::query()
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
            ->where('queue_entries.organisation_id', $actor->organisation_id)
            ->where('queue_entries.branch_id', $branch->id)
            ->whereDate('queue_entries.operational_date', $today)
            ->whereNotNull('queue_entries.called_at');
        if (! $branchHolder) {
            $query->where('visits.assigned_doctor_user_id', $actor->id);
        }
        $rows = $query->get(['queue_entries.queued_at', 'queue_entries.called_at']);
        if ($rows->count() < self::MIN_SAMPLES) {
            return ['averageWaitMinutes' => null, 'samples' => $rows->count(), 'operationalDate' => $today];
        }
        $average = $rows->avg(fn (QueueEntry $row): float => max(0, $row->called_at->diffInSeconds($row->queued_at, true)) / 60);

        return ['averageWaitMinutes' => (int) round((float) $average), 'samples' => $rows->count(), 'operationalDate' => $today];
    }
}
