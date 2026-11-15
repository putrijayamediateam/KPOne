<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QueueWaitingCountService
{
    public function __construct(private BranchAccessService $branches) {}

    /** @return array{branchId: int, own: int, branch: int|null, notReady?:int} */
    public function counts(User $actor, int $expectedBranchId, bool $readiness = false, string $scope = 'own'): array
    {
        abort_unless($actor->is_active, 403);
        Gate::forUser($actor)->authorize('viewAny', QueueEntry::class);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $this->branches->canSelect($actor, $branch), 403);
        if ($branch->id !== $expectedBranchId) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed. Reload the page.']);
        }

        // Count only: never load patients or reuse the queue's patient-row projection.
        // Include still-waiting carry-over, but not future operational days, OTC or Serving/On Hold.
        $query = QueueEntry::query()
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
            ->where('queue_entries.organisation_id', $actor->organisation_id)
            ->where('queue_entries.branch_id', $branch->id)
            ->where('visits.organisation_id', $actor->organisation_id)
            ->where('visits.branch_id', $branch->id)
            ->where('visits.visit_type', 'consultation')
            ->where('visits.status', Visit::STATUS_REGISTERED)
            ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
            ->whereDate('queue_entries.operational_date', '<=', now()->setTimezone($branch->timezone)->toDateString());

        $result = [
            'branchId' => $branch->id,
            'own' => (clone $query)->where('visits.assigned_doctor_user_id', $actor->id)->count(),
            'branch' => $actor->can('queue.view.branch') ? $query->count() : null,
        ];
        if ($readiness) {
            $visible = clone $query;
            if ($scope !== 'branch' || ! $actor->can('queue.view.branch')) {
                $visible->where('visits.assigned_doctor_user_id', $actor->id);
            }
            $result['notReady'] = (clone $visible)->whereIn('queue_entries.id', DB::table('queue_call_holds')->whereNull('closed_at')->select('queue_entry_id'))->count();
        }

        return $result;
    }
}
