<?php

namespace App\Domain\HR\OpenShifts\Services;

use App\Domain\HR\OpenShifts\OpenShiftPermissions;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Models\RosterWeek;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\HR\Roster\Services\RosterService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-15b open shifts (HR-PLAN 21.4). The Director or HR Manager posts an open shift with the same rules as a roster shift
 * (at most 16 hours, a break shorter than the shift); a locum with a linked sign-in asks for it; a manager who is not the
 * locum confirms one locum. The confirm adds the shift to the branch's next draft roster week through the HR-1 roster
 * service, with all its checks (the locum's branch assignment, no overlap, no approved leave), and declines the other
 * asks. The locum sees the shift on My roster only after that week is approved. First come is not first served.
 */
final class OpenShiftService
{
    public const ROLES = ['resident_doctor' => 'Doctor', 'ca' => 'Clinic Assistant', 'other' => 'Other'];

    public const DECLINE_REASONS = ['not_suitable' => 'Not suitable for this shift', 'not_assigned' => 'Not assigned to this branch on that day',
        'other' => 'Other reason'];

    /** How far ahead a shift may be posted. */
    public const MAX_DAYS_AHEAD = 90;

    public function __construct(private RecordSupport $support, private RosterRules $rules, private RosterService $roster) {}

    /** @param array<string, mixed> $input branch_id, date, start_time, end_time, break_minutes, session_type, role_needed, idempotency_key */
    public function post(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $role = is_string($input['role_needed'] ?? null) && array_key_exists($input['role_needed'], self::ROLES) ? $input['role_needed'] : null;
        if ($role === null) {
            throw ValidationException::withMessages(['role_needed' => 'Choose who is needed.']);
        }

        return DB::transaction(function () use ($actor, $input, $role, $key): string {
            $actor = $this->support->lock($actor, OpenShiftPermissions::MANAGE);
            $branch = $this->support->authority->branch($actor, $input['branch_id'] ?? null);
            $weekStart = $this->rules->weekStart($branch, $input['date'] ?? null, 'date');
            // The roster's own parser, with no staff member yet (an open shift has nobody on it).
            $shift = $this->rules->parse($branch, $weekStart, [...$input, 'staff_user_id' => 1, 'branch_room_id' => null]);
            $now = CarbonImmutable::now('UTC');
            if ($shift['starts_at']->lessThanOrEqualTo($now) || $shift['starts_at']->greaterThan($now->addDays(self::MAX_DAYS_AHEAD))) {
                throw ValidationException::withMessages(['date' => 'Choose a time from now up to '.self::MAX_DAYS_AHEAD.' days ahead.']);
            }
            $hash = $this->support->hash([$actor->id, 'open_shift', $branch->id, $shift['starts_at']->toIso8601String(), $shift['ends_at']->toIso8601String(),
                $shift['break_minutes'], $shift['session_type'], $role]);
            if (($replay = $this->support->replay($actor, $key, 'open_shift', $hash)) !== null) {
                return $replay;
            }
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_open_shifts')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'starts_at' => $shift['starts_at'], 'ends_at' => $shift['ends_at'], 'timezone' => $branch->timezone, 'break_minutes' => $shift['break_minutes'],
                'session_type' => $shift['session_type'], 'role_needed' => $role, 'status' => 'open', 'posted_by_user_id' => $actor->id, 'posted_at' => $now,
                'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'open_shift', $hash, $publicId);
            $this->support->audit->record('hr.open_shifts.posted', null, ['open_shift_id' => $id], $actor, $branch, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Withdraw an open shift; every waiting ask is declined.
     *
     * @param  array<string, mixed>  $input  expected_lock_version, idempotency_key
     */
    public function withdraw(User $actor, string $shiftId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $shiftId, $input, $key): void {
            $actor = $this->support->lock($actor, OpenShiftPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'open_shift_withdraw', $shiftId]);
            if ($this->support->replay($actor, $key, 'open_shift_withdraw', $hash) !== null) {
                return;
            }
            $shift = $this->lockShift($actor, $shiftId);
            if ($shift->status !== 'open') {
                throw ValidationException::withMessages(['open_shift' => 'This shift is no longer open.']);
            }
            $this->support->assertLock($shift, $input);
            $now = now()->utc();
            $this->declineWaiting($shift, $actor, 'shift_withdrawn', $now);
            DB::table('hr_open_shifts')->where('id', $shift->id)->update(['status' => 'withdrawn', 'closed_by_user_id' => $actor->id, 'closed_at' => $now,
                'lock_version' => (int) $shift->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'open_shift_withdraw', $hash, $shiftId);
            $this->support->audit->record('hr.open_shifts.withdrawn', null, ['open_shift_id' => (int) $shift->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * A linked locum asks for an open shift at a branch they are assigned to on that day.
     *
     * @param  array<string, mixed>  $input  idempotency_key
     */
    public function ask(User $actor, string $shiftId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $shiftId, $key): void {
            $actor = $this->support->lock($actor, OpenShiftPermissions::REQUEST_OWN);
            $hash = $this->support->hash([$actor->id, 'open_shift_ask', $shiftId]);
            if ($this->support->replay($actor, $key, 'open_shift_ask', $hash) !== null) {
                return;
            }
            if (LocumSignIns::workerFor($actor) === null) {
                throw new AuthorizationException('Only a locum sign-in linked to a locum file asks for an open shift.');
            }
            $shift = $this->lockShift($actor, $shiftId, forShare: true);
            if ($shift->status !== 'open' || CarbonImmutable::parse((string) $shift->starts_at, 'UTC')->isPast()) {
                throw ValidationException::withMessages(['open_shift' => 'This shift is no longer open.']);
            }
            if (DB::table('hr_open_shift_requests')->where('open_shift_id', $shift->id)->where('locum_user_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['open_shift' => 'You have already asked for this shift.']);
            }
            $branch = Branch::query()->whereKey($shift->branch_id)->firstOrFail();
            $this->rules->assertAssigned($actor, $branch, CarbonImmutable::parse((string) $shift->starts_at, 'UTC'), CarbonImmutable::parse((string) $shift->ends_at, 'UTC'), 'open_shift');
            $now = now()->utc();
            $id = DB::table('hr_open_shift_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                'open_shift_id' => $shift->id, 'locum_user_id' => $actor->id, 'status' => 'asked', 'asked_at' => $now, 'lock_version' => 1,
                'created_at' => $now, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'open_shift_ask', $hash, (string) $id);
            $this->support->audit->record('hr.open_shifts.asked', null, ['open_shift_id' => (int) $shift->id, 'request_id' => $id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * The locum withdraws their own waiting ask.
     *
     * @param  array<string, mixed>  $input  expected_lock_version, idempotency_key
     */
    public function withdrawAsk(User $actor, string $requestId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $requestId, $input, $key): void {
            $actor = $this->support->lock($actor, OpenShiftPermissions::REQUEST_OWN);
            $hash = $this->support->hash([$actor->id, 'open_shift_ask_withdraw', $requestId]);
            if ($this->support->replay($actor, $key, 'open_shift_ask_withdraw', $hash) !== null) {
                return;
            }
            $request = $this->lockRequest($actor, $requestId);
            abort_if((int) $request->locum_user_id !== $actor->id, 404);
            $this->support->assertLock($request, $input);
            if ($request->status !== 'asked') {
                throw ValidationException::withMessages(['open_shift' => 'This ask was already answered.']);
            }
            DB::table('hr_open_shift_requests')->where('id', $request->id)->update(['status' => 'withdrawn', 'lock_version' => (int) $request->lock_version + 1,
                'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'open_shift_ask_withdraw', $hash, $requestId);
            $this->support->audit->record('hr.open_shifts.ask_withdrawn', null, ['request_id' => (int) $request->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input reason, expected_lock_version, idempotency_key */
    public function decline(User $actor, string $requestId, array $input): void
    {
        $reason = is_string($input['reason'] ?? null) && array_key_exists($input['reason'], self::DECLINE_REASONS) ? $input['reason'] : null;
        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $locum = $this->locumOf($actor, $requestId);
        DB::transaction(function () use ($actor, $requestId, $input, $reason, $key, $locum): void {
            $actor = $this->support->lock($actor, OpenShiftPermissions::MANAGE, [$locum]);
            $hash = $this->support->hash([$actor->id, 'open_shift_decline', $requestId, $reason]);
            if ($this->support->replay($actor, $key, 'open_shift_decline', $hash) !== null) {
                return;
            }
            $request = $this->lockRequest($actor, $requestId);
            if ($request->status !== 'asked') {
                throw ValidationException::withMessages(['open_shift' => 'This ask was already answered.']);
            }
            $this->support->assertLock($request, $input);
            $now = now()->utc();
            DB::table('hr_open_shift_requests')->where('id', $request->id)->update(['status' => 'declined', 'decline_reason' => $reason,
                'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'lock_version' => (int) $request->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'open_shift_decline', $hash, $requestId);
            $this->support->audit->record('hr.open_shifts.declined', null, ['request_id' => (int) $request->id, 'reason' => $reason], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Confirm one locum: the shift joins the branch's next draft roster week (a draft is started from the approved week, or
     * blank, when none is open), and the other asks are declined. A week waiting for approval must be returned to draft
     * first, because a sent week does not change.
     *
     * @param  array<string, mixed>  $input  expected_lock_version, idempotency_key
     */
    public function confirm(User $actor, string $requestId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $locum = $this->locumOf($actor, $requestId);
        DB::transaction(function () use ($actor, $requestId, $input, $key, $locum): void {
            $actor = $this->support->lock($actor, OpenShiftPermissions::MANAGE, [$locum]);
            if (! $actor->can(RosterPermissions::MANAGE)) {
                throw new AuthorizationException('Only someone who plans the roster confirms an open shift.');
            }
            $hash = $this->support->hash([$actor->id, 'open_shift_confirm', $requestId]);
            if ($this->support->replay($actor, $key, 'open_shift_confirm', $hash) !== null) {
                return;
            }
            // The shift first, then its asks: the same order as withdraw(), so a confirm and a withdraw never deadlock.
            $shiftRowId = DB::table('hr_open_shift_requests')->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->value('open_shift_id');
            $shift = DB::table('hr_open_shifts')->where('id', $shiftRowId)->lockForUpdate()->first();
            abort_if(! $shift instanceof \stdClass, 404);
            $request = $this->lockRequest($actor, $requestId);
            // What happened first, then the version: a manager who lost a race reads why.
            if ($request->status !== 'asked' || $shift->status !== 'open') {
                throw ValidationException::withMessages(['open_shift' => 'This shift was already filled or withdrawn, or the ask was answered.']);
            }
            $this->support->assertLock($request, $input);
            if ((int) $request->locum_user_id === $actor->id) {
                throw new AuthorizationException('You may not confirm your own ask.');
            }
            $subject = User::query()->whereKey($request->locum_user_id)->firstOrFail();
            if (LocumSignIns::workerFor($subject) === null) {
                throw ValidationException::withMessages(['open_shift' => 'This locum sign-in is no longer linked to a locum file.']);
            }
            $branch = Branch::query()->whereKey($shift->branch_id)->firstOrFail();
            $starts = CarbonImmutable::parse((string) $shift->starts_at, 'UTC')->setTimezone($branch->timezone);
            $ends = CarbonImmutable::parse((string) $shift->ends_at, 'UTC')->setTimezone($branch->timezone);
            if ($starts->isPast()) {
                throw ValidationException::withMessages(['open_shift' => 'This shift has already started.']);
            }
            $version = $this->nextDraft($actor, $branch, $starts);
            $this->roster->addShift($actor, $version->public_id, ['staff_user_id' => $subject->id, 'date' => $starts->toDateString(),
                'start_time' => $starts->format('H:i'), 'end_time' => $ends->format('H:i'), 'break_minutes' => (int) $shift->break_minutes,
                'session_type' => (string) $shift->session_type, 'branch_room_id' => null, 'expected_version_lock' => $version->lock_version,
                'idempotency_key' => (string) Str::uuid()]);
            $rosterShift = RosterShift::query()->where('roster_version_id', $version->id)->where('staff_user_id', $subject->id)
                ->where('starts_at', $starts->utc())->where('ends_at', $ends->utc())->orderByDesc('id')->firstOrFail();
            $now = now()->utc();
            DB::table('hr_open_shift_requests')->where('id', $request->id)->update(['status' => 'confirmed', 'decided_by_user_id' => $actor->id,
                'decided_at' => $now, 'roster_shift_id' => $rosterShift->id, 'lock_version' => (int) $request->lock_version + 1, 'updated_at' => $now]);
            $this->declineWaiting($shift, $actor, 'filled', $now);
            DB::table('hr_open_shifts')->where('id', $shift->id)->update(['status' => 'filled', 'closed_by_user_id' => $actor->id, 'closed_at' => $now,
                'lock_version' => (int) $shift->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'open_shift_confirm', $hash, $requestId);
            $this->support->audit->record('hr.open_shifts.confirmed', null, ['open_shift_id' => (int) $shift->id, 'request_id' => (int) $request->id,
                'roster_version_id' => $version->id], $actor, $branch, $actor->organisation_id);
        }, 3);
    }

    /** The branch week's open draft, or a new one (a change of the approved week, or blank). A sent week is refused. */
    private function nextDraft(User $actor, Branch $branch, CarbonImmutable $starts): RosterVersion
    {
        $weekStart = $this->rules->weekStart($branch, $starts->toDateString());
        $week = RosterWeek::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->toDateString())->first();
        $versions = $week === null ? collect() : RosterVersion::query()->where('roster_week_id', $week->id)->get();
        $open = $versions->first(fn (RosterVersion $v): bool => $v->isOpen());
        if ($open instanceof RosterVersion && $open->status === 'submitted') {
            throw ValidationException::withMessages(['open_shift' => 'The roster for the week of '.$weekStart->format('j M Y').' is waiting for approval. Return it to draft, then confirm.']);
        }
        if ($open instanceof RosterVersion) {
            return $open;
        }
        $mode = $versions->contains(fn (RosterVersion $v): bool => $v->status === 'approved') ? 'change' : 'blank';
        $started = $this->roster->startDraft($actor, ['branch_id' => $branch->id, 'week' => $weekStart->toDateString(), 'mode' => $mode,
            'idempotency_key' => (string) Str::uuid()]);

        return $started['version']->refresh();
    }

    private function declineWaiting(\stdClass $shift, User $actor, string $reason, CarbonImmutable $now): void
    {
        $waiting = DB::table('hr_open_shift_requests')->where('open_shift_id', $shift->id)->where('status', 'asked')->orderBy('id')->lockForUpdate()->get();
        foreach ($waiting as $other) {
            DB::table('hr_open_shift_requests')->where('id', $other->id)->update(['status' => 'declined', 'decline_reason' => $reason,
                'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'lock_version' => (int) $other->lock_version + 1, 'updated_at' => $now]);
        }
    }

    private function lockShift(User $actor, string $shiftId, bool $forShare = false): \stdClass
    {
        abort_if(! Str::isUuid($shiftId), 404);
        $query = DB::table('hr_open_shifts')->where('organisation_id', $actor->organisation_id)->where('public_id', $shiftId);
        $shift = ($forShare ? $query->sharedLock() : $query->lockForUpdate())->first();
        abort_if(! $shift instanceof \stdClass, 404);

        return $shift;
    }

    private function lockRequest(User $actor, string $requestId): \stdClass
    {
        abort_if(! Str::isUuid($requestId), 404);
        $request = DB::table('hr_open_shift_requests')->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->lockForUpdate()->first();
        abort_if(! $request instanceof \stdClass, 404);

        return $request;
    }

    /** The ask's locum, read before any lock so the account joins the id-ordered lock. */
    private function locumOf(User $actor, string $requestId): int
    {
        abort_if(! Str::isUuid($requestId), 404);
        $id = DB::table('hr_open_shift_requests')->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->value('locum_user_id');
        abort_if($id === null, 404);

        return (int) $id;
    }
}
