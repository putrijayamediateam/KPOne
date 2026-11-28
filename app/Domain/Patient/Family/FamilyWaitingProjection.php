<?php

namespace App\Domain\Patient\Family;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * FAM-1 part 1 (owner decision F3, 27 Nov 2026): the doctor's Consultation "Family" card lists the patient's relatives
 * waiting today for a consultation at this branch, each with a link to their Patient details. It carries no clinical,
 * contact, payment or triage data. Relatives' conditions (part 2) are not built here: they need a named doctor's sign-off
 * and a PDPA review first, and will sit behind a setting that is off by default.
 *
 * Family membership never widens access: each relative must pass the patient view policy and each queue entry the
 * existing queue view policy (own doctor or branch reader). Excluded relatives are never counted.
 */
final class FamilyWaitingProjection
{
    public const LIMIT = 5;

    public function __construct(private BranchAccessService $branches) {}

    /** @return array{rows: list<array<string, string>>, recordUrl: string}|null */
    public function forEncounter(User $actor, ClinicalEncounter $encounter): ?array
    {
        $visit = $encounter->visit;
        if (! $actor->is_active
            || Gate::forUser($actor)->denies('view', $encounter)
            || $encounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS
            || $visit->status !== Visit::STATUS_REGISTERED
            || $visit->visit_type !== 'consultation'
            || $visit->queueEntry?->status !== QueueEntry::STATUS_SERVING) {
            return null;
        }
        $patient = Patient::query()->where('organisation_id', $actor->organisation_id)->find($visit->patient_id);
        $branch = $this->branches->activeBranch($actor);
        if (! $patient || $patient->isMergedAway() || ! $branch || $branch->id !== $encounter->branch_id
            || Gate::forUser($actor)->denies('view', $patient)) {
            return null;
        }
        $familyId = PatientFamilyMember::query()->where('organisation_id', $actor->organisation_id)
            ->where('patient_id', $patient->id)->whereNull('left_at')->value('patient_family_id');
        if ($familyId === null) {
            return null;
        }
        $relatives = [];
        $members = PatientFamilyMember::query()->where('organisation_id', $actor->organisation_id)
            ->where('patient_family_id', $familyId)->whereNull('left_at')->where('patient_id', '!=', $patient->id)
            ->with('patient')->get();
        foreach ($members as $member) {
            $relative = $member->patient;
            if (! $relative->isMergedAway() && Gate::forUser($actor)->allows('view', $relative)) {
                $relatives[$relative->id] = ['patient' => $relative, 'role' => FamilyRoles::LABELS[$member->role]];
            }
        }
        $rows = [];
        if ($relatives !== []) {
            $entries = QueueEntry::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('status', QueueEntry::STATUS_WAITING)
                ->whereDate('operational_date', now($branch->timezone)->toDateString())
                ->whereHas('visit', fn ($query) => $query->whereIn('patient_id', array_keys($relatives))
                    ->where('visit_type', 'consultation')->where('status', Visit::STATUS_REGISTERED))
                ->with('visit:id,organisation_id,branch_id,patient_id,assigned_doctor_user_id')
                ->orderBy('queued_at')->orderBy('id')
                ->get();
            foreach ($entries as $entry) {
                if (Gate::forUser($actor)->denies('view', $entry)) {
                    continue;
                }
                $relative = $relatives[$entry->visit->patient_id];
                $rows[] = [
                    'roleLabel' => $relative['role'],
                    'name' => $relative['patient']->full_name,
                    'patientNumber' => $relative['patient']->patient_number,
                    'queueLabel' => QueueNumberFormat::format($entry->queue_number),
                    'url' => route('patients.show', $relative['patient']),
                ];
                if (count($rows) === self::LIMIT) {
                    break;
                }
            }
        }

        return ['rows' => $rows, 'recordUrl' => route('patients.show', $patient)];
    }
}
