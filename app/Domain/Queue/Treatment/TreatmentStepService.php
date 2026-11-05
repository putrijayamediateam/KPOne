<?php

namespace App\Domain\Queue\Treatment;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\SystemEvent;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Q-2 treatment progress: a controlled step on a Serving consultation (held or not), for the board only.
 * Never clinical text, billing evidence or a TV field. Lock order follows ConsultationLocks:
 * users (actor, attending doctor), then visit, queue entry and step.
 */
class TreatmentStepService
{
    public const STEPS = [
        'nebuliser' => 'Nebuliser',
        'wound_dressing' => 'Wound dressing',
        'injection_observation' => 'Injection and observation',
        'iv_drip' => 'IV drip',
        'observation_after_medicine' => 'Observation after medicine',
        'ecg' => 'ECG',
        'blood_test_poc' => 'Blood test (point-of-care)',
        'other_procedure' => 'Other procedure',
    ];

    public const CANCEL_REASONS = [
        'wrong_patient' => 'Wrong patient',
        'wrong_step' => 'Wrong step',
        'marked_too_early' => 'Marked too early',
    ];

    /** @param array<string,mixed> $input
     * @return array{id:int,state:string,lockVersion:int}
     */
    public function start(User $actor, Visit $visit, array $input): array
    {
        return $this->change($actor, $visit, $input, 'start');
    }

    /** @param array<string,mixed> $input
     * @return array{id:int,state:string,lockVersion:int}
     */
    public function complete(User $actor, Visit $visit, array $input): array
    {
        return $this->change($actor, $visit, $input, 'done');
    }

    /** @param array<string,mixed> $input
     * @return array{id:int,state:string,lockVersion:int}
     */
    public function cancel(User $actor, Visit $visit, array $input): array
    {
        return $this->change($actor, $visit, $input, 'cancel');
    }

    /** Called inside an existing transaction that already holds the doctor, visit and queue entry locks (Resume). */
    public function acknowledgeLocked(QueueEntry $entry, User $doctor): void
    {
        $step = TreatmentStep::query()->where('queue_entry_id', $entry->id)->whereNull('closed_at')->lockForUpdate()->first();
        if ($step && $step->state === TreatmentStep::STATE_DONE) {
            $this->transition($step, 'acknowledged', $doctor, null);
        }
    }

    /** The attending doctor opening the consultation clears "Ready to resume" (owner decision 2026-11-05). */
    public function acknowledgeOnOpen(User $actor, Visit $visit): void
    {
        if (! $actor->is_active || $visit->organisation_id !== $actor->organisation_id
            || $visit->assigned_doctor_user_id !== $actor->id || ! $actor->can('queue.view.own')) {
            return;
        }
        $entryId = QueueEntry::query()->where('organisation_id', $actor->organisation_id)->where('visit_id', $visit->id)->value('id');
        if (! $entryId || ! TreatmentStep::query()->where('queue_entry_id', $entryId)->whereNull('closed_at')->where('state', TreatmentStep::STATE_DONE)->exists()) {
            return;
        }
        DB::transaction(function () use ($actor, $visit): void {
            ConsultationLocks::users($actor->organisation_id, [$actor->id]);
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            if ($locked->assigned_doctor_user_id !== $actor->id) {
                return;
            }
            $entry = QueueEntry::query()->where('visit_id', $locked->id)->lockForUpdate()->firstOrFail();
            $this->acknowledgeLocked($entry, $actor);
        }, 3);
    }

    /** QueueEntry model hook: a step closes itself when its consultation leaves Serving. */
    public function leavingServing(QueueEntry $entry): void
    {
        if (! $entry->isDirty('status') || $entry->getOriginal('status') !== QueueEntry::STATUS_SERVING || $entry->status === QueueEntry::STATUS_SERVING) {
            return;
        }
        // A historical schema (some migration regression tests stop before Q-2) has no step table; nothing to close.
        if (! Schema::connection($entry->getConnectionName())->hasTable('queue_treatment_steps')) {
            return;
        }
        $step = TreatmentStep::query()->where('queue_entry_id', $entry->id)->whereNull('closed_at')->lockForUpdate()->first();
        if ($step) {
            abort_unless(DB::transactionLevel() > 0, 409);
            $this->transition($step, 'system_closed', User::query()->findOrFail($entry->updated_by_user_id), null);
            SystemEvent::query()->create(['event_id' => (string) Str::uuid(), 'organisation_id' => $step->organisation_id, 'branch_id' => $step->branch_id, 'name' => 'queue.treatment_step.system_closed', 'source' => 'queue', 'severity' => 'info', 'payload' => ['treatment_step_id' => $step->id, 'queue_entry_id' => $step->queue_entry_id], 'occurred_at' => now()->utc()]);
        }
    }

    /** @param array<string,mixed> $input
     * @return array{id:int,state:string,lockVersion:int}
     */
    private function change(User $actor, Visit $visit, array $input, string $action): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        if (! $actor || ! $actor->is_active || ! ($actor->can(TreatmentStepPermissions::SET_BRANCH) || $actor->can(TreatmentStepPermissions::SET_OWN))) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $start = $action === 'start';
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'],
            'step_code' => [$start ? 'required' : 'prohibited', Rule::in(array_keys(self::STEPS))],
            'treatment_step_id' => [$start ? 'prohibited' : 'required', 'integer', 'min:1'],
            'lock_version' => [$start ? 'prohibited' : 'required', 'integer', 'min:1'],
            'reason_code' => [$action === 'cancel' ? 'required' : 'prohibited', Rule::in(array_keys(self::CANCEL_REASONS))],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $authority = app(TransactionalActorAuthority::class);
        $branch = $authority->branch($actor, $data['expected_branch_id']);
        $entry = QueueEntry::query()->with('visit')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('visit_id', $visit->id)->firstOrFail();
        Gate::forUser($actor)->authorize('manageTreatment', $entry);
        $payload = ['action' => $action, 'entry' => $entry->id, 'code' => $data['step_code'] ?? null, 'step' => $data['treatment_step_id'] ?? null, 'version' => $data['lock_version'] ?? null, 'reason' => $data['reason_code'] ?? null];

        return DB::transaction(function () use ($actor, $visit, $entry, $branch, $authority, $data, $payload, $action): array {
            ConsultationLocks::users($actor->organisation_id, array_values(array_filter([$actor->id, $visit->assigned_doctor_user_id])));
            $permission = $actor->can(TreatmentStepPermissions::SET_BRANCH) ? TreatmentStepPermissions::SET_BRANCH : TreatmentStepPermissions::SET_OWN;
            $actor = $authority->lock($actor, $branch, $permission);
            $originalDoctorId = $visit->assigned_doctor_user_id;
            $visit = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $entry = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->setRelation('visit', $visit);
            Gate::forUser($actor)->authorize('manageTreatment', $entry);
            if ($retry = $this->retry($actor, $data['idempotency_key'], $payload)) {
                if (! is_int($retry['id'] ?? null) || ! is_string($retry['state'] ?? null) || ! is_int($retry['lockVersion'] ?? null)) {
                    abort(503, 'The retry record is invalid.');
                }

                return ['id' => $retry['id'], 'state' => $retry['state'], 'lockVersion' => $retry['lockVersion']];
            }
            if ($visit->assigned_doctor_user_id !== $originalDoctorId || $entry->status !== QueueEntry::STATUS_SERVING
                || $visit->status !== Visit::STATUS_REGISTERED || $visit->visit_type !== 'consultation') {
                $this->refuse('Treatment progress can only be updated while the consultation is in progress or on hold.');
            }
            $step = TreatmentStep::query()->where('queue_entry_id', $entry->id)->whereNull('closed_at')->lockForUpdate()->first();
            if ($action === 'start') {
                if ($step) {
                    $this->refuse('This patient already has an open treatment step. Mark it done or cancel it first.');
                }
                $now = now()->utc();
                $step = new TreatmentStep;
                $step->forceFill(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'queue_entry_id' => $entry->id, 'step_code' => $data['step_code'], 'state' => TreatmentStep::STATE_STARTED, 'started_by_user_id' => $actor->id, 'started_at' => $now, 'lock_version' => 1])->save();
                $this->event($step, 'started', $actor->id, null, null);
                $this->audit($step, 'started', $actor);
            } else {
                if (! $step || $step->id !== (int) $data['treatment_step_id'] || $step->lock_version !== (int) $data['lock_version']) {
                    $this->refuse("This patient's treatment progress was changed by someone else. Reload and try again.");
                }
                if ($action === 'done') {
                    if ($step->state !== TreatmentStep::STATE_STARTED) {
                        $this->refuse('This treatment step is already marked done.');
                    }
                    // The attending doctor is alerted, unless they marked it done themselves.
                    $doctorId = $visit->assigned_doctor_user_id !== $actor->id ? $visit->assigned_doctor_user_id : null;
                    $this->transition($step, 'done', $actor, null, $doctorId);
                } else {
                    $this->transition($step, 'cancelled', $actor, $data['reason_code']);
                }
            }
            $result = ['id' => $step->id, 'state' => $step->state, 'lockVersion' => $step->lock_version];
            $this->remember($actor, $data['idempotency_key'], $payload, $result);

            return $result;
        }, 3);
    }

    private function transition(TreatmentStep $step, string $state, User $actor, ?string $reason, ?int $doctorId = null): void
    {
        $now = now()->utc();
        $changes = ['state' => $state, 'lock_version' => $step->lock_version + 1];
        if ($state === 'done') {
            $changes += ['done_by_user_id' => $actor->id, 'done_at' => $now];
        } else {
            $changes += ['closed_at' => $now, 'cancel_reason_code' => $reason];
        }
        $step->forceFill($changes)->save();
        $this->event($step, $state, $actor->id, $doctorId, $reason);
        $this->audit($step, $state, $actor, $reason ? ['reason_code' => $reason] : []);
    }

    private function event(TreatmentStep $step, string $type, ?int $actorId, ?int $doctorId, ?string $reason): void
    {
        DB::table('queue_treatment_step_events')->insert(['treatment_step_id' => $step->id, 'event_type' => $type, 'actor_user_id' => $actorId, 'doctor_user_id' => $doctorId, 'reason_code' => $reason, 'record_version' => $step->lock_version, 'occurred_at' => now()->utc()]);
    }

    /** Structural audit: identifiers, the controlled step code and versions only; never patient or clinical text.
     * @param  array<string,string>  $extra
     */
    private function audit(TreatmentStep $step, string $action, User $actor, array $extra = []): void
    {
        abort_if(app(AuditRecorder::class)->record('queue.treatment_step.'.$action, $step, ['queue_entry_id' => $step->queue_entry_id, 'step_code' => $step->step_code, 'action_by_user_id' => $actor->id, 'record_version' => $step->lock_version, ...$extra], $actor, Branch::query()->findOrFail($step->branch_id), $step->organisation_id) === null, 503, 'Unable to record the audit. Try again.');
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function retry(User $actor, string $key, array $payload): ?array
    {
        $command = DB::table('queue_treatment_step_commands')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $key)->first();
        if (! $command) {
            return null;
        }
        if (! hash_equals($command->payload_hash, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))) {
            $this->refuse('The retry key has already been used for a different action.');
        }

        return json_decode($command->result, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $payload
     * @param  array<string,mixed>  $result
     */
    private function remember(User $actor, string $key, array $payload, array $result): void
    {
        DB::table('queue_treatment_step_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'retry_key' => $key, 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'occurred_at' => now()->utc()]);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['treatment' => $message]);
    }
}
