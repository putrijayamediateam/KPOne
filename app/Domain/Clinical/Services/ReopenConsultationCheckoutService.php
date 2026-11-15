<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReopenConsultationCheckoutService
{
    public function __construct(private TransactionalActorAuthority $authority, private CheckoutEvidenceService $evidence, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function reopen(User $actor, Visit $visit, array $attributes): void
    {
        $a = Validator::make($attributes, ['expected_branch_id' => ['required', 'integer'], 'checkout_lock_version' => ['required', 'integer', 'min:1'], 'visit_lock_version' => ['required', 'integer', 'min:1']])->validate();
        $branch = $this->authority->branch($actor, $a['expected_branch_id']);
        DB::transaction(function () use ($actor, $visit, $branch, $a): void {
            $actor = $this->authority->lock($actor, $branch, 'consultations.reopen.own');
            abort_unless($actor->hasRole('resident_doctor') && $visit->organisation_id === $actor->organisation_id && $visit->branch_id === $branch->id, 404);
            Patient::query()->whereKey($visit->patient_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $visit = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $encounter = $visit->clinicalEncounter()->lockForUpdate()->firstOrFail();
            abort_unless($encounter->attending_clinician_user_id === $actor->id && $visit->assigned_doctor_user_id === $actor->id, 404);
            $plan = TreatmentPlan::query()->where('clinical_encounter_id', $encounter->id)->lockForUpdate()->first();
            $checkout = ConsultationCheckout::query()->where('current_visit_guard', $visit->id)->lockForUpdate()->firstOrFail();
            $case = DispensaryCase::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
            if ($visit->status !== Visit::STATUS_REGISTERED || $visit->lock_version !== (int) $a['visit_lock_version'] || $checkout->lock_version !== (int) $a['checkout_lock_version']
                || $checkout->route !== 'billing' || $queue->status !== QueueEntry::STATUS_REMOVED || $queue->removal_reason !== 'sent_to_billing'
                || $checkout->plan_version !== $plan?->lock_version || $checkout->encounter_version !== $encounter->lock_version
                || ($case && $case->status !== DispensaryCase::STATUS_RETURNED)) {
                throw ValidationException::withMessages(['checkout' => 'This checkout cannot be reopened. Reload the current workflow.']);
            }
            if (Schema::hasTable('invoices')) {
                $invoices = DB::table('invoices')->where('visit_id', $visit->id)->orderBy('id')->lockForUpdate()->get();
                if ($invoices->contains(fn ($i): bool => $i->status !== 'draft') || DB::table('payment_allocations')->whereIn('invoice_id', $invoices->pluck('id'))->exists()
                    || DB::table('coverage_allocations')->whereIn('invoice_id', $invoices->pluck('id'))->exists() || DB::table('patient_receivables')->whereIn('invoice_id', $invoices->pluck('id'))->exists()) {
                    throw ValidationException::withMessages(['checkout' => 'Financial finalization or allocation prevents clinical reopening.']);
                }
                DB::table('invoices')->whereIn('id', $invoices->pluck('id'))->update(['source_stale' => true, 'lock_version' => DB::raw('lock_version + 1')]);
            }
            $this->evidence->supersede($checkout);
            $queue->forceFill(['status' => QueueEntry::STATUS_SERVING, 'removed_at' => null, 'removal_reason' => null, 'updated_by_user_id' => $actor->id, 'lock_version' => $queue->lock_version + 1])->save();
            app(ConsultationHoldService::class)->holdReturningConsultation($actor, $branch, $visit, $queue, $encounter);
            $this->audit->record('consultation.checkout_reopened', $checkout, ['record_version' => $checkout->lock_version], $actor, $branch);
        }, 3);
    }
}
