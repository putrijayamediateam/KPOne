<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\ServiceDelivery;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Clinical\Services\CheckoutEvidenceService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use Illuminate\Validation\ValidationException;

class BillingSourceService
{
    /** Called only after the actor/Patient/Visit locks, before commercial or Invoice locks.
     * @return array{checkout: ConsultationCheckout, manifest: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function locked(Visit $visit): array
    {
        if ($visit->visit_type !== 'consultation' || $visit->status !== Visit::STATUS_REGISTERED) {
            $this->invalid();
        }
        $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
        $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
        if (! $encounter || ! $queue || $queue->status !== QueueEntry::STATUS_REMOVED || $encounter->status !== 'in_progress' || $encounter->attending_clinician_user_id !== $visit->assigned_doctor_user_id) {
            $this->invalid();
        }
        $plan = TreatmentPlan::query()->where('clinical_encounter_id', $encounter->id)->lockForUpdate()->first();
        $medicines = $plan ? TreatmentPlanMedicineOrder::query()->where('treatment_plan_id', $plan->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() : collect();
        $services = $plan ? TreatmentPlanServiceOrder::query()->where('treatment_plan_id', $plan->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() : collect();
        $checkout = ConsultationCheckout::query()->where('current_visit_guard', $visit->id)->lockForUpdate()->first();
        if (! $checkout || $checkout->clinical_encounter_id !== $encounter->id || $checkout->patient_id !== $visit->patient_id || $checkout->attending_doctor_user_id !== $encounter->attending_clinician_user_id
            || $checkout->encounter_version !== $encounter->lock_version || $checkout->treatment_plan_id !== $plan?->id || $checkout->plan_version !== $plan?->lock_version) {
            $this->invalid();
        }
        $deliveries = ServiceDelivery::query()->where('consultation_checkout_id', $checkout->id)->orderBy('id')->lockForUpdate()->get();
        if ($deliveries->count() !== $services->count()) {
            $this->invalid();
        }
        $lines = [$this->line('consultation', $checkout->id, 'consultation', 'Consultation', 'CONSULTATION', 'consultation', '1.000', (string) $checkout->lock_version)];
        foreach ($services as $order) {
            $delivery = $deliveries->firstWhere('treatment_plan_service_order_id', $order->id);
            if (! $delivery || $delivery->source_plan_version !== $plan?->lock_version || $delivery->source_fingerprint !== CheckoutEvidenceService::fingerprint($order)
                || $delivery->confirmed_by_user_id !== $encounter->attending_clinician_user_id || ExactMoney::quantity($delivery->quantity_performed) > ExactMoney::quantity($order->quantity_ordered)) {
                $this->invalid();
            }
            if ($delivery->disposition === 'performed' && ExactMoney::quantity($delivery->quantity_performed) > 0) {
                $lines[] = $this->line('service', $delivery->id, 'service:'.$order->clinical_service_catalogue_item_id, $order->service_name_snapshot, $order->service_code_snapshot, $order->unit_snapshot, $delivery->quantity_performed, $delivery->source_fingerprint);
            } elseif ($delivery->disposition !== 'not_performed' || ExactMoney::quantity($delivery->quantity_performed) !== 0) {
                $this->invalid();
            }
        }
        $case = DispensaryCase::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
        $handoff = null;
        if ($checkout->route === 'dispensary') {
            if (! $case || $case->status !== DispensaryCase::STATUS_COMPLETED || $medicines->isEmpty() || $queue->removal_reason !== 'sent_to_dispensary' || $plan?->status !== TreatmentPlan::STATUS_READY_FOR_DISPENSING) {
                $this->invalid();
            }
            $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->orderByDesc('attempt_number')->lockForUpdate()->first();
            if (! $handoff || $handoff->id !== $checkout->dispensary_handoff_id || $handoff->status !== DispensaryHandoff::STATUS_COMPLETED || $handoff->treatment_plan_lock_version_received !== $plan->lock_version) {
                $this->invalid();
            }
            $items = DispensaryItem::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->lockForUpdate()->get();
            if ($items->count() !== $medicines->count()) {
                $this->invalid();
            }
            foreach ($items as $item) {
                $order = $medicines->firstWhere('id', $item->treatment_plan_medicine_order_id);
                if (! $order || ! in_array($item->status, ['dispensed', 'partial', 'not_dispensed'], true) || $item->quantity_dispensed === null) {
                    $this->invalid();
                }
                if (ExactMoney::quantity($item->quantity_dispensed) > 0) {
                    $lines[] = $this->line('medicine', $item->id, 'medicine:'.$item->medicine_catalogue_item_id, $item->medicine_name_snapshot, $item->medicine_code_snapshot, $item->unit_snapshot, $item->quantity_dispensed, (string) $item->lock_version);
                }
            }
        } elseif ($checkout->route !== 'billing' || $medicines->isNotEmpty() || $queue->removal_reason !== 'sent_to_billing' || ($case && $case->status !== DispensaryCase::STATUS_RETURNED)) {
            $this->invalid();
        }

        return ['checkout' => $checkout, 'manifest' => ['checkout' => $checkout->id, 'checkout_version' => $checkout->lock_version, 'encounter_version' => $encounter->lock_version, 'plan_version' => $plan?->lock_version, 'handoff' => $handoff?->id, 'case_version' => $case?->lock_version], 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private function line(string $type, int $id, string $chargeKey, string $name, string $code, string $unit, string $quantity, string $version): array
    {
        return ['line_type' => $type, 'source_key' => $type.':'.$id, 'charge_key' => $chargeKey,
            'dispensary_item_id' => $type === 'medicine' ? $id : null, 'service_delivery_id' => $type === 'service' ? $id : null,
            'consultation_checkout_id' => $type === 'consultation' ? $id : null,
            'display_name' => $name, 'code_snapshot' => $code, 'unit_snapshot' => $unit, 'quantity' => $quantity,
            'source_fingerprint' => hash('sha256', json_encode([$type, $id, $quantity, $version], JSON_THROW_ON_ERROR))];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['invoice' => 'Authoritative checkout or fulfilment is incomplete or changed. Review the Patient workflow.']);
    }
}
