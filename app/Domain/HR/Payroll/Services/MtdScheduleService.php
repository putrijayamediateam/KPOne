<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-7d yearly MTD schedule (HR-PLAN 18.2). The clinic enters the year's bands, reliefs, EPF relief limit, smallest MTD
 * and rounding; KPOne ships no tax number. Someone other than the entrant checks it, with the same decider rule as the
 * statutory tables (a Director, or the HR Manager when a Director entered it); a later checked schedule for the same
 * year replaces the earlier one. Every write locks the actor, re-checks the permission under lock, runs in one
 * transaction with lock_version and an idempotency key, and is audited with ids, codes and counts only, never figures.
 */
final class MtdScheduleService
{
    public const MAX_BANDS = 40;

    /** RM 99,999,999.99: the top of a yearly chargeable-income band. */
    public const MAX_YEARLY_SEN = 9999999999;

    public const RELIEFS = ['individual_relief' => 'Individual relief (D)', 'spouse_relief' => 'Spouse relief, category 2 (S)',
        'disabled_self_relief' => 'Disabled individual relief (DU)', 'disabled_spouse_relief' => 'Disabled spouse relief, category 2 (SU)',
        'child_relief' => 'Relief for each child (Q)', 'epf_relief_cap' => 'EPF relief limit for the year'];

    public function __construct(private RecordSupport $support, private PayrollSetupService $setup) {}

    /** @param array<string, mixed> $input */
    public function enter(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        [$values, $rows] = self::parse($input);

        return DB::transaction(function () use ($actor, $key, $values, $rows): string {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, 'payroll_mtd_schedule', $values, $rows]);
            if (($replay = $this->support->replay($actor, $key, 'payroll_mtd_schedule', $hash)) !== null) {
                return $replay;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_mtd_schedules:'.$actor->organisation_id.':'.$values['year']]);
            if (DB::table('hr_mtd_schedules')->where('organisation_id', $actor->organisation_id)->where('year', $values['year'])->where('status', 'entered')->exists()) {
                throw ValidationException::withMessages(['year' => 'An MTD schedule for '.$values['year'].' is already waiting to be checked. Check, reject or withdraw it first.']);
            }
            $now = now()->utc();
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_mtd_schedules')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, ...$values,
                'status' => 'entered', 'entered_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($rows as $i => $row) {
                DB::table('hr_mtd_schedule_rows')->insert(['organisation_id' => $actor->organisation_id, 'schedule_id' => $id, 'position' => $i + 1, ...$row]);
            }
            $this->support->remember($actor, $key, 'payroll_mtd_schedule', $hash, $publicId);
            $this->support->audit->record('hr.payroll.mtd_schedule_entered', null, ['schedule_id' => $id, 'year' => $values['year'], 'rounding' => $values['rounding'],
                'bands' => count($rows)], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, string $scheduleId, string $decision, array $input): void
    {
        if (! in_array($decision, ['check', 'reject'], true)) {
            abort(404);
        }
        $reason = $input['reason'] ?? null;
        if ($decision === 'reject' && (! is_string($reason) || ! array_key_exists($reason, PayrollSetupService::TABLE_REJECT_REASONS))) {
            throw ValidationException::withMessages(['reason' => 'Choose why it is rejected.']);
        }
        $reason = $decision === 'reject' ? (string) $reason : null;
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $scheduleId, $decision, $input, $key, $reason): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, 'payroll_mtd_schedule_decide', $scheduleId, $decision, $reason]);
            if ($this->support->replay($actor, $key, 'payroll_mtd_schedule_decide', $hash) !== null) {
                return;
            }
            $schedule = $this->row($actor, $scheduleId);
            $this->support->assertLock($schedule, $input);
            if ($schedule->status !== 'entered') {
                throw ValidationException::withMessages(['payroll' => 'This MTD schedule was already decided. Reload to see it.']);
            }
            $refusal = $this->setup->deciderRefusal($actor, (int) $schedule->entered_by_user_id, null);
            if ($refusal !== null) {
                throw new AuthorizationException($refusal);
            }
            $now = now()->utc();
            $replaced = 0;
            if ($decision === 'check') {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_mtd_schedules:'.$actor->organisation_id.':'.$schedule->year]);
                $replaced = DB::table('hr_mtd_schedules')->where('organisation_id', $actor->organisation_id)->where('year', $schedule->year)->where('status', 'checked')
                    ->update(['status' => 'replaced', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $now]);
            }
            DB::table('hr_mtd_schedules')->where('id', $schedule->id)->update(['status' => $decision === 'check' ? 'checked' : 'rejected',
                'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'decision_reason' => $reason, 'lock_version' => (int) $schedule->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'payroll_mtd_schedule_decide', $hash, (string) $schedule->public_id);
            $this->support->audit->record($decision === 'check' ? 'hr.payroll.mtd_schedule_checked' : 'hr.payroll.mtd_schedule_rejected', null,
                ['schedule_id' => (int) $schedule->id, 'year' => (int) $schedule->year, 'reason_code' => $reason, 'replaced' => $replaced], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input Only the person who entered a schedule withdraws it, before it is checked. */
    public function withdraw(User $actor, string $scheduleId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $scheduleId, $input, $key): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, 'payroll_mtd_schedule_withdraw', $scheduleId]);
            if ($this->support->replay($actor, $key, 'payroll_mtd_schedule_withdraw', $hash) !== null) {
                return;
            }
            $schedule = $this->row($actor, $scheduleId);
            $this->support->assertLock($schedule, $input);
            if ($schedule->status !== 'entered') {
                throw ValidationException::withMessages(['payroll' => 'This MTD schedule was already decided. Reload to see it.']);
            }
            if ((int) $schedule->entered_by_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who entered this MTD schedule may withdraw it.');
            }
            DB::table('hr_mtd_schedules')->where('id', $schedule->id)->update(['status' => 'withdrawn', 'lock_version' => (int) $schedule->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'payroll_mtd_schedule_withdraw', $hash, (string) $schedule->public_id);
            $this->support->audit->record('hr.payroll.mtd_schedule_withdrawn', null, ['schedule_id' => (int) $schedule->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    private function row(User $actor, string $publicId): \stdClass
    {
        if (! Str::isUuid($publicId)) {
            abort(404);
        }
        $row = DB::table('hr_mtd_schedules')->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->lockForUpdate()->first();
        abort_if(! $row instanceof \stdClass, 404);

        return $row;
    }

    /**
     * Every field of a schedule, with each band error under its own row. One submit names everything to fix.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, int|string>, 1: list<array<string, int|null>>}
     */
    public static function parse(array $input): array
    {
        $errors = [];
        $values = [];
        $year = $input['year'] ?? null;
        if (! is_scalar($year) || preg_match('/\A20\d{2}\z/', trim((string) $year)) !== 1) {
            $errors['year'] = 'Enter the year this schedule is for, for example 2026.';
        } else {
            $values['year'] = (int) $year;
        }
        $rounding = $input['rounding'] ?? null;
        if (! is_string($rounding) || ! array_key_exists($rounding, MtdCalculator::ROUNDING)) {
            $errors['rounding'] = 'Choose how MTD is rounded.';
        } else {
            $values['rounding'] = $rounding;
        }
        try {
            $values['minimum_sen'] = (int) PayrollMoney::sen($input['minimum'] ?? null, 'minimum', false, true);
        } catch (ValidationException $e) {
            $errors['minimum'] = 'Enter the smallest MTD that is deducted, in RM (0.00 to deduct any amount).';
        }
        foreach (array_keys(self::RELIEFS) as $field) {
            $sen = self::yearly($input[$field] ?? null, false);
            if ($sen === null) {
                $errors[$field] = 'Enter an amount in RM for the year, for example 9,000.00 (0.00 if none).';
            } else {
                $values["{$field}_sen"] = $sen;
            }
        }
        $note = is_string($input['source_note'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['source_note'])) : '';
        if (mb_strlen($note) < 2 || mb_strlen($note) > 200) {
            $errors['source_note'] = 'Say where these figures came from, in 2 to 200 characters.';
        } else {
            $values['source_note'] = $note;
        }
        $raw = $input['rows'] ?? null;
        $rows = [];
        if (! is_array($raw) || $raw === [] || count($raw) > self::MAX_BANDS) {
            $errors['rows'] = 'Add from 1 to 40 bands.';
        } else {
            $raw = array_values($raw);
            $last = count($raw) - 1;
            $previousTo = null;
            foreach ($raw as $i => $band) {
                $band = is_array($band) ? $band : [];
                $from = self::yearly($band['from'] ?? null, false);
                if ($from === null) {
                    $errors["rows.{$i}.from"] = 'Enter where this band starts (M), in RM.';
                }
                $blankTo = PayrollMoney::blank($band['to'] ?? null);
                $to = $blankTo ? null : self::yearly($band['to'], false);
                if ($blankTo && $i !== $last) {
                    $errors["rows.{$i}.to"] = 'Enter where this band ends. Only the last band may be left open.';
                } elseif (! $blankTo && $to === null) {
                    $errors["rows.{$i}.to"] = 'Enter where this band ends, in RM.';
                } elseif ($from !== null && $to !== null && $to < $from) {
                    $errors["rows.{$i}.to"] = 'A band ends at or after where it starts.';
                }
                if ($from !== null && $previousTo !== null && ! isset($errors["rows.{$i}.from"]) && $from !== $previousTo + 1) {
                    $errors["rows.{$i}.from"] = ($from <= $previousTo ? 'This band overlaps the one above.' : 'There is a gap after the band above.')
                        .' It should start at '.PayrollMoney::rm($previousTo + 1).'.';
                }
                $rate = 0;
                try {
                    $rate = PayrollMoney::basisPoints($band['rate'] ?? null, "rows.{$i}.rate");
                } catch (ValidationException $e) {
                    $errors["rows.{$i}.rate"] = $e->errors()["rows.{$i}.rate"][0];
                }
                $bases = [];
                foreach (['base_cat13' => 'categories 1 and 3', 'base_cat2' => 'category 2'] as $field => $words) {
                    $bases[$field] = self::yearly($band[$field] ?? null, true);
                    if ($bases[$field] === null) {
                        $errors["rows.{$i}.{$field}"] = "Enter the tax on M for {$words}, in RM (it may be below zero, for example -400.00).";
                    }
                }
                $previousTo = $to;
                $rows[] = ['chargeable_from_sen' => $from, 'chargeable_to_sen' => $to, 'rate_bps' => $rate, 'base_cat13_sen' => $bases['base_cat13'], 'base_cat2_sen' => $bases['base_cat2']];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$values, $rows];
    }

    /** A yearly RM amount ("9000" or "-400.00") to whole sen, up to RM 99,999,999.99; negative only where allowed. */
    public static function yearly(mixed $value, bool $signed): ?int
    {
        if (PayrollMoney::blank($value)) {
            return null;
        }
        $text = is_int($value) ? (string) $value : (is_string($value) ? str_replace([',', ' '], '', trim($value)) : '');
        if (preg_match('/\A(-?)(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $text, $m) !== 1 || ($m[1] === '-' && ! $signed)) {
            return null;
        }
        $sen = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        if ($sen > self::MAX_YEARLY_SEN) {
            return null;
        }

        return $m[1] === '-' ? -$sen : $sen;
    }
}
