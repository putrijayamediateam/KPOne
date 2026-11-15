<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ReferralSource
{
    /** Caller has locked referral authority; all values are saved, never browser clinical input.
     * @return array<string, mixed>
     */
    public function capture(User $actor, Branch $branch, Visit $visit, ClinicalEncounter $encounter): array
    {
        $patient = Patient::query()->whereKey($visit->patient_id)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->firstOrFail();
        $credential = ClinicianProfessionalProfile::query()->where('organisation_id', $branch->organisation_id)->where('user_id', $actor->id)->lockForUpdate()->first();
        if (! $credential?->verified) {
            throw ValidationException::withMessages(['credentials' => 'Verified professional name and MMC number are required.']);
        }
        $head = app(DocumentLetterheadCapture::class)->capture($branch);
        $diagnoses = $encounter->diagnoses()->orderBy('id')->lockForUpdate()->get();
        $plan = TreatmentPlan::query()->where('clinical_encounter_id', $encounter->id)->lockForUpdate()->first();
        $orders = $plan?->medicineOrders()->orderBy('id')->lockForUpdate()->get() ?? collect();
        $allergy = PatientAllergyProfile::query()->where('organisation_id', $branch->organisation_id)->where('patient_id', $patient->id)->lockForUpdate()->first();
        $records = $allergy?->allergyRecords()->orderBy('id')->lockForUpdate()->get() ?? collect();
        $review = $encounter->allergyReview()->lockForUpdate()->first();
        $source = [
            'patient' => ['name' => $patient->full_name, 'number' => $patient->patient_number, 'dateOfBirth' => $patient->date_of_birth?->format('d/m/Y')],
            'visit' => $visit->visit_number, 'examDate' => $encounter->started_at->setTimezone($branch->timezone)->format('d/m/Y'),
            'doctor' => ['name' => $credential->professional_name, 'mmc' => $credential->mmc_number, 'version' => $credential->lock_version],
            'letterhead' => $head, 'allergyStatus' => $allergy->status ?? 'unknown',
            'diagnoses' => $diagnoses->map(fn ($d): array => ['id' => $d->id, 'text' => $d->diagnosis_text])->all(),
            'medicines' => $orders->where('status', 'active')->map(fn ($m): array => ['id' => $m->id, 'text' => implode(' · ', array_filter([$m->medicine_name_snapshot, $m->strength_snapshot, $m->dosage_form_snapshot, $m->quantity_ordered.' '.$m->unit_snapshot, $m->dosage, $m->frequency, $m->duration, $m->route, $m->administration_instruction, $m->precaution]))])->values()->all(),
            'allergies' => $records->where('status', 'active')->map(fn ($a): array => ['id' => $a->id, 'text' => implode(' · ', array_filter([$a->allergen_text, $a->reaction_text, $a->severity]))])->values()->all(),
        ];
        $manifest = [$branch->id, $branch->timezone, $source, $patient->getAttributes(), $visit->getAttributes(), $encounter->getAttributes(), $credential->getAttributes(), $head, $plan?->getAttributes(), $orders->map->getAttributes()->all(), $diagnoses->map->getAttributes()->all(), $allergy?->getAttributes(), $records->map->getAttributes()->all(), $review?->getAttributes()];
        $source['token'] = hash_hmac('sha256', json_encode($manifest, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $source;
    }
}
