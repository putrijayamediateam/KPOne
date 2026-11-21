<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Clinical\Documents\AttendanceService;
use App\Domain\Clinical\Documents\MedicalCertificateService;
use App\Domain\Clinical\Documents\ReferralService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\ConsultationHold;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Clinical\Triage\TriageService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\BranchDisplaySetting;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Queue\Treatment\TreatmentStepService;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentReconciliation;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CashClosingService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PaymentReconciliationService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\PostedReceiptProjection;
use App\Domain\Visit\Billing\Services\RefundService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitReasonService;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA phase 2: one clinic day at every branch, through the existing services as the fictional staff. Each of a
 * branch's ten fictional patients gets one scenario visit that ends in a different state, so the boards, the
 * Dispensary, billing, documents, the TV, reports and Insights all have something to show. Each scenario runs in one
 * transaction and is skipped when its patient already has a visit, so a failed step leaves nothing half-done and a
 * second run adds nothing.
 */
final class PhaseTwoClinicDay
{
    public const POSTERS_PER_BRANCH = 2;

    /** The scenario each of a branch's ten patients gets, by position at the branch (1 to 10). */
    public const SCENARIOS = [
        1 => 'Completed: cash, a discount, MC, referral and attendance letter',
        2 => 'Completed: card receipt reversed and refunded, then paid in cash',
        3 => 'Completed: Panel, invoice voided and reissued',
        4 => 'Completed: part pay later, part cash',
        5 => 'Completed: medicine only (OTC), called to the dispensary',
        6 => 'Waiting at the dispensary, called to the counter',
        7 => 'Returned to the doctor from the dispensary (held)',
        8 => 'Held, with a nebuliser treatment step done',
        9 => 'Waiting, triaged, flagged not ready to call',
        10 => 'Being seen, triaged, called to the treatment room',
    ];

    /** @var array<string, int> */
    private array $created = [];

    private PlaceholderFiles $files;

    public function __construct(private readonly TrialDataContext $context)
    {
        $this->files = new PlaceholderFiles;
    }

    /** @return array<string, int> what was created, by kind */
    public function run(): array
    {
        try {
            foreach ($this->context->branches as $code => $branch) {
                $this->posters($code, $branch);
                $this->clinicDay($code, $branch);
                $this->dayEnd($code, $branch);
            }
        } finally {
            $this->files->cleanUp();
        }

        return $this->created;
    }

    /** Waiting-room TV posters, added by the branch's first CA Supervisor. */
    private function posters(string $code, Branch $branch): void
    {
        $service = app(QueueDisplayAdministrationService::class);
        for ($number = count($this->settings($branch)->posters ?? []) + 1; $number <= self::POSTERS_PER_BRANCH; $number++) {
            $actor = $this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch);
            $file = $this->files->image('Waiting room poster '.$number, 1280, 720);
            $service->addPoster($actor, $branch, $file, (int) ($this->settings($branch)->lock_version ?? 0));
            $this->count('posters');
        }
    }

    private function settings(Branch $branch): ?BranchDisplaySetting
    {
        return BranchDisplaySetting::query()->where('branch_id', $branch->id)->first();
    }

    private function clinicDay(string $code, Branch $branch): void
    {
        // Doctor 1 sees patients 1 to 4 and is left serving patient 10; doctor 2 sees 6 to 8.
        $steps = [
            1 => fn (Visit $visit) => $this->completedWithDocuments($code, $branch, $visit),
            2 => fn (Visit $visit) => $this->completedWithRefund($code, $branch, $visit),
            3 => fn (Visit $visit) => $this->completedPanel($code, $branch, $visit),
            4 => fn (Visit $visit) => $this->completedPayLater($code, $branch, $visit),
            5 => fn (Visit $visit) => $this->completedOtc($code, $branch, $visit),
            6 => fn (Visit $visit) => $this->waitingAtDispensary($code, $branch, $visit),
            7 => fn (Visit $visit) => $this->returnedToDoctor($code, $branch, $visit),
            8 => fn (Visit $visit) => $this->heldWithTreatmentStep($code, $branch, $visit),
            9 => fn (Visit $visit) => $this->waitingNotReady($code, $branch, $visit),
            10 => fn (Visit $visit) => $this->beingSeen($code, $branch, $visit),
        ];
        $doctors = [1 => 'doctor1', 2 => 'doctor1', 3 => 'doctor1', 4 => 'doctor1', 5 => null, 6 => 'doctor2', 7 => 'doctor2', 8 => 'doctor2', 9 => 'doctor1', 10 => 'doctor1'];

        foreach ($steps as $position => $step) {
            $patient = $this->patient($code, $position);
            if (Visit::query()->where('patient_id', $patient->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($code, $branch, $patient, $doctors, $position, $step): void {
                $step($this->register($code, $branch, $patient, $doctors[$position], $position === 3, $position));
            });
            $this->count('visits');
        }
    }

    // --- scenarios -------------------------------------------------------------------------------------------------

    private function completedWithDocuments(string $code, Branch $branch, Visit $visit): void
    {
        $this->queueAndCall($code, $branch, $visit, 'doctor1');
        $plan = $this->consult($code, $branch, $visit, 'doctor1', 0, [['TRIAL-PARA500', '10.000']], ['TRIAL-GLUCOSE'], 'Trial pollen (fictional)');
        $this->issueDocuments($code, $branch, $visit);
        $this->checkOut($code, $branch, $visit, 'doctor1', $plan);
        $this->dispense($code, $branch, $visit);

        $invoice = $this->bill($code, $branch, $visit);
        $supervisor = $this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch);
        app(InvoiceAdjustmentService::class)->add($supervisor, $visit->refresh(), $invoice->refresh(), [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'threshold_version' => InvoiceAdjustmentService::threshold($branch->id)['lockVersion'],
            'direction' => 'deduct',
            'input_type' => 'fixed',
            'input_value' => 500,
            'confirm_above_limit' => true,
            'reason' => 'Fictional trial discount',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->pay($code, $branch, $visit, $invoice, 'ca1', 'CASH');
        $this->completeVisit($code, $branch, $visit, $invoice);
    }

    private function completedWithRefund(string $code, Branch $branch, Visit $visit): void
    {
        $this->queueAndCall($code, $branch, $visit, 'doctor1');
        $plan = $this->consult($code, $branch, $visit, 'doctor1', 1, [['TRIAL-AMOX250', '15.000']], []);
        $this->checkOut($code, $branch, $visit, 'doctor1', $plan);
        $this->dispense($code, $branch, $visit);

        $invoice = $this->bill($code, $branch, $visit);
        $card = $this->pay($code, $branch, $visit, $invoice, 'ca1', 'CARD', 'TRIAL-CARD-'.$visit->id);
        $finance = $this->context->as('finance', $branch);
        app(InvoiceCorrectionService::class)->reverse($finance, $visit->refresh(), $invoice->refresh(), $card->refresh(), [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'payment_lock_version' => $card->lock_version,
            'recording_error_only' => true,
            'reason' => 'Fictional trial: card charged on the wrong terminal',
        ]);
        $refund = app(RefundService::class)->record($this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch), $visit->refresh(), $card->public_id, [
            'expected_branch_id' => $branch->id,
            'payment_method_id' => $this->method('CARD')->id,
            'reason_code' => 'invoice_corrected',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        app(RefundService::class)->decide($this->context->as('finance', $branch), $visit->refresh(), $refund->public_id, 'approved', [
            'expected_branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->count('refunds');
        $this->pay($code, $branch, $visit, $invoice, 'ca1', 'CASH');
        $this->completeVisit($code, $branch, $visit, $invoice);
    }

    private function completedPanel(string $code, Branch $branch, Visit $visit): void
    {
        $this->queueAndCall($code, $branch, $visit, 'doctor1');
        $plan = $this->consult($code, $branch, $visit, 'doctor1', 3, [['TRIAL-CETI10', '7.000']], ['TRIAL-DRESS']);
        $this->checkOut($code, $branch, $visit, 'doctor1', $plan);
        $this->dispense($code, $branch, $visit);

        // The first invoice is voided by Finance and a replacement is issued, before any money is recorded.
        $first = $this->bill($code, $branch, $visit);
        app(InvoiceCorrectionService::class)->void($this->context->as('finance', $branch), $visit->refresh(), $first->refresh(), [
            'expected_branch_id' => $branch->id,
            'lock_version' => $first->lock_version,
            'reason' => 'Fictional trial: reissued with the Panel details',
        ]);
        $this->count('voided_invoices');
        $invoice = $this->bill($code, $branch, $visit);

        $panel = $this->panel(0);
        $ca = $this->context->as(TrialDataContext::key($code, 'ca1'), $branch);
        $responsibility = app(ResponsibilityService::class);
        $proposal = $responsibility->propose($ca, $visit->refresh(), $invoice->refresh(), 'panel', [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => $this->due($invoice),
            'panel_id' => $panel->id,
            'member_reference' => 'TRIAL-MEMBER-'.str_pad((string) $visit->patient_id, 4, '0', STR_PAD_LEFT),
            'reason' => 'Fictional trial: covered by the Panel',
        ]);
        $responsibility->approve($this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch), $visit->refresh(), $invoice->refresh(), 'panel', $proposal->public_id, [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'proposal_lock_version' => $proposal->refresh()->lock_version,
        ]);
        $this->completeVisit($code, $branch, $visit, $invoice);
    }

    private function completedPayLater(string $code, Branch $branch, Visit $visit): void
    {
        $this->queueAndCall($code, $branch, $visit, 'doctor1');
        $plan = $this->consult($code, $branch, $visit, 'doctor1', 4, [['TRIAL-OMEP20', '14.000']], ['TRIAL-ECG']);
        $this->checkOut($code, $branch, $visit, 'doctor1', $plan);
        $this->dispense($code, $branch, $visit);

        $invoice = $this->bill($code, $branch, $visit);
        $ca = $this->context->as(TrialDataContext::key($code, 'ca1'), $branch);
        $responsibility = app(ResponsibilityService::class);
        $proposal = $responsibility->propose($ca, $visit->refresh(), $invoice->refresh(), 'deferment', [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => 2000,
            'due_date' => $this->today($branch)->addDays(14)->toDateString(),
            'reason' => 'Fictional trial: patient pays the rest next visit',
        ]);
        $responsibility->approve($this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch), $visit->refresh(), $invoice->refresh(), 'deferment', $proposal->public_id, [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'proposal_lock_version' => $proposal->refresh()->lock_version,
        ]);
        $this->pay($code, $branch, $visit, $invoice, 'ca1', 'CASH');
        $this->completeVisit($code, $branch, $visit, $invoice);
    }

    private function completedOtc(string $code, Branch $branch, Visit $visit): void
    {
        $ca = $this->ca($code, $branch);
        app(OtcQueueService::class)->enter($ca, $visit->refresh(), ['expected_branch_id' => $branch->id]);
        app(RoomCallService::class)->callOtcToDispensary($this->ca($code, $branch), $visit->refresh(), null, ['expected_branch_id' => $branch->id]);

        $case = app(OtcDispensaryService::class)->open($this->ca($code, $branch), $visit->refresh(), ['expected_branch_id' => $branch->id]);
        [$location, $sku, $batch] = $this->stock($code, 'TRIAL-ORS');
        $dispensary = app(DispensaryService::class);
        $dispensary->addItem($this->ca($code, $branch), $case->refresh(), [
            'expected_branch_id' => $branch->id,
            'case_lock_version' => $case->lock_version,
            'medicine_public_id' => $this->medicine('TRIAL-ORS')->public_id,
            'quantity_dispensed' => '4.000',
            'dosage' => 'One sachet',
            'frequency' => 'After each loose stool',
            'duration' => '2 days',
            'route' => 'Oral',
            'administration_instruction' => 'Dissolve in 200 ml of water',
            'precaution' => null,
            'allocations' => [['location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '4.000']],
        ]);
        $dispensary->confirmOtcAllergy($this->ca($code, $branch), $case->refresh(), [
            'expected_branch_id' => $branch->id,
            'case_lock_version' => $case->lock_version,
            'allergy_statement' => 'none',
        ]);
        $dispensary->complete($this->ca($code, $branch), $case->refresh(), ['expected_branch_id' => $branch->id, 'case_lock_version' => $case->lock_version]);

        $invoice = $this->bill($code, $branch, $visit);
        $this->pay($code, $branch, $visit, $invoice, 'ca1', 'CASH');
        $this->completeVisit($code, $branch, $visit, $invoice);
    }

    private function waitingAtDispensary(string $code, Branch $branch, Visit $visit): void
    {
        $this->queueAndCall($code, $branch, $visit, 'doctor2');
        $plan = $this->consult($code, $branch, $visit, 'doctor2', 6, [['TRIAL-IBU400', '10.000']], []);
        $this->checkOut($code, $branch, $visit, 'doctor2', $plan);
        app(RoomCallService::class)->callToDispensary($this->ca($code, $branch), $this->case($visit), null, ['expected_branch_id' => $branch->id]);
    }

    private function returnedToDoctor(string $code, Branch $branch, Visit $visit): void
    {
        $this->queueAndCall($code, $branch, $visit, 'doctor2');
        $plan = $this->consult($code, $branch, $visit, 'doctor2', 3, [['TRIAL-LORA10', '7.000']], []);
        $this->checkOut($code, $branch, $visit, 'doctor2', $plan);
        $dispensary = app(DispensaryService::class);
        $case = $this->case($visit);
        $dispensary->start($this->ca($code, $branch), $case, ['expected_branch_id' => $branch->id, 'case_lock_version' => $case->lock_version]);
        $case = $this->case($visit);
        $dispensary->returnToDoctor($this->ca($code, $branch), $case, ['expected_branch_id' => $branch->id, 'case_lock_version' => $case->lock_version]);
        // The return holds the consultation only when the doctor is busy elsewhere; otherwise the doctor holds it, as the
        // patient waits while the doctor sees the next one.
        if (! ConsultationHold::query()->where('visit_id', $visit->id)->whereNull('resumed_at')->exists()) {
            $this->hold($code, $branch, $visit, 'doctor2');
        }
    }

    private function hold(string $code, Branch $branch, Visit $visit, string $doctor): void
    {
        app(ConsultationHoldService::class)->hold($this->context->as(TrialDataContext::key($code, $doctor), $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $this->queue($visit)->lock_version,
            'encounter_lock_version' => $this->encounter($visit)->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function heldWithTreatmentStep(string $code, Branch $branch, Visit $visit): void
    {
        $this->queueAndCall($code, $branch, $visit, 'doctor2');
        $this->startConsultation($code, $branch, $visit, 'doctor2', 0);
        $this->hold($code, $branch, $visit, 'doctor2');

        $steps = app(TreatmentStepService::class);
        $started = $steps->start($this->ca($code, $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'step_code' => 'nebuliser',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $step = TreatmentStep::query()->findOrFail($started['id']);
        $steps->complete($this->ca($code, $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'treatment_step_id' => $step->id,
            'lock_version' => $step->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->count('treatment_steps');
    }

    private function waitingNotReady(string $code, Branch $branch, Visit $visit): void
    {
        $queue = $this->enterQueue($code, $branch, $visit);
        $this->triage($code, $branch, $visit);
        app(CallHoldService::class)->change($this->ca($code, $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'reason_code' => 'awaiting_patient_details',
            'idempotency_key' => (string) Str::uuid(),
        ], false);
    }

    private function beingSeen(string $code, Branch $branch, Visit $visit): void
    {
        $this->enterQueue($code, $branch, $visit);
        $this->triage($code, $branch, $visit);
        $this->callIn($code, $branch, $visit, 'doctor1');
        $this->startConsultation($code, $branch, $visit, 'doctor1', 5);
        $treatment = $this->room($branch, BranchRoom::KIND_TREATMENT);
        app(RoomCallService::class)->callToTreatment($this->context->as(TrialDataContext::key($code, 'doctor1'), $branch), $visit->refresh(), $treatment->id, [
            'expected_branch_id' => $branch->id,
            'queue_lock_version' => $this->queue($visit)->lock_version,
        ]);
    }

    // --- day end ---------------------------------------------------------------------------------------------------

    /** The card terminal close and the cash count for today, recorded by a CA Supervisor and a CA. Approval waits. */
    private function dayEnd(string $code, Branch $branch): void
    {
        $date = $this->today($branch);
        $card = $this->method('CARD');
        if (! PaymentReconciliation::query()->where('branch_id', $branch->id)->where('payment_method_id', $card->id)->whereDate('business_date', $date->toDateString())->exists()) {
            $posted = PostedReceiptProjection::query($branch->organisation_id, $branch->id, $date->startOfDay()->utc(), $date->addDay()->startOfDay()->utc())
                ->where('payment_method_id', $card->id);
            app(PaymentReconciliationService::class)->reconcile($this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch), [
                'expected_branch_id' => $branch->id,
                'payment_method_id' => $card->id,
                'business_date' => $date->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
                'terminal_sales_count' => (clone $posted)->count(),
                'terminal_sales_total' => number_format(((int) (clone $posted)->sum('amount_sen')) / 100, 2, '.', ''),
                'terminal_batch_reference' => 'TRIAL-BATCH-'.$date->format('Ymd'),
                'notes' => 'Fictional trial data',
            ]);
            $this->count('terminal_closes');
        }

        if (! CashClosing::query()->where('branch_id', $branch->id)->whereDate('business_date', $date->toDateString())->exists()) {
            $closings = app(CashClosingService::class);
            $expected = (int) $closings->expected($branch, $date)['sen'];
            $closings->count($this->ca($code, $branch), [
                'expected_branch_id' => $branch->id,
                'business_date' => $date->toDateString(),
                'counted_cash' => number_format($expected / 100, 2, '.', ''),
                'variance_reason' => null,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->count('cash_counts');
        }
    }

    // --- building blocks -------------------------------------------------------------------------------------------

    private function register(string $code, Branch $branch, Patient $patient, ?string $doctor, bool $panel, int $position): Visit
    {
        $reasons = $doctor === null ? [] : [app(VisitReasonService::class)->create($this->ca($code, $branch), PhaseOneFoundation::VISIT_REASONS[$position % count(PhaseOneFoundation::VISIT_REASONS)])->public_id];

        return app(VisitRegistrationService::class)->register($this->ca($code, $branch), [
            'idempotency_key' => (string) Str::uuid(),
            'expected_branch_id' => $branch->id,
            'patient_number' => $patient->patient_number,
            'visit_type' => $doctor === null ? 'otc' : 'consultation',
            'assigned_doctor_user_id' => $doctor === null ? null : $this->user(TrialDataContext::key($code, $doctor))->id,
            'visit_reason_public_ids' => $reasons,
            'priority' => $position === 10 ? 'urgent' : 'normal',
            'coverage_type' => $panel ? 'panel' : 'self_pay',
            'panel_id' => $panel ? $this->panel(0)->id : null,
            'coverage_member_reference' => $panel ? 'TRIAL-MEMBER-'.str_pad((string) $patient->id, 4, '0', STR_PAD_LEFT) : null,
            'confirm_repeat' => false,
        ]);
    }

    private function enterQueue(string $code, Branch $branch, Visit $visit): QueueEntry
    {
        return app(QueueEntryService::class)->enter($this->ca($code, $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
        ]);
    }

    private function queueAndCall(string $code, Branch $branch, Visit $visit, string $doctor): void
    {
        $this->enterQueue($code, $branch, $visit);
        $this->callIn($code, $branch, $visit, $doctor);
    }

    private function callIn(string $code, Branch $branch, Visit $visit, string $doctor): void
    {
        $actor = $this->context->as(TrialDataContext::key($code, $doctor), $branch);
        $rooms = app(DoctorRoomService::class);
        if (($rooms->choice($actor)['currentRoomId'] ?? null) === null) {
            $room = BranchRoom::query()->where('branch_id', $branch->id)->where('kind', BranchRoom::KIND_CONSULTATION)
                ->where('name', $doctor === 'doctor1' ? 'Consultation Room 1' : 'Consultation Room 2')->firstOrFail();
            $rooms->select($actor, $room->id, $branch->id);
            $actor = $this->context->as(TrialDataContext::key($code, $doctor), $branch);
        }
        app(QueueEntryService::class)->call($actor, $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $this->queue($visit)->lock_version,
        ]);
    }

    private function startConsultation(string $code, Branch $branch, Visit $visit, string $doctor, int $diagnosis): ClinicalEncounter
    {
        $actor = $this->context->as(TrialDataContext::key($code, $doctor), $branch);
        $encounters = app(ClinicalEncounterService::class);
        $encounter = $encounters->start($actor, $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $this->queue($visit)->lock_version,
        ]);

        return $encounters->update($actor, $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'lock_version' => $encounter->refresh()->lock_version,
            'clinical_note' => 'Fictional trial consultation note. Not a real patient.',
            'vitals' => ['systolic_bp' => 118 + $diagnosis, 'diastolic_bp' => 76, 'pulse_bpm' => 78, 'temperature_celsius' => 37.2,
                'spo2_percent' => 98, 'weight_kg' => 62, 'height_cm' => 163],
            'diagnoses' => [['diagnosis_text' => PhaseOneFoundation::DIAGNOSES[$diagnosis % count(PhaseOneFoundation::DIAGNOSES)],
                'diagnosis_code' => null, 'code_system' => null, 'is_primary' => true]],
        ]);
    }

    /**
     * Starts the consultation, records the allergy status and its review, and saves the treatment plan.
     *
     * @param  list<array{string, string}>  $medicines  medicine code and quantity
     * @param  list<string>  $services  service codes
     */
    private function consult(string $code, Branch $branch, Visit $visit, string $doctor, int $diagnosis, array $medicines, array $services, ?string $allergen = null): TreatmentPlan
    {
        $this->startConsultation($code, $branch, $visit, $doctor, $diagnosis);
        $actor = $this->context->as(TrialDataContext::key($code, $doctor), $branch);
        $allergies = app(PatientAllergyService::class);
        $profile = PatientAllergyProfile::query()->where('patient_id', $visit->patient_id)->first();
        if ($allergen !== null) {
            $allergies->add($actor, $visit->refresh(), [
                'expected_branch_id' => $branch->id,
                'profile_lock_version' => $profile?->lock_version,
                'allergen_text' => $allergen,
                'category' => 'environmental',
                'reaction_text' => 'Sneezing (fictional)',
                'severity' => 'mild',
            ]);
        } elseif ($profile === null) {
            $allergies->declareNoKnown($actor, $visit->refresh(), ['expected_branch_id' => $branch->id, 'profile_lock_version' => null]);
        }
        $allergies->review($actor, $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'profile_lock_version' => PatientAllergyProfile::query()->where('patient_id', $visit->patient_id)->firstOrFail()->lock_version,
        ]);

        $plan = app(TreatmentPlanService::class)->save($actor, $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'lock_version' => null,
            'medicines' => array_map(fn (array $row): array => [
                'public_id' => null,
                'catalogue_public_id' => $this->medicine($row[0])->public_id,
                'quantity_ordered' => $row[1],
                'dosage' => 'One unit',
                'frequency' => 'Three times a day',
                'duration' => '5 days',
                'route' => 'Oral',
                'administration_instruction' => 'After food',
                'indication' => null,
                'precaution' => null,
            ], $medicines),
            'services' => array_map(fn (string $service): array => [
                'public_id' => null,
                'catalogue_public_id' => ClinicalServiceCatalogueItem::query()->where('organisation_id', $branch->organisation_id)->where('code', $service)->firstOrFail()->public_id,
                'quantity_ordered' => 1,
                'clinical_instruction' => null,
            ], $services),
        ]);

        return $plan ?? throw new \LogicException('The treatment plan was not saved.');
    }

    /** MC, referral letter and attendance confirmation, while the patient is being seen (wording stays DRAFT). */
    private function issueDocuments(string $code, Branch $branch, Visit $visit): void
    {
        $doctor = $this->context->as(TrialDataContext::key($code, 'doctor1'), $branch);
        app(MedicalCertificateService::class)->act($doctor, $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'lock_version' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'issue',
            'language' => 'en',
            'start_date' => $this->today($branch)->toDateString(),
            'end_date' => $this->today($branch)->addDay()->toDateString(),
        ]);

        $referrals = app(ReferralService::class);
        $command = [
            'expected_branch_id' => $branch->id, 'action' => 'issue', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(),
            'source_token' => $referrals->source($doctor, $visit->refresh())['token'], 'language' => 'en',
            'institution' => 'Trial General Hospital (fictional)', 'department' => 'Medical clinic', 'clinician' => '',
            'reason' => 'Fictional trial referral for review', 'urgency' => 'routine', 'history' => '', 'findings' => '',
            'management' => '', 'diagnoses' => [], 'medicines' => [], 'allergies' => [], 'include_allergies' => false, 'consent' => true,
        ];
        $command['review_token'] = $referrals->preview($doctor, $visit->refresh(), $command)['reviewToken'];
        $referrals->act($doctor, $visit->refresh(), $command);

        $attendance = app(AttendanceService::class);
        $command = [
            'expected_branch_id' => $branch->id, 'action' => 'issue', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(),
            'source_token' => $attendance->source($doctor, $visit->refresh())['token'], 'language' => 'en',
            'recipient' => 'Trial Employer Sdn Bhd (fictional)', 'consent' => true,
        ];
        $command['review_token'] = $attendance->preview($doctor, $visit->refresh(), $command)['reviewToken'];
        $attendance->act($doctor, $visit->refresh(), $command);
        $this->count('documents', 3);
    }

    private function checkOut(string $code, Branch $branch, Visit $visit, string $doctor, TreatmentPlan $plan): void
    {
        $plan->refresh();
        app(CompleteConsultationService::class)->complete($this->context->as(TrialDataContext::key($code, $doctor), $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $this->queue($visit)->lock_version,
            'encounter_lock_version' => $this->encounter($visit)->lock_version,
            'lock_version' => $plan->lock_version,
            'service_deliveries' => $plan->serviceOrders()->where('status', '!=', TreatmentPlanServiceOrder::STATUS_WITHDRAWN)->get()
                ->map(fn (TreatmentPlanServiceOrder $order): array => ['order_public_id' => $order->public_id, 'disposition' => 'performed', 'quantity_performed' => '1'])
                ->values()->all(),
        ]);
    }

    private function dispense(string $code, Branch $branch, Visit $visit): void
    {
        $dispensary = app(DispensaryService::class);
        $case = $this->case($visit);
        $dispensary->start($this->ca($code, $branch), $case, ['expected_branch_id' => $branch->id, 'case_lock_version' => $case->lock_version]);
        $handoff = $this->case($visit)->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole();
        foreach ($handoff->items()->get() as $item) {
            $medicine = MedicineCatalogueItem::query()->findOrFail($item->medicine_catalogue_item_id);
            [$location, $sku, $batch] = $this->stock($code, $medicine->code);
            $quantity = (string) $item->quantity_ordered;
            $dispensary->updateItem($this->ca($code, $branch), $this->case($visit), $item->refresh(), [
                'expected_branch_id' => $branch->id,
                'case_lock_version' => $this->case($visit)->lock_version,
                'item_lock_version' => $item->lock_version,
                'status' => 'dispensed',
                'quantity_dispensed' => $quantity,
                'reason' => null,
                'allocations' => [['location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $quantity]],
            ]);
        }
        $dispensary->complete($this->ca($code, $branch), $this->case($visit), ['expected_branch_id' => $branch->id, 'case_lock_version' => $this->case($visit)->lock_version]);
    }

    private function bill(string $code, Branch $branch, Visit $visit): Invoice
    {
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($this->ca($code, $branch), $visit->refresh(), ['expected_branch_id' => $branch->id, 'lock_version' => null]);

        return $builder->finalize($this->ca($code, $branch), $visit->refresh(), $invoice, ['expected_branch_id' => $branch->id, 'lock_version' => $invoice->lock_version]);
    }

    /** Pays what is due now with the given method. */
    private function pay(string $code, Branch $branch, Visit $visit, Invoice $invoice, string $actor, string $method, ?string $reference = null): Payment
    {
        $payment = app(PaymentService::class)->add($this->context->as(TrialDataContext::key($code, $actor), $branch), $visit->refresh(), $invoice->refresh(), [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => $this->due($invoice),
            'method' => $method,
            'reference' => $reference,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->count('payments');

        return $payment;
    }

    private function completeVisit(string $code, Branch $branch, Visit $visit, Invoice $invoice): void
    {
        app(CompleteVisitationService::class)->complete($this->ca($code, $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'lock_version' => $invoice->refresh()->lock_version,
        ]);
        $this->count('completed_visits');
    }

    private function triage(string $code, Branch $branch, Visit $visit): void
    {
        app(TriageService::class)->record($this->ca($code, $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $this->queue($visit)->lock_version,
            'expected_latest_triage_id' => null,
            // No triage guideline is approved until phase 6, so no zone is chosen yet.
            'expected_guideline_version_id' => null,
            'systolic_bp' => 124, 'diastolic_bp' => 82, 'pulse_bpm' => 88, 'temperature_celsius' => '37.80',
            'spo2_percent' => '97', 'weight_kg' => '58', 'height_cm' => '160',
            'red_flag_codes' => [], 'yellow_sign_codes' => [],
            'chosen_zone' => null,
            'override_reason_code' => null,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->count('triage');
    }

    // --- lookups ---------------------------------------------------------------------------------------------------

    private function patient(string $code, int $position): Patient
    {
        $index = array_search($code, array_keys(TrialDataContext::BRANCHES), true);
        $name = sprintf('Trial Patient %02d', (int) $index * PhaseOneFoundation::PATIENTS_PER_BRANCH + $position);

        return Patient::query()->where('organisation_id', $this->context->organisation->id)->where('full_name', $name)->firstOrFail();
    }

    private function ca(string $code, Branch $branch): User
    {
        return $this->context->as(TrialDataContext::key($code, 'ca1'), $branch);
    }

    private function user(string $key): User
    {
        return User::query()->where('email', TrialDataContext::email($key))->firstOrFail();
    }

    private function queue(Visit $visit): QueueEntry
    {
        return QueueEntry::query()->where('visit_id', $visit->id)->firstOrFail();
    }

    private function encounter(Visit $visit): ClinicalEncounter
    {
        return ClinicalEncounter::query()->where('visit_id', $visit->id)->firstOrFail();
    }

    private function case(Visit $visit): DispensaryCase
    {
        return DispensaryCase::query()->where('visit_id', $visit->id)->firstOrFail();
    }

    private function medicine(string $medicineCode): MedicineCatalogueItem
    {
        return MedicineCatalogueItem::query()->where('organisation_id', $this->context->organisation->id)->where('code', $medicineCode)->firstOrFail();
    }

    private function panel(int $index): Panel
    {
        return Panel::query()->where('organisation_id', $this->context->organisation->id)->where('code', PhaseOneFoundation::PANELS[$index]['code'])->firstOrFail();
    }

    private function method(string $methodCode): PaymentMethod
    {
        return PaymentMethod::query()->where('organisation_id', $this->context->organisation->id)->where('code', $methodCode)->firstOrFail();
    }

    private function room(Branch $branch, string $kind): BranchRoom
    {
        return BranchRoom::query()->where('branch_id', $branch->id)->where('kind', $kind)->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    }

    /** @return array{InventoryLocation, InventorySku, InventoryBatch} the branch dispensary, the SKU and its phase 1 batch */
    private function stock(string $code, string $medicineCode): array
    {
        $organisationId = $this->context->organisation->id;
        $mapping = MedicineCatalogueInventorySku::query()->where('organisation_id', $organisationId)
            ->where('medicine_catalogue_item_id', $this->medicine($medicineCode)->id)->where('is_active', true)->firstOrFail();
        $sku = InventorySku::query()->whereKey($mapping->inventory_sku_id)->firstOrFail();
        $location = InventoryLocation::query()->where('organisation_id', $organisationId)->where('code', PhaseOneFoundation::dispensaryCode($code))->firstOrFail();
        $batch = InventoryBatch::query()->where('organisation_id', $organisationId)->where('inventory_sku_id', $sku->id)
            ->where('batch_number', 'like', 'TRIAL-B%')->firstOrFail();

        return [$location, $sku, $batch];
    }

    private function due(Invoice $invoice): int
    {
        return (int) app(FinancialLedger::class)->state($invoice->refresh())['due_now'];
    }

    private function today(Branch $branch): CarbonImmutable
    {
        return CarbonImmutable::now($branch->timezone)->startOfDay();
    }

    private function count(string $kind, int $by = 1): void
    {
        $this->created[$kind] = ($this->created[$kind] ?? 0) + $by;
    }
}
