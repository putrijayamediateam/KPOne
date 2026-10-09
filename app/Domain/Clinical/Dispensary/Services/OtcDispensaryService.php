<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DS-01b-1: opens the Dispensary case of an OTC visit. An OTC visit has no doctor, encounter,
 * treatment plan or queue entry; the CA who presses Dispense owns the case from the start.
 */
class OtcDispensaryService
{
    public function __construct(private DispensaryAuthorityService $authority, private AuditRecorder $audit) {}

    /**
     * Idempotent: pressing Dispense again on a visit whose case is still open returns that case.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function open(User $actor, Visit $visit, array $attributes): DispensaryCase
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $visit, $branch): DispensaryCase {
            $actor = $this->authority->lock($actor, $branch, 'dispensary.otc.create.branch');
            Patient::query()->whereKey($visit->patient_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $visit = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            if ($visit->visit_type !== 'otc' || $visit->status !== Visit::STATUS_REGISTERED) {
                throw ValidationException::withMessages(['visit' => 'Only a registered OTC visit can be dispensed here.']);
            }
            $case = DispensaryCase::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
            if ($case) {
                if ($case->status === DispensaryCase::STATUS_COMPLETED) {
                    throw ValidationException::withMessages(['visit' => 'This OTC visit has already been dispensed.']);
                }

                return $case;
            }
            $case = new DispensaryCase;
            $case->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'visit_id' => $visit->id,
                'clinical_encounter_id' => null, 'treatment_plan_id' => null, 'case_type' => DispensaryCase::TYPE_OTC,
                'status' => DispensaryCase::STATUS_DISPENSING, 'current_handler_user_id' => $actor->id,
                'lock_version' => 1, 'received_at' => now()->utc(), 'started_at' => now()->utc(),
            ])->save();
            $handoff = new DispensaryHandoff;
            $handoff->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'dispensary_case_id' => $case->id,
                'attempt_number' => 1, 'treatment_plan_lock_version_received' => null, 'status' => DispensaryHandoff::STATUS_OPEN, 'open_case_guard' => $case->id,
                'sent_by_user_id' => $actor->id, 'sent_at' => now()->utc(), 'started_by_user_id' => $actor->id, 'started_at' => now()->utc(),
            ])->save();
            $this->audit->record('dispensary.otc_opened', $case, ['record_version' => $case->lock_version], $actor, $branch);

            return $case->fresh(['handoffs.items']);
        }, 3);
    }
}
