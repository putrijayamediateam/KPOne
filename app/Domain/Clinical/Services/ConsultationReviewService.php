<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\ReadOnlyReviewAudit;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Item 4 (owner decision P4 of 20-21 Nov 2026; 15 = A, 16 = A): the Director's read-only view of a consultation, at any
 * branch. Every open is recorded first (ids only); only then is the page data built.
 *
 * The data is an explicit list of fields. It reuses neither the doctor's `detail()` nor the plan and allergy directory
 * services (they gate on the attending clinician and carry lock versions, keys and `can` flags), so there is nothing a
 * form could post. A test pins the keys. Call it straight from the controller, never inside an outer transaction.
 */
final class ConsultationReviewService
{
    public const PERMISSION = 'consultations.view_readonly.organisation';

    public function __construct(private ReadOnlyReviewAudit $audit) {}

    /** @return array<string, mixed> */
    public function open(User $actor, Visit $visit): array
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException;
        }
        abort_unless($visit->organisation_id === $actor->organisation_id, 404);

        $encounter = ClinicalEncounter::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('visit_id', $visit->id)
            ->first();
        abort_if($encounter === null, 404);
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKey($encounter->branch_id)->firstOrFail();

        $this->audit->record($actor, 'consultation.reviewed', $encounter, [
            'encounter' => $encounter->id,
            'visit' => $visit->id,
            'patient' => $visit->patient_id,
        ], $branch);

        return $this->project($actor, $encounter, $visit, $branch);
    }

    /** @return array<string, mixed> */
    private function project(User $actor, ClinicalEncounter $encounter, Visit $visit, Branch $branch): array
    {
        $encounter->load(['attendingClinician:id,name', 'vitalObservation', 'diagnoses']);
        $visit->load('patient:id,patient_number,full_name');
        $plan = TreatmentPlan::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('clinical_encounter_id', $encounter->id)
            ->with(['medicineOrders', 'serviceOrders'])
            ->first();
        $profile = PatientAllergyProfile::query()->where('organisation_id', $actor->organisation_id)->where('patient_id', $visit->patient_id)->first();
        $medicines = $plan ? $plan->medicineOrders->sortBy('position') : collect();
        $services = $plan ? $plan->serviceOrders->sortBy('position') : collect();

        return [
            'branch' => $branch->name,
            'timezone' => $branch->timezone,
            'patient' => ['patientNumber' => $visit->patient->patient_number, 'name' => $visit->patient->full_name],
            'visit' => ['visitNumber' => $visit->visit_number],
            'doctor' => $encounter->attendingClinician->name,
            'status' => $encounter->status,
            'startedAt' => CarbonImmutable::parse($encounter->started_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
            'vitals' => $this->vitals($encounter->vitalObservation, $branch),
            'clinicalNote' => $encounter->clinical_note,
            'diagnoses' => $encounter->diagnoses->map(fn (EncounterDiagnosis $d): array => [
                'text' => $d->diagnosis_text,
                'code' => $d->diagnosis_code,
                'isPrimary' => (bool) $d->is_primary,
            ])->values()->all(),
            'allergies' => [
                'status' => $profile ? $profile->status : PatientAllergyProfile::STATUS_UNKNOWN,
                'records' => $profile
                    ? $profile->allergyRecords()->where('status', 'active')->orderBy('id')->get(['allergen_text', 'reaction_text', 'severity'])
                        ->map(fn ($a): array => ['allergen' => $a->allergen_text, 'reaction' => $a->reaction_text, 'severity' => $a->severity])->values()->all()
                    : [],
            ],
            'medicines' => $medicines->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE)->map(fn (TreatmentPlanMedicineOrder $o): array => [
                'name' => $o->medicine_name_snapshot,
                'strength' => $o->strength_snapshot,
                'dosageForm' => $o->dosage_form_snapshot,
                'unit' => $o->unit_snapshot,
                'quantity' => $o->quantity_ordered,
                'dosage' => $o->dosage,
                'frequency' => $o->frequency,
                'duration' => $o->duration,
                'route' => $o->route,
                'instruction' => $o->administration_instruction,
                'indication' => $o->indication,
                'precaution' => $o->precaution,
            ])->values()->all(),
            'services' => $services->where('status', TreatmentPlanServiceOrder::STATUS_ACTIVE)->map(fn (TreatmentPlanServiceOrder $o): array => [
                'name' => $o->service_name_snapshot,
                'unit' => $o->unit_snapshot,
                'quantity' => $o->quantity_ordered,
                'instruction' => $o->clinical_instruction,
            ])->values()->all(),
            'withdrawn' => [
                'medicines' => $medicines->where('status', TreatmentPlanMedicineOrder::STATUS_WITHDRAWN)->map(fn (TreatmentPlanMedicineOrder $o): string => $o->medicine_name_snapshot)->values()->all(),
                'services' => $services->where('status', TreatmentPlanServiceOrder::STATUS_WITHDRAWN)->map(fn (TreatmentPlanServiceOrder $o): string => $o->service_name_snapshot)->values()->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function vitals(?EncounterVitalObservation $vitals, Branch $branch): array
    {
        $weight = $vitals?->weight_kg !== null ? (float) $vitals->weight_kg : null;
        $height = $vitals?->height_cm !== null ? (float) $vitals->height_cm : null;

        return [
            'observedAt' => $vitals?->observed_at ? CarbonImmutable::parse($vitals->observed_at)->setTimezone($branch->timezone)->format('j M Y, g:i A') : null,
            'systolicBp' => $vitals?->systolic_bp,
            'diastolicBp' => $vitals?->diastolic_bp,
            'pulseBpm' => $vitals?->pulse_bpm,
            'temperatureCelsius' => $vitals?->temperature_celsius,
            'spo2Percent' => $vitals?->spo2_percent,
            'weightKg' => $vitals?->weight_kg,
            'heightCm' => $vitals?->height_cm,
            'bmi' => $weight !== null && $height !== null && $height > 0 ? round($weight / (($height / 100) ** 2), 1) : null,
        ];
    }
}
