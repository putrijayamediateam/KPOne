<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Bounded structural metadata only; no medicine names, quantities or clinical content. */
final class QueueMedicineCheckMetadata
{
    public function __construct(private BranchAccessService $branches) {}

    /**
     * @param  Collection<int, Visit>  $visits  Already authorized workload page (at most 25 visits).
     * @return array<string, array{itemLineCount: int, sentBy: ?string}>
     */
    public function forVisits(User $actor, Collection $visits): array
    {
        if ($visits->isEmpty() || ! $actor->is_active || ! $actor->hasRole('resident_doctor')
            || ! $actor->can('dispensary.doctor_check.own') || ! $actor->can('encounters.view.own')) {
            return [];
        }
        abort_if($visits->count() > 25, 422);
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $actor->staffProfile()->exists() || ! $this->branches->canSelect($actor, $branch)
            || ! $this->branches->hasEffectiveAssignment($actor, $branch)) {
            return [];
        }
        $ids = $visits->filter(fn (Visit $visit): bool => $visit->organisation_id === $actor->organisation_id
            && $visit->branch_id === $branch->id && $visit->assigned_doctor_user_id === $actor->id)->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        // One statement: sender and all current review-line counts share the same snapshot.
        // Repeat current database assignment/encounter/handoff eligibility; never trust a stale loaded visit.
        $rows = DB::table('dispensary_cases as c')
            ->join('visits as v', fn ($join) => $join->on('v.id', '=', 'c.visit_id')
                ->on('v.organisation_id', '=', 'c.organisation_id')->on('v.branch_id', '=', 'c.branch_id'))
            ->join('clinical_encounters as e', fn ($join) => $join->on('e.id', '=', 'c.clinical_encounter_id')
                ->on('e.visit_id', '=', 'v.id')->on('e.organisation_id', '=', 'c.organisation_id')->on('e.branch_id', '=', 'c.branch_id'))
            ->join('queue_entries as q', fn ($join) => $join->on('q.visit_id', '=', 'v.id')
                ->on('q.organisation_id', '=', 'c.organisation_id')->on('q.branch_id', '=', 'c.branch_id'))
            ->join('dispensary_handoffs as h', fn ($join) => $join->on('h.dispensary_case_id', '=', 'c.id')
                ->on('h.organisation_id', '=', 'c.organisation_id')->on('h.branch_id', '=', 'c.branch_id'))
            ->leftJoin('users as sender', fn ($join) => $join->on('sender.id', '=', 'c.sent_for_check_by_user_id')
                ->on('sender.organisation_id', '=', 'c.organisation_id'))
            ->where('c.organisation_id', $actor->organisation_id)->where('c.branch_id', $branch->id)
            ->whereIn('v.id', $ids)->where('v.assigned_doctor_user_id', $actor->id)
            ->where('v.status', Visit::STATUS_REGISTERED)->where('e.attending_clinician_user_id', $actor->id)
            ->where('q.status', QueueEntry::STATUS_REMOVED)->where('q.removal_reason', 'sent_to_dispensary')
            ->where('c.status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)->where('h.status', DispensaryHandoff::STATUS_OPEN)
            ->groupBy('v.visit_number')
            ->select('v.visit_number')
            ->selectRaw('count(*) as candidate_count, min(sender.name) as sender_name')
            ->selectRaw('(case when count(*) = 1 then max((select count(*) from dispensary_items item where item.dispensary_handoff_id = h.id and item.organisation_id = c.organisation_id and item.branch_id = c.branch_id)) else null end) as item_line_count')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            // Unexpected ambiguous current identity is unavailable, never combined or guessed.
            if ((int) $row->candidate_count !== 1) {
                continue;
            }
            $result[(string) $row->visit_number] = ['itemLineCount' => (int) $row->item_line_count,
                'sentBy' => $row->sender_name === null ? null : (string) $row->sender_name];
        }

        return $result;
    }
}
