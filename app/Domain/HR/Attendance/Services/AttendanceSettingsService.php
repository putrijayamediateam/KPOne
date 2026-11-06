<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-3a clinic-entered attendance settings. KPOne ships no number: the form suggests the clinic HR proposal's values
 * but nothing counts until a manager saves a version. A version applies from its date (never earlier than today), so a
 * change never rewrites flags already shown for past days.
 */
final class AttendanceSettingsService
{
    /** Suggestions only, shown in the empty form (HR-PLAN 9.9). */
    public const SUGGESTED = ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7];

    /** @var array<int, Collection<int, AttendanceSettingsVersion>> */
    private array $versions = [];

    public function __construct(private AttendanceSupport $support) {}

    /** @return Collection<int, AttendanceSettingsVersion> every version of the organisation, newest effective date and id first */
    public function versions(int $organisationId): Collection
    {
        return $this->versions[$organisationId] ??= DB::table('hr_attendance_settings')->where('organisation_id', $organisationId)
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->map(fn (object $row): AttendanceSettingsVersion => AttendanceSettingsVersion::fromRow($row))->values();
    }

    /** The version in force on a branch-local date, or null when attendance is not set up for that day. */
    public function forDate(int $organisationId, CarbonImmutable|string $date): ?AttendanceSettingsVersion
    {
        $day = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return $this->versions($organisationId)->first(fn (AttendanceSettingsVersion $v): bool => $v->effectiveFrom <= $day);
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input): AttendanceSettingsVersion
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $values = [
            'grace_minutes' => $this->number($input['grace_minutes'] ?? null, 'grace_minutes', 0, 120),
            'forgotten_window_minutes' => $this->number($input['forgotten_window_minutes'] ?? null, 'forgotten_window_minutes', 15, 720),
            'correction_days' => $this->number($input['correction_days'] ?? null, 'correction_days', 1, 31),
        ];

        $row = DB::transaction(function () use ($actor, $input, $key, $values): AttendanceSettingsVersion {
            $actor = $this->support->lock($actor, ClockPermissions::REVIEW);
            $from = $this->effectiveFrom($actor, $input['effective_from'] ?? null);
            $hash = $this->support->hash([$actor->id, 'settings', $from, ...array_values($values)]);
            if (($replay = $this->support->replay($actor, $key, 'attendance_settings', $hash)) !== null) {
                return AttendanceSettingsVersion::fromRow(DB::table('hr_attendance_settings')->where('id', (int) $replay)->first() ?? throw ValidationException::withMessages(['attendance' => 'Reload the page.']));
            }
            // One saver at a time per organisation, so two saves cannot interleave their effective dates.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_attendance_settings:'.$actor->organisation_id]);
            $id = DB::table('hr_attendance_settings')->insertGetId(['organisation_id' => $actor->organisation_id, 'effective_from' => $from, ...$values,
                'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'attendance_settings', $hash, (string) $id);
            $this->support->audit->record('hr.attendance.settings_saved', null, ['settings_id' => $id, 'effective_from' => $from, ...$values], $actor, null, $actor->organisation_id);

            return AttendanceSettingsVersion::fromRow(DB::table('hr_attendance_settings')->where('id', $id)->first() ?? throw ValidationException::withMessages(['attendance' => 'Reload the page.']));
        }, 3);
        unset($this->versions[$actor->organisation_id]);

        return $row;
    }

    /** The organisation's "today" for settings: the first active branch's local date (all branches share a time zone today). */
    public function today(int $organisationId): CarbonImmutable
    {
        $zone = Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->startOfDay();
    }

    private function effectiveFrom(User $actor, mixed $value): string
    {
        $today = $this->today($actor->organisation_id)->toDateString();
        if ($value === null || $value === '') {
            return $today;
        }
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1 || CarbonImmutable::createFromFormat('!Y-m-d', $value)?->toDateString() !== $value) {
            throw ValidationException::withMessages(['effective_from' => 'Choose a date.']);
        }
        if ($value < $today) {
            throw ValidationException::withMessages(['effective_from' => 'Choose today or a later date. Past days keep the settings they had.']);
        }
        if ($value > CarbonImmutable::parse($today)->addYear()->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => 'Choose a date within the next year.']);
        }

        return $value;
    }

    private function number(mixed $value, string $field, int $min, int $max): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($n === false) {
            throw ValidationException::withMessages([$field => "Enter a whole number from {$min} to {$max}."]);
        }

        return $n;
    }
}
