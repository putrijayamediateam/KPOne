<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared write plumbing for HR-11 clinic compliance, following the HR slices: the account, its roles and grants are
 * locked and re-read inside the transaction and must hold the permission now; one save at a time per key with
 * idempotent replay; optimistic `lock_version`; audit holds ids and codes only.
 */
final class ComplianceSupport
{
    public function __construct(public readonly AuditRecorder $audit, private BranchAccessService $branches) {}

    /** As lock(), for an operation open to the holder of any one of several permissions. */
    /** @param  list<string>  $permissions */
    public function lockAny(User $actor, array $permissions): User
    {
        $user = $this->lock($actor, '__none__', false);
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $user;
            }
        }
        throw new AuthorizationException('You may not perform this operation.');
    }

    public function lock(User $actor, string $permission, bool $check = true): User
    {
        $user = User::query()->whereKey($actor->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        $roleIds = DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('role_id')->lockForUpdate()->pluck('role_id');
        DB::table('roles')->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->orderBy('role_id')->orderBy('permission_id')->lockForUpdate()->get();
        DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('permission_id')->lockForUpdate()->get();
        $user->load(['roles.permissions', 'permissions']);
        if (! $user->is_active || ($check && ! $user->can($permission))) {
            throw new AuthorizationException('You may not perform this operation.');
        }

        return $user;
    }

    /** The holder must be assigned to the branch today (branch-local), as for other branch permissions. */
    public function assertBranch(User $actor, int $branchId): void
    {
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKey($branchId)->first();
        if ($branch === null || ! $this->branches->hasEffectiveAssignment($actor, $branch)) {
            throw new AuthorizationException('You may not perform this operation.');
        }
    }

    public function key(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages(['compliance' => 'Refresh this page and try again.']);
        }

        return $value;
    }

    /** @param list<mixed> $parts */
    public function hash(array $parts): string
    {
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), 'kpone-compliance-retry|'.(string) config('app.key'));
    }

    public function replay(User $actor, string $key, string $action, string $hash): ?string
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['compliance_key:'.$actor->organisation_id.':'.$key]);
        $row = DB::table('compliance_retries')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
        if ($row === null) {
            return null;
        }
        if ($row->action !== $action || $row->payload_hash !== $hash || (int) $row->actor_user_id !== $actor->id) {
            throw ValidationException::withMessages(['compliance' => 'This request was already used for a different change. Reload and try again.']);
        }

        return (string) $row->result;
    }

    public function remember(User $actor, string $key, string $action, string $hash, string $result): void
    {
        DB::table('compliance_retries')->insert(['organisation_id' => $actor->organisation_id, 'idempotency_key' => $key, 'actor_user_id' => $actor->id,
            'action' => $action, 'payload_hash' => $hash, 'result' => $result, 'created_at' => now()->utc()]);
    }

    /** @param array<string, mixed> $input */
    public function assertLock(object $row, array $input, string $field = 'expected_lock_version'): void
    {
        if (filter_var($input[$field] ?? null, FILTER_VALIDATE_INT) !== (int) data_get($row, 'lock_version')) {
            throw ValidationException::withMessages(['compliance' => 'This was changed by someone else. Reload to see the latest version.']);
        }
    }

    /** "Today" for whole-clinic items: the first active branch's zone. A branch's own licence uses that branch's zone. */
    public function today(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->toDateString();
    }

    public function text(mixed $value, string $field, int $max, bool $required = false): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw ValidationException::withMessages([$field => 'Fill this in.']);
            }

            return null;
        }
        $clean = is_string($value) ? trim((string) preg_replace('/\s+/', ' ', $value)) : '';
        if ($clean === '' || mb_strlen($clean) > $max) {
            throw ValidationException::withMessages([$field => "Use at most {$max} characters."]);
        }

        return $clean;
    }

    public function date(mixed $value, string $field, bool $required = false): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => 'Enter a date.']);
            }

            return null;
        }
        $parsed = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $value || $parsed->year < 1990 || $parsed->year > 2100) {
            throw ValidationException::withMessages([$field => 'Enter a valid date.']);
        }

        return $value;
    }
}
