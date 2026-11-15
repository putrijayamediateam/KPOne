<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class DoctorDispensaryAttentionService
{
    public function __construct(private BranchAccessService $branches) {}

    // Structural navigation hint only. Do not load medicine snapshots into Queue polling.
    public function hasPendingForVisit(User $actor, Visit $visit): bool
    {
        if (! $actor->is_active || ! $actor->hasRole('resident_doctor')
            || ! $actor->can('dispensary.acknowledge_partial.own')
            || $visit->assigned_doctor_user_id !== $actor->id) {
            return false;
        }

        $encounter = ClinicalEncounter::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $visit->branch_id)
            ->where('visit_id', $visit->id)
            ->first(['id', 'organisation_id', 'branch_id', 'visit_id', 'attending_clinician_user_id', 'status']);
        if (! $encounter) {
            return false;
        }
        $encounter->setRelation('visit', $visit);

        return Gate::forUser($actor)->allows('view', $encounter)
            && $this->eligible($actor, $encounter)
            && DispensaryCase::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $visit->branch_id)
                ->where('visit_id', $visit->id)
                ->where('clinical_encounter_id', $encounter->id)
                ->whereIn('status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING])
                ->whereHas('handoffs', fn ($handoff) => $handoff
                    ->where('status', DispensaryHandoff::STATUS_OPEN)
                    ->whereHas('items.exceptions', fn ($exception) => $exception
                        ->where('reason', DispensaryItemException::REASON_PATIENT_DECLINED)
                        ->where('status', DispensaryItemException::STATUS_AWAITING)))
                ->exists();
    }

    /** Structural ids only: same eligibility as hasPendingForVisit(), no medicine content.
     * @param  Collection<int,Visit>  $visits
     * @return array<int,bool>
     */
    public function pendingForVisits(User $actor, Collection $visits): array
    {
        if ($visits->isEmpty() || ! $actor->is_active || ! $actor->hasRole('resident_doctor')
            || ! $actor->can('dispensary.acknowledge_partial.own') || ! $actor->can('encounters.view.own')) {
            return [];
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $actor->staffProfile()->exists() || ! $this->branches->canSelect($actor, $branch) || ! $this->branches->hasEffectiveAssignment($actor, $branch)) {
            return [];
        }
        $ids = $visits->filter(fn (Visit $v): bool => $v->organisation_id === $actor->organisation_id && $v->branch_id === $branch->id && $v->assigned_doctor_user_id === $actor->id)->map(fn (Visit $v): int => $v->id)->all();
        if ($ids === []) {
            return [];
        }

        return DispensaryCase::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->whereIn('visit_id', $ids)
            ->whereIn('status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING])
            ->whereHas('encounter', fn ($q) => $q->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('attending_clinician_user_id', $actor->id))
            ->whereHas('visit', fn ($q) => $q->where('status', Visit::STATUS_REGISTERED)->whereHas('queueEntry', fn ($queue) => $queue->where('status', QueueEntry::STATUS_REMOVED)->where('removal_reason', 'sent_to_dispensary')))
            ->whereHas('handoffs', fn ($h) => $h->where('status', DispensaryHandoff::STATUS_OPEN)->whereHas('items.exceptions', fn ($e) => $e->where('reason', DispensaryItemException::REASON_PATIENT_DECLINED)->where('status', DispensaryItemException::STATUS_AWAITING)))
            ->pluck('visit_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /** @return list<array<string, mixed>> */
    public function forEncounter(User $actor, ClinicalEncounter $encounter): array
    {
        if (! $this->eligible($actor, $encounter)) {
            return [];
        }

        return $this->attention($actor, $encounter);
    }

    private function eligible(User $actor, ClinicalEncounter $encounter): bool
    {
        $branch = $this->branches->activeBranch($actor);
        $visit = $encounter->visit;

        if (! $actor->is_active
            || ! $actor->hasRole('resident_doctor')
            || ! $actor->can('dispensary.acknowledge_partial.own')
            || ! $actor->staffProfile()->exists()
            || $branch?->id !== $encounter->branch_id
            || ! $this->branches->canSelect($actor, $branch)
            || ! $this->branches->hasEffectiveAssignment($actor, $branch)
            || $actor->organisation_id !== $encounter->organisation_id
            || $encounter->attending_clinician_user_id !== $actor->id
            || $visit->assigned_doctor_user_id !== $actor->id
            || $visit->status !== Visit::STATUS_REGISTERED
            || $visit->queueEntry->status !== QueueEntry::STATUS_REMOVED
            || $visit->queueEntry->removal_reason !== 'sent_to_dispensary') {
            return false;
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function attention(User $actor, ClinicalEncounter $encounter): array
    {
        $visit = $encounter->visit;
        $case = DispensaryCase::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $encounter->branch_id)
            ->where('visit_id', $visit->id)
            ->where('clinical_encounter_id', $encounter->id)
            ->whereIn('status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING])
            ->with(['handoffs' => fn ($query) => $query
                ->where('status', DispensaryHandoff::STATUS_OPEN)
                ->with(['items.exceptions' => fn ($exceptions) => $exceptions->orderBy('id')])])
            ->latest('id')
            ->first();

        $handoff = $case?->handoffs->first();
        if (! $case || ! $handoff) {
            return [];
        }

        return array_values($handoff->items->map(function (DispensaryItem $item) use ($case): ?array {
            $current = $item->exceptions
                ->whereIn('status', [DispensaryItemException::STATUS_AWAITING, DispensaryItemException::STATUS_ACKNOWLEDGED])
                ->last();

            if (! $current || $current->reason !== DispensaryItemException::REASON_PATIENT_DECLINED) {
                return null;
            }

            $reviewAgain = $current->status === DispensaryItemException::STATUS_AWAITING
                && $item->exceptions->contains(fn (DispensaryItemException $exception): bool => $exception->status === DispensaryItemException::STATUS_SUPERSEDED
                    && $exception->acknowledged_by_user_id !== null);

            return [
                'exceptionPublicId' => $current->public_id,
                'medicineName' => $item->medicine_name_snapshot,
                'strength' => $item->strength_snapshot,
                'unit' => $item->unit_snapshot,
                'quantityOrdered' => $item->quantity_ordered,
                'proposedQuantity' => $current->proposed_quantity_dispensed,
                'reason' => 'Patient declined remaining quantity',
                'status' => $current->status,
                'reviewAgain' => $reviewAgain,
                'caseLockVersion' => $case->lock_version,
                'itemLockVersion' => $item->lock_version,
                'acknowledgeUrl' => route('dispensary.exceptions.acknowledge', $current),
            ];
        })->filter()->all());
    }
}
