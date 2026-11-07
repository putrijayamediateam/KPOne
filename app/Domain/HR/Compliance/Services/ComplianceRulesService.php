<?php

namespace App\Domain\HR\Compliance\Services;

use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-5b clinic-entered compliance rules (HR-PLAN 12.3 rule 7). KPOne ships no number. Each save adds a version; the
 * newest is in force. Director and HR Manager save them (hr.credentials.manage.organisation). The rules only feed
 * warnings on the roster planner; nothing ever refuses a shift, a send or an approval because of them.
 */
final class ComplianceRulesService
{
    public const SUGGESTED = ['min_resident_doctors' => 1, 'max_days_in_row' => 6, 'min_rest_hours' => 11, 'max_week_hours' => 48];

    public function __construct(private RecordSupport $support) {}

    /** @return array{version: int, hours: array<int, array{open: string, close: string}>, minDoctors: int}|null */
    public static function coverage(int $branchId): ?array
    {
        $row = DB::table('hr_coverage_rules')->where('branch_id', $branchId)->orderByDesc('version')->first();
        if ($row === null) {
            return null;
        }
        $hours = [];
        foreach ((array) json_decode((string) $row->opening_hours, true) as $day => $span) {
            if (is_array($span) && is_string($span['open'] ?? null) && is_string($span['close'] ?? null)) {
                $hours[(int) $day] = ['open' => $span['open'], 'close' => $span['close']];
            }
        }
        ksort($hours);

        return ['version' => (int) $row->version, 'hours' => $hours, 'minDoctors' => (int) $row->min_resident_doctors];
    }

    /** @return array{version: int, maxDaysInRow: int|null, minRestMinutes: int|null, maxWeekMinutes: int|null}|null */
    public static function workHours(int $organisationId): ?array
    {
        $row = DB::table('hr_work_hour_rules')->where('organisation_id', $organisationId)->orderByDesc('version')->first();

        return $row === null ? null : ['version' => (int) $row->version, 'maxDaysInRow' => $row->max_days_in_row === null ? null : (int) $row->max_days_in_row,
            'minRestMinutes' => $row->min_rest_minutes === null ? null : (int) $row->min_rest_minutes, 'maxWeekMinutes' => $row->max_week_minutes === null ? null : (int) $row->max_week_minutes];
    }

    /** @param array<string, mixed> $input */
    public function saveCoverage(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $hours = $this->hours($input['hours'] ?? []);
        $min = $this->whole($input['min_resident_doctors'] ?? null, 0, 20, 'min_resident_doctors', 'Enter the minimum of resident doctors, from 0 to 20 (0 turns the warning off).');

        DB::transaction(function () use ($actor, $input, $key, $hours, $min): void {
            $actor = $this->support->lock($actor, CredentialPermissions::MANAGE);
            $branchId = filter_var($input['branch_id'] ?? null, FILTER_VALIDATE_INT);
            $branch = $branchId === false ? null : Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereKey($branchId)->first();
            if ($branch === null) {
                throw ValidationException::withMessages(['branch_id' => 'Choose a branch.']);
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_coverage_rules:'.$branch->id]);
            $hash = $this->support->hash([$actor->id, 'coverage', $branch->id, $hours, $min]);
            if ($this->support->replay($actor, $key, 'compliance_coverage', $hash) !== null) {
                return;
            }
            $current = (int) DB::table('hr_coverage_rules')->where('branch_id', $branch->id)->max('version');
            $this->assertVersion($input, $current);
            DB::table('hr_coverage_rules')->insert(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'version' => $current + 1,
                'opening_hours' => json_encode((object) $hours, JSON_THROW_ON_ERROR), 'min_resident_doctors' => $min, 'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'compliance_coverage', $hash, (string) ($current + 1));
            $this->support->audit->record('hr.compliance.coverage_saved', null, ['branch_id' => $branch->id, 'version' => $current + 1, 'open_days' => count($hours),
                'min_resident_doctors' => $min], $actor, $branch, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function saveWorkHours(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $days = $this->optional($input['max_days_in_row'] ?? null, 1, 13, 'max_days_in_row', 'Enter the most days in a row, from 1 to 13, or leave it empty.');
        $rest = $this->optional($input['min_rest_hours'] ?? null, 1, 24, 'min_rest_hours', 'Enter the least rest between shifts in whole hours, from 1 to 24, or leave it empty.');
        $week = $this->optional($input['max_week_hours'] ?? null, 1, 100, 'max_week_hours', 'Enter the most planned hours a week, from 1 to 100, or leave it empty.');

        DB::transaction(function () use ($actor, $input, $key, $days, $rest, $week): void {
            $actor = $this->support->lock($actor, CredentialPermissions::MANAGE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_work_hour_rules:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'work_hours', $days, $rest, $week]);
            if ($this->support->replay($actor, $key, 'compliance_work_hours', $hash) !== null) {
                return;
            }
            $current = (int) DB::table('hr_work_hour_rules')->where('organisation_id', $actor->organisation_id)->max('version');
            $this->assertVersion($input, $current);
            DB::table('hr_work_hour_rules')->insert(['organisation_id' => $actor->organisation_id, 'version' => $current + 1, 'max_days_in_row' => $days,
                'min_rest_minutes' => $rest === null ? null : $rest * 60, 'max_week_minutes' => $week === null ? null : $week * 60,
                'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'compliance_work_hours', $hash, (string) ($current + 1));
            $this->support->audit->record('hr.compliance.work_hours_saved', null, ['version' => $current + 1, 'max_days_in_row' => $days, 'min_rest_hours' => $rest,
                'max_week_hours' => $week], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    private function assertVersion(array $input, int $current): void
    {
        if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== $current) {
            throw ValidationException::withMessages(['compliance' => 'These rules were changed by someone else. Reload to see the latest version.']);
        }
    }

    /** @return array<int, array{open: string, close: string}> ISO weekday => one opening span; an empty day is closed */
    private function hours(mixed $input): array
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages(['hours' => 'Enter the opening hours.']);
        }
        $out = [];
        $errors = [];
        for ($day = 1; $day <= 7; $day++) {
            $span = $input[$day] ?? $input[(string) $day] ?? null;
            $open = is_array($span) && is_string($span['open'] ?? null) ? trim($span['open']) : '';
            $close = is_array($span) && is_string($span['close'] ?? null) ? trim($span['close']) : '';
            if ($open === '' && $close === '') {
                continue; // closed
            }
            $valid = fn (string $t): bool => preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $t) === 1;
            if (! $valid($open) || ! $valid($close)) {
                $errors["hours.{$day}"] = 'Enter both times, or leave both empty for a closed day.';
            } elseif ($close <= $open) {
                $errors["hours.{$day}"] = 'Closing must be after opening on the same day.';
            } else {
                $out[$day] = ['open' => $open, 'close' => $close];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function whole(mixed $value, int $min, int $max, string $field, string $message): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($n === false) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $n;
    }

    private function optional(mixed $value, int $min, int $max, string $field, string $message): ?int
    {
        return $value === null || $value === '' ? null : $this->whole($value, $min, $max, $field, $message);
    }
}
