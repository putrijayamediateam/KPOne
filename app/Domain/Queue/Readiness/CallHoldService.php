<?php

namespace App\Domain\Queue\Readiness;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\SystemEvent;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CallHoldService
{
    public const REASONS = [
        'awaiting_panel_confirmation' => 'Awaiting Panel confirmation',
        'awaiting_patient_details' => 'Awaiting patient details',
        'awaiting_payment_or_deposit' => 'Awaiting payment or deposit',
        'other_administrative' => 'Other administrative',
    ];

    public const OVERRIDES = [
        'patient_is_urgent' => 'Patient is urgent',
        'issue_resolved_verbally' => 'Issue resolved verbally',
        'patient_at_door' => 'Patient is at the door',
    ];

    /** @param array<string,mixed> $input
     * @return array{id:int,state:string,lockVersion:int}
     */
    public function change(User $actor, Visit $visit, array $input, bool $clear): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        if (! $actor || ! $actor->is_active || ! ($clear ? ($actor->can(CallHoldPermissions::SET) || $actor->can(CallHoldPermissions::CLEAR)) : $actor->can(CallHoldPermissions::SET))) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'queue_lock_version' => ['required', 'integer', 'min:1'],
            'call_hold_id' => [$clear ? 'required' : 'prohibited', 'integer', 'min:1'],
            'lock_version' => [$clear ? 'required' : 'nullable', 'integer', 'min:1'],
            'reason_code' => [$clear ? 'prohibited' : 'required', Rule::in(array_keys(self::REASONS))],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $authority = app(TransactionalActorAuthority::class);
        $branch = $authority->branch($actor, $data['expected_branch_id']);
        $entry = QueueEntry::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('visit_id', $visit->id)->firstOrFail();
        Gate::forUser($actor)->authorize($clear ? 'clearReadiness' : 'setReadiness', $entry);
        $payload = ['action' => $clear ? 'clear' : 'set', 'entry' => $entry->id, 'queue_version' => (int) $data['queue_lock_version'], 'flag' => $data['call_hold_id'] ?? null, 'version' => $data['lock_version'] ?? null, 'reason' => $data['reason_code'] ?? null];

        return DB::transaction(function () use ($actor, $visit, $entry, $branch, $authority, $data, $payload, $clear): array {
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $visit->assigned_doctor_user_id]);
            $permission = $clear && $actor->fresh()->load(['roles.permissions', 'permissions'])->can(CallHoldPermissions::CLEAR) ? CallHoldPermissions::CLEAR : CallHoldPermissions::SET;
            $actor = $authority->lock($actor, $branch, $permission);
            $originalDoctorId = $visit->assigned_doctor_user_id;
            $visit = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $entry = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($clear ? 'clearReadiness' : 'setReadiness', $entry);
            if ($retry = $this->retry($actor, $data['idempotency_key'], $payload)) {
                if (! is_int($retry['id'] ?? null) || ! is_string($retry['state'] ?? null) || ! is_int($retry['lockVersion'] ?? null)) {
                    abort(503, 'The retry record is invalid.');
                }

                return ['id' => $retry['id'], 'state' => $retry['state'], 'lockVersion' => $retry['lockVersion']];
            }
            if ($visit->assigned_doctor_user_id !== $originalDoctorId || $entry->lock_version !== (int) $data['queue_lock_version'] || $entry->status !== 'waiting' || $visit->status !== 'registered' || $visit->visit_type !== 'consultation') {
                $this->refuse('The queue state has changed. Reload the page.');
            }
            $flag = CallHold::query()->where('queue_entry_id', $entry->id)->whereNull('closed_at')->lockForUpdate()->first();
            if ($clear) {
                if (! $flag || $flag->id !== (int) $data['call_hold_id'] || $flag->lock_version !== (int) $data['lock_version']) {
                    $this->refuse('The flag has changed. Reload the page.');
                }
                $this->finish($flag, 'cleared', $actor, $visit->assigned_doctor_user_id);
            } else {
                if ($flag) {
                    $this->refuse('This queue entry is already marked not ready to call.');
                }
                $flag = new CallHold;
                $flag->forceFill(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'queue_entry_id' => $entry->id, 'set_by_user_id' => $actor->id, 'reason_code' => $data['reason_code'], 'state' => 'active', 'lock_version' => 1])->save();
                $this->event($flag, 'set', $actor->id, null);
                $this->audit($flag, 'set', $actor);
            }
            $result = ['id' => $flag->id, 'state' => $flag->state, 'lockVersion' => $flag->lock_version];
            $this->remember($actor, $data['idempotency_key'], $payload, $result);

            return $result;
        }, 3);
    }

    /** Called only inside the existing Call In transaction, after fresh scope/authority locks.
     * @param  array<string,mixed>  $data
     */
    public function assertCall(User $actor, Visit $visit, QueueEntry $entry, array $data): ?CallHold
    {
        $flag = CallHold::query()->where('queue_entry_id', $entry->id)->whereNull('closed_at')->lockForUpdate()->first();
        if (! $flag) {
            if (isset($data['override_reason'])) {
                $this->refuse('There is no active flag to override.');
            }

            return null;
        }
        if (! isset($data['override_reason'])) {
            $this->refuse('Not ready to call: '.self::REASONS[$flag->reason_code].'.');
        }
        if ($flag->id !== (int) ($data['call_hold_id'] ?? 0) || $flag->lock_version !== (int) ($data['call_hold_lock_version'] ?? 0)) {
            $this->refuse('The flag has changed. Reload the page.');
        }
        abort_unless($actor->can(CallHoldPermissions::OVERRIDE) && $visit->assigned_doctor_user_id === $actor->id, 403);
        Validator::make($data, ['override_reason' => ['required', Rule::in(array_keys(self::OVERRIDES))], 'idempotency_key' => ['required', 'uuid']])->validate();

        return $flag;
    }

    /** @param array<string,mixed> $data */
    public function override(CallHold $flag, QueueCall $call, User $actor, array $data): void
    {
        // Called after Serving and its immutable QueueCall witness exist; suppress departure closure separately.
        DB::table('queue_call_hold_overrides')->insert(['call_hold_id' => $flag->id, 'queue_call_id' => $call->id, 'actor_user_id' => $actor->id, 'reason_code' => $data['override_reason'], 'occurred_at' => now()->utc()]);
        $this->event($flag, 'overridden', $actor->id, null);
        $this->system($flag);
        $this->audit($flag, 'overridden', $actor, ['override_reason_code' => $data['override_reason'], 'queue_call_id' => $call->id]);
    }

    public function markOverridden(CallHold $flag): void
    {
        $flag->forceFill(['state' => 'overridden', 'closed_at' => now()->utc(), 'lock_version' => $flag->lock_version + 1])->save();
    }

    /** Queue model hook: no-flag updates produce no evidence or audit changes. */
    public function leavingWaiting(QueueEntry $entry): void
    {
        if (! $entry->isDirty('status') || $entry->getOriginal('status') !== 'waiting' || $entry->status === 'waiting') {
            return;
        }
        $flag = CallHold::query()->where('queue_entry_id', $entry->id)->whereNull('closed_at')->lockForUpdate()->first();
        if ($flag) {
            abort_unless(DB::transactionLevel() > 0, 409);
            $actor = User::query()->findOrFail($entry->updated_by_user_id);
            $this->finish($flag, 'system_closed', $actor, null);
        }
    }

    private function finish(CallHold $flag, string $state, User $actor, ?int $doctorId): void
    {
        $flag->forceFill(['state' => $state, 'closed_at' => now()->utc(), 'lock_version' => $flag->lock_version + 1])->save();
        $this->event($flag, $state, $actor->id, $doctorId);
        if ($state === 'system_closed') {
            $this->system($flag);
        }
        $this->audit($flag, $state, $actor);
    }

    private function event(CallHold $flag, string $type, ?int $actorId, ?int $doctorId): void
    {
        DB::table('queue_call_hold_events')->insert(['call_hold_id' => $flag->id, 'event_type' => $type, 'actor_user_id' => $actorId, 'doctor_user_id' => $doctorId, 'record_version' => $flag->lock_version, 'occurred_at' => now()->utc()]);
    }

    private function system(CallHold $flag): void
    {
        SystemEvent::query()->create(['event_id' => (string) Str::uuid(), 'organisation_id' => $flag->organisation_id, 'branch_id' => $flag->branch_id, 'name' => 'queue.call_hold.system_closed', 'source' => 'queue', 'severity' => 'info', 'payload' => ['call_hold_id' => $flag->id, 'queue_entry_id' => $flag->queue_entry_id], 'occurred_at' => now()->utc()]);
    }

    /** @param array<string,int|string> $extra */
    private function audit(CallHold $flag, string $action, User $actor, array $extra = []): void
    {
        abort_if(app(AuditRecorder::class)->record('queue.call_hold.'.$action, $flag, ['queue_entry_id' => $flag->queue_entry_id, 'set_by_user_id' => $flag->set_by_user_id, 'action_by_user_id' => $actor->id, 'reason_code' => $flag->reason_code, 'record_version' => $flag->lock_version, ...$extra], $actor, Branch::query()->findOrFail($flag->branch_id), $flag->organisation_id) === null, 503, 'Unable to record the audit. Try again.');
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public function retry(User $actor, string $key, array $payload): ?array
    {
        $command = DB::table('queue_call_hold_commands')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $key)->first();
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
    public function remember(User $actor, string $key, array $payload, array $result): void
    {
        DB::table('queue_call_hold_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'retry_key' => $key, 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'occurred_at' => now()->utc()]);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['queue' => $message]);
    }
}
