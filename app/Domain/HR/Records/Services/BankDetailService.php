<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\HR\Records\Models\BankDetail;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-4a bank details (HR-PLAN 11.3 rule 4). A new or changed account is entered as waiting; someone other than the
 * person who entered it and other than the staff member confirms it, and only then does it replace the confirmed one.
 * A rejection is kept. Account numbers never reach audit or the change log.
 */
final class BankDetailService
{
    public const REJECT_REASONS = ['wrong_account' => 'Wrong account number', 'wrong_person' => 'Not this person\'s account', 'not_confirmed' => 'Could not confirm it', 'other' => 'Other'];

    public function __construct(private RecordSupport $support) {}

    /** @param array<string, mixed> $input */
    public function enter(User $actor, string $workerId, array $input): BankDetail
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $values = self::values($input);

        return $this->transaction(function () use ($actor, $workerId, $key, $values): BankDetail {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);

            return $this->enterFor($actor, $worker, $key, $values, 'records_bank');
        });
    }

    /**
     * Shared with an applied bank correction: the applier becomes the person who entered it, so a third person confirms.
     *
     * @param  array{bank_name: string, holder: string, account: string}  $values
     */
    public function enterFor(User $actor, Worker $worker, ?string $key, array $values, string $action): BankDetail
    {
        $hash = $this->support->hash([$actor->id, $action, $worker->public_id, $values['bank_name'], $values['holder'], $values['account']]);
        if ($key !== null && ($replay = $this->support->replay($actor, $key, $action, $hash)) !== null) {
            return BankDetail::query()->where('worker_id', $worker->id)->where('public_id', $replay)->firstOrFail();
        }
        if ($worker->kind === 'locum') {
            throw ValidationException::withMessages(['bank' => 'A locum file holds a name, a contact phone and employment periods only.']);
        }
        if (BankDetail::query()->where('worker_id', $worker->id)->where('status', 'waiting')->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['bank' => 'A bank change is already waiting. Confirm, reject or withdraw it first.']);
        }
        $bank = new BankDetail;
        $bank->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'bank_name_encrypted' => $values['bank_name'], 'holder_encrypted' => $values['holder'], 'account_encrypted' => $values['account'],
            'account_last4' => RecordSupport::last4($values['account']), 'status' => 'waiting', 'entered_by_user_id' => $actor->id, 'lock_version' => 1])->save();
        $this->support->change($actor, $worker, 'bank', ['bank_name', 'holder', 'account']);
        if ($key !== null) {
            $this->support->remember($actor, $key, $action, $hash, $bank->public_id);
        }
        $this->support->audit->record('hr.records.bank_entered', $worker, ['bank_id' => $bank->id], $actor, null, $actor->organisation_id);

        return $bank;
    }

    /**
     * Confirm (the earlier confirmed account becomes replaced) or reject with a reason.
     *
     * @param  array<string, mixed>  $input
     */
    public function decide(User $actor, string $workerId, string $bankId, array $input): BankDetail
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $decision = $input['decision'] ?? null;
        if (! in_array($decision, ['confirm', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose confirm or reject.']);
        }
        $reason = $input['reason'] ?? null;
        if ($decision === 'reject' && (! is_string($reason) || ! array_key_exists($reason, self::REJECT_REASONS))) {
            throw ValidationException::withMessages(['reason' => 'Choose why it is rejected.']);
        }
        $reason = $decision === 'reject' ? $reason : null;

        return $this->transaction(function () use ($actor, $workerId, $bankId, $input, $key, $decision, $reason): BankDetail {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $hash = $this->support->hash([$actor->id, 'bank_decide', $bankId, $decision, $reason]);
            if ($this->support->replay($actor, $key, 'records_bank_decide', $hash) !== null) {
                return BankDetail::query()->where('public_id', $bankId)->firstOrFail();
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);
            $bank = $this->waiting($worker, $bankId);
            $this->support->assertLock($bank, $input);
            if ($bank->entered_by_user_id === $actor->id) {
                throw new AuthorizationException('Someone else confirms this. You entered it.');
            }
            // The confirmer must have looked at the full new account (an audited Show), not only its last four (Suresh, N1).
            if ($decision === 'confirm' && ! DB::table('audit_logs')->where('event', 'hr.records.value_shown')->where('actor_user_id', $actor->id)
                ->where('organisation_id', $actor->organisation_id)->whereRaw("(metadata::jsonb ->> 'bank_id') = ?", [(string) $bank->id])->exists()) {
                throw ValidationException::withMessages(['bank' => 'Show the new account number to check it before you confirm.']);
            }
            $now = now()->utc();
            if ($decision === 'confirm') {
                BankDetail::query()->where('worker_id', $worker->id)->where('status', 'confirmed')->lockForUpdate()->get()
                    ->each(fn (BankDetail $old) => $old->forceFill(['status' => 'replaced', 'lock_version' => $old->lock_version + 1])->save());
            }
            $bank->forceFill(['status' => $decision === 'confirm' ? 'confirmed' : 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => $now,
                'decision_reason' => $reason, 'lock_version' => $bank->lock_version + 1])->save();
            $this->support->change($actor, $worker, 'bank', [$decision === 'confirm' ? 'confirmed' : 'rejected'], $reason);
            $this->support->remember($actor, $key, 'records_bank_decide', $hash, $bank->public_id);
            $this->support->audit->record($decision === 'confirm' ? 'hr.records.bank_confirmed' : 'hr.records.bank_rejected', $worker,
                ['bank_id' => $bank->id, 'reason_code' => $reason], $actor, null, $actor->organisation_id);

            return $bank;
        });
    }

    /**
     * Only the person who entered a waiting change withdraws it.
     *
     * @param  array<string, mixed>  $input
     */
    public function withdraw(User $actor, string $workerId, string $bankId, array $input): BankDetail
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $workerId, $bankId, $input, $key): BankDetail {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $hash = $this->support->hash([$actor->id, 'bank_withdraw', $bankId]);
            if ($this->support->replay($actor, $key, 'records_bank_withdraw', $hash) !== null) {
                return BankDetail::query()->where('public_id', $bankId)->firstOrFail();
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);
            $bank = $this->waiting($worker, $bankId);
            $this->support->assertLock($bank, $input);
            if ($bank->entered_by_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who entered this change may withdraw it.');
            }
            $bank->forceFill(['status' => 'withdrawn', 'lock_version' => $bank->lock_version + 1])->save();
            $this->support->change($actor, $worker, 'bank', ['withdrawn']);
            $this->support->remember($actor, $key, 'records_bank_withdraw', $hash, $bank->public_id);
            $this->support->audit->record('hr.records.bank_withdrawn', $worker, ['bank_id' => $bank->id], $actor, null, $actor->organisation_id);

            return $bank;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{bank_name: string, holder: string, account: string}
     */
    public static function values(array $input): array
    {
        $bank = is_string($input['bank_name'] ?? null) ? trim($input['bank_name']) : '';
        if (mb_strlen($bank) < 2 || mb_strlen($bank) > 80) {
            throw ValidationException::withMessages(['bank_name' => 'Enter the bank name.']);
        }
        $holder = is_string($input['holder'] ?? null) ? trim($input['holder']) : '';
        if (mb_strlen($holder) < 2 || mb_strlen($holder) > 120) {
            throw ValidationException::withMessages(['holder' => 'Enter the account holder\'s name.']);
        }
        $account = is_string($input['account'] ?? null) ? preg_replace('/[\s-]/', '', $input['account']) : null;
        if (! is_string($account) || preg_match('/\A\d{5,20}\z/', $account) !== 1) {
            throw ValidationException::withMessages(['account' => 'Enter the account number (5 to 20 digits).']);
        }

        return ['bank_name' => $bank, 'holder' => $holder, 'account' => $account];
    }

    private function waiting(Worker $worker, string $bankId): BankDetail
    {
        if (! Str::isUuid($bankId)) {
            abort(404);
        }
        $bank = BankDetail::query()->where('worker_id', $worker->id)->where('public_id', $bankId)->lockForUpdate()->firstOrFail();
        if ($bank->status !== 'waiting') {
            throw ValidationException::withMessages(['bank' => 'This bank change was already decided. Reload to see the latest version.']);
        }

        return $bank;
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
                throw ValidationException::withMessages(['bank' => 'A bank change is already waiting. Reload the page.']);
            }
            throw $e;
        }
    }
}
