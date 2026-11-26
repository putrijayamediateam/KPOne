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
        // One scalar row regardless of daily volume; preserve the existing absolute elapsed-minute definition.
        $stats = $query->selectRaw('COUNT(*) AS samples, AVG(ABS(EXTRACT(EPOCH FROM (queue_entries.called_at - queue_entries.queued_at))) / 60.0) AS average_wait_minutes')
            ->toBase()->first();
        $samples = (int) $stats->samples;
        if ($samples < self::MIN_SAMPLES) {
            return ['averageWaitMinutes' => null, 'samples' => $samples, 'operationalDate' => $today];
        }

        return ['averageWaitMinutes' => (int) round((float) $stats->average_wait_minutes), 'samples' => $samples, 'operationalDate' => $today];
    }
}
