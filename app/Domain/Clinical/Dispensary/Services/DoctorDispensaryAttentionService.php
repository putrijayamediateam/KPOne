<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryDoctorCheck;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
                ->where(fn ($cases) => $cases
                    ->where(fn ($declined) => $declined
                        ->whereIn('status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING])
                        ->whereHas('handoffs', fn ($handoff) => $handoff
                            ->where('status', DispensaryHandoff::STATUS_OPEN)
                            ->whereHas('items.exceptions', fn ($exception) => $exception
                                ->where('reason', DispensaryItemException::REASON_PATIENT_DECLINED)
                                ->where('status', DispensaryItemException::STATUS_AWAITING))))
                    // DSP-23: a case waiting for this doctor's check also needs the doctor.
                    ->orWhere(fn ($waiting) => $this->waitingForCheck($actor, $waiting)))
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
            ->whereHas('encounter', fn ($q) => $q->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('attending_clinician_user_id', $actor->id))
            ->whereHas('visit', fn ($q) => $q->where('status', Visit::STATUS_REGISTERED)->whereHas('queueEntry', fn ($queue) => $queue->where('status', QueueEntry::STATUS_REMOVED)->where('removal_reason', 'sent_to_dispensary')))
            ->where(fn ($cases) => $cases
                ->where(fn ($declined) => $declined
                    ->whereIn('status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING])
                    ->whereHas('handoffs', fn ($h) => $h->where('status', DispensaryHandoff::STATUS_OPEN)->whereHas('items.exceptions', fn ($e) => $e->where('reason', DispensaryItemException::REASON_PATIENT_DECLINED)->where('status', DispensaryItemException::STATUS_AWAITING))))
                ->orWhere(fn ($waiting) => $this->waitingForCheck($actor, $waiting)))
            ->pluck('visit_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /**
     * DSP-23: a case waiting for this doctor's check, on the doctor's own case only.
     *
     * @param  Builder<DispensaryCase>  $query
     * @return Builder<DispensaryCase>
     */
    private function waitingForCheck(User $actor, Builder $query): Builder
    {
        if (! $actor->can('dispensary.doctor_check.own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)
            ->whereHas('handoffs', fn ($h) => $h->where('status', DispensaryHandoff::STATUS_OPEN));
    }

    /**
     * DSP-23: the "Medicine ready for your check" row on the consultation page: each line with the order beside what
     * the CA prepared, the batches and expiry, and the allergy status line (Daniel, note 3). Only for the attending
     * doctor of a case waiting for the check.
     *
     * @return array<string, mixed>|null
     */
    public function checkForEncounter(User $actor, ClinicalEncounter $encounter): ?array
    {
        if (! $actor->can('dispensary.doctor_check.own') || ! $this->eligible($actor, $encounter)) {
            return null;
        }
        $case = DispensaryCase::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $encounter->branch_id)
            ->where('visit_id', $encounter->visit->id)
            ->where('clinical_encounter_id', $encounter->id)
            ->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)
            ->with(['handoffs' => fn ($query) => $query->where('status', DispensaryHandoff::STATUS_OPEN)->with('items.allocations.batch:id,batch_number,expiry_date')])
            ->first();
        $handoff = $case?->handoffs->first();
        if (! $case || ! $handoff) {
            return null;
        }
        $branch = Branch::query()->findOrFail($case->branch_id);
        $profile = PatientAllergyProfile::query()->where('organisation_id', $actor->organisation_id)->where('patient_id', $encounter->visit->patient_id)->first();

        return [
            'caseLockVersion' => $case->lock_version,
            'expectedBranchId' => $case->branch_id,
            'sentAt' => app(DispensaryDoctorCheckDirectory::class)->time($case->sent_for_check_at, $branch),
            'sentBy' => User::query()->whereKey($case->sent_for_check_by_user_id)->value('name'),
            // Daniel and B.E.R.N.I.S.S: the line names its source (the patient's allergy profile, not this consultation's
            // review, which the page's own banner speaks about) and the date it was last reviewed or changed.
            'allergies' => [
                'status' => $profile ? $profile->status : PatientAllergyProfile::STATUS_UNKNOWN,
                'records' => $profile ? $profile->allergyRecords()->where('status', 'active')->orderBy('id')->pluck('allergen_text')->values()->all() : [],
                'updatedOn' => ($profile->reviewed_at ?? $profile?->updated_at)?->setTimezone($branch->timezone)->format('j M Y'),
            ],
            'lines' => $this->checkLines($handoff),
            'reasons' => DispensaryDoctorCheck::REASONS,
            'verifyUrl' => route('dispensary.doctor-check.verify', $case),
            'sendBackUrl' => route('dispensary.doctor-check.send-back', $case),
        ];
    }

    /**
     * DSP-23 (Daniel, change 4): after a CA Supervisor checked the case instead of this doctor, the consultation page
     * says so (who, when, why) while the visit is still open. Null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function supervisorCheckForEncounter(User $actor, ClinicalEncounter $encounter): ?array
    {
        if (! $actor->can('dispensary.doctor_check.own') || ! $this->eligible($actor, $encounter)) {
            return null;
        }
        $case = DispensaryCase::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $encounter->branch_id)
            ->where('visit_id', $encounter->visit->id)->where('clinical_encounter_id', $encounter->id)->latest('id')->first();

        return $case ? app(DispensaryDoctorCheckDirectory::class)->supervisorCheck($case, Branch::query()->findOrFail($case->branch_id)) : null;
    }

    /** @return list<array<string, mixed>> */
    private function checkLines(DispensaryHandoff $handoff): array
    {
        $lines = [];
        foreach ($handoff->items as $item) {
            $batches = [];
            foreach ($item->allocations as $allocation) {
                $batches[] = [
                    'batchNumber' => $allocation->batch?->batch_number,
                    'expiryDate' => $allocation->batch?->expiry_date ? CarbonImmutable::parse($allocation->batch->expiry_date)->format('j M Y') : null,
                    'quantity' => (string) $allocation->quantity,
                ];
            }
            $lines[] = [
                'name' => $item->medicine_name_snapshot,
                'strength' => $item->strength_snapshot,
                'unit' => $item->unit_snapshot,
                'changeState' => $item->change_state,
                'source' => $item->source,
                'ordered' => ['quantity' => $item->quantity_ordered, 'dosage' => $item->dosage, 'frequency' => $item->frequency, 'duration' => $item->duration],
                'prepared' => ['quantity' => $item->quantity_dispensed, 'dosage' => $item->effective('dosage'), 'frequency' => $item->effective('frequency'), 'duration' => $item->effective('duration'), 'instruction' => $item->effective('administration_instruction')],
                'batches' => $batches,
            ];
        }

        return $lines;
    }

    /**
     * DSP-23, for Queue & TV's board chip (Amirul): which of this doctor's visits have a case waiting for their check.
     * The same guards as pendingForVisits(); structural ids only, one query per page. It reads the case status, not
     * the branch switch, because a case sent while the switch was on still waits after it is turned off.
     *
     * @param  Collection<int,Visit>  $visits
     * @return array<int,bool>
     */
    public function checksForVisits(User $actor, Collection $visits): array
    {
        if ($visits->isEmpty() || ! $actor->is_active || ! $actor->hasRole('resident_doctor')
            || ! $actor->can('dispensary.doctor_check.own') || ! $actor->can('encounters.view.own')) {
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
            ->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)
            ->whereHas('encounter', fn ($q) => $q->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('attending_clinician_user_id', $actor->id))
            ->whereHas('visit', fn ($q) => $q->where('status', Visit::STATUS_REGISTERED)->whereHas('queueEntry', fn ($queue) => $queue->where('status', QueueEntry::STATUS_REMOVED)->where('removal_reason', 'sent_to_dispensary')))
            ->whereHas('handoffs', fn ($h) => $h->where('status', DispensaryHandoff::STATUS_OPEN))
            ->pluck('visit_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /**
     * Q-15 (owner decision 21 Nov 2026): how many dispensary cases wait for THIS doctor's check, for the "N to check" count on
     * the Queue tab. One aggregate count over all of the doctor's cases at $branch (not only the rows a page lists), with the
     * same case rules as checksForVisits(). The caller (the Queue page) has already authorised the actor for $branch, so the
     * branch checks are not repeated here (they cost four queries a poll); the count is still limited to the actor's own cases.
     * Returns 0 without a query for anyone who is not that doctor.
     */
    public function checksWaitingCount(User $actor, Branch $branch): int
    {
        if (! $actor->is_active || ! $actor->hasRole('resident_doctor') || $branch->organisation_id !== $actor->organisation_id
            || ! $actor->can('dispensary.doctor_check.own') || ! $actor->can('encounters.view.own')) {
            return 0;
        }

        return DispensaryCase::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)
            ->whereHas('encounter', fn ($q) => $q->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('attending_clinician_user_id', $actor->id))
            ->whereHas('visit', fn ($q) => $q->where('status', Visit::STATUS_REGISTERED)->where('assigned_doctor_user_id', $actor->id)
                ->whereHas('queueEntry', fn ($queue) => $queue->where('status', QueueEntry::STATUS_REMOVED)->where('removal_reason', 'sent_to_dispensary')))
            ->whereHas('handoffs', fn ($h) => $h->where('status', DispensaryHandoff::STATUS_OPEN))
            ->count();
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
