<?php

namespace App\Domain\Queue\Services;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Structural workload membership only; existing row projections decide what clinical detail is visible. */
final class QueueWorkloadQuery
{
    /** @param Builder<QueueEntry> $query
     * @return Builder<QueueEntry>
     */
    public function apply(Builder $query, User $actor, Branch $branch, string $section): Builder
    {
        $query->whereIn('visits.status', $section === 'completed' ? [Visit::STATUS_REGISTERED, Visit::STATUS_COMPLETED] : [Visit::STATUS_REGISTERED])
            ->where('visits.organisation_id', $actor->organisation_id)->where('visits.branch_id', $branch->id);
        if ($section === 'completed') {
            $start = CarbonImmutable::now($branch->timezone)->startOfDay();

            return $query->where('queue_entries.status', QueueEntry::STATUS_REMOVED)
                ->whereIn('queue_entries.removal_reason', ['sent_to_billing', 'sent_to_dispensary'])
                ->where('queue_entries.removed_at', '>=', $start->utc())
                ->where('queue_entries.removed_at', '<', $start->addDay()->utc());
        }
        if ($section === 'seeing') {
            return $query->where('queue_entries.status', QueueEntry::STATUS_SERVING)
                ->whereNotExists(fn ($holds) => $holds->selectRaw('1')->from('consultation_holds')
                    ->whereColumn('consultation_holds.queue_entry_id', 'queue_entries.id')
                    ->whereColumn('consultation_holds.visit_id', 'visits.id')->whereNull('resumed_at'));
        }
        if ($section === 'held') {
            return $query->where('queue_entries.status', QueueEntry::STATUS_SERVING)
                ->whereExists(fn ($holds) => $holds->selectRaw('1')->from('consultation_holds')
                    ->whereColumn('consultation_holds.queue_entry_id', 'queue_entries.id')
                    ->whereColumn('consultation_holds.visit_id', 'visits.id')->whereNull('resumed_at'));
        }
        if ($section === 'treatment') {
            // Same unacknowledged done-step definition as Q2 updates(). Neither a physical return nor an auto-resume.
            return $query->where('queue_entries.status', QueueEntry::STATUS_SERVING)
                ->whereExists(fn ($steps) => $steps->selectRaw('1')->from('queue_treatment_steps')
                    ->whereColumn('queue_treatment_steps.queue_entry_id', 'queue_entries.id')
                    ->where('queue_treatment_steps.organisation_id', $actor->organisation_id)
                    ->where('queue_treatment_steps.branch_id', $branch->id)
                    ->where('state', TreatmentStep::STATE_DONE)->whereNull('closed_at'));
        }
        abort_unless($section === 'checks', 422);
        // Exact Q13/Q15 membership. Medicine snapshots never enter this structural directory.
        if (! $actor->hasRole('resident_doctor') || ! $actor->can('dispensary.doctor_check.own')
            || ! $actor->can('encounters.view.own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('visits.assigned_doctor_user_id', $actor->id)
            ->where('queue_entries.status', QueueEntry::STATUS_REMOVED)
            ->where('queue_entries.removal_reason', 'sent_to_dispensary')
            ->whereIn('visits.id', DispensaryCase::query()->select('visit_id')
                ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)
                ->whereHas('encounter', fn ($q) => $q->where('organisation_id', $actor->organisation_id)
                    ->where('branch_id', $branch->id)->where('attending_clinician_user_id', $actor->id))
                ->whereHas('handoffs', fn ($q) => $q->where('status', DispensaryHandoff::STATUS_OPEN)));
    }
}
