<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class TreatmentPlanDirectoryService
{
    public function __construct(private CurrentClinicalCareService $currentCare) {}

    /** @return array<string, mixed> */
    public function detail(User $actor, ClinicalEncounter $encounter): array
    {
        Gate::forUser($actor)->authorize('viewTreatmentPlan', $encounter);
        $plan = TreatmentPlan::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $encounter->branch_id)
            ->where('clinical_encounter_id', $encounter->id)
            ->with(['medicineOrders', 'serviceOrders'])
            ->first();

        $checkout = ConsultationCheckout::query()->where('current_visit_guard', $encounter->visit_id)->first();
        $isHeld = $encounter->activeHold()->exists();

        return [
            'present' => $plan !== null,
            'status' => $plan?->status,
            'lockVersion' => $plan?->lock_version,
            'medicines' => $plan ? $plan->medicineOrders->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE)->map(fn (TreatmentPlanMedicineOrder $order): array => $this->medicine($order))->values()->all() : [],
            'withdrawnMedicines' => $plan ? $plan->medicineOrders->where('status', TreatmentPlanMedicineOrder::STATUS_WITHDRAWN)->map(fn (TreatmentPlanMedicineOrder $order): array => $this->withdrawnMedicine($order))->values()->all() : [],
            'services' => $plan ? $plan->serviceOrders->where('status', TreatmentPlanServiceOrder::STATUS_ACTIVE)->map(fn (TreatmentPlanServiceOrder $order): array => $this->service($order))->values()->all() : [],
            'withdrawnServices' => $plan ? $plan->serviceOrders->where('status', TreatmentPlanServiceOrder::STATUS_WITHDRAWN)->map(fn (TreatmentPlanServiceOrder $order): array => $this->withdrawnService($order))->values()->all() : [],
            'canSave' => ($plan === null || $plan->status === TreatmentPlan::STATUS_IN_PROGRESS)
                && ! $isHeld
                && $encounter->visit->status === Visit::STATUS_REGISTERED
                && $encounter->visit->queueEntry?->status === 'serving'
                && Gate::forUser($actor)->allows($plan ? 'updateTreatmentPlan' : 'createTreatmentPlan', $encounter),
            'canSendToDispensary' => $this->canSend($actor, $encounter, $plan),
            'canCompleteConsultation' => $actor->can('consultations.complete.own') && $checkout === null
                && ! $isHeld
                && $encounter->visit->status === Visit::STATUS_REGISTERED && $encounter->visit->queueEntry?->status === 'serving'
                && (($plan === null || $plan->medicineOrders->where('status', 'active')->isEmpty()) || $this->canSend($actor, $encounter, $plan)),
            'checkout' => $checkout ? ['route' => $checkout->route, 'lockVersion' => $checkout->lock_version,
                'canReopen' => app(CheckoutReopenEligibility::class)->allows($actor, $encounter->visit)] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function searchMedicines(User $actor, Visit $visit, array $attributes): array
    {
        return DB::transaction(function () use ($actor, $visit, $attributes): array {
            $branch = $this->currentCare->activeBranch($actor, $visit, $attributes);
            $this->currentCare->lock($actor, $visit, $branch, 'treatment_plans.view.own', allowHeld: true);
            $term = '%'.$this->escapeLike(Str::lower(trim($attributes['query']))).'%';

            return array_values(MedicineCatalogueItem::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereRaw("LOWER(display_name) LIKE ? ESCAPE '\\'", [$term])
                    ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '\\'", [$term]))
                ->orderBy('display_name')->orderBy('id')->limit(20)
                ->get(['public_id', 'code', 'display_name', 'strength_text', 'dosage_form', 'order_unit'])
                ->map(fn (MedicineCatalogueItem $item): array => [
                    'publicId' => $item->public_id,
                    'code' => $item->code,
                    'displayName' => $item->display_name,
                    'strength' => $item->strength_text,
                    'dosageForm' => $item->dosage_form,
                    'unit' => $item->order_unit,
                ])->all());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function searchServices(User $actor, Visit $visit, array $attributes): array
    {
        return DB::transaction(function () use ($actor, $visit, $attributes): array {
            $branch = $this->currentCare->activeBranch($actor, $visit, $attributes);
            $this->currentCare->lock($actor, $visit, $branch, 'treatment_plans.view.own', allowHeld: true);
            $term = '%'.$this->escapeLike(Str::lower(trim($attributes['query']))).'%';

            return array_values(ClinicalServiceCatalogueItem::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereRaw("LOWER(display_name) LIKE ? ESCAPE '\\'", [$term])
                    ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '\\'", [$term]))
                ->orderBy('display_name')->orderBy('id')->limit(20)
                ->get(['public_id', 'code', 'display_name', 'order_unit'])
                ->map(fn (ClinicalServiceCatalogueItem $item): array => [
                    'publicId' => $item->public_id,
                    'code' => $item->code,
                    'displayName' => $item->display_name,
                    'unit' => $item->order_unit,
                ])->all());
        });
    }

    /** @return array<string, mixed> */
    private function medicine(TreatmentPlanMedicineOrder $order): array
    {
        return [
            'publicId' => $order->public_id,
            'code' => $order->medicine_code_snapshot,
            'displayName' => $order->medicine_name_snapshot,
            'strength' => $order->strength_snapshot,
            'dosageForm' => $order->dosage_form_snapshot,
            'unit' => $order->unit_snapshot,
            'quantityOrdered' => $order->quantity_ordered,
            'dosage' => $order->dosage,
            'frequency' => $order->frequency,
            'duration' => $order->duration,
            'route' => $order->route,
            'administrationInstruction' => $order->administration_instruction,
            'indication' => $order->indication,
            'precaution' => $order->precaution,
            'allergyProfileVersionValidated' => $order->allergy_profile_version_validated,
        ];
    }

    /** @return array<string, mixed> */
    private function service(TreatmentPlanServiceOrder $order): array
    {
        return [
            'publicId' => $order->public_id,
            'code' => $order->service_code_snapshot,
            'displayName' => $order->service_name_snapshot,
            'unit' => $order->unit_snapshot,
            'quantityOrdered' => $order->quantity_ordered,
            'clinicalInstruction' => $order->clinical_instruction,
        ];
    }

    /** @return array<string, mixed> */
    private function withdrawnMedicine(TreatmentPlanMedicineOrder $order): array
    {
        return ['displayName' => $order->medicine_name_snapshot, 'withdrawnAt' => $order->withdrawn_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function withdrawnService(TreatmentPlanServiceOrder $order): array
    {
        return ['displayName' => $order->service_name_snapshot, 'withdrawnAt' => $order->withdrawn_at?->toIso8601String()];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function canSend(User $actor, ClinicalEncounter $encounter, ?TreatmentPlan $plan): bool
    {
        if (! $plan || $plan->status !== TreatmentPlan::STATUS_IN_PROGRESS || ! $actor->hasRole('resident_doctor') || ! $actor->can('treatment_plans.send_to_dispensary.own')
            || $encounter->activeHold()->exists()) {
            return false;
        }
        $orders = $plan->medicineOrders->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE);
        if ($orders->isEmpty()) {
            return false;
        }
        $patientId = $encounter->visit()->value('patient_id');
        $profile = PatientAllergyProfile::query()->where('organisation_id', $actor->organisation_id)->where('patient_id', $patientId)->first();
        $review = ClinicalEncounterAllergyReview::query()->where('clinical_encounter_id', $encounter->id)->first();

        return $profile && $profile->status !== PatientAllergyProfile::STATUS_UNKNOWN && $review
            && $review->reviewed_by_user_id === $encounter->attending_clinician_user_id
            && $review->allergy_profile_lock_version_reviewed === $profile->lock_version
            && $orders->every(fn (TreatmentPlanMedicineOrder $order): bool => $order->allergy_profile_version_validated === $profile->lock_version);
    }
}
