<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Services\AllergyReviewGate;
use App\Domain\Clinical\Services\CheckoutEvidenceService;
use App\Domain\Clinical\Services\CurrentClinicalCareService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispensaryHandoffService
{
    public function __construct(
        private CurrentClinicalCareService $currentCare,
        private AllergyReviewGate $allergyGate,
        private AuditRecorder $audit,
        private CheckoutEvidenceService $checkoutEvidence,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function send(User $actor, Visit $visit, array $attributes): DispensaryCase
    {
        $branch = $this->currentCare->activeBranch($actor, $visit, $attributes);

        return DB::transaction(function () use ($actor, $visit, $attributes, $branch): DispensaryCase {
            $care = $this->currentCare->lock($actor, $visit, $branch, 'treatment_plans.send_to_dispensary.own');
            if (! $care->actor->can('consultations.complete.own')) {
                throw new AuthorizationException('You may not complete this consultation.');
            }
            $profile = PatientAllergyProfile::query()->where('organisation_id', $care->actor->organisation_id)->where('patient_id', $care->patient->id)->lockForUpdate()->first();
            if ($profile) {
                PatientAllergyRecord::query()->where('patient_allergy_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get();
            }
            $review = ClinicalEncounterAllergyReview::query()->where('clinical_encounter_id', $care->encounter->id)->lockForUpdate()->first();
            $plan = TreatmentPlan::query()->where('clinical_encounter_id', $care->encounter->id)->lockForUpdate()->first();
            if (! $plan || $plan->status !== TreatmentPlan::STATUS_IN_PROGRESS || $plan->lock_version !== $attributes['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'The Treatment Plan changed or is not editable. Reload and review it before sending.']);
            }
            $orders = TreatmentPlanMedicineOrder::query()->where('treatment_plan_id', $plan->id)->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw ValidationException::withMessages(['treatment_plan' => 'At least one active Medicine Order is required before sending to Dispensary.']);
            }
            $this->allergyGate->assertCurrent($care, $profile, $review);
            if ($orders->contains(fn (TreatmentPlanMedicineOrder $order): bool => $order->allergy_profile_version_validated !== $profile?->lock_version)) {
                throw ValidationException::withMessages(['allergy_review' => 'One or more Medicine Orders were authorised against an older Allergy Profile. Review and update them before sending.']);
            }

            $services = $this->checkoutEvidence->lockServices($plan);
            $confirmed = $this->checkoutEvidence->validateServices($care, $services, $attributes);
            ConsultationCheckout::query()->where('visit_id', $care->visit->id)->orderBy('id')->lockForUpdate()->get();
            $case = DispensaryCase::query()->where('treatment_plan_id', $plan->id)->lockForUpdate()->first();
            if ($case && $case->status !== DispensaryCase::STATUS_RETURNED) {
                throw ValidationException::withMessages(['treatment_plan' => 'This Treatment Plan already has an active or completed Dispensary handoff.']);
            }
            if ($case && DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->where('status', DispensaryHandoff::STATUS_OPEN)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['treatment_plan' => 'This Treatment Plan already has an open Dispensary handoff.']);
            }

            $plan->forceFill(['status' => TreatmentPlan::STATUS_READY_FOR_DISPENSING, 'lock_version' => $plan->lock_version + 1, 'updated_by_user_id' => $care->actor->id])->save();
            if (! $case) {
                $case = new DispensaryCase;
                $case->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $care->actor->organisation_id, 'branch_id' => $care->branch->id, 'visit_id' => $care->visit->id, 'clinical_encounter_id' => $care->encounter->id, 'treatment_plan_id' => $plan->id, 'status' => DispensaryCase::STATUS_PENDING, 'lock_version' => 1, 'received_at' => now()->utc()])->save();
            } else {
                $case->forceFill(['status' => DispensaryCase::STATUS_PENDING, 'current_handler_user_id' => null, 'lock_version' => $case->lock_version + 1, 'received_at' => now()->utc(), 'started_at' => null, 'returned_at' => null])->save();
            }
            $attempt = ((int) DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->max('attempt_number')) + 1;
            $handoff = new DispensaryHandoff;
            $handoff->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $care->actor->organisation_id, 'branch_id' => $care->branch->id, 'dispensary_case_id' => $case->id, 'attempt_number' => $attempt, 'treatment_plan_lock_version_received' => $plan->lock_version, 'status' => DispensaryHandoff::STATUS_OPEN, 'open_case_guard' => $case->id, 'sent_by_user_id' => $care->actor->id, 'sent_at' => now()->utc()])->save();
            foreach ($orders as $order) {
                $item = new DispensaryItem;
                $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $care->actor->organisation_id, 'branch_id' => $care->branch->id, 'dispensary_handoff_id' => $handoff->id, 'treatment_plan_medicine_order_id' => $order->id, 'medicine_catalogue_item_id' => $order->medicine_catalogue_item_id, 'medicine_order_public_id' => $order->public_id, 'medicine_code_snapshot' => $order->medicine_code_snapshot, 'medicine_name_snapshot' => $order->medicine_name_snapshot, 'strength_snapshot' => $order->strength_snapshot, 'dosage_form_snapshot' => $order->dosage_form_snapshot, 'unit_snapshot' => $order->unit_snapshot, 'quantity_ordered' => $order->quantity_ordered, 'dosage' => $order->dosage, 'frequency' => $order->frequency, 'duration' => $order->duration, 'route' => $order->route, 'administration_instruction' => $order->administration_instruction, 'precaution' => $order->precaution, 'allergy_profile_version_validated' => $order->allergy_profile_version_validated, 'status' => DispensaryItem::STATUS_PENDING, 'lock_version' => 1])->save();
            }
            $care->queue->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => 'sent_to_dispensary', 'updated_by_user_id' => $care->actor->id, 'lock_version' => $care->queue->lock_version + 1])->save();
            $this->checkoutEvidence->record($care, $plan, $services, $confirmed, $handoff);
            $this->audit->record('treatment_plan.sent_to_dispensary', $plan, ['record_version' => $plan->lock_version, 'handoff_attempt' => $attempt], $care->actor, $care->branch);

            return $case->fresh(['handoffs.items']);
        }, 3);
    }
}
