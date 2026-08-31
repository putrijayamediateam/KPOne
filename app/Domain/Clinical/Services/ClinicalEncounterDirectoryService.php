<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ClinicalEncounterDirectoryService
{
    public function __construct(private BranchAccessService $branches) {}

    /** @return array<string, mixed> */
    public function detail(User $actor, Visit $visit): array
    {
        $encounter = ClinicalEncounter::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $visit->branch_id)
            ->where('visit_id', $visit->id)
            ->where('attending_clinician_user_id', $actor->id)
            ->with([
                'visit' => fn ($query) => $query->select([
                    'id', 'organisation_id', 'branch_id', 'patient_id', 'visit_number', 'visit_type',
                    'status', 'priority', 'visit_reason', 'assigned_doctor_user_id', 'registered_at', 'lock_version',
                ])->with([
                    'patient:id,organisation_id,patient_number,full_name,date_of_birth,sex',
                    'branch:id,organisation_id,code,name,timezone',
                    'queueEntry:id,organisation_id,branch_id,visit_id,operational_date,queue_number,status,called_at,lock_version',
                ]),
                'attendingClinician:id,organisation_id,name',
                'vitalObservation',
                'diagnoses',
            ])
            ->firstOrFail();
        Gate::forUser($actor)->authorize('view', $encounter);

        $vitals = $encounter->vitalObservation;

        return [
            'branch' => $encounter->visit->branch->only(['id', 'code', 'name', 'timezone']),
            'patient' => [
                'patientNumber' => $encounter->visit->patient->patient_number,
                'name' => $encounter->visit->patient->full_name,
                'dateOfBirth' => $encounter->visit->patient->date_of_birth?->toDateString(),
                'sex' => $encounter->visit->patient->sex,
            ],
            'visit' => [
                'visitNumber' => $encounter->visit->visit_number,
                'priority' => $encounter->visit->priority,
                'registrationReason' => $encounter->visit->visit_reason,
                'registeredAt' => $encounter->visit->registered_at->toIso8601String(),
                'lockVersion' => $encounter->visit->lock_version,
            ],
            'queue' => [
                'queueNumber' => sprintf('%03d', $encounter->visit->queueEntry->queue_number),
                'operationalDate' => $encounter->visit->queueEntry->operational_date->toDateString(),
                'status' => $encounter->visit->queueEntry->status,
                'calledAt' => $encounter->visit->queueEntry->called_at?->toIso8601String(),
                'lockVersion' => $encounter->visit->queueEntry->lock_version,
            ],
            'encounter' => [
                'status' => $encounter->status,
                'clinicalNote' => $encounter->clinical_note,
                'startedAt' => $encounter->started_at->toIso8601String(),
                'attendingClinician' => $encounter->attendingClinician->name,
                'lockVersion' => $encounter->lock_version,
                'updatedAt' => $encounter->updated_at->toIso8601String(),
            ],
            'vitals' => $this->vitals($vitals),
            'diagnoses' => $encounter->diagnoses->map(fn (EncounterDiagnosis $diagnosis): array => [
                'diagnosisText' => $diagnosis->diagnosis_text,
                'diagnosisCode' => $diagnosis->diagnosis_code,
                'codeSystem' => $diagnosis->code_system,
                'isPrimary' => $diagnosis->is_primary,
            ])->values(),
            'history' => $this->history($actor, $encounter),
            'limitations' => [
                'structuredHistory' => 'Structured allergy and medical-condition history is not yet available in KPOne.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function historicalDetail(User $actor, Visit $historicalVisit): array
    {
        $historical = ClinicalEncounter::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('visit_id', $historicalVisit->id)
            ->with([
                'visit' => fn ($query) => $query->select([
                    'id', 'organisation_id', 'branch_id', 'patient_id', 'visit_number',
                ])->with('patient:id,organisation_id,patient_number,full_name,date_of_birth,sex'),
                'branch:id,organisation_id,code,name,timezone',
                'attendingClinician:id,organisation_id,name',
                'vitalObservation',
                'diagnoses',
            ])
            ->firstOrFail();

        $currentCare = $this->currentCareEncounter($actor, $historical->visit->patient_id);
        abort_unless(
            Gate::forUser($actor)->allows('viewHistory', [$historical, $currentCare]),
            404,
        );

        return [
            'patient' => [
                'patientNumber' => $historical->visit->patient->patient_number,
                'name' => $historical->visit->patient->full_name,
                'dateOfBirth' => $historical->visit->patient->date_of_birth?->toDateString(),
                'sex' => $historical->visit->patient->sex,
            ],
            'visit' => [
                'visitNumber' => $historical->visit->visit_number,
            ],
            'branch' => [
                'name' => $historical->branch->name,
            ],
            'encounter' => [
                'status' => $historical->status,
                'clinicalNote' => $historical->clinical_note,
                'startedAt' => $historical->started_at->toIso8601String(),
                'attendingClinician' => $historical->attendingClinician->name,
            ],
            'vitals' => $this->vitals($historical->vitalObservation),
            'diagnoses' => $historical->diagnoses->map(fn (EncounterDiagnosis $diagnosis): array => [
                'diagnosisText' => $diagnosis->diagnosis_text,
                'diagnosisCode' => $diagnosis->diagnosis_code,
                'codeSystem' => $diagnosis->code_system,
                'isPrimary' => $diagnosis->is_primary,
            ])->values(),
            'navigation' => [
                'backLabel' => $currentCare ? 'Current consultation' : 'My Queue',
                'backUrl' => $currentCare
                    ? route('encounters.show', $currentCare->visit)
                    : route('queue.index'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function history(User $actor, ClinicalEncounter $current): array
    {
        if (Gate::forUser($actor)->denies('history', $current)
            || $current->status !== ClinicalEncounter::STATUS_IN_PROGRESS
            || $current->visit->assigned_doctor_user_id !== $actor->id
            || $current->visit->status !== Visit::STATUS_REGISTERED
            || $current->visit->visit_type !== 'consultation'
            || $current->visit->queueEntry?->status !== QueueEntry::STATUS_SERVING) {
            return [];
        }

        $history = ClinicalEncounter::query()
            ->where('clinical_encounters.organisation_id', $current->organisation_id)
            ->whereKeyNot($current->id)
            ->whereHas('visit', fn ($query) => $query
                ->where('patient_id', $current->visit->patient_id))
            ->with([
                'visit:id,organisation_id,branch_id,visit_number,patient_id',
                'branch:id,organisation_id,code,name',
                'attendingClinician:id,organisation_id,name',
            ])
            ->latest('started_at')
            ->limit(15)
            ->get([
                'id', 'organisation_id', 'branch_id', 'visit_id', 'attending_clinician_user_id',
                'status', 'started_at',
            ])
            ->map(fn (ClinicalEncounter $encounter): array => [
                'startedAt' => $encounter->started_at->toIso8601String(),
                'branch' => $encounter->branch->name,
                'attendingClinician' => $encounter->attendingClinician->name,
                'status' => $encounter->status,
                'viewUrl' => route('encounters.history.show', $encounter->visit),
            ])->values()->all();

        return array_values($history);
    }

    private function currentCareEncounter(User $actor, int $patientId): ?ClinicalEncounter
    {
        if (! $actor->is_active
            || ! $actor->hasRole('resident_doctor')
            || ! $actor->can('encounters.view.own')
            || ! $actor->can('encounters.history.view.organisation')
            || ! $actor->staffProfile()->exists()) {
            return null;
        }

        $activeBranch = $this->branches->activeBranch($actor);
        if (! $activeBranch
            || ! $this->branches->canSelect($actor, $activeBranch)
            || ! $this->branches->hasEffectiveAssignment($actor, $activeBranch)) {
            return null;
        }

        return ClinicalEncounter::query()
            ->where('clinical_encounters.organisation_id', $actor->organisation_id)
            ->where('clinical_encounters.branch_id', $activeBranch->id)
            ->where('clinical_encounters.attending_clinician_user_id', $actor->id)
            ->where('clinical_encounters.status', ClinicalEncounter::STATUS_IN_PROGRESS)
            ->whereHas('visit', fn ($query) => $query
                ->where('patient_id', $patientId)
                ->where('assigned_doctor_user_id', $actor->id)
                ->where('status', Visit::STATUS_REGISTERED)
                ->where('visit_type', 'consultation')
                ->whereHas('queueEntry', fn ($queue) => $queue->where('status', QueueEntry::STATUS_SERVING)))
            ->with([
                'visit' => fn ($query) => $query->select([
                    'id', 'organisation_id', 'branch_id', 'patient_id', 'visit_number',
                    'visit_type', 'status', 'assigned_doctor_user_id',
                ])->with('queueEntry:id,organisation_id,branch_id,visit_id,status'),
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function vitals(?EncounterVitalObservation $vitals): array
    {
        $weight = $vitals?->weight_kg !== null ? (float) $vitals->weight_kg : null;
        $height = $vitals?->height_cm !== null ? (float) $vitals->height_cm : null;
        $bmi = $weight !== null && $height !== null && $height > 0
            ? round($weight / (($height / 100) ** 2), 1)
            : null;

        return [
            'observedAt' => $vitals?->observed_at?->toIso8601String(),
            'systolicBp' => $vitals?->systolic_bp,
            'diastolicBp' => $vitals?->diastolic_bp,
            'pulseBpm' => $vitals?->pulse_bpm,
            'temperatureCelsius' => $vitals?->temperature_celsius,
            'spo2Percent' => $vitals?->spo2_percent,
            'weightKg' => $vitals?->weight_kg,
            'heightCm' => $vitals?->height_cm,
            'bmi' => $bmi,
        ];
    }
}
