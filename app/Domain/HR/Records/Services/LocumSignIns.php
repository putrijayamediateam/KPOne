<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Domain\HR\Applications\Services\StaffAccountRequests;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-15a locum sign-ins (HR-PLAN 21.3). A locum file has no KPOne sign-in by default. HR asks for one, giving the email
 * the locum will sign in with; the request shows on the Staff page to whoever passes the staff-creation check
 * (StaffProvisioningService's own check). That person creates the account the usual way with the `locum` role, then
 * links it to the file here, which closes the request. HR creates no account and changes no role or branch, and
 * nothing here touches users, roles or branch assignments. A link is set once and never removed.
 */
final class LocumSignIns
{
    public function __construct(private RecordSupport $support) {}

    /** @param array<string, mixed> $input email, idempotency_key */
    public function request(User $actor, string $workerId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $email = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : '';
        if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'Enter the email the locum will sign in with.']);
        }
        abort_if(! Str::isUuid($workerId), 404);
        DB::transaction(function () use ($actor, $workerId, $email, $key): void {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'locum_sign_in', $workerId, hash('sha256', $email)]);
            if ($this->support->replay($actor, $key, 'locum_sign_in', $hash) !== null) {
                return;
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            if ($worker->kind !== 'locum') {
                throw ValidationException::withMessages(['email' => 'A sign-in is asked for only for a locum file.']);
            }
            $existing = DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->whereIn('status', ['open', 'done'])->value('status');
            if ($existing !== null) {
                throw ValidationException::withMessages(['email' => $existing === 'done' ? 'This locum already has a sign-in.'
                    : 'A sign-in was already asked for. It is waiting on the Staff page.']);
            }
            $now = now()->utc();
            $id = DB::table('hr_locum_sign_ins')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                'worker_id' => $worker->id, 'email_encrypted' => Crypt::encryptString($email), 'status' => 'open', 'requested_by_user_id' => $actor->id,
                'requested_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'locum_sign_in', $hash, (string) $id);
            $this->support->audit->record('hr.records.locum_sign_in_requested', $worker, ['request_id' => $id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * HR withdraws its open request.
     *
     * @param  array<string, mixed>  $input  expected_lock_version, idempotency_key
     */
    public function cancel(User $actor, string $requestId, array $input): void
    {
        $this->close($actor, $requestId, $input, 'cancelled', RecordPermissions::MANAGE, null);
    }

    /**
     * The account administrator links the account they created and closes the request.
     *
     * @param  array<string, mixed>  $input  user_id, expected_lock_version, idempotency_key
     */
    public function link(User $actor, string $requestId, array $input): void
    {
        $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            throw ValidationException::withMessages(['user_id' => 'Choose the locum account you created.']);
        }
        $this->close($actor, $requestId, $input, 'done', StaffAccountRequests::CREATE_STAFF[0], $userId);
    }

    /**
     * For the Staff page: open requests, each with the locum account that can be linked to it (the one with the email HR
     * asked for), or null for anyone who may not create staff accounts.
     *
     * @return array{requests:list<array<string, mixed>>}|null
     */
    public static function openFor(User $actor): ?array
    {
        if (! StaffAccountRequests::canProvision($actor)) {
            return null;
        }
        $zone = JobApplicationService::zone((int) $actor->organisation_id);
        $rows = DB::table('hr_locum_sign_ins as s')->join('hr_workers as w', 'w.id', '=', 's.worker_id')->leftJoin('users as u', 'u.id', '=', 's.requested_by_user_id')
            ->where('s.organisation_id', $actor->organisation_id)->where('s.status', 'open')->orderBy('s.requested_at')->orderBy('s.id')->limit(50)
            ->get(['s.public_id', 's.email_encrypted', 's.requested_at', 's.lock_version', 'w.display_name', 'u.name as requester']);

        return [
            'requests' => array_values($rows->map(function (\stdClass $r) use ($actor, $zone): array {
                $email = self::reveal($r->email_encrypted);
                // Only the account with the email HR asked for can be linked (Suresh, M2).
                $account = $email === '' ? null : self::linkable((int) $actor->organisation_id)->whereRaw('lower(email) = ?', [$email])->first(['id', 'name']);

                return ['id' => (string) $r->public_id, 'name' => (string) $r->display_name, 'email' => $email,
                    'requestedOn' => CarbonImmutable::parse((string) $r->requested_at)->setTimezone($zone)->format('j M Y'),
                    'requestedBy' => (string) ($r->requester ?? 'Staff member'), 'lockVersion' => (int) $r->lock_version,
                    'account' => $account === null ? null : ['id' => (string) $account->id, 'name' => (string) $account->name]];
            })->all()),
        ];
    }

    /**
     * The sign-in state of a locum file, for its staff file page.
     *
     * @return array{state:string, label:string, requestId:?string, email:?string, accountName:?string, at:?string, lockVersion:?int}
     */
    public static function stateFor(Worker $worker, string $zone): array
    {
        $row = DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->whereIn('status', ['open', 'done'])->orderByDesc('id')->first();
        if ($row === null) {
            return ['state' => 'none', 'label' => 'No KPOne sign-in', 'requestId' => null, 'email' => null, 'accountName' => null, 'at' => null, 'lockVersion' => null];
        }
        $at = CarbonImmutable::parse((string) ($row->closed_at ?? $row->requested_at))->setTimezone($zone)->format('j M Y');
        if ($row->status === 'open') {
            return ['state' => 'open', 'label' => 'Sign-in asked for, waiting for the account administrator', 'requestId' => (string) $row->public_id,
                'email' => self::reveal($row->email_encrypted), 'accountName' => null, 'at' => $at, 'lockVersion' => (int) $row->lock_version];
        }
        $account = User::query()->whereKey($row->linked_user_id)->first(['name', 'is_active']);

        return ['state' => 'linked', 'label' => $account?->is_active ? 'Signs in to KPOne' : 'Sign-in turned off', 'requestId' => null,
            'email' => null, 'accountName' => $account?->name, 'at' => $at, 'lockVersion' => null];
    }

    /** The locum file a locum account signs in for, if linked. */
    public static function workerFor(User $user): ?Worker
    {
        $workerId = DB::table('hr_locum_sign_ins')->where('linked_user_id', $user->id)->where('status', 'done')->value('worker_id');

        return $workerId === null ? null : Worker::query()->whereKey($workerId)->first();
    }

    /**
     * Active accounts whose only role is Locum (Suresh, M1), with no staff file and no other locum linked.
     *
     * @return Builder<User>
     */
    private static function linkable(int $organisationId): Builder
    {
        return User::query()->where('organisation_id', $organisationId)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', PermissionCatalogue::LOCUM_ROLE))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', '<>', PermissionCatalogue::LOCUM_ROLE))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('hr_workers')->whereColumn('hr_workers.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('hr_locum_sign_ins')->whereColumn('hr_locum_sign_ins.linked_user_id', 'users.id')
                ->where('hr_locum_sign_ins.status', 'done'));
    }

    /** @param array<string, mixed> $input */
    private function close(User $actor, string $requestId, array $input, string $to, string $permission, ?int $userId): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        abort_if(! Str::isUuid($requestId), 404);
        DB::transaction(function () use ($actor, $requestId, $input, $key, $to, $permission, $userId): void {
            $actor = $this->support->lock($actor, $permission, $userId === null ? [] : [$userId]);
            if ($to === 'done' && ! StaffAccountRequests::canProvision($actor)) {
                throw new AuthorizationException('Only someone who can create staff accounts links a locum sign-in.');
            }
            $hash = $this->support->hash([$actor->id, 'locum_sign_in_'.$to, $requestId, $userId]);
            if ($this->support->replay($actor, $key, 'locum_sign_in_'.$to, $hash) !== null) {
                return;
            }
            $request = DB::table('hr_locum_sign_ins')->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->lockForUpdate()->first();
            abort_if(! $request instanceof \stdClass, 404);
            if ($request->status !== 'open') {
                throw ValidationException::withMessages(['request' => 'This request was already closed.']);
            }
            $this->support->assertLock($request, $input);
            if ($to === 'done') {
                $email = self::reveal($request->email_encrypted);
                $account = self::linkable((int) $actor->organisation_id)->whereKey($userId)->first(['id', 'email']);
                if ($account === null) {
                    throw ValidationException::withMessages(['user_id' => 'Link an active account that holds the Locum role only, with no staff file and no other locum linked.']);
                }
                if ($email === '' || mb_strtolower((string) $account->email) !== $email) {
                    throw ValidationException::withMessages(['user_id' => "The account's email is not the one HR asked for ({$email}). Create the account with that email."]);
                }
            }
            $now = now()->utc();
            DB::table('hr_locum_sign_ins')->where('id', $request->id)->update(['status' => $to, 'closed_by_user_id' => $actor->id, 'closed_at' => $now,
                'linked_user_id' => $to === 'done' ? $userId : null, 'lock_version' => (int) $request->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'locum_sign_in_'.$to, $hash, $requestId);
            $this->support->audit->record($to === 'done' ? 'hr.records.locum_sign_in_linked' : 'hr.records.locum_sign_in_cancelled', null,
                ['request_id' => (int) $request->id], $actor, null, $actor->organisation_id);
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
