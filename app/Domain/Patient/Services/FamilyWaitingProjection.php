<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientFamilyLink;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class FamilyWaitingProjection
{
    /** @return array<string,mixed>|null */
    public function forEncounter(User $actor, ClinicalEncounter $encounter): ?array
    {
        if (! $actor->is_active || Gate::forUser($actor)->denies('view', $encounter)
            || $encounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS
            || $encounter->visit->status !== Visit::STATUS_REGISTERED
            || $encounter->visit->visit_type !== 'consultation'
            || $encounter->visit->queueEntry?->status !== QueueEntry::STATUS_SERVING) {
            return null;
        }
        $patient = Patient::query()->find($encounter->visit->patient_id);
        $branch = app(BranchAccessService::class)->activeBranch($actor);
        if (! $patient || $patient->isMergedAway() || ! $branch || $branch->id !== $encounter->branch_id || Gate::forUser($actor)->denies('view', $patient)) {
            return null;
        }
        $links = PatientFamilyLink::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->where(fn ($q) => $q->where('patient_low_id', $patient->id)->orWhere('patient_high_id', $patient->id))->get();
        $relatives = [];
        foreach ($links as $link) {
            $otherId = $link->patient_low_id === $patient->id ? $link->patient_high_id : $link->patient_low_id;
            $other = Patient::query()->where('organisation_id', $actor->organisation_id)->whereNull('merged_into_patient_id')->find($otherId);
            if ($other && Gate::forUser($actor)->allows('view', $other)) {
                $code = $link->patient_low_id === $patient->id ? PatientFamilyLinkService::inverse($link->relationship_code) : $link->relationship_code;
                $relatives[$otherId] = ['patient' => $other, 'relationship' => PatientFamilyLinkService::LABELS[$code]];
            }
        }
        $entries = QueueEntry::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where('status', QueueEntry::STATUS_WAITING)->whereDate('operational_date', now($branch->timezone)->toDateString())
            ->whereHas('visit', fn ($q) => $q->whereIn('patient_id', array_keys($relatives))->where('visit_type', 'consultation')->where('status', Visit::STATUS_REGISTERED))
            ->with('visit')->orderBy('queued_at')->orderBy('id')->get();
        $rows = [];
        foreach ($entries as $entry) {
            if (Gate::forUser($actor)->denies('view', $entry)) {
                continue;
            }
            $relative = $relatives[$entry->visit->patient_id];
            $rows[] = ['relationshipLabel' => $relative['relationship'], 'name' => $relative['patient']->full_name, 'patientNumber' => $relative['patient']->patient_number, 'queueLabel' => QueueNumberFormat::format($entry->queue_number), 'url' => route('patients.show', $relative['patient'])];
            if (count($rows) === 5) {
                break;
            }
        }

        return ['rows' => $rows, 'recordUrl' => route('patients.show', $patient).'#family-links'];
    }
}
