<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Roster\Services\RosterAuthority;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared write plumbing for HR-3a, following HR-2a: account locks in one id-ordered pass, a fresh permission check
 * under lock, per-key serialisation and idempotent replay, and the time formats staff see.
 */
final class AttendanceSupport
{
    /** A session older than this cannot be closed by the button; it needs a clock-out correction. */
    public const MAX_SESSION_HOURS = 16;

    /** A press this soon after the last one is a double tap and is refused. */
    public const DOUBLE_TAP_SECONDS = 60;

    public function __construct(public readonly RosterAuthority $authority, public readonly AuditRecorder $audit) {}

    /** @param list<mixed> $people */
    public function lock(User $actor, string $permission, array $people = []): User
    {
        $ids = [$actor->id];
        foreach ($people as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value !== false) {
                $ids[] = (int) $value;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        User::query()->whereIn('id', $ids)->where('organisation_id', $actor->organisation_id)->orderBy('id')->lockForUpdate()->get(['id']);

        return $this->authority->lock($actor, $permission);
    }

    public function key(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages(['attendance' => 'Refresh this page and try again.']);
        }

        return $value;
    }

    /** @param list<mixed> $parts */
    public function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public function replay(User $actor, string $key, string $action, string $hash): ?string
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_attendance_key:'.$actor->organisation_id.':'.$key]);
        $row = DB::table('hr_attendance_retries')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
        if ($row === null) {
            return null;
        }
        if ($row->action !== $action || $row->payload_hash !== $hash || (int) $row->actor_user_id !== $actor->id) {
            throw ValidationException::withMessages(['attendance' => 'This request was already used for a different change. Reload and try again.']);
        }

        return (string) $row->result;
    }

    public function remember(User $actor, string $key, string $action, string $hash, string $result): void
    {
        DB::table('hr_attendance_retries')->insert(['organisation_id' => $actor->organisation_id, 'idempotency_key' => $key, 'actor_user_id' => $actor->id,
            'action' => $action, 'payload_hash' => $hash, 'result' => $result, 'created_at' => now()->utc()]);
    }

    /** @param array<string, mixed> $input */
    public function assertLock(object $row, array $input, string $field = 'expected_lock_version'): void
    {
        if (filter_var($input[$field] ?? null, FILTER_VALIDATE_INT) !== (int) data_get($row, 'lock_version')) {
            throw ValidationException::withMessages(['attendance' => 'This was changed by someone else. Reload to see the latest version.']);
        }
    }

    /**
     * Whether the person has an effective assignment to the branch on the branch-local date. With $lock the profile and
     * its assignments are locked first (the order BranchAssignmentService uses), so a change cannot slip in between.
     */
    public function assigned(User $person, Branch $branch, CarbonImmutable $localDate, bool $lock = false): bool
    {
        $profile = StaffProfile::query()->where('user_id', $person->id)->when($lock, fn ($q) => $q->lockForUpdate())->value('id');
        if ($profile === null) {
            return false;
        }
        $day = $localDate->toDateString();

        return StaffBranchAssignment::query()->where('staff_profile_id', $profile)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()
            ->contains(fn (StaffBranchAssignment $a): bool => $a->branch_id === $branch->id && $a->valid_from->toDateString() <= $day
                && ($a->valid_until === null || $a->valid_until->toDateString() >= $day));
    }

    public static function time(?CarbonImmutable $at, string $timezone): ?string
    {
        return $at?->setTimezone($timezone)->format('g:i A');
    }

    public static function day(CarbonImmutable $date): string
    {
        return $date->format('j M Y');
    }

    /** "9 h 2 min", "45 min", "8 h" (Daniel: never a decimal). */
    public static function duration(int $minutes): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;

        return match (true) {
            $h === 0 => "{$m} min",
            $m === 0 => "{$h} h",
            default => "{$h} h {$m} min",
        };
    }
}
