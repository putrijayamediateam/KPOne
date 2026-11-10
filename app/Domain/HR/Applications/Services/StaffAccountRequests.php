<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-17e staff-account handoff (HR-PLAN 19.8; owner, 2026-11-10: "anyone yg ada access to create staff"). HR asks for a
 * staff account for a passed candidate; the request (name, email, position, branch) is shown on the Staff page to
 * whoever passes the staff-creation check (staff.manage.organisation and access.manage.organisation, the same check as
 * StaffProvisioningService). That person creates the account the usual way and marks the request done. HR creates no
 * account and changes no role or branch; nothing here touches users, roles or branch assignments.
 */
final class StaffAccountRequests
{
    public const CREATE_STAFF = ['staff.manage.organisation', 'access.manage.organisation'];

    public function __construct(private RecordSupport $support) {}

    /** Whether a user passes the staff-creation check. */
    public static function canProvision(User $user): bool
    {
        return collect(self::CREATE_STAFF)->every(fn (string $permission): bool => $user->can($permission));
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function request(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        if (! Str::isUuid($applicationId)) {
            abort(404);
        }
        DB::transaction(function () use ($actor, $applicationId, $input, $key): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'staff_account_request', $applicationId]);
            if ($this->support->replay($actor, $key, 'staff_account_request', $hash) !== null) {
                return;
            }
            $application = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)->where('public_id', $applicationId)->lockForUpdate()->first();
            abort_if(! $application instanceof \stdClass, 404);
            $this->support->assertLock($application, $input);
            if ($application->status !== 'passed' || $application->purged_at !== null) {
                throw ValidationException::withMessages(['application' => 'A staff account can be asked for only for a candidate who passed.']);
            }
            if (DB::table('hr_staff_account_requests')->where('application_id', $application->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['application' => 'A staff account was already asked for. It is waiting on the Staff page.']);
            }
            $position = DB::table('hr_job_positions')->where('id', $application->position_id)->first(['title', 'branch_id']);
            $now = now()->utc();
            $id = DB::table('hr_staff_account_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                'application_id' => $application->id, 'name_encrypted' => $application->name_encrypted, 'email_encrypted' => $application->email_encrypted,
                'position_title' => mb_substr((string) $position?->title, 0, 80), 'branch_id' => $position?->branch_id, 'status' => 'open',
                'requested_by_user_id' => $actor->id, 'requested_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'action' => 'account_requested', 'actor_user_id' => $actor->id, 'created_at' => $now]);
            $this->support->remember($actor, $key, 'staff_account_request', $hash, (string) $id);
            $this->support->audit->record('hr.applications.account_requested', null, ['application_id' => (int) $application->id, 'request_id' => $id],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * HR withdraws its own open request.
     *
     * @param  array<string, mixed>  $input  expected_lock_version, idempotency_key
     */
    public function cancel(User $actor, string $requestId, array $input): void
    {
        $this->close($actor, $requestId, $input, 'cancelled', ApplicationPermissions::MANAGE);
    }

    /**
     * The account administrator marks the request done after creating the account.
     *
     * @param  array<string, mixed>  $input  expected_lock_version, idempotency_key
     */
    public function done(User $actor, string $requestId, array $input): void
    {
        $this->close($actor, $requestId, $input, 'done', self::CREATE_STAFF[0]);
    }

    /**
     * Open requests for the Staff page, or null for anyone who may not create staff accounts.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function openFor(User $actor): ?array
    {
        if (! self::canProvision($actor)) {
            return null;
        }
        $zone = JobApplicationService::zone((int) $actor->organisation_id);
        $rows = DB::table('hr_staff_account_requests as r')->leftJoin('branches as b', 'b.id', '=', 'r.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.requested_by_user_id')->where('r.organisation_id', $actor->organisation_id)
            ->where('r.status', 'open')->orderBy('r.requested_at')->orderBy('r.id')->limit(50)
            ->get(['r.public_id', 'r.name_encrypted', 'r.email_encrypted', 'r.position_title', 'r.requested_at', 'r.lock_version', 'b.name as branch', 'u.name as requester']);

        return array_values($rows->map(fn (\stdClass $r): array => ['id' => (string) $r->public_id, 'name' => self::reveal($r->name_encrypted),
            'email' => self::reveal($r->email_encrypted), 'position' => (string) $r->position_title, 'branch' => (string) ($r->branch ?? 'Any branch'),
            'requestedOn' => CarbonImmutable::parse($r->requested_at)->setTimezone($zone)->format('j M Y'), 'requestedBy' => (string) ($r->requester ?? 'Staff member'),
            'lockVersion' => (int) $r->lock_version])->all());
    }

    /**
     * The latest request for an application, for HR's drawer.
     *
     * @return array{id:string, status:string, statusLabel:string, at:string, lockVersion:int}|null
     */
    public static function latestFor(int $applicationId, string $zone): ?array
    {
        $r = DB::table('hr_staff_account_requests')->where('application_id', $applicationId)->orderByDesc('id')->first();
        if ($r === null) {
            return null;
        }
        $labels = ['open' => 'Asked for, waiting on the Staff page', 'done' => 'Account created by the administrator', 'cancelled' => 'Request cancelled'];

        return ['id' => (string) $r->public_id, 'status' => (string) $r->status, 'statusLabel' => $labels[$r->status] ?? (string) $r->status,
            'at' => CarbonImmutable::parse($r->closed_at ?? $r->requested_at)->setTimezone($zone)->format('j M Y, g:i A'), 'lockVersion' => (int) $r->lock_version];
    }

    /** @param array<string, mixed> $input */
    private function close(User $actor, string $requestId, array $input, string $to, string $permission): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        if (! Str::isUuid($requestId)) {
            abort(404);
        }
        DB::transaction(function () use ($actor, $requestId, $input, $key, $to, $permission): void {
            $actor = $this->support->lock($actor, $permission);
            if ($to === 'done' && ! self::canProvision($actor)) {
                throw new AuthorizationException('Only someone who can create staff accounts marks this done.');
            }
            $hash = $this->support->hash([$actor->id, 'staff_account_request_'.$to, $requestId]);
            if ($this->support->replay($actor, $key, 'staff_account_request_'.$to, $hash) !== null) {
                return;
            }
            $request = DB::table('hr_staff_account_requests')->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->lockForUpdate()->first();
            abort_if(! $request instanceof \stdClass, 404);
            if ($request->status !== 'open') {
                throw ValidationException::withMessages(['request' => 'This request was already closed.']);
            }
            $this->support->assertLock($request, $input);
            $now = now()->utc();
            DB::table('hr_staff_account_requests')->where('id', $request->id)->update(['status' => $to, 'closed_by_user_id' => $actor->id, 'closed_at' => $now,
                'lock_version' => (int) $request->lock_version + 1, 'updated_at' => $now]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $request->application_id,
                'action' => $to === 'done' ? 'account_done' : 'account_cancelled', 'actor_user_id' => $actor->id, 'created_at' => $now]);
            $this->support->remember($actor, $key, 'staff_account_request_'.$to, $hash, $requestId);
            $this->support->audit->record('hr.applications.account_request_'.$to, null, ['request_id' => (int) $request->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    private static function reveal(?string $encrypted): string
    {
        if ($encrypted === null) {
            return '';
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return '';
        }
    }
}
