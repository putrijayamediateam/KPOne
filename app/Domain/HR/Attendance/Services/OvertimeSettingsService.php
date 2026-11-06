<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Roster\RosterPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-3b clinic-entered overtime settings. KPOne ships no rule: the form suggests the clinic HR proposal's values and
 * ticks no role, and nothing counts until a manager saves a version. A version applies from its date (today or later),
 * so a change never rewrites a decision already made; each approval stores the version and multiplier it used.
 */
final class OvertimeSettingsService
{
    /** Suggestions only, shown in the empty form (HR-PLAN 10, TEAM.md decision 10). */
    public const SUGGESTED = ['normal_multiplier' => '1.50', 'rest_day_multiplier' => '2.00', 'public_holiday_multiplier' => '3.00', 'nearing_hours' => 40, 'limit_hours' => 45];

    /** @var array<int, Collection<int, OvertimeSettingsVersion>> */
    private array $versions = [];

    public function __construct(private AttendanceSupport $support, private AttendanceSettingsService $attendance) {}

    /** @return Collection<int, OvertimeSettingsVersion> newest effective date and id first */
    public function versions(int $organisationId): Collection
    {
        if (isset($this->versions[$organisationId])) {
            return $this->versions[$organisationId];
        }
        $rows = DB::table('hr_overtime_settings')->where('organisation_id', $organisationId)->orderByDesc('effective_from')->orderByDesc('id')->get();
        $roles = DB::table('hr_overtime_setting_roles')->whereIn('overtime_setting_id', $rows->pluck('id'))->orderBy('role_name')->get()->groupBy('overtime_setting_id');

        return $this->versions[$organisationId] = $rows->map(fn (object $row): OvertimeSettingsVersion => OvertimeSettingsVersion::fromRow($row,
            array_values(($roles[$row->id] ?? collect())->pluck('role_name')->map(fn ($r): string => (string) $r)->all())))->values();
    }

    public function forDate(int $organisationId, CarbonImmutable|string $date): ?OvertimeSettingsVersion
    {
        $day = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return $this->versions($organisationId)->first(fn (OvertimeSettingsVersion $v): bool => $v->effectiveFrom <= $day);
    }

    /** Whether the person holds a role the version allows to claim overtime. */
    public function eligible(OvertimeSettingsVersion $version, User $person): bool
    {
        return array_intersect($version->roles, $person->getRoleNames()->all()) !== [];
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input): OvertimeSettingsVersion
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $values = [
            'normal_multiplier' => $this->multiplier($input['normal_multiplier'] ?? null, 'normal_multiplier'),
            'rest_day_multiplier' => $this->multiplier($input['rest_day_multiplier'] ?? null, 'rest_day_multiplier'),
            'public_holiday_multiplier' => $this->multiplier($input['public_holiday_multiplier'] ?? null, 'public_holiday_multiplier'),
            'nearing_hours' => $this->number($input['nearing_hours'] ?? null, 'nearing_hours', 1, 167),
            'limit_hours' => $this->number($input['limit_hours'] ?? null, 'limit_hours', 2, 168),
        ];
        if ($values['nearing_hours'] >= $values['limit_hours']) {
            throw ValidationException::withMessages(['nearing_hours' => 'The nearing level must be below the weekly limit.']);
        }
        $roles = $this->roles($input['roles'] ?? []);

        $version = DB::transaction(function () use ($actor, $input, $key, $values, $roles): OvertimeSettingsVersion {
            $actor = $this->support->lock($actor, ClockPermissions::REVIEW);
            $from = $this->effectiveFrom($actor, $input['effective_from'] ?? null);
            $hash = $this->support->hash([$actor->id, 'overtime_settings', $from, ...array_values($values), ...$roles]);
            if (($replay = $this->support->replay($actor, $key, 'overtime_settings', $hash)) === null) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_overtime_settings:'.$actor->organisation_id]);
                $id = DB::table('hr_overtime_settings')->insertGetId(['organisation_id' => $actor->organisation_id, 'effective_from' => $from, ...$values,
                    'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
                foreach ($roles as $role) {
                    DB::table('hr_overtime_setting_roles')->insert(['overtime_setting_id' => $id, 'role_name' => $role]);
                }
                $this->support->remember($actor, $key, 'overtime_settings', $hash, (string) $id);
                $this->support->audit->record('hr.overtime.settings_saved', null, ['settings_id' => $id, 'effective_from' => $from, ...$values, 'roles' => $roles],
                    $actor, null, $actor->organisation_id);
            } else {
                $id = (int) $replay;
            }
            unset($this->versions[$actor->organisation_id]);

            return $this->versions($actor->organisation_id)->firstWhere('id', $id) ?? throw ValidationException::withMessages(['overtime' => 'Reload the page.']);
        }, 3);
        unset($this->versions[$actor->organisation_id]);

        return $version;
    }

    private function effectiveFrom(User $actor, mixed $value): string
    {
        $today = $this->attendance->today($actor->organisation_id)->toDateString();
        if ($value === null || $value === '') {
            return $today;
        }
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1 || CarbonImmutable::createFromFormat('!Y-m-d', $value)?->toDateString() !== $value) {
            throw ValidationException::withMessages(['overtime_effective_from' => 'Choose a date.']);
        }
        if ($value < $today) {
            throw ValidationException::withMessages(['overtime_effective_from' => 'Choose today or a later date. Past days keep the settings they had.']);
        }
        if ($value > CarbonImmutable::parse($today)->addYear()->toDateString()) {
            throw ValidationException::withMessages(['overtime_effective_from' => 'Choose a date within the next year.']);
        }

        return $value;
    }

    private function multiplier(mixed $value, string $field): string
    {
        $text = is_int($value) || is_float($value) ? (string) $value : $value;
        if (! is_string($text) || preg_match('/\A\d(\.\d{1,2})?\z/', trim($text)) !== 1 || (float) $text < 1 || (float) $text > 5) {
            throw ValidationException::withMessages([$field => 'Enter a number from 1.00 to 5.00, with at most two decimals.']);
        }

        return number_format((float) $text, 2, '.', '');
    }

    private function number(mixed $value, string $field, int $min, int $max): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($n === false) {
            throw ValidationException::withMessages([$field => "Enter a whole number from {$min} to {$max}."]);
        }

        return $n;
    }

    /** @return list<string> */
    private function roles(mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages(['roles' => 'Choose the roles that may claim overtime.']);
        }
        $roles = [];
        foreach ($value as $role) {
            if (! is_string($role) || ! in_array($role, RosterPermissions::HUMAN_ROLES, true)) {
                throw ValidationException::withMessages(['roles' => 'Choose the roles that may claim overtime.']);
            }
            $roles[] = $role;
        }
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }
}
