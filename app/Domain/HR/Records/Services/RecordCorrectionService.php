<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\HR\Records\Models\PersonalDetails;
use App\Domain\HR\Records\Models\RecordCorrection;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-4a corrections (HR-PLAN 11.3 rule 6). A staff member asks to correct a field they cannot change themselves; a
 * manager who is not the person applies or rejects it. An applied bank correction becomes a waiting bank change, so a
 * third person confirms it (rule 4). The new value and the note are encrypted and never audited.
 */
final class RecordCorrectionService
{
    public const KINDS = ['legal_name' => 'Legal name', 'identity' => 'Identity number', 'date_of_birth' => 'Date of birth', 'nationality' => 'Nationality', 'bank' => 'Bank details'];

    public const REASONS = ['wrong' => 'Wrong in KPOne', 'changed' => 'Changed', 'other' => 'Other'];

    public const REJECT_REASONS = ['not_confirmed' => 'Could not confirm it', 'wrong_value' => 'The new value is wrong', 'other' => 'Other'];

    public function __construct(private RecordSupport $support, private StaffFileService $files, private BankDetailService $banks) {}

    /** @param array<string, mixed> $input */
    public function ask(User $actor, array $input): RecordCorrection
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $kind = $input['kind'] ?? null;
        if (! is_string($kind) || ! array_key_exists($kind, self::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose what to correct.']);
        }
        $value = $this->value($kind, $input);
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }
        $note = $this->support->note($input['note'] ?? null);
        if ($reason === 'other' && $note === null) {
            throw ValidationException::withMessages(['note' => 'Add a short note for Other.']);
        }

        return $this->transaction(function () use ($actor, $key, $kind, $value, $reason, $note): RecordCorrection {
            $actor = $this->support->lock($actor, RecordPermissions::VIEW_OWN);
            $hash = $this->support->hash([$actor->id, 'correction', $kind, $value, $reason, $note]);
            if (($replay = $this->support->replay($actor, $key, 'records_correction', $hash)) !== null) {
                return RecordCorrection::query()->where('public_id', $replay)->firstOrFail();
            }
            $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($worker === null) {
                throw ValidationException::withMessages(['records' => 'HR has not opened your staff file yet.']);
            }
            if (RecordCorrection::query()->where('worker_id', $worker->id)->where('kind', $kind)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['kind' => 'You already asked for this correction. Withdraw it first to change it.']);
            }
            $correction = new RecordCorrection;
            $correction->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id, 'kind' => $kind,
                'new_value_encrypted' => $value, 'reason_code' => $reason, 'note_encrypted' => $note, 'status' => 'pending', 'asked_by_user_id' => $actor->id,
                'lock_version' => 1])->save();
            $this->support->change($actor, $worker, 'correction', [$kind], $reason);
            $this->support->remember($actor, $key, 'records_correction', $hash, $correction->public_id);
            $this->support->audit->record('hr.records.correction_asked', $worker, ['correction_id' => $correction->id, 'kind' => $kind, 'reason_code' => $reason],
                $actor, null, $actor->organisation_id);

            return $correction;
        });
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, string $workerId, string $correctionId, array $input): RecordCorrection
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $decision = $input['decision'] ?? null;
        if (! in_array($decision, ['apply', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose apply or reject.']);
        }
        $reason = $input['reason'] ?? null;
        if ($decision === 'reject' && (! is_string($reason) || ! array_key_exists($reason, self::REJECT_REASONS))) {
            throw ValidationException::withMessages(['reason' => 'Choose why it is rejected.']);
        }
        $reason = $decision === 'reject' ? $reason : null;

        return $this->transaction(function () use ($actor, $workerId, $correctionId, $input, $key, $decision, $reason): RecordCorrection {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $hash = $this->support->hash([$actor->id, 'correction_decide', $correctionId, $decision, $reason]);
            if ($this->support->replay($actor, $key, 'records_correction_decide', $hash) !== null) {
                return RecordCorrection::query()->where('public_id', $correctionId)->firstOrFail();
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);
            $correction = $this->pending($worker, $correctionId);
            $this->support->assertLock($correction, $input);
            if ($decision === 'apply') {
                $value = $correction->new_value_encrypted;
                if ($correction->kind === 'bank') {
                    $this->banks->enterFor($actor, $worker, null, ['bank_name' => (string) $value['bank_name'], 'holder' => (string) $value['holder'], 'account' => (string) $value['account']], 'records_bank');
                } else {
                    $details = PersonalDetails::query()->where('worker_id', $worker->id)->lockForUpdate()->first();
                    $values = [$correction->kind => $correction->kind === 'identity'
                        ? ['kind' => (string) $value['kind'], 'number' => (string) $value['number'], 'country' => isset($value['country']) ? (string) $value['country'] : null]
                        : (string) $value['value']];
                    [, $fields] = $this->files->persistPersonal($actor, $worker, $details, $values);
                    $this->support->change($actor, $worker, 'personal', $fields, 'correction');
                }
            }
            $correction->forceFill(['status' => $decision === 'apply' ? 'applied' : 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'decision_reason' => $reason, 'lock_version' => $correction->lock_version + 1])->save();
            $this->support->change($actor, $worker, 'correction', [$decision === 'apply' ? 'applied' : 'rejected'], $reason);
            $this->support->remember($actor, $key, 'records_correction_decide', $hash, $correction->public_id);
            $this->support->audit->record($decision === 'apply' ? 'hr.records.correction_applied' : 'hr.records.correction_rejected', $worker,
                ['correction_id' => $correction->id, 'kind' => $correction->kind, 'reason_code' => $reason], $actor, null, $actor->organisation_id);

            return $correction;
        });
    }

    /**
     * Only the person who asked withdraws a waiting correction.
     *
     * @param  array<string, mixed>  $input
     */
    public function withdraw(User $actor, string $correctionId, array $input): RecordCorrection
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $correctionId, $input, $key): RecordCorrection {
            $actor = $this->support->lock($actor, RecordPermissions::VIEW_OWN);
            $hash = $this->support->hash([$actor->id, 'correction_withdraw', $correctionId]);
            if ($this->support->replay($actor, $key, 'records_correction_withdraw', $hash) !== null) {
                return RecordCorrection::query()->where('public_id', $correctionId)->firstOrFail();
            }
            $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_if($worker === null, 404);
            $correction = $this->pending($worker, $correctionId);
            $this->support->assertLock($correction, $input);
            if ($correction->asked_by_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who asked may withdraw this.');
            }
            $correction->forceFill(['status' => 'withdrawn', 'lock_version' => $correction->lock_version + 1])->save();
            $this->support->change($actor, $worker, 'correction', ['withdrawn']);
            $this->support->remember($actor, $key, 'records_correction_withdraw', $hash, $correction->public_id);
            $this->support->audit->record('hr.records.correction_withdrawn', $worker, ['correction_id' => $correction->id, 'kind' => $correction->kind],
                $actor, null, $actor->organisation_id);

            return $correction;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function value(string $kind, array $input): array
    {
        return match ($kind) {
            'legal_name', 'nationality' => ['value' => $this->text($input['value'] ?? null, $kind === 'legal_name' ? 150 : 60)],
            'date_of_birth' => ['value' => $this->files->dateOfBirth($input['value'] ?? null)],
            'identity' => $this->files->identity($input['identity_kind'] ?? null, $input['identity_number'] ?? null, $input['passport_country'] ?? null)
                ?? throw ValidationException::withMessages(['identity_kind' => 'Choose IC or passport.']),
            default => BankDetailService::values($input),
        };
    }

    private function text(mixed $value, int $max): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            throw ValidationException::withMessages(['value' => 'Enter the correct value.']);
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['value' => "Use at most {$max} characters."]);
        }

        return $text;
    }

    private function pending(Worker $worker, string $correctionId): RecordCorrection
    {
        if (! Str::isUuid($correctionId)) {
            abort(404);
        }
        $correction = RecordCorrection::query()->where('worker_id', $worker->id)->where('public_id', $correctionId)->lockForUpdate()->firstOrFail();
        if ($correction->status !== 'pending') {
            throw ValidationException::withMessages(['correction' => 'This correction was already decided. Reload to see the latest version.']);
        }

        return $correction;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction(fn () => $callback(), 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['records' => 'This already exists. Reload the page.']);
            }
            throw $e;
        }
    }
}
