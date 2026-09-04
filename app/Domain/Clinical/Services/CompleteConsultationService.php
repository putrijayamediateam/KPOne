<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompleteConsultationService
{
    public function __construct(private CurrentClinicalCareService $care, private CheckoutEvidenceService $evidence, private DispensaryHandoffService $handoff, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function complete(User $actor, Visit $visit, array $attributes): ConsultationCheckout
    {
        $a = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'], 'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'], 'encounter_lock_version' => ['required', 'integer', 'min:1'],
            'lock_version' => ['present', 'nullable', 'integer', 'min:1'], 'service_deliveries' => ['present', 'array', 'max:100'],
        ])->validate();
        $branch = $this->care->activeBranch($actor, $visit, $a);

        return DB::transaction(function () use ($actor, $visit, $a, $branch): ConsultationCheckout {
            $care = $this->care->lock($actor, $visit, $branch, 'consultations.complete.own');
            if ($care->visit->lock_version !== (int) $a['visit_lock_version'] || $care->queue->lock_version !== (int) $a['queue_lock_version'] || $care->encounter->lock_version !== (int) $a['encounter_lock_version']) {
                throw ValidationException::withMessages(['checkout' => 'The consultation changed. Reload before completing it.']);
            }
            // Lock the common prefix before dispatch; no Plan-to-Allergy reverse acquisition.
            $profile = PatientAllergyProfile::query()->where('organisation_id', $actor->organisation_id)->where('patient_id', $care->patient->id)->lockForUpdate()->first();
            if ($profile) {
                PatientAllergyRecord::query()->where('patient_allergy_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get();
            }
            ClinicalEncounterAllergyReview::query()->where('clinical_encounter_id', $care->encounter->id)->lockForUpdate()->first();
            $plan = TreatmentPlan::query()->where('clinical_encounter_id', $care->encounter->id)->lockForUpdate()->first();
            if ($plan?->lock_version !== ($a['lock_version'] === null ? null : (int) $a['lock_version']) || ($plan && $plan->status !== TreatmentPlan::STATUS_IN_PROGRESS)) {
                throw ValidationException::withMessages(['lock_version' => 'The Treatment Plan changed. Reload and review.']);
            }
            $medicines = $plan ? TreatmentPlanMedicineOrder::query()->where('treatment_plan_id', $plan->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() : collect();
            if ($medicines->isNotEmpty()) {
                $this->handoff->send($actor, $visit, $a);

                return ConsultationCheckout::query()->where('current_visit_guard', $visit->id)->sole();
            }
            $services = $this->evidence->lockServices($plan);
            $confirmed = $this->evidence->validateServices($care, $services, $a);
            ConsultationCheckout::query()->where('visit_id', $visit->id)->orderBy('id')->lockForUpdate()->get();
            $case = DispensaryCase::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
            if ($case && $case->status !== DispensaryCase::STATUS_RETURNED) {
                throw ValidationException::withMessages(['checkout' => 'Resolve the existing physical Dispensary handoff first.']);
            }
            $checkout = $this->evidence->record($care, $plan, $services, $confirmed);
            $care->queue->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removal_reason' => 'sent_to_billing', 'removed_at' => now()->utc(), 'updated_by_user_id' => $actor->id, 'lock_version' => $care->queue->lock_version + 1])->save();
            $this->audit->record('consultation.checked_out', $checkout, ['record_version' => 1, 'route' => 'billing'], $actor, $branch);

            return $checkout;
        }, 3);
    }
}
