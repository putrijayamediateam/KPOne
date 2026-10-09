<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryServiceLine;
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
use Carbon\CarbonInterface;

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

        $handoff = $this->completedHandoff($visit);

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
            'medicines' => $this->medicines($plan, $handoff),
            'services' => $this->services($plan, $handoff, $timezone),
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
            'doctor' => $encounter->attendingClinician->name,
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

    /** The completed Dispensary handoff of this visit (consultation or OTC), if it went through Dispensary. */
    private function completedHandoff(Visit $visit): ?DispensaryHandoff
    {
        $case = DispensaryCase::query()->where('visit_id', $visit->id)->where('status', DispensaryCase::STATUS_COMPLETED)->first();

        return $case ? DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->where('status', DispensaryHandoff::STATUS_COMPLETED)->orderByDesc('attempt_number')->first() : null;
    }

    /**
     * DS-01c: "ordered" is what the doctor sent; "dispensed" is the CA's final line. A line the CA added has
     * no ordered side, and a line the CA removed is kept and marked, so the record shows every change.
     *
     * @return list<array<string, mixed>>
     */
    private function medicines(?TreatmentPlan $plan, ?DispensaryHandoff $handoff): array
    {
        $items = $handoff ? DispensaryItem::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->get() : collect();
        $byOrder = $items->whereNotNull('treatment_plan_medicine_order_id')->keyBy('treatment_plan_medicine_order_id');
        $rows = [];
        $orders = $plan ? $plan->medicineOrders->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE) : collect();
        foreach ($orders as $order) {
            $item = $byOrder->has($order->id) ? $byOrder->get($order->id) : null;
            $rows[] = [
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
                'source' => 'doctor',
                'changeState' => $item?->change_state ?? 'unchanged',
                'dispensedQuantity' => $item?->quantity_dispensed,
                'dispensedStatus' => $item?->status,
                'dispensed' => $item ? $this->dispensedText($item) : null,
            ];
        }
        foreach ($items->whereNull('treatment_plan_medicine_order_id') as $item) {
            $rows[] = [
                'name' => $item->medicine_name_snapshot,
                'strength' => $item->strength_snapshot,
                'dosageForm' => $item->dosage_form_snapshot,
                'unit' => $item->unit_snapshot,
                'quantityOrdered' => null,
                'dosage' => null,
                'frequency' => null,
                'duration' => null,
                'route' => null,
                'instruction' => null,
                'indication' => null,
                'precaution' => null,
                'source' => 'ca',
                'changeState' => $item->change_state,
                'dispensedQuantity' => $item->quantity_dispensed,
                'dispensedStatus' => $item->status,
                'dispensed' => $this->dispensedText($item),
            ];
        }

        return $rows;
    }

    /** @return array<string, string|null> */
    private function dispensedText(DispensaryItem $item): array
    {
        return [
            'dosage' => $item->effective('dosage'),
            'frequency' => $item->effective('frequency'),
            'duration' => $item->effective('duration'),
            'route' => $item->effective('route'),
            'instruction' => $item->effective('administration_instruction'),
            'precaution' => $item->effective('precaution'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function services(?TreatmentPlan $plan, ?DispensaryHandoff $handoff, string $timezone): array
    {
        $orders = $plan ? $plan->serviceOrders->where('status', TreatmentPlanServiceOrder::STATUS_ACTIVE) : collect();
        $lines = $handoff ? DispensaryServiceLine::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->get() : collect();
        $deliveries = ServiceDelivery::query()
            ->whereIn('treatment_plan_service_order_id', $orders->pluck('id'))
            ->get()->keyBy('treatment_plan_service_order_id');
        $byOrder = $lines->whereNotNull('treatment_plan_service_order_id')->keyBy('treatment_plan_service_order_id');
        $rows = [];
        foreach ($orders as $order) {
            $delivery = $deliveries->has($order->id) ? $deliveries->get($order->id) : null;
            $line = $byOrder->has($order->id) ? $byOrder->get($order->id) : null;
            $rows[] = [
                'name' => $order->service_name_snapshot,
                'unit' => $order->unit_snapshot,
                'quantityOrdered' => $order->quantity_ordered,
                'instruction' => $order->clinical_instruction,
                'source' => 'doctor',
                'changeState' => $line?->change_state ?? 'unchanged',
                'disposition' => $line?->disposition ?? $delivery?->disposition,
                'quantityPerformed' => $line?->quantity_performed ?? $delivery?->quantity_performed,
                'doctorQuantityPerformed' => $delivery?->quantity_performed,
                'finalInstruction' => $line?->effectiveInstruction(),
                'performedAt' => ($line?->performed_at ?? $delivery?->performed_at)?->setTimezone($timezone)->format('j M Y, g:i A'),
            ];
        }
        foreach ($lines->whereNull('treatment_plan_service_order_id') as $line) {
            $rows[] = [
                'name' => $line->service_name_snapshot,
                'unit' => $line->unit_snapshot,
                'quantityOrdered' => null,
                'instruction' => null,
                'source' => 'ca',
                'changeState' => $line->change_state,
                'disposition' => $line->disposition,
                'quantityPerformed' => $line->quantity_performed,
                'doctorQuantityPerformed' => null,
                'finalInstruction' => $line->effectiveInstruction(),
                'performedAt' => $line->performed_at?->setTimezone($timezone)->format('j M Y, g:i A'),
            ];
        }

        return $rows;
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

    private function age(?CarbonInterface $dateOfBirth): ?string
    {
        if ($dateOfBirth === null) {
            return null;
        }
        $diff = $dateOfBirth->diff(now());

        return $diff->y >= 2 ? "{$diff->y} years" : ($diff->y * 12 + $diff->m).' months';
    }
}
