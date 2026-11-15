<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\ServiceDelivery;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Visit\Billing\Services\ExactMoney;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutEvidenceService
{
    /** @return Collection<int, TreatmentPlanServiceOrder> */
    public function lockServices(?TreatmentPlan $plan): Collection
    {
        return $plan ? TreatmentPlanServiceOrder::query()->where('treatment_plan_id', $plan->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() : collect();
    }

    /** @param Collection<int, TreatmentPlanServiceOrder> $orders
     * @param  array<string, mixed>  $attributes
     * @return array<int, array{quantity: string, disposition: string}>
     */
    public function validateServices(CurrentClinicalCareContext $care, Collection $orders, array $attributes): array
    {
        $rows = $attributes['service_deliveries'] ?? [];
        if (! is_array($rows) || count($rows) !== $orders->count() || ($orders->isNotEmpty() && ! $care->actor->can('services.confirm.own'))) {
            throw ValidationException::withMessages(['service_deliveries' => 'The attending doctor must confirm every current Service Order.']);
        }
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['service_deliveries' => 'Invalid service confirmation.']);
            }
            $order = $orders->firstWhere('public_id', $row['order_public_id'] ?? null);
            $quantity = ExactMoney::quantity($row['quantity_performed'] ?? null, 'service_deliveries');
            $disposition = $row['disposition'] ?? null;
            if (! $order || isset($result[$order->id]) || $quantity > ExactMoney::quantity($order->quantity_ordered)
                || ! (($disposition === 'performed' && $quantity > 0) || ($disposition === 'not_performed' && $quantity === 0))) {
                throw ValidationException::withMessages(['service_deliveries' => 'Confirm each current service with a valid performed quantity or explicit not-performed disposition.']);
            }
            $result[$order->id] = ['quantity' => ExactMoney::decimal($quantity), 'disposition' => $disposition];
        }

        return $result;
    }

    public static function fingerprint(TreatmentPlanServiceOrder $order): string
    {
        return hash('sha256', json_encode($order->only(['public_id', 'clinical_service_catalogue_item_id', 'quantity_ordered', 'clinical_instruction', 'status', 'unit_snapshot']), JSON_THROW_ON_ERROR));
    }

    /** @param Collection<int, TreatmentPlanServiceOrder> $orders
     * @param  array<int, array{quantity: string, disposition: string}>  $confirmed
     */
    public function record(CurrentClinicalCareContext $care, ?TreatmentPlan $plan, Collection $orders, array $confirmed, ?DispensaryHandoff $handoff = null): ConsultationCheckout
    {
        if (ConsultationCheckout::query()->where('current_visit_guard', $care->visit->id)->exists()) {
            throw ValidationException::withMessages(['checkout' => 'This consultation already has current checkout evidence.']);
        }
        $checkout = new ConsultationCheckout;
        $checkout->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $care->actor->organisation_id,
            'branch_id' => $care->branch->id, 'patient_id' => $care->patient->id, 'visit_id' => $care->visit->id,
            'clinical_encounter_id' => $care->encounter->id, 'treatment_plan_id' => $plan?->id,
            'dispensary_handoff_id' => $handoff?->id, 'attending_doctor_user_id' => $care->actor->id,
            'route' => $handoff ? 'dispensary' : 'billing', 'status' => 'current', 'current_visit_guard' => $care->visit->id,
            'encounter_version' => $care->encounter->lock_version, 'plan_version' => $plan?->lock_version,
            'lock_version' => 1, 'checked_out_at' => now()->utc(),
        ])->save();
        foreach ($orders as $order) {
            $row = $confirmed[$order->id];
            $delivery = new ServiceDelivery;
            $delivery->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $care->actor->organisation_id,
                'branch_id' => $care->branch->id, 'consultation_checkout_id' => $checkout->id,
                'treatment_plan_service_order_id' => $order->id, 'source_plan_version' => $plan?->lock_version,
                'source_fingerprint' => self::fingerprint($order), 'disposition' => $row['disposition'],
                'quantity_performed' => $row['quantity'], 'performed_at' => $row['disposition'] === 'performed' ? now()->utc() : null,
                'confirmed_by_user_id' => $care->actor->id, 'lock_version' => 1,
            ])->save();
        }

        return $checkout;
    }

    public function supersede(?ConsultationCheckout $checkout): void
    {
        if ($checkout) {
            $checkout->forceFill(['status' => 'superseded', 'current_visit_guard' => null, 'superseded_at' => now()->utc(), 'lock_version' => $checkout->lock_version + 1])->save();
        }
    }
}
