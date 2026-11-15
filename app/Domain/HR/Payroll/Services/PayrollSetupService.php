<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Payroll\PayProfileMtd;
use App\Domain\HR\Payroll\PayrollPermissions;
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
 * HR-7a payroll setup (HR-PLAN 14.2 and 14.3). Pay groups, pay item types and rate settings are kept by preparers.
 * Statutory banded tables and pay profiles need two people: whoever enters one never checks or confirms it, nobody
 * confirms their own pay profile, and only the Director decides, or the HR Manager when a Director entered it (14.10
 * item 3). Every write locks the accounts in id order and re-checks the permission under lock, runs in one transaction
 * with lock_version and an idempotency key, and is audited with ids, codes, counts and versions only, never amounts.
 */
final class PayrollSetupService
{
    public const SCHEMES = ['epf' => 'EPF', 'socso' => 'SOCSO', 'eis' => 'EIS', 'hrd' => 'HRD levy'];

    public const ROUNDING = ['none' => 'Drop part sen (round down to the sen)', 'nearest_sen' => 'To the nearest sen', 'up_ringgit' => 'Up to the next ringgit'];

    public const KINDS = ['earnings' => 'Earnings', 'deduction' => 'Deduction', 'refund' => 'Refund'];

    public const TABLE_REJECT_REASONS = ['wrong_figures' => 'Wrong figures', 'wrong_category' => 'Wrong category', 'wrong_date' => 'Wrong date', 'other' => 'Other'];

    public const PROFILE_REJECT_REASONS = ['wrong_amount' => 'Wrong amount', 'wrong_person' => 'Wrong person', 'wrong_schemes' => 'Wrong schemes',
        'wrong_date' => 'Wrong date', 'other' => 'Other'];

    public const SUGGESTED_ITEM_TYPES = ['Basic salary', 'Fixed allowance', 'Overtime', 'Unpaid leave', 'Staff claim refund', 'Bonus', 'Other addition', 'Other deduction'];

    /**
     * Managers' own pay stays outside KPOne in v1 (B.E.R.N.I.S.S for the owner, 2026-11-06, option (a)): with one Director
     * and one HR Manager nobody is left to confirm either of their profiles under the two-person and HR-4 rules.
     */
    public const MANAGERS_OUTSIDE = "Managers' own pay (the Director and the HR Manager) stays outside KPOne in this version.";

    public const MAX_ROWS = 200;

    public const MAX_ITEMS = 20;

    public const MAX_HOURLY_SEN = 999999;

    public function __construct(private RecordSupport $support) {}

    /** @param array<string, mixed> $input */
    public function saveGroup(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $name = self::name($input['name'] ?? null);
        $rawDay = $input['pay_day'] ?? null;
        $day = PayrollMoney::blank($rawDay) ? null : filter_var($rawDay, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
        $errors = $name === null ? ['name' => 'Enter a name of 2 to 60 characters.'] : [];
        if ($day === false) {
            $errors['pay_day'] = 'Enter a day of the month from 1 to 31, or leave it empty.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $active = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOL);
        $groupId = $input['group_id'] ?? null;

        return DB::transaction(function () use ($actor, $input, $key, $name, $day, $active, $groupId): string {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_pay_groups:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'payroll_group', $groupId, $name, $day, $active]);
            if (($replay = $this->support->replay($actor, $key, 'payroll_group', $hash)) !== null) {
                return $replay;
            }
            $group = $groupId === null ? null : $this->row('hr_pay_groups', $actor, $groupId);
            if ($group !== null) {
                $this->support->assertLock($group, $input);
            }
            if (DB::table('hr_pay_groups')->where('organisation_id', $actor->organisation_id)->whereRaw('lower(name) = lower(?)', [$name])
                ->when($group !== null, fn ($q) => $q->where('id', '<>', $group->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Another pay group already has this name.']);
            }
            $now = now()->utc();
            if ($group === null) {
                $publicId = (string) Str::uuid();
                $id = DB::table('hr_pay_groups')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'name' => $name,
                    'pay_day' => $day, 'is_active' => $active, 'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $publicId = (string) $group->public_id;
                $id = (int) $group->id;
                DB::table('hr_pay_groups')->where('id', $id)->update(['name' => $name, 'pay_day' => $day, 'is_active' => $active,
                    'lock_version' => (int) $group->lock_version + 1, 'updated_at' => $now]);
            }
            $this->support->remember($actor, $key, 'payroll_group', $hash, $publicId);
            $this->support->audit->record('hr.payroll.group_saved', null, ['pay_group_id' => $id, 'pay_day' => $day, 'is_active' => $active],
                $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Adds a pay item type, or saves a new version of one. Every version is kept.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveItemType(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $name = self::name($input['name'] ?? null);
        $kind = $input['kind'] ?? null;
        $errors = [];
        if ($name === null) {
            $errors['name'] = 'Enter a name of 2 to 60 characters.';
        }
        if (! is_string($kind) || ! array_key_exists($kind, self::KINDS)) {
            $errors['kind'] = 'Choose earnings, deduction or refund.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $counts = [];
        foreach (['epf', 'socso', 'eis', 'mtd', 'hrd'] as $scheme) {
            $counts["counts_{$scheme}"] = filter_var($input["counts_{$scheme}"] ?? false, FILTER_VALIDATE_BOOL);
        }
        $active = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOL);
        $typeId = $input['item_type_id'] ?? null;

        return DB::transaction(function () use ($actor, $input, $key, $name, $kind, $counts, $active, $typeId): string {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_pay_item_types:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'payroll_item_type', $typeId, $name, $kind, $counts, $active]);
            if (($replay = $this->support->replay($actor, $key, 'payroll_item_type', $hash)) !== null) {
                return $replay;
            }
            $type = $typeId === null ? null : $this->row('hr_pay_item_types', $actor, $typeId);
            if ($type !== null) {
                $this->support->assertLock($type, $input);
            }
            $taken = DB::table('hr_pay_item_type_versions as v')->join('hr_pay_item_types as t', function ($join): void {
                $join->on('t.id', '=', 'v.item_type_id')->on('t.current_version', '=', 'v.version');
            })->where('t.organisation_id', $actor->organisation_id)->whereRaw('lower(v.name) = lower(?)', [$name])
                ->when($type !== null, fn ($q) => $q->where('t.id', '<>', $type->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['name' => 'Another pay item type already has this name.']);
            }
            $now = now()->utc();
            if ($type === null) {
                $publicId = (string) Str::uuid();
                $id = DB::table('hr_pay_item_types')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'current_version' => 1,
                    'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $version = 1;
            } else {
                $publicId = (string) $type->public_id;
                $id = (int) $type->id;
                $version = (int) $type->current_version + 1;
            }
            DB::table('hr_pay_item_type_versions')->insert(['organisation_id' => $actor->organisation_id, 'item_type_id' => $id, 'version' => $version, 'name' => $name,
                'kind' => $kind, ...$counts, 'is_active' => $active, 'set_by_user_id' => $actor->id, 'created_at' => $now]);
            if ($type !== null) {
                DB::table('hr_pay_item_types')->where('id', $id)->update(['current_version' => $version, 'lock_version' => (int) $type->lock_version + 1, 'updated_at' => $now]);
            }
            $this->support->remember($actor, $key, 'payroll_item_type', $hash, $publicId);
            $this->support->audit->record('hr.payroll.item_type_saved', null, ['item_type_id' => $id, 'version' => $version, 'kind' => $kind, ...$counts, 'is_active' => $active],
                $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function saveRates(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $errors = [];
        $daily = $hourly = 0;
        try {
            $daily = PayrollMoney::hundredths($input['daily_divisor'] ?? null, 'daily_divisor', 100, 3100, 'Enter the number of days to divide the monthly salary by, from 1 to 31 (for example 26).');
        } catch (ValidationException $e) {
            $errors = [...$errors, ...$e->errors()];
        }
        try {
            $hourly = PayrollMoney::hundredths($input['hourly_divisor'] ?? null, 'hourly_divisor', 100, 2400, 'Enter the number of hours to divide a day\'s rate by, from 1 to 24 (for example 8).');
        } catch (ValidationException $e) {
            $errors = [...$errors, ...$e->errors()];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn ($m) => $m[0], $errors));
        }
        DB::transaction(function () use ($actor, $input, $key, $daily, $hourly): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_rate_settings:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'payroll_rates', $daily, $hourly]);
            if ($this->support->replay($actor, $key, 'payroll_rates', $hash) !== null) {
                return;
            }
            $current = (int) DB::table('hr_rate_settings')->where('organisation_id', $actor->organisation_id)->max('version');
            if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== $current) {
                throw ValidationException::withMessages(['payroll' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            DB::table('hr_rate_settings')->insert(['organisation_id' => $actor->organisation_id, 'version' => $current + 1, 'daily_divisor_hundredths' => $daily,
                'hourly_divisor_hundredths' => $hourly, 'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'payroll_rates', $hash, (string) ($current + 1));
            $this->support->audit->record('hr.payroll.rates_saved', null, ['version' => $current + 1, 'daily_divisor_hundredths' => $daily,
                'hourly_divisor_hundredths' => $hourly], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Enters a statutory banded table with its bands, for someone else to check. Bands run on from each other with no
     * gap or overlap; only the last may be open-ended.
     *
     * @param  array<string, mixed>  $input
     */
    public function enterTable(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        [$scheme, $category, $from, $rounding, $note, $rows] = self::table($input);

        return DB::transaction(function () use ($actor, $key, $scheme, $category, $from, $rounding, $note, $rows): string {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, 'payroll_table', $scheme, $category, $from, $rounding, $note, $rows]);
            if (($replay = $this->support->replay($actor, $key, 'payroll_table', $hash)) !== null) {
                return $replay;
            }
            $now = now()->utc();
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_statutory_tables')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'scheme' => $scheme,
                'category' => $category, 'effective_from' => $from, 'rounding' => $rounding, 'source_note' => $note, 'status' => 'entered',
                'entered_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($rows as $i => $row) {
                DB::table('hr_statutory_table_rows')->insert(['organisation_id' => $actor->organisation_id, 'table_id' => $id, 'position' => $i + 1, ...$row]);
            }
            $this->support->remember($actor, $key, 'payroll_table', $hash, $publicId);
            $this->support->audit->record('hr.payroll.table_entered', null, ['table_id' => $id, 'scheme' => $scheme, 'effective_from' => $from,
                'rounding' => $rounding, 'bands' => count($rows)], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Checks (an earlier checked table for the same scheme, category and date becomes replaced) or rejects with a reason.
     *
     * @param  array<string, mixed>  $input
     */
    public function decideTable(User $actor, string $tableId, string $decision, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $this->decision($decision, $input['reason'] ?? null, self::TABLE_REJECT_REASONS);
        DB::transaction(function () use ($actor, $tableId, $decision, $input, $key, $reason): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, 'payroll_table_decide', $tableId, $decision, $reason]);
            if ($this->support->replay($actor, $key, 'payroll_table_decide', $hash) !== null) {
                return;
            }
            $table = $this->row('hr_statutory_tables', $actor, $tableId);
            $this->support->assertLock($table, $input);
            if ($table->status !== 'entered') {
                throw ValidationException::withMessages(['payroll' => 'This table was already decided. Reload to see it.']);
            }
            $refusal = $this->deciderRefusal($actor, (int) $table->entered_by_user_id, null);
            if ($refusal !== null) {
                throw new AuthorizationException($refusal);
            }
            $now = now()->utc();
            $replaced = 0;
            if ($decision === 'check') {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_statutory_tables:'.$actor->organisation_id.':'.$table->scheme]);
                $replaced = DB::table('hr_statutory_tables')->where('organisation_id', $actor->organisation_id)->where('scheme', $table->scheme)
                    ->whereRaw('lower(category) = lower(?)', [$table->category])->where('effective_from', $table->effective_from)->where('status', 'checked')
                    ->update(['status' => 'replaced', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $now]);
            }
            DB::table('hr_statutory_tables')->where('id', $table->id)->update(['status' => $decision === 'check' ? 'checked' : 'rejected',
                'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'decision_reason' => $reason, 'lock_version' => (int) $table->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'payroll_table_decide', $hash, (string) $table->public_id);
            $this->support->audit->record($decision === 'check' ? 'hr.payroll.table_checked' : 'hr.payroll.table_rejected', null,
                ['table_id' => (int) $table->id, 'scheme' => $table->scheme, 'reason_code' => $reason, 'replaced' => $replaced], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input Only the person who entered a table withdraws it, before it is checked. */
    public function withdrawTable(User $actor, string $tableId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $tableId, $input, $key): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, 'payroll_table_withdraw', $tableId]);
            if ($this->support->replay($actor, $key, 'payroll_table_withdraw', $hash) !== null) {
                return;
            }
            $table = $this->row('hr_statutory_tables', $actor, $tableId);
            $this->support->assertLock($table, $input);
            if ($table->status !== 'entered') {
                throw ValidationException::withMessages(['payroll' => 'This table was already decided. Reload to see it.']);
            }
            if ((int) $table->entered_by_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who entered this table may withdraw it.');
            }
            DB::table('hr_statutory_tables')->where('id', $table->id)->update(['status' => 'withdrawn', 'lock_version' => (int) $table->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'payroll_table_withdraw', $hash, (string) $table->public_id);
            $this->support->audit->record('hr.payroll.table_withdrawn', null, ['table_id' => (int) $table->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Enters a pay profile for a staff member, waiting for someone other than the person who entered it, and other
     * than the worker, to confirm it. The earlier confirmed profile stays in force until then.
     *
     * @param  array<string, mixed>  $input
     */
    public function enterProfile(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $workerId = $input['worker_id'] ?? null;
        if (! is_string($workerId) || ! Str::isUuid($workerId)) {
            throw ValidationException::withMessages(['worker_id' => 'Choose a staff member.']);
        }
        $values = self::profile($input);

        return DB::transaction(function () use ($actor, $key, $workerId, $values): string {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE, [$this->support->workerUserId($actor, $workerId)]);
            $worker = $this->support->lockWorker($actor, $workerId);
            $hash = $this->support->hash([$actor->id, 'payroll_profile', $workerId, $values]);
            if (($replay = $this->support->replay($actor, $key, 'payroll_profile', $hash)) !== null) {
                return $replay;
            }
            if ($worker->kind !== 'staff') {
                throw ValidationException::withMessages(['worker_id' => 'Locums are paid outside KPOne in this version.']);
            }
            if (self::isManager($worker)) {
                throw ValidationException::withMessages(['worker_id' => self::MANAGERS_OUTSIDE]);
            }
            if ($worker->user_id === $actor->id) {
                throw new AuthorizationException('Nobody enters their own pay profile. Ask someone else.');
            }
            $this->support->assertManages($actor, $worker);
            if (DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->where('status', 'waiting')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['payroll' => 'A pay profile change is already waiting. Confirm, reject or withdraw it first.']);
            }
            $group = DB::table('hr_pay_groups')->where('organisation_id', $actor->organisation_id)->where('public_id', $values['pay_group_id'])->first();
            if (! $group instanceof \stdClass || ! $group->is_active) {
                throw ValidationException::withMessages(['pay_group_id' => 'Choose a pay group that is in use.']);
            }
            $items = [];
            foreach ($values['items'] as $i => $item) {
                $type = DB::table('hr_pay_item_types as t')->join('hr_pay_item_type_versions as v', function ($join): void {
                    $join->on('v.item_type_id', '=', 't.id')->on('v.version', '=', 't.current_version');
                })->where('t.organisation_id', $actor->organisation_id)->where('t.public_id', $item['item_type_id'])->first(['t.id', 'v.version', 'v.kind', 'v.is_active']);
                if (! $type instanceof \stdClass || ! $type->is_active || $type->kind !== 'earnings') {
                    throw ValidationException::withMessages(["items.{$i}.item_type_id" => 'Choose an earnings pay item type that is in use.']);
                }
                $items[] = ['item_type_id' => (int) $type->id, 'item_type_version' => (int) $type->version, 'amount_sen' => $item['amount_sen']];
            }
            $now = now()->utc();
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_pay_profiles')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'worker_id' => $worker->id,
                'pay_group_id' => $group->id, 'pay_type' => $values['pay_type'], 'rate_encrypted' => Crypt::encryptString((string) $values['rate_sen']),
                'epf' => $values['epf'], 'epf_category' => $values['epf_category'], 'socso' => $values['socso'], 'socso_category' => $values['socso_category'],
                'eis' => $values['eis'], 'mtd' => $values['mtd'], 'hrd' => $values['hrd'],
                // HR-7e: the MTD details are encrypted as one value (health data: the disability flags).
                'mtd_details_encrypted' => PayProfileMtd::encrypt($values['mtd_category'] === null ? null : ['category' => $values['mtd_category'],
                    'children' => (int) $values['mtd_children'], 'disabled_self' => $values['mtd_disabled_self'], 'disabled_spouse' => $values['mtd_disabled_spouse']]),
                'effective_from' => $values['effective_from'], 'status' => 'waiting',
                'entered_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($items as $i => $item) {
                DB::table('hr_pay_profile_items')->insert(['organisation_id' => $actor->organisation_id, 'profile_id' => $id, 'position' => $i + 1,
                    'item_type_id' => $item['item_type_id'], 'item_type_version' => $item['item_type_version'],
                    'amount_encrypted' => Crypt::encryptString((string) $item['amount_sen'])]);
            }
            $this->support->remember($actor, $key, 'payroll_profile', $hash, $publicId);
            $this->support->audit->record('hr.payroll.profile_entered', $worker, ['profile_id' => $id, 'pay_group_id' => (int) $group->id, 'pay_type' => $values['pay_type'],
                'effective_from' => $values['effective_from'], 'allowances' => count($items)], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Confirms (an earlier confirmed profile from the same date becomes replaced) or rejects with a reason.
     *
     * @param  array<string, mixed>  $input
     */
    public function decideProfile(User $actor, string $profileId, string $decision, array $input): void
    {
        if (! in_array($decision, ['confirm', 'reject'], true)) {
            abort(404);
        }
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $this->decision($decision === 'confirm' ? 'check' : $decision, $input['reason'] ?? null, self::PROFILE_REJECT_REASONS);
        $workerUserId = $this->profileWorkerUserId($actor, $profileId);
        DB::transaction(function () use ($actor, $profileId, $decision, $input, $key, $reason, $workerUserId): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE, [$workerUserId]);
            $hash = $this->support->hash([$actor->id, 'payroll_profile_decide', $profileId, $decision, $reason]);
            if ($this->support->replay($actor, $key, 'payroll_profile_decide', $hash) !== null) {
                return;
            }
            // The staff file row first, then the profile, the same order as entering one (Suresh, HR-7a M1).
            $worker = $this->profileWorker($actor, $profileId);
            $profile = $this->row('hr_pay_profiles', $actor, $profileId);
            if ($worker->user_id !== $actor->id) { // your own profile gets its own refusal below
                $this->support->assertManages($actor, $worker);
            }
            $this->support->assertLock($profile, $input);
            if ($profile->status !== 'waiting') {
                throw ValidationException::withMessages(['payroll' => 'This pay profile was already decided. Reload to see it.']);
            }
            $refusal = $this->deciderRefusal($actor, (int) $profile->entered_by_user_id, $worker->user_id, 'confirms');
            if ($refusal !== null) {
                throw new AuthorizationException($refusal);
            }
            $now = now()->utc();
            $replaced = 0;
            if ($decision === 'confirm') {
                $replaced = DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->where('effective_from', $profile->effective_from)->where('status', 'confirmed')
                    ->update(['status' => 'replaced', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $now]);
            }
            DB::table('hr_pay_profiles')->where('id', $profile->id)->update(['status' => $decision === 'confirm' ? 'confirmed' : 'rejected',
                'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'decision_reason' => $reason, 'lock_version' => (int) $profile->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'payroll_profile_decide', $hash, (string) $profile->public_id);
            $this->support->audit->record($decision === 'confirm' ? 'hr.payroll.profile_confirmed' : 'hr.payroll.profile_rejected', $worker,
                ['profile_id' => (int) $profile->id, 'reason_code' => $reason, 'replaced' => $replaced], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input Only the person who entered a profile withdraws it, while it waits. */
    public function withdrawProfile(User $actor, string $profileId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $workerUserId = $this->profileWorkerUserId($actor, $profileId);
        DB::transaction(function () use ($actor, $profileId, $input, $key, $workerUserId): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE, [$workerUserId]);
            $hash = $this->support->hash([$actor->id, 'payroll_profile_withdraw', $profileId]);
            if ($this->support->replay($actor, $key, 'payroll_profile_withdraw', $hash) !== null) {
                return;
            }
            // The staff file row first, then the profile, the same order as entering one (Suresh, HR-7a M1).
            $worker = $this->profileWorker($actor, $profileId);
            $profile = $this->row('hr_pay_profiles', $actor, $profileId);
            if ($worker->user_id !== $actor->id) { // your own profile gets its own refusal below
                $this->support->assertManages($actor, $worker);
            }
            $this->support->assertLock($profile, $input);
            if ($profile->status !== 'waiting') {
                throw ValidationException::withMessages(['payroll' => 'This pay profile was already decided. Reload to see it.']);
            }
            if ((int) $profile->entered_by_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who entered this pay profile may withdraw it.');
            }
            DB::table('hr_pay_profiles')->where('id', $profile->id)->update(['status' => 'withdrawn', 'lock_version' => (int) $profile->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'payroll_profile_withdraw', $hash, (string) $profile->public_id);
            $this->support->audit->record('hr.payroll.profile_withdrawn', $worker, ['profile_id' => (int) $profile->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Who may check a table or confirm a profile (HR-PLAN 14.3 rule 2 and 14.10 item 3), as one predicate shared by the
     * service and the page. Null when the actor may decide.
     */
    public function deciderRefusal(User $actor, int $enteredBy, ?int $workerUserId, string $verb = 'checks'): ?string
    {
        if ($enteredBy === $actor->id) {
            return "Someone else {$verb} this. You entered it.";
        }
        if ($workerUserId !== null && $workerUserId === $actor->id) {
            return 'Nobody confirms their own pay profile.';
        }
        if ($actor->can(PayrollPermissions::APPROVE)) {
            return null;
        }
        $entrant = User::query()->find($enteredBy);
        if ($actor->can(PayrollPermissions::PREPARE) && $entrant !== null && $entrant->hasRole('director')) {
            return null;
        }

        return "The Director {$verb} this, or the HR Manager when a Director entered it.";
    }

    /** A Director or HR Manager, whose own pay stays outside KPOne in v1. */
    public static function isManager(Worker $worker): bool
    {
        return $worker->user !== null && ($worker->user->hasRole('director') || $worker->user->hasRole('hr_manager'));
    }

    private function profileWorker(User $actor, string $profileId): Worker
    {
        $workerId = DB::table('hr_pay_profiles')->where('organisation_id', $actor->organisation_id)->where('public_id', $profileId)->value('worker_id');
        abort_if($workerId === null, 404);

        return Worker::query()->whereKey($workerId)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
    }

    private function profileWorkerUserId(User $actor, string $profileId): ?int
    {
        if (! Str::isUuid($profileId)) {
            abort(404);
        }
        $row = DB::table('hr_pay_profiles as p')->join('hr_workers as w', 'w.id', '=', 'p.worker_id')
            ->where('p.organisation_id', $actor->organisation_id)->where('p.public_id', $profileId)->first(['w.user_id']);
        abort_if(! $row instanceof \stdClass, 404);

        return $row->user_id === null ? null : (int) $row->user_id;
    }

    private function row(string $table, User $actor, mixed $publicId): \stdClass
    {
        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            abort(404);
        }
        $row = DB::table($table)->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->lockForUpdate()->first();
        abort_if(! $row instanceof \stdClass, 404);

        return $row;
    }

    /** @param array<string, string> $reasons */
    private function decision(string $decision, mixed $reason, array $reasons): ?string
    {
        if (! in_array($decision, ['check', 'reject'], true)) {
            abort(404);
        }
        if ($decision === 'reject' && (! is_string($reason) || ! array_key_exists($reason, $reasons))) {
            throw ValidationException::withMessages(['reason' => 'Choose why it is rejected.']);
        }

        return $decision === 'reject' ? $reason : null;
    }

    private static function name(mixed $value): ?string
    {
        $name = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

        return mb_strlen($name) < 2 || mb_strlen($name) > 60 ? null : $name;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value && $value >= '2000-01-01' && $value <= '2099-12-31' ? $value : null;
    }

    /**
     * Every field of a table, with each band error under its own row. One submit names everything to fix.
     *
     * @param  array<string, mixed>  $input
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:list<array<string, mixed>>}
     */
    public static function table(array $input): array
    {
        $errors = [];
        $scheme = $input['scheme'] ?? null;
        if (! is_string($scheme) || ! array_key_exists($scheme, self::SCHEMES)) {
            $errors['scheme'] = 'Choose EPF, SOCSO, EIS or HRD levy.';
        }
        $category = self::name($input['category'] ?? null);
        if ($category === null) {
            $errors['category'] = 'Enter a category of 2 to 60 characters, for example "Malaysian below 60".';
        }
        $from = self::date($input['effective_from'] ?? null);
        if ($from === null) {
            $errors['effective_from'] = 'Choose the date this table applies from.';
        }
        $rounding = $input['rounding'] ?? null;
        if (! is_string($rounding) || ! array_key_exists($rounding, self::ROUNDING)) {
            $errors['rounding'] = 'Choose how amounts are rounded.';
        }
        $note = is_string($input['source_note'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['source_note'])) : '';
        if (mb_strlen($note) < 2 || mb_strlen($note) > 200) {
            $errors['source_note'] = 'Say where these figures came from, in 2 to 200 characters.';
        }
        $raw = $input['rows'] ?? null;
        $rows = [];
        if (! is_array($raw) || $raw === [] || count($raw) > self::MAX_ROWS) {
            $errors['rows'] = 'Add from 1 to 200 bands.';
        } else {
            $raw = array_values($raw);
            $last = count($raw) - 1;
            $previousTo = null;
            foreach ($raw as $i => $band) {
                $band = is_array($band) ? $band : [];
                $row = ['wage_from_sen' => null, 'wage_to_sen' => null, 'employee_kind' => null, 'employee_value' => null, 'employer_kind' => null, 'employer_value' => null];
                try {
                    $row['wage_from_sen'] = PayrollMoney::sen($band['from'] ?? null, "rows.{$i}.from", false, true);
                } catch (ValidationException $e) {
                    $errors["rows.{$i}.from"] = $e->errors()["rows.{$i}.from"][0];
                }
                try {
                    $row['wage_to_sen'] = PayrollMoney::sen($band['to'] ?? null, "rows.{$i}.to", $i === $last, true);
                } catch (ValidationException $e) {
                    $errors["rows.{$i}.to"] = $i === $last ? $e->errors()["rows.{$i}.to"][0] : 'Enter where this band ends. Only the last band may be left open.';
                }
                if ($row['wage_from_sen'] !== null && $row['wage_to_sen'] !== null && $row['wage_to_sen'] < $row['wage_from_sen'] && ! isset($errors["rows.{$i}.to"])) {
                    $errors["rows.{$i}.to"] = 'A band ends at or after where it starts.';
                }
                if ($row['wage_from_sen'] !== null && $previousTo !== null && ! isset($errors["rows.{$i}.from"]) && $row['wage_from_sen'] !== $previousTo + 1) {
                    $errors["rows.{$i}.from"] = ($row['wage_from_sen'] <= $previousTo ? 'This band overlaps the one above.' : 'There is a gap after the band above.')
                        .' It should start at '.PayrollMoney::rm($previousTo + 1).'.';
                }
                foreach (['employee', 'employer'] as $part) {
                    $kind = $band["{$part}_kind"] ?? null;
                    if (! in_array($kind, ['fixed', 'rate'], true)) {
                        $errors["rows.{$i}.{$part}"] = 'Choose a fixed amount or a percentage.';

                        continue;
                    }
                    $row["{$part}_kind"] = $kind;
                    try {
                        $row["{$part}_value"] = $kind === 'rate' ? PayrollMoney::basisPoints($band[$part] ?? null, "rows.{$i}.{$part}")
                            : PayrollMoney::sen($band[$part] ?? null, "rows.{$i}.{$part}", false, true);
                    } catch (ValidationException $e) {
                        $errors["rows.{$i}.{$part}"] = $e->errors()["rows.{$i}.{$part}"][0];
                    }
                }
                $previousTo = $row['wage_to_sen'];
                $rows[] = $row;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [(string) $scheme, (string) $category, (string) $from, (string) $rounding, $note, $rows];
    }

    /**
     * Every field of a pay profile. One submit names everything to fix.
     *
     * @param  array<string, mixed>  $input
     * @return array{pay_group_id:string, pay_type:string, rate_sen:int, epf:bool, epf_category:?string, socso:bool, socso_category:?string, eis:bool, mtd:bool, hrd:bool, mtd_category:?int, mtd_children:?int, mtd_disabled_self:bool, mtd_disabled_spouse:bool, effective_from:string, items:list<array{item_type_id:string, amount_sen:int}>}
     */
    public static function profile(array $input): array
    {
        $errors = [];
        $group = $input['pay_group_id'] ?? null;
        if (! is_string($group) || ! Str::isUuid($group)) {
            $errors['pay_group_id'] = 'Choose a pay group.';
        }
        $type = $input['pay_type'] ?? null;
        if (! in_array($type, ['monthly', 'hourly'], true)) {
            $errors['pay_type'] = 'Choose monthly salary or hourly.';
        }
        $rate = 0;
        try {
            $rate = (int) PayrollMoney::sen($input['rate'] ?? null, 'rate');
            if ($type === 'hourly' && $rate > self::MAX_HOURLY_SEN) {
                $errors['rate'] = 'Enter an hourly rate up to RM 9,999.99.';
            }
        } catch (ValidationException $e) {
            $errors['rate'] = $e->errors()['rate'][0];
        }
        $schemes = [];
        foreach (['epf', 'socso', 'eis', 'mtd', 'hrd'] as $scheme) {
            $schemes[$scheme] = filter_var($input[$scheme] ?? false, FILTER_VALIDATE_BOOL);
        }
        $categories = ['epf_category' => null, 'socso_category' => null];
        foreach (['epf', 'socso'] as $scheme) {
            if ($schemes[$scheme]) {
                $categories["{$scheme}_category"] = self::name($input["{$scheme}_category"] ?? null);
                if ($categories["{$scheme}_category"] === null) {
                    $errors["{$scheme}_category"] = 'Enter the '.self::SCHEMES[$scheme].' category, as named on its table.';
                }
            }
        }
        // HR-7d (HR-PLAN 18.3): optional MTD details. With a category KPOne works out MTD; without one it is typed each run.
        $mtd = ['mtd_category' => null, 'mtd_children' => null, 'mtd_disabled_self' => false, 'mtd_disabled_spouse' => false];
        $category = $input['mtd_category'] ?? null;
        if ($schemes['mtd'] && $category !== null && $category !== '') {
            if (! in_array((string) $category, ['1', '2', '3'], true)) {
                $errors['mtd_category'] = 'Choose category 1, 2 or 3, or leave it empty to type MTD each run.';
            } else {
                $mtd['mtd_category'] = (int) $category;
                $children = $input['mtd_children'] ?? '0';
                if (! is_scalar($children) || preg_match('/\A\d{1,2}\z/', trim((string) $children)) !== 1 || (int) $children > 20) {
                    $errors['mtd_children'] = 'Enter the number of children for relief, from 0 to 20.';
                } else {
                    $mtd['mtd_children'] = (int) $children;
                }
                $mtd['mtd_disabled_self'] = filter_var($input['mtd_disabled_self'] ?? false, FILTER_VALIDATE_BOOL);
                $mtd['mtd_disabled_spouse'] = $mtd['mtd_category'] === 2 && filter_var($input['mtd_disabled_spouse'] ?? false, FILTER_VALIDATE_BOOL);
            }
        }
        $from = self::date($input['effective_from'] ?? null);
        if ($from === null) {
            $errors['effective_from'] = 'Choose the date this profile applies from.';
        }
        $items = [];
        $raw = $input['items'] ?? [];
        if (! is_array($raw) || count($raw) > self::MAX_ITEMS) {
            $errors['items'] = 'Add up to 20 fixed allowances.';
        } else {
            foreach (array_values($raw) as $i => $item) {
                $item = is_array($item) ? $item : [];
                $typeId = $item['item_type_id'] ?? null;
                if (! is_string($typeId) || ! Str::isUuid($typeId)) {
                    $errors["items.{$i}.item_type_id"] = 'Choose a pay item type.';
                }
                try {
                    $amount = (int) PayrollMoney::sen($item['amount'] ?? null, "items.{$i}.amount");
                } catch (ValidationException $e) {
                    $errors["items.{$i}.amount"] = $e->errors()["items.{$i}.amount"][0];
                    $amount = 0;
                }
                $items[] = ['item_type_id' => (string) $typeId, 'amount_sen' => $amount];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['pay_group_id' => (string) $group, 'pay_type' => (string) $type, 'rate_sen' => $rate, ...$schemes, ...$categories, ...$mtd, 'effective_from' => (string) $from, 'items' => $items];
    }
}
