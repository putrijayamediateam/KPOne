<?php

namespace App\Domain\HR\LocumPay\Services;

use App\Domain\HR\LocumPay\LocumPayPermissions;
use App\Domain\HR\Payroll\Services\PayrollMoney;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-15g writes (owner, 18 Nov 2026). Every write takes a fresh permission check under lock, locks the locum file row (so
 * a mark, a correction or a rate decision and the month's payment are one after the other), checks the page's lock
 * version, is idempotent on its key, and audits ids and codes only, never an amount or a reference.
 */
final class LocumPayService
{
    public function __construct(private readonly RecordSupport $support) {}

    /** @param array<string, mixed> $input kind, amount, effective_from, idempotency_key */
    public function enterRate(User $actor, string $workerId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $kind = in_array($input['kind'] ?? null, ['hour', 'session'], true) ? (string) $input['kind']
            : throw ValidationException::withMessages(['kind' => 'Choose per hour or per session.']);
        $sen = (int) PayrollMoney::sen($input['amount'] ?? null, 'amount');
        $from = $this->date($input['effective_from'] ?? null, 'effective_from', 'Enter the date the rate starts.');

        DB::transaction(function () use ($actor, $workerId, $key, $kind, $sen, $from): void {
            $actor = $this->support->lock($actor, LocumPayPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'locum_rate', $workerId, $kind, $sen, $from]);
            if ($this->support->replay($actor, $key, 'locum_rate', $hash) !== null) {
                return;
            }
            $worker = $this->locum($actor, $workerId);
            if (DB::table('hr_locum_pay_rates')->where('worker_id', $worker->id)->where('status', 'waiting')->exists()) {
                throw ValidationException::withMessages(['rate' => 'A rate is already waiting for confirmation. Withdraw it first to enter another.']);
            }
            $now = now()->utc();
            $id = (string) Str::uuid();
            DB::table('hr_locum_pay_rates')->insert(['public_id' => $id, 'organisation_id' => $actor->organisation_id, 'worker_id' => $worker->id,
                'kind' => $kind, 'amount_encrypted' => Crypt::encryptString((string) $sen), 'effective_from' => $from, 'status' => 'waiting',
                'entered_by_user_id' => $actor->id, 'entered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'locum_rate', $hash, $id);
            $this->support->audit->record('hr.locum_pay.rate_entered', $worker, ['kind' => $kind], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function withdrawRate(User $actor, string $rateId, array $input): void
    {
        $this->decideRate($actor, $rateId, $input, LocumPayPermissions::MANAGE, 'withdrawn', null);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function confirmRate(User $actor, string $rateId, array $input): void
    {
        $this->decideRate($actor, $rateId, $input, LocumPayPermissions::FINANCE, 'confirmed', null);
    }

    /** @param array<string, mixed> $input reason, expected_lock_version, idempotency_key */
    public function rejectRate(User $actor, string $rateId, array $input): void
    {
        $reason = in_array($input['reason'] ?? null, ['wrong_amount', 'wrong_kind', 'wrong_date', 'not_agreed', 'other'], true) ? (string) $input['reason']
            : throw ValidationException::withMessages(['reason' => 'Choose why the rate is not confirmed.']);
        $this->decideRate($actor, $rateId, $input, LocumPayPermissions::FINANCE, 'rejected', $reason);
    }

    /** @param array<string, mixed> $input shift_id, reason, idempotency_key */
    public function markNotWorked(User $actor, string $workerId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $shiftId = is_string($input['shift_id'] ?? null) && Str::isUuid($input['shift_id']) ? $input['shift_id'] : abort(404);
        $reason = array_key_exists((string) ($input['reason'] ?? ''), LocumPayStatement::REASONS) ? (string) $input['reason']
            : throw ValidationException::withMessages(['reason' => 'Choose why the shift was not worked.']);

        DB::transaction(function () use ($actor, $workerId, $key, $shiftId, $reason): void {
            $actor = $this->support->lock($actor, LocumPayPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'locum_not_worked', $workerId, $shiftId, $reason]);
            if ($this->support->replay($actor, $key, 'locum_not_worked', $hash) !== null) {
                return;
            }
            $worker = $this->locum($actor, $workerId);
            $userId = LocumPayStatement::linkedUserId((int) $worker->id);
            $shift = DB::table('hr_roster_shifts as s')->join('hr_roster_versions as v', 'v.id', '=', 's.roster_version_id')
                ->where('s.organisation_id', $actor->organisation_id)->where('s.public_id', $shiftId)->where('v.status', 'approved')
                ->where('s.staff_user_id', $userId ?? 0)->first(['s.id', 's.starts_at', 's.timezone']);
            abort_if($shift === null, 404);
            $month = CarbonImmutable::parse((string) $shift->starts_at)->setTimezone((string) $shift->timezone)->format('Y-m-01');
            $this->assertUnpaid((int) $worker->id, $month);
            if (DB::table('hr_locum_pay_exclusions')->where('roster_shift_id', $shift->id)->whereNull('removed_at')->exists()) {
                throw ValidationException::withMessages(['locum_pay' => 'This shift is already marked not worked.']);
            }
            $now = now()->utc();
            $id = (string) Str::uuid();
            DB::table('hr_locum_pay_exclusions')->insert(['public_id' => $id, 'organisation_id' => $actor->organisation_id, 'worker_id' => $worker->id,
                'roster_shift_id' => $shift->id, 'month' => $month, 'reason' => $reason, 'entered_by_user_id' => $actor->id, 'entered_at' => $now,
                'created_at' => $now, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'locum_not_worked', $hash, $id);
            $this->support->audit->record('hr.locum_pay.not_worked', $worker, ['roster_shift_id' => (int) $shift->id, 'reason' => $reason], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function removeNotWorked(User $actor, string $exclusionId, array $input): void
    {
        $this->removeItem($actor, 'hr_locum_pay_exclusions', $exclusionId, $input, 'hr.locum_pay.not_worked_removed');
    }

    /** @param array<string, mixed> $input month, direction, amount, reason, for_month, idempotency_key */
    public function addCorrection(User $actor, string $workerId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $month = $this->month($input['month'] ?? null, 'month');
        $direction = in_array($input['direction'] ?? null, ['add', 'deduct'], true) ? (string) $input['direction']
            : throw ValidationException::withMessages(['direction' => 'Choose whether this adds to or takes from the pay.']);
        $sen = (int) PayrollMoney::sen($input['amount'] ?? null, 'amount');
        $reason = array_key_exists((string) ($input['reason'] ?? ''), LocumPayStatement::ADJUSTMENT_REASONS) ? (string) $input['reason']
            : throw ValidationException::withMessages(['reason' => 'Choose the reason for the correction.']);
        $forMonth = null;
        if ($reason === 'earlier_month') {
            $forMonth = $this->month($input['for_month'] ?? null, 'for_month');
            if ($forMonth >= $month) {
                throw ValidationException::withMessages(['for_month' => 'Choose a month before this one.']);
            }
        }
        $signed = $direction === 'deduct' ? -$sen : $sen;

        DB::transaction(function () use ($actor, $workerId, $key, $month, $signed, $reason, $forMonth): void {
            $actor = $this->support->lock($actor, LocumPayPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'locum_correction', $workerId, $month, $signed, $reason, $forMonth]);
            if ($this->support->replay($actor, $key, 'locum_correction', $hash) !== null) {
                return;
            }
            $worker = $this->locum($actor, $workerId);
            $this->assertUnpaid((int) $worker->id, $month);
            $now = now()->utc();
            $id = (string) Str::uuid();
            DB::table('hr_locum_pay_adjustments')->insert(['public_id' => $id, 'organisation_id' => $actor->organisation_id, 'worker_id' => $worker->id,
                'month' => $month, 'amount_encrypted' => Crypt::encryptString((string) $signed), 'reason' => $reason, 'for_month' => $forMonth,
                'entered_by_user_id' => $actor->id, 'entered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'locum_correction', $hash, $id);
            $this->support->audit->record('hr.locum_pay.correction_added', $worker, ['reason' => $reason], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function removeCorrection(User $actor, string $adjustmentId, array $input): void
    {
        $this->removeItem($actor, 'hr_locum_pay_adjustments', $adjustmentId, $input, 'hr.locum_pay.correction_removed');
    }

    /** @param array<string, mixed> $input month, paid_on, reference, fingerprint, idempotency_key */
    public function markPaid(User $actor, string $workerId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $month = $this->month($input['month'] ?? null, 'month');
        $paidOn = $this->date($input['paid_on'] ?? null, 'paid_on', 'Enter the date the clinic paid.');
        $reference = is_string($input['reference'] ?? null) ? trim($input['reference']) : '';
        if (mb_strlen($reference) > 40 || ($reference !== '' && preg_match('/\A[\pL\pN \-\/.#]+\z/u', $reference) !== 1)) {
            throw ValidationException::withMessages(['reference' => 'Use up to 40 letters, numbers, spaces and - / . #.']);
        }
        $fingerprint = is_string($input['fingerprint'] ?? null) ? $input['fingerprint'] : '';

        DB::transaction(function () use ($actor, $workerId, $key, $month, $paidOn, $reference, $fingerprint): void {
            $actor = $this->support->lock($actor, LocumPayPermissions::FINANCE);
            $hash = $this->support->hash([$actor->id, 'locum_paid', $workerId, $month, $paidOn, $reference]);
            if ($this->support->replay($actor, $key, 'locum_paid', $hash) !== null) {
                return;
            }
            $worker = $this->locum($actor, $workerId);
            $this->assertUnpaid((int) $worker->id, $month);
            $today = CarbonImmutable::now($this->zone((int) $actor->organisation_id))->format('Y-m-d');
            if ($month >= substr($today, 0, 7).'-01') {
                throw ValidationException::withMessages(['locum_pay' => 'A month can be marked paid once it has ended.']);
            }
            if ($paidOn < $month || $paidOn > $today) {
                throw ValidationException::withMessages(['paid_on' => 'Enter a date from the start of the month to today.']);
            }
            $statement = LocumPayStatement::build((int) $actor->organisation_id, (int) $worker->id, $month);
            if (! hash_equals($statement['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['locum_pay' => 'The statement changed since you opened it. Reload and check it again.']);
            }
            if ($statement['problems'] !== []) {
                throw ValidationException::withMessages(['locum_pay' => $statement['problems'][0]]);
            }
            $now = now()->utc();
            $snapshot = ['total' => $statement['total'], 'lines' => $statement['lines'], 'corrections' => $statement['corrections']];
            DB::table('hr_locum_pay_months')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                'worker_id' => $worker->id, 'month' => $month, 'total_encrypted' => Crypt::encryptString((string) $statement['total']),
                'statement_encrypted' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 'paid_on' => $paidOn,
                'reference_encrypted' => $reference === '' ? null : Crypt::encryptString($reference),
                'reference_last4' => $reference === '' ? null : mb_substr($reference, -4),
                'paid_by_user_id' => $actor->id, 'recorded_at' => $now, 'created_at' => $now]);
            $this->support->remember($actor, $key, 'locum_paid', $hash, $month);
            $this->support->audit->record('hr.locum_pay.month_paid', $worker, ['month' => substr($month, 0, 7), 'shifts' => count($statement['lines'])],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    private function decideRate(User $actor, string $rateId, array $input, string $permission, string $status, ?string $reason): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        abort_if(! Str::isUuid($rateId), 404);

        DB::transaction(function () use ($actor, $rateId, $input, $key, $permission, $status, $reason): void {
            $actor = $this->support->lock($actor, $permission);
            $hash = $this->support->hash([$actor->id, 'locum_rate_'.$status, $rateId, $reason]);
            if ($this->support->replay($actor, $key, 'locum_rate_'.$status, $hash) !== null) {
                return;
            }
            $workerId = DB::table('hr_locum_pay_rates')->where('organisation_id', $actor->organisation_id)->where('public_id', $rateId)->value('worker_id');
            abort_if($workerId === null, 404);
            $worker = Worker::query()->whereKey($workerId)->lockForUpdate()->firstOrFail();
            $rate = DB::table('hr_locum_pay_rates')->where('public_id', $rateId)->lockForUpdate()->first();
            abort_if($rate === null, 404);
            $this->support->assertLock($rate, $input);
            if ($rate->status !== 'waiting') {
                throw ValidationException::withMessages(['rate' => 'This rate was already dealt with. Reload to see the latest version.']);
            }
            if ($status !== 'withdrawn' && (int) $rate->entered_by_user_id === $actor->id) {
                throw new AuthorizationException('You entered this rate, so someone else confirms it.');
            }
            $now = now()->utc();
            DB::table('hr_locum_pay_rates')->where('id', $rate->id)->update(['status' => $status, 'reject_reason' => $reason,
                'decided_by_user_id' => $status === 'withdrawn' ? null : $actor->id, 'decided_at' => $status === 'withdrawn' ? null : $now,
                'lock_version' => (int) $rate->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'locum_rate_'.$status, $hash, $rateId);
            $this->support->audit->record('hr.locum_pay.rate_'.$status, $worker, array_filter(['rate_id' => (int) $rate->id, 'reason' => $reason]), $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    private function removeItem(User $actor, string $table, string $itemId, array $input, string $event): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        abort_if(! Str::isUuid($itemId), 404);

        DB::transaction(function () use ($actor, $table, $itemId, $input, $key, $event): void {
            $actor = $this->support->lock($actor, LocumPayPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, $event, $itemId]);
            if ($this->support->replay($actor, $key, $event, $hash) !== null) {
                return;
            }
            $workerId = DB::table($table)->where('organisation_id', $actor->organisation_id)->where('public_id', $itemId)->value('worker_id');
            abort_if($workerId === null, 404);
            $worker = Worker::query()->whereKey($workerId)->lockForUpdate()->firstOrFail();
            $item = DB::table($table)->where('public_id', $itemId)->lockForUpdate()->first();
            abort_if($item === null, 404);
            $this->support->assertLock($item, $input);
            if ($item->removed_at !== null) {
                throw ValidationException::withMessages(['locum_pay' => 'This was already removed. Reload to see the latest version.']);
            }
            $this->assertUnpaid((int) $worker->id, substr((string) $item->month, 0, 10));
            $now = now()->utc();
            DB::table($table)->where('id', $item->id)->update(['removed_by_user_id' => $actor->id, 'removed_at' => $now,
                'lock_version' => (int) $item->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, $event, $hash, $itemId);
            $this->support->audit->record($event, $worker, ['item_id' => (int) $item->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** The locum file, locked. Only locum files have locum pay. */
    private function locum(User $actor, string $workerId): Worker
    {
        abort_if(! Str::isUuid($workerId), 404);
        $worker = $this->support->lockWorker($actor, $workerId);
        if ($worker->kind !== 'locum') {
            throw ValidationException::withMessages(['locum_pay' => 'Locum pay is only for locum files.']);
        }

        return $worker;
    }

    private function assertUnpaid(int $workerId, string $month): void
    {
        if (DB::table('hr_locum_pay_months')->where('worker_id', $workerId)->where('month', $month)->exists()) {
            throw ValidationException::withMessages(['locum_pay' => 'This month is paid and locked. Add a correction to a later month instead.']);
        }
    }

    private function month(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/\A(20[0-9]{2}|2100)-(0[1-9]|1[0-2])\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Choose a month.']);
        }

        return $value.'-01';
    }

    private function date(mixed $value, string $field, string $message): string
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === null || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2100-12-31') {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}
