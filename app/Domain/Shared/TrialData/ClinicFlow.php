<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitReasonService;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA: the visit steps the phases share (register, queue, call in, consult, bill, complete), each through the
 * existing service as the fictional account that would do it. Needs $this->context.
 */
trait ClinicFlow
{
    /** @var array<string, int> */
    private array $created = [];

    private function register(string $code, Branch $branch, Patient $patient, ?string $doctor, ?Panel $panel, int $reason, bool $urgent = false, bool $repeat = false): Visit
    {
        $reasons = $doctor === null ? [] : [app(VisitReasonService::class)->create($this->ca($code, $branch), PhaseOneFoundation::VISIT_REASONS[$reason % count(PhaseOneFoundation::VISIT_REASONS)])->public_id];

        return app(VisitRegistrationService::class)->register($this->ca($code, $branch), [
            'idempotency_key' => (string) Str::uuid(),
            'expected_branch_id' => $branch->id,
            'patient_number' => $patient->patient_number,
            'visit_type' => $doctor === null ? 'otc' : 'consultation',
            'assigned_doctor_user_id' => $doctor === null ? null : $this->user(TrialDataContext::key($code, $doctor))->id,
            'visit_reason_public_ids' => $reasons,
            'priority' => $urgent ? 'urgent' : 'normal',
            'coverage_type' => $panel !== null ? 'panel' : 'self_pay',
            'panel_id' => $panel?->id,
            'coverage_member_reference' => $panel !== null ? $this->memberReference($patient) : null,
            'confirm_repeat' => $repeat,
        ]);
    }

    private function memberReference(Patient $patient): string
    {
        return 'TRIAL-MEMBER-'.str_pad((string) $patient->id, 4, '0', STR_PAD_LEFT);
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

    private function bill(string $code, Branch $branch, Visit $visit): Invoice
    {
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($this->ca($code, $branch), $visit->refresh(), ['expected_branch_id' => $branch->id, 'lock_version' => null]);

        return $builder->finalize($this->ca($code, $branch), $visit->refresh(), $invoice, ['expected_branch_id' => $branch->id, 'lock_version' => $invoice->lock_version]);
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

    private function panel(int $index): Panel
    {
        return Panel::query()->where('organisation_id', $this->context->organisation->id)->where('code', PhaseOneFoundation::PANELS[$index]['code'])->firstOrFail();
    }

    private function method(string $methodCode): PaymentMethod
    {
        return PaymentMethod::query()->where('organisation_id', $this->context->organisation->id)->where('code', $methodCode)->firstOrFail();
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
