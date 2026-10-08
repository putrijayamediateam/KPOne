<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Clinical\Models\ServiceDelivery;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * VH-01: read-only history of a completed visit, for every role that can see Registration and
 * Consultation (permission visits.history.view.branch). It reads existing records only and
 * changes nothing. Clinical content is shown to these roles by explicit owner decision
 * (2026-10-08); financial content is shown only to actors who already hold a billing view
 * permission, and invoice lines only to those who may see them on the billing page.
 */
class VisitHistoryService
{
    public function __construct(
        private BranchAccessService $branches,
        private VisitReasonService $reasons,
        private FinancialLedger $ledger,
    ) {}

    /** True when a Registration row should offer the history page. */
    public function available(User $actor, Visit $visit): bool
    {
        return $visit->status === Visit::STATUS_COMPLETED && $actor->can('visits.history.view.branch');
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, Visit $visit): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless(
            $branch !== null
                && $actor->can('visits.history.view.branch')
                && $visit->organisation_id === $actor->organisation_id
                && $visit->branch_id === $branch->id
                && $visit->status === Visit::STATUS_COMPLETED,
            404,
        );

        $visit->load([
            'patient:id,organisation_id,patient_number,full_name,date_of_birth,sex',
            'assignedDoctor:id,name',
        ]);
        $timezone = $branch->timezone;
        $encounter = ClinicalEncounter::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('visit_id', $visit->id)
            ->with(['attendingClinician:id,name', 'vitalObservation', 'diagnoses'])
            ->first();
        $plan = $encounter ? TreatmentPlan::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('clinical_encounter_id', $encounter->id)
            ->with(['medicineOrders', 'serviceOrders'])
            ->first() : null;

        $canFinance = $actor->can('billing.view.branch') || $actor->can('billing.summary.branch');
        $canLines = $actor->can('billing.view.branch') || $actor->can('billing.lines.view.branch');
        $invoice = $canFinance ? Invoice::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('visit_id', $visit->id)
            ->where('status', '!=', 'draft')
            ->orderByDesc('id')->first() : null;

        return [
            'patient' => [
                'name' => $visit->patient->full_name,
                'patientNumber' => $visit->patient->patient_number,
                'age' => $this->age($visit->patient->date_of_birth),
                'sex' => $visit->patient->sex,
                'profileUrl' => $actor->can('patients.view.organisation')
                    ? '/patients/'.rawurlencode($visit->patient->patient_number) : null,
            ],
            'visit' => [
                'visitNumber' => $visit->visit_number,
                'type' => $visit->visit_type,
                'branch' => $branch->name,
                'doctor' => $visit->assignedDoctor?->name,
                'coverage' => $visit->coverage_type === 'panel' ? $visit->coverage_panel_name_snapshot : 'Self-pay',
                'reason' => $this->reasons->summary($visit),
                'registeredAt' => $visit->registered_at->setTimezone($timezone)->format('j M Y, g:i A'),
                'completedAt' => $visit->completed_at?->setTimezone($timezone)->format('j M Y, g:i A'),
            ],
            'consultation' => $encounter ? $this->consultation($encounter, $timezone) : null,
            'medicines' => $plan ? $this->medicines($plan) : [],
            'services' => $plan ? $this->services($plan, $timezone) : [],
            'financial' => $invoice ? $this->financial($actor, $visit, $invoice, $canLines) : null,
            'canSeeFinancial' => $canFinance,
            'links' => [
                'registration' => route('registration.index'),
                'billing' => $actor->can('billing.view.branch') ? route('billing.show', $visit) : null,
                'consultation' => $actor->can('encounters.history.view.organisation') && $encounter !== null
                    ? route('encounters.history.show', $visit) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function consultation(ClinicalEncounter $encounter, string $timezone): array
    {
        $vitals = $encounter->vitalObservation;
        $weight = $vitals?->weight_kg !== null ? (float) $vitals->weight_kg : null;
        $height = $vitals?->height_cm !== null ? (float) $vitals->height_cm : null;

        return [
            'status' => $encounter->status,
            'startedAt' => $encounter->started_at->setTimezone($timezone)->format('j M Y, g:i A'),
            'doctor' => $encounter->attendingClinician?->name,
            'note' => $encounter->clinical_note,
            'vitals' => [
                'systolicBp' => $vitals?->systolic_bp,
                'diastolicBp' => $vitals?->diastolic_bp,
                'pulseBpm' => $vitals?->pulse_bpm,
                'temperatureCelsius' => $vitals?->temperature_celsius,
                'spo2Percent' => $vitals?->spo2_percent,
                'weightKg' => $vitals?->weight_kg,
                'heightCm' => $vitals?->height_cm,
                'bmi' => $weight !== null && $height !== null && $height > 0
                    ? round($weight / (($height / 100) ** 2), 1) : null,
            ],
            'diagnoses' => $encounter->diagnoses->map(fn (EncounterDiagnosis $diagnosis): array => [
                'text' => $diagnosis->diagnosis_text,
                'code' => $diagnosis->diagnosis_code,
                'isPrimary' => (bool) $diagnosis->is_primary,
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function medicines(TreatmentPlan $plan): array
    {
        $orders = $plan->medicineOrders->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE);
        $dispensed = DispensaryItem::query()
            ->whereIn('treatment_plan_medicine_order_id', $orders->pluck('id'))
            ->get()->keyBy('treatment_plan_medicine_order_id');

        return $orders->map(function (TreatmentPlanMedicineOrder $order) use ($dispensed): array {
            $item = $dispensed->get($order->id);

            return [
                'name' => $order->medicine_name_snapshot,
                'strength' => $order->strength_snapshot,
                'dosageForm' => $order->dosage_form_snapshot,
                'unit' => $order->unit_snapshot,
                'quantityOrdered' => $order->quantity_ordered,
                'dosage' => $order->dosage,
                'frequency' => $order->frequency,
                'duration' => $order->duration,
                'route' => $order->route,
                'instruction' => $order->administration_instruction,
                'indication' => $order->indication,
                'precaution' => $order->precaution,
                'dispensedQuantity' => $item?->quantity_dispensed,
                'dispensedStatus' => $item?->status,
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function services(TreatmentPlan $plan, string $timezone): array
    {
        $orders = $plan->serviceOrders->where('status', TreatmentPlanServiceOrder::STATUS_ACTIVE);
        $deliveries = ServiceDelivery::query()
            ->whereIn('treatment_plan_service_order_id', $orders->pluck('id'))
            ->get()->keyBy('treatment_plan_service_order_id');

        return $orders->map(function (TreatmentPlanServiceOrder $order) use ($deliveries, $timezone): array {
            $delivery = $deliveries->get($order->id);

            return [
                'name' => $order->service_name_snapshot,
                'unit' => $order->unit_snapshot,
                'quantityOrdered' => $order->quantity_ordered,
                'instruction' => $order->clinical_instruction,
                'disposition' => $delivery?->disposition,
                'quantityPerformed' => $delivery?->quantity_performed,
                'performedAt' => $delivery?->performed_at?->setTimezone($timezone)->format('j M Y, g:i A'),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function financial(User $actor, Visit $visit, Invoice $invoice, bool $canLines): array
    {
        $payments = Payment::query()
            ->whereIn('id', PaymentAllocation::query()->where('invoice_id', $invoice->id)->select('payment_id'))
            ->orderBy('id')->get();
        $canPrint = $actor->can('billing.print.branch');

        return [
            'invoiceNumber' => $invoice->invoice_number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'state' => $this->ledger->state($invoice),
            'lines' => $canLines ? InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()
                ->map(fn (InvoiceLine $line): array => [
                    'type' => $line->line_type,
                    'name' => $line->display_name,
                    'unit' => $line->unit_snapshot,
                    'quantity' => $line->quantity,
                    'unitPriceSen' => $line->unit_price_sen,
                    'totalSen' => $line->line_total_sen,
                ])->all() : [],
            'payments' => $payments->map(fn (Payment $payment): array => [
                'receiptNumber' => $payment->receipt_number,
                'amountSen' => $payment->amount_sen,
                'method' => $payment->method_snapshot,
                'status' => $payment->status,
                'printUrl' => $canPrint ? route('billing.receipt', [$visit, $invoice, $payment]) : null,
            ])->all(),
            'printUrl' => $canPrint ? route('billing.print', [$visit, $invoice]) : null,
        ];
    }

    private function age(?CarbonImmutable $dateOfBirth): ?string
    {
        if ($dateOfBirth === null) {
            return null;
        }
        $diff = $dateOfBirth->diff(now());

        return $diff->y >= 2 ? "{$diff->y} years" : ($diff->y * 12 + $diff->m).' months';
    }
}
