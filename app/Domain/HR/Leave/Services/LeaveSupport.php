<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Roster\Services\RosterAuthority;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared write plumbing for HR-2a: account locks in one id-ordered pass (the HR-1 rule), a fresh permission check
 * under lock, per-key serialisation and idempotent replay, and structural audit (ids, codes and counts only).
 */
final class LeaveSupport
{
    public function __construct(public readonly RosterAuthority $authority, public readonly AuditRecorder $audit) {}

    /**
     * Locks the actor and every other person the write touches, in id order, before any leave row; then re-reads the
     * actor's grants under lock and requires the permission.
     *
     * @param  list<mixed>  $people
     */
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
            throw ValidationException::withMessages(['leave' => 'Refresh this page and try again.']);
        }

        return $value;
    }

    /** @param list<mixed> $parts */
    public function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** Returns the stored result of an earlier identical request, or null; a reused key with other content is refused. */
    public function replay(User $actor, string $key, string $action, string $hash): ?string
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_leave_key:'.$actor->organisation_id.':'.$key]);
        $row = DB::table('hr_leave_retries')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
        if ($row === null) {
            return null;
        }
        if ($row->action !== $action || $row->payload_hash !== $hash || (int) $row->actor_user_id !== $actor->id) {
            throw ValidationException::withMessages(['leave' => 'This request was already used for a different change. Reload and try again.']);
        }

        return (string) $row->result;
    }

    public function remember(User $actor, string $key, string $action, string $hash, string $result): void
    {
        DB::table('hr_leave_retries')->insert(['organisation_id' => $actor->organisation_id, 'idempotency_key' => $key, 'actor_user_id' => $actor->id,
            'action' => $action, 'payload_hash' => $hash, 'result' => $result, 'created_at' => now()->utc()]);
    }

    /** @param array<string, mixed> $input */
    public function assertLock(object $row, array $input, string $field = 'expected_lock_version'): void
    {
        if (filter_var($input[$field] ?? null, FILTER_VALIDATE_INT) !== (int) data_get($row, 'lock_version')) {
            throw ValidationException::withMessages(['leave' => 'This was changed by someone else. Reload to see the latest version.']);
        }
    }

    /** Days typed as "1", "1.5" or "0.5" become whole half days; anything else is refused. */
    public function halves(mixed $value, string $field, bool $allowNegative = false, int $max = 730): int
    {
        $text = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (preg_match('/\A(-?)(\d{1,3})(?:\.(0|5|50|00))?\z/', $text, $m) !== 1 || ($m[1] === '-' && ! $allowNegative)) {
            throw ValidationException::withMessages([$field => $allowNegative ? 'Enter days in steps of 0.5, for example 1.5 or -1.' : 'Enter days in steps of 0.5, for example 1.5.']);
        }
        $halves = ((int) $m[2]) * 2 + (isset($m[3]) && str_starts_with($m[3], '5') ? 1 : 0);
        if ($halves > $max) {
            throw ValidationException::withMessages([$field => 'That is more days than allowed.']);
        }

        return $m[1] === '-' ? -$halves : $halves;
    }

    public static function days(int $halves): string
    {
        $abs = abs($halves);
        $text = intdiv($abs, 2).($abs % 2 === 1 ? '.5' : '');

        return ($halves < 0 ? '−' : '').$text;
    }
}
