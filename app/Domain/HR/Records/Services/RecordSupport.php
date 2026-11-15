<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Roster\Services\RosterAuthority;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared write plumbing for HR-4a, following HR-2a and HR-3a: account locks in one id-ordered pass, a fresh permission
 * check under lock, per-key serialisation with idempotent replay, the worker row lock, and the change log (field names
 * only, never values).
 */
final class RecordSupport
{
    public function __construct(public readonly RosterAuthority $authority, public readonly AuditRecorder $audit) {}

    /** @param list<int|null> $people */
    public function lock(User $actor, string $permission, array $people = []): User
    {
        $ids = array_values(array_unique(array_filter([$actor->id, ...$people], fn ($id): bool => is_int($id) && $id > 0)));
        sort($ids);
        User::query()->whereIn('id', $ids)->where('organisation_id', $actor->organisation_id)->orderBy('id')->lockForUpdate()->get(['id']);

        return $this->authority->lock($actor, $permission);
    }

    public function key(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages(['records' => 'Refresh this page and try again.']);
        }

        return $value;
    }

    /** @param list<mixed> $parts */
    public function hash(array $parts): string
    {
        // Keyed (Suresh, B1): the parts can hold identity, date-of-birth and bank values, and retries are append-only, so a
        // plain digest could be reversed by guessing. Only someone holding the application key can recompute it.
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), 'kpone-hr-retry|'.(string) config('app.key'));
    }

    public function replay(User $actor, string $key, string $action, string $hash): ?string
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_records_key:'.$actor->organisation_id.':'.$key]);
        $row = DB::table('hr_record_retries')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
        if ($row === null) {
            return null;
        }
        if ($row->action !== $action || $row->payload_hash !== $hash || (int) $row->actor_user_id !== $actor->id) {
            throw ValidationException::withMessages(['records' => 'This request was already used for a different change. Reload and try again.']);
        }

        return (string) $row->result;
    }

    public function remember(User $actor, string $key, string $action, string $hash, string $result): void
    {
        DB::table('hr_record_retries')->insert(['organisation_id' => $actor->organisation_id, 'idempotency_key' => $key, 'actor_user_id' => $actor->id,
            'action' => $action, 'payload_hash' => $hash, 'result' => $result, 'created_at' => now()->utc()]);
    }

    /** @param array<string, mixed> $input */
    public function assertLock(object $row, array $input, string $field = 'expected_lock_version'): void
    {
        if (filter_var($input[$field] ?? null, FILTER_VALIDATE_INT) !== (int) data_get($row, 'lock_version')) {
            throw ValidationException::withMessages(['records' => 'This was changed by someone else. Reload to see the latest version.']);
        }
    }

    /** The worker's user id for a public id, read before any lock so the account can join the id-ordered lock. */
    public function workerUserId(User $actor, string $workerId): ?int
    {
        if (! Str::isUuid($workerId)) {
            abort(404);
        }
        $row = Worker::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $workerId)->first(['user_id']);
        abort_if($row === null, 404);

        return $row->user_id;
    }

    public function lockWorker(User $actor, string $workerId): Worker
    {
        return Worker::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $workerId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Nobody manages their own file; the Director's file is changed only by another Director, and an HR Manager's only
     * by the Director or another HR Manager (HR-PLAN 11.3 rule 3; AGENTS.md protected director authority).
     */
    public function assertManages(User $actor, Worker $worker): void
    {
        $refusal = $this->refusal($actor, $worker->user_id);
        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }
    }

    /** Rule 3 as one predicate, shared by the services and the page (Suresh, S4). Null when the actor may manage. */
    public function refusal(User $actor, ?int $subjectUserId): ?string
    {
        if ($subjectUserId === null) {
            return null;
        }
        if ($subjectUserId === $actor->id) {
            return 'You may not manage your own staff file. Ask someone else, or use My HR details.';
        }
        $subject = User::query()->find($subjectUserId);

        return $subject === null ? 'This staff file cannot be managed.' : $this->refusalFor($actor, $subject);
    }

    /** The same rule for an already loaded account (roles eager-loaded), so a list makes no query per person. */
    public function refusalFor(User $actor, User $subject): ?string
    {
        if ($subject->id === $actor->id) {
            return 'You may not manage your own staff file. Ask someone else, or use My HR details.';
        }
        if ($subject->hasRole('director') && ! $actor->hasRole('director')) {
            return "Only a Director may change a Director's staff file.";
        }
        if ($subject->hasRole('hr_manager') && ! $actor->hasRole('director') && ! $actor->hasRole('hr_manager')) {
            return "Only the Director or an HR Manager may change an HR Manager's staff file.";
        }

        return null;
    }

    /** @param list<string> $fields */
    public function change(User $actor, Worker $worker, string $area, array $fields, ?string $reason = null): void
    {
        $fields = array_map(fn (string $f): string => str_replace('_encrypted', '', $f), $fields);
        DB::table('hr_record_changes')->insert(['organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id, 'area' => $area,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'reason_code' => $reason, 'changed_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
    }

    /** Last four characters for a masked value ("•••• 1234"). */
    public static function last4(string $value): string
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', $value) ?? '';

        return substr($clean, -4);
    }

    public function note(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value) || mb_strlen(trim($value)) > 200) {
            throw ValidationException::withMessages(['note' => 'Use at most 200 characters.']);
        }

        return trim($value);
    }
}
