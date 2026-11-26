<?php

namespace App\Domain\Clinical\TreatmentRoom;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecord;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecordVoid;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Queue\Treatment\TreatmentStepService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TreatmentRoomService
{
    public function __construct(private TransactionalActorAuthority $authority, private TreatmentStepService $steps, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $input
     * @return array{id:int,state:string,lockVersion:int}
     */
    public function start(User $actor, Visit $visit, array $input): array
    {
        $data = $this->validate($input, 'start');

        return $this->context($actor, $visit, $data, function ($actor, $visit, $queue) use ($data) {
            return $this->steps->startTreatmentRoomLocked($actor, $queue, $data['procedure_code'], $data['idempotency_key'], ['visit_id' => $visit->id, ...Arr::except($data, ['idempotency_key'])]);
        });
    }

    /** @param array<string,mixed> $input */
    public function recordOutcome(User $actor, Visit $visit, array $input): TreatmentRoomRecord
    {
        $record = $this->write($actor, $visit, $input, 'record');
        if (! $record instanceof TreatmentRoomRecord) {
            throw new \LogicException('Outcome command returned invalid evidence.');
        }

        return $record;
    }

    /** @param array<string,mixed> $input */
    public function correctOutcome(User $actor, Visit $visit, array $input): TreatmentRoomRecord
    {
        $record = $this->write($actor, $visit, $input, 'correct');
        if (! $record instanceof TreatmentRoomRecord) {
            throw new \LogicException('Correction command returned invalid evidence.');
        }

        return $record;
    }

    /** @param array<string,mixed> $input */
    public function voidWrongPatient(User $actor, Visit $visit, array $input): TreatmentRoomRecordVoid
    {
        $record = $this->write($actor, $visit, $input, 'void');
        if (! $record instanceof TreatmentRoomRecordVoid) {
            throw new \LogicException('Void command returned invalid evidence.');
        }

        return $record;
    }

    /** @param array<string,mixed> $input */
    private function write(User $actor, Visit $visit, array $input, string $operation): TreatmentRoomRecord|TreatmentRoomRecordVoid
    {
        $data = $this->validate($input, $operation);
        $fingerprint = hash('sha256', json_encode(['actor' => $actor->id, 'visit' => $visit->id, 'operation' => $operation, ...Arr::except($data, ['idempotency_key'])], JSON_THROW_ON_ERROR));

        return $this->context($actor, $visit, $data, function ($actor, $visit, $queue, $lockedSteps, $branch) use ($data, $operation, $fingerprint) {
            $model = $operation === 'void' ? TreatmentRoomRecordVoid::class : TreatmentRoomRecord::class;
            $prior = $model::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($prior) {
                if (! hash_equals($prior->payload_fingerprint, $fingerprint)) {
                    $this->refuse('This request key was already used for a different treatment action.');
                }

                return $prior;
            }
            $this->assertCurrent($visit, $queue, $data);
            $target = null;
            if ($operation !== 'record') {
                $target = TreatmentRoomRecord::query()->whereKey($data['target_record_id'])->where('organisation_id', $actor->organisation_id)->where('visit_id', $visit->id)->where('queue_entry_id', $queue->id)->lockForUpdate()->firstOrFail();
                if (TreatmentRoomRecord::query()->where('supersedes_record_id', $target->id)->exists()
                    || TreatmentRoomRecordVoid::query()->where('target_record_id', $target->id)->exists()) {
                    $this->refuse('This treatment record has already been corrected or voided. Reload its history.');
                }
            }
            $base = ['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'visit_id' => $visit->id, 'queue_entry_id' => $queue->id, 'idempotency_key' => $data['idempotency_key'], 'payload_fingerprint' => $fingerprint];
            if ($operation === 'void') {
                $record = new TreatmentRoomRecordVoid;
                $record->forceFill([...$base, 'target_record_id' => $target->id, 'reason_code' => 'wrong_patient', 'voided_by_user_id' => $actor->id, 'voided_at' => now()->utc()])->save();
                $metadata = ['target_record_id' => $target->id, 'reason_code' => 'wrong_patient'];
                $event = 'treatment_room.voided';
            } else {
                if ($target) {
                    if (($data['correction_code'] === 'wrong_outcome' && $data['procedure_code'] !== $target->procedure_code)
                        || ($data['correction_code'] === 'wrong_procedure' && ($data['outcome_code'] !== $target->outcome_code || $data['reason_code'] !== $target->reason_code))) {
                        $this->refuse('Change only the fields described by the correction code.');
                    }
                    $evidence = ['treatment_step_id' => $target->treatment_step_id, 'started_at' => $target->started_at, 'finished_at' => $target->finished_at, 'supersedes_record_id' => $target->id, 'correction_code' => $data['correction_code']];
                } else {
                    $step = $lockedSteps->firstWhere('id', $data['treatment_step_id']);
                    if (! $step || $step->lock_version !== $data['step_lock_version']) {
                        $this->refuse('Treatment progress changed. Reload and review the latest step.');
                    }
                    if (TreatmentRoomRecord::query()->where('treatment_step_id', $step->id)->whereNull('supersedes_record_id')->exists()) {
                        $this->refuse('This treatment step already has an outcome. Use an explicit correction.');
                    }
                    if ($step->state === 'cancelled') {
                        // A cancelled step is immutable evidence of an error; use a new controlled step.
                        $replacement = $this->steps->startTreatmentRoomLocked($actor, $queue, $data['procedure_code'], (string) Str::uuid(), ['operation' => 'replacement', 'record_key' => $data['idempotency_key'], 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
                        $step = TreatmentStep::query()->findOrFail($replacement['id']);
                    }
                    if ($step->step_code !== $data['procedure_code']) {
                        $this->refuse('The procedure must match the linked treatment step.');
                    }
                    $step = $this->steps->finishTreatmentRoomLocked($actor, $queue, $step);
                    $evidence = ['treatment_step_id' => $step->id, 'started_at' => $step->started_at, 'finished_at' => $step->done_at, 'supersedes_record_id' => null, 'correction_code' => null];
                }
                $record = new TreatmentRoomRecord;
                $record->forceFill([...$base, ...$evidence, 'procedure_code' => $data['procedure_code'], 'outcome_code' => $data['outcome_code'], 'reason_code' => $data['reason_code'], 'recorded_by_user_id' => $actor->id, 'recorded_at' => now()->utc()])->save();
                $metadata = ['procedure_code' => $record->procedure_code, 'outcome_code' => $record->outcome_code, 'reason_code' => $record->reason_code, 'treatment_step_id' => $record->treatment_step_id, 'supersedes_record_id' => $record->supersedes_record_id, 'correction_code' => $record->correction_code];
                $event = $target ? 'treatment_room.superseded' : 'treatment_room.recorded';
            }
            abort_if($this->audit->record($event, $record, ['visit_id' => $visit->id, 'queue_entry_id' => $queue->id, ...$metadata], $actor, $branch, $actor->organisation_id) === null, 503, 'Unable to record treatment audit. Retry the same request.');

            return $record->refresh();
        });
    }

    /** All commands share actor/branch and lock order, including replay authorization.
     * @param  array<string,mixed>  $data
     */
    private function context(User $actor, Visit $visit, array $data, Closure $command): mixed
    {
        $branch = $this->authority->branch($actor, $data['expected_branch_id']);
        $snapshot = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->with('clinicalEncounter')->firstOrFail();
        $perform = function () use ($actor, $snapshot, $branch, $command) {
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $snapshot->assigned_doctor_user_id, $snapshot->clinicalEncounter?->attending_clinician_user_id]);
            $actor = $this->authority->lock($actor, $branch, TreatmentRoomPermissions::RECORD_BRANCH);
            $visit = Visit::query()->whereKey($snapshot->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
            if ($visit->assigned_doctor_user_id !== $snapshot->assigned_doctor_user_id || $encounter?->attending_clinician_user_id !== $snapshot->clinicalEncounter?->attending_clinician_user_id) {
                $this->refuse('The consultation assignment changed. Reload before recording treatment.');
            }
            $queue->setRelation('visit', $visit);
            $steps = TreatmentStep::query()->where('queue_entry_id', $queue->id)->orderBy('id')->lockForUpdate()->get();

            return $command($actor, $visit, $queue, $steps, $branch);
        };
        try {
            return DB::transaction($perform, 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request-key collision is re-read through the same fresh authority/fingerprint rules.
            return DB::transaction($perform, 3);
        }
    }

    /** @param array<string,mixed> $data */
    private function assertCurrent(Visit $visit, QueueEntry $queue, array $data): void
    {
        if ($visit->visit_type !== 'consultation' || $visit->status !== Visit::STATUS_REGISTERED || $queue->status !== QueueEntry::STATUS_SERVING) {
            $this->refuse('Treatment records and corrections require a consultation that is Serving or On Hold.');
        }
        if ($visit->lock_version !== $data['visit_lock_version'] || $queue->lock_version !== $data['queue_lock_version']) {
            $this->refuse('The visit or queue changed. Reload before recording treatment.');
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, string $operation): array
    {
        $rules = ['expected_branch_id' => ['required', 'integer', 'min:1'], 'visit_lock_version' => ['required', 'integer', 'min:1'], 'queue_lock_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']];
        if ($operation !== 'void') {
            $rules['procedure_code'] = ['required', Rule::in(array_keys(TreatmentRoomVocabulary::PROCEDURES))];
        }
        if (in_array($operation, ['record', 'correct'], true)) {
            $rules['outcome_code'] = ['required', Rule::in(['done', 'not_successful'])];
            $rules['reason_code'] = [Rule::requiredIf(($input['outcome_code'] ?? '') === 'not_successful'), Rule::prohibitedIf(($input['outcome_code'] ?? '') === 'done'), 'nullable', Rule::in(array_keys(TreatmentRoomVocabulary::REASONS))];
        }
        if ($operation === 'record') {
            $rules['treatment_step_id'] = ['required', 'integer', 'min:1'];
            $rules['step_lock_version'] = ['required', 'integer', 'min:1'];
        }
        if (in_array($operation, ['correct', 'void'], true)) {
            $rules['target_record_id'] = ['required', 'integer', 'min:1'];
            $rules[$operation === 'void' ? 'reason_code' : 'correction_code'] = ['required', Rule::in($operation === 'void' ? ['wrong_patient'] : ['wrong_procedure', 'wrong_outcome'])];
        }
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            $this->refuse('Only the approved treatment fields may be submitted.');
        }
        $data = Validator::make($input, $rules)->validate();
        if (in_array($operation, ['record', 'correct'], true)) {
            $data['reason_code'] ??= null;
        }
        foreach ($data as $key => $value) {
            if (str_ends_with($key, '_id') || str_ends_with($key, '_version')) {
                $data[$key] = (int) $value;
            }
        }
        ksort($data);

        return $data;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['treatment' => $message]);
    }
}
