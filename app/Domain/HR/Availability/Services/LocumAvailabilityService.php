<?php

namespace App\Domain\HR\Availability\Services;

use App\Domain\HR\Availability\AvailabilityPermissions;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-22f locum availability (HR-PLAN 29.7). A locum with a linked sign-in proposes a time they are free at a branch they
 * are assigned to (at most 30 waiting, up to 90 days ahead, an optional encrypted note of 200 characters). A roster
 * planner who is not the locum uses it, which adds a draft roster shift inside the offered time to that branch's next
 * draft week through the HR-1 roster service (every roster check applies; HR may shorten it and set the room and station),
 * or declines it with a fixed reason. The locum may withdraw a proposal while it waits; a proposal whose time has passed
 * shows as expired. Nothing is sent to anyone. Every write: a fresh permission check under lock, DB::transaction, row
 * locks (people in id order, then the proposal), lock_version, an idempotency key and audit with ids and codes only.
 */
final class LocumAvailabilityService
{
    public const MAX_WAITING = 30;

    public const MAX_DAYS_AHEAD = 90;

    public const DECLINE_REASONS = ['not_needed' => 'Not needed', 'outside_hours' => 'Outside opening hours', 'other' => 'Other reason'];

    public function __construct(private RecordSupport $support, private RosterRules $rules, private RosterService $roster) {}

    /** @param array<string, mixed> $input branch_id, date, start_time, end_time, note, idempotency_key */
    public function propose(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $note = $this->support->note($input['note'] ?? null);

        return DB::transaction(function () use ($actor, $input, $note, $key): string {
            $actor = $this->support->lock($actor, AvailabilityPermissions::PROPOSE_OWN);
            if (LocumSignIns::workerFor($actor) === null) {
                throw new AuthorizationException('Only a locum sign-in linked to a locum file proposes free times.');
            }
            $branch = $this->support->authority->branch($actor, $input['branch_id'] ?? null);
            $weekStart = $this->rules->weekStart($branch, $input['date'] ?? null, 'date');
            // The roster's own parser, for the person proposing; a free time has no session, room or station yet.
            $time = $this->rules->parse($branch, $weekStart, [...$input, 'staff_user_id' => $actor->id, 'session_type' => 'other',
                'break_minutes' => 0, 'branch_room_id' => null, 'station' => null]);
            $now = CarbonImmutable::now('UTC');
            if ($time['starts_at']->lessThanOrEqualTo($now) || $time['starts_at']->greaterThan($now->addDays(self::MAX_DAYS_AHEAD))) {
                throw ValidationException::withMessages(['date' => 'Choose a time from now up to '.self::MAX_DAYS_AHEAD.' days ahead.']);
            }
            $hash = $this->support->hash([$actor->id, 'availability_propose', $branch->id, $time['starts_at']->toIso8601String(),
                $time['ends_at']->toIso8601String(), $note ?? '']);
            if (($replay = $this->support->replay($actor, $key, 'availability_propose', $hash)) !== null) {
                return $replay;
            }
            $this->rules->assertAssigned($actor, $branch, $time['starts_at'], $time['ends_at'], 'branch_id');
            $waiting = DB::table('hr_locum_availability')->where('locum_user_id', $actor->id)->where('status', 'waiting')->where('ends_at', '>', $now)->count();
            if ($waiting >= self::MAX_WAITING) {
                throw ValidationException::withMessages(['availability' => 'You already have '.self::MAX_WAITING.' free times waiting. Withdraw one first.']);
            }
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_locum_availability')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id, 'locum_user_id' => $actor->id, 'starts_at' => $time['starts_at'], 'ends_at' => $time['ends_at'],
                'timezone' => $branch->timezone, 'note_encrypted' => $note === null ? null : Crypt::encryptString($note), 'status' => 'waiting',
                'proposed_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'availability_propose', $hash, $publicId);
            $this->support->audit->record('hr.locum_availability.proposed', null, ['availability_id' => $id, 'has_note' => $note !== null], $actor, $branch, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function withdraw(User $actor, string $availabilityId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $availabilityId, $input, $key): void {
            $actor = $this->support->lock($actor, AvailabilityPermissions::PROPOSE_OWN);
            $hash = $this->support->hash([$actor->id, 'availability_withdraw', $availabilityId]);
            if ($this->support->replay($actor, $key, 'availability_withdraw', $hash) !== null) {
                return;
            }
            $row = $this->lockRow($actor, $availabilityId);
            abort_if((int) $row->locum_user_id !== $actor->id, 404);
            if ($row->status !== 'waiting') {
                throw ValidationException::withMessages(['availability' => 'This free time was already answered.']);
            }
            $this->refuseEnded($row);
            $this->support->assertLock($row, $input);
            DB::table('hr_locum_availability')->where('id', $row->id)->update(['status' => 'withdrawn', 'lock_version' => (int) $row->lock_version + 1,
                'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'availability_withdraw', $hash, $availabilityId);
            $this->support->audit->record('hr.locum_availability.withdrawn', null, ['availability_id' => (int) $row->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input reason, expected_lock_version, idempotency_key */
    public function decline(User $actor, string $availabilityId, array $input): void
    {
        $reason = is_string($input['reason'] ?? null) && array_key_exists($input['reason'], self::DECLINE_REASONS) ? $input['reason'] : null;
        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $locum = $this->locumOf($actor, $availabilityId);
        DB::transaction(function () use ($actor, $availabilityId, $input, $reason, $key, $locum): void {
            $actor = $this->support->lock($actor, RosterPermissions::MANAGE, [$locum]);
            $hash = $this->support->hash([$actor->id, 'availability_decline', $availabilityId, $reason]);
            if ($this->support->replay($actor, $key, 'availability_decline', $hash) !== null) {
                return;
            }
            $row = $this->lockRow($actor, $availabilityId);
            if ((int) $row->locum_user_id === $actor->id) {
                throw new AuthorizationException('You may not answer your own free time.');
            }
            if ($row->status !== 'waiting') {
                throw ValidationException::withMessages(['availability' => 'This free time was already answered or withdrawn.']);
            }
            $this->refuseEnded($row);
            $this->support->assertLock($row, $input);
            $now = now()->utc();
            DB::table('hr_locum_availability')->where('id', $row->id)->update(['status' => 'declined', 'decline_reason' => $reason,
                'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'lock_version' => (int) $row->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'availability_decline', $hash, $availabilityId);
            $this->support->audit->record('hr.locum_availability.declined', null, ['availability_id' => (int) $row->id, 'reason' => $reason], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Use a free time: a draft roster shift inside the offered time joins the branch's next draft week. HR may shorten it
     * and set the session, break, room and station; every roster check applies.
     *
     * @param  array<string, mixed>  $input  start_time, end_time, break_minutes, session_type, branch_room_id, station, expected_lock_version, idempotency_key
     */
    public function use(User $actor, string $availabilityId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $locum = $this->locumOf($actor, $availabilityId);
        DB::transaction(function () use ($actor, $availabilityId, $input, $key, $locum): void {
            $actor = $this->support->lock($actor, RosterPermissions::MANAGE, [$locum]);
            $hash = $this->support->hash([$actor->id, 'availability_use', $availabilityId, ...array_map(fn (string $f): string => is_scalar($input[$f] ?? null) ? (string) $input[$f] : '',
                ['start_time', 'end_time', 'break_minutes', 'session_type', 'branch_room_id', 'station'])]);
            if ($this->support->replay($actor, $key, 'availability_use', $hash) !== null) {
                return;
            }
            $row = $this->lockRow($actor, $availabilityId);
            // What happened first, then the version: a manager who lost a race reads why.
            if ($row->status !== 'waiting') {
                throw ValidationException::withMessages(['availability' => 'This free time was already answered or withdrawn.']);
            }
            $this->support->assertLock($row, $input);
            if ((int) $row->locum_user_id === $actor->id) {
                throw new AuthorizationException('You may not use your own free time.');
            }
            $subject = User::query()->whereKey($row->locum_user_id)->firstOrFail();
            if (LocumSignIns::workerFor($subject) === null) {
                throw ValidationException::withMessages(['availability' => 'This locum sign-in is no longer linked to a locum file.']);
            }
            $branch = Branch::query()->whereKey($row->branch_id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
            $offeredStart = CarbonImmutable::parse((string) $row->starts_at, 'UTC');
            $offeredEnd = CarbonImmutable::parse((string) $row->ends_at, 'UTC');
            $date = $offeredStart->setTimezone($branch->timezone)->toDateString();
            $weekStart = $this->rules->weekStart($branch, $date, 'date');
            $shift = $this->rules->parse($branch, $weekStart, [...$input, 'staff_user_id' => $subject->id, 'date' => $date]);
            if ($shift['starts_at']->lessThan($offeredStart) || $shift['ends_at']->greaterThan($offeredEnd)) {
                throw ValidationException::withMessages(['start_time' => 'Keep the shift inside the offered time ('
                    .$this->rules->timeLabel($offeredStart->setTimezone($branch->timezone)).' to '.$this->rules->timeLabel($offeredEnd->setTimezone($branch->timezone)).').']);
            }
            if ($shift['starts_at']->lessThanOrEqualTo(now()->utc())) {
                throw ValidationException::withMessages(['availability' => 'This time has already started.']);
            }
            $version = $this->nextDraft($actor, $branch, $shift['starts_at']->setTimezone($branch->timezone));
            $start = $shift['starts_at']->setTimezone($branch->timezone);
            $end = $shift['ends_at']->setTimezone($branch->timezone);
            $this->roster->addShift($actor, $version->public_id, ['staff_user_id' => $subject->id, 'date' => $date,
                'start_time' => $start->format('H:i'), 'end_time' => $end->format('H:i'), 'break_minutes' => $shift['break_minutes'],
                'session_type' => $shift['session_type'], 'branch_room_id' => $shift['branch_room_id'], 'station' => $shift['station'],
                'branch_id' => $branch->id, 'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
            $rosterShift = RosterShift::query()->where('roster_version_id', $version->id)->where('staff_user_id', $subject->id)
                ->where('starts_at', $shift['starts_at'])->where('ends_at', $shift['ends_at'])->orderByDesc('id')->firstOrFail();
            $now = now()->utc();
            DB::table('hr_locum_availability')->where('id', $row->id)->update(['status' => 'used', 'decided_by_user_id' => $actor->id, 'decided_at' => $now,
                'roster_shift_id' => $rosterShift->id, 'lock_version' => (int) $row->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'availability_use', $hash, $availabilityId);
            $this->support->audit->record('hr.locum_availability.used', null, ['availability_id' => (int) $row->id, 'roster_version_id' => $version->id,
                'roster_shift_id' => $rosterShift->id], $actor, $branch, $actor->organisation_id);
        }, 3);
    }

    /** A free time whose offered time has ended stays as it is (Suresh m3): it shows as expired and frees a slot. */
    private function refuseEnded(\stdClass $row): void
    {
        if (CarbonImmutable::parse((string) $row->ends_at, 'UTC')->lessThanOrEqualTo(now()->utc())) {
            throw ValidationException::withMessages(['availability' => 'This free time has ended.']);
        }
    }

    /** The branch week's open draft, or a new one (a change of the approved week, or blank). A sent week is refused. */
    private function nextDraft(User $actor, Branch $branch, CarbonImmutable $starts): RosterVersion
    {
        $weekStart = $this->rules->weekStart($branch, $starts->toDateString());
        // The same lock key as an open-shift confirm, so a confirm and a use into one branch week take turns.
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_open_shift_week:'.$branch->organisation_id.':'.$branch->id.':'.$weekStart->toDateString()]);
        $week = RosterWeek::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->toDateString())->first();
        $versions = $week === null ? collect() : RosterVersion::query()->where('roster_week_id', $week->id)->get();
        $open = $versions->first(fn (RosterVersion $v): bool => $v->isOpen());
        if ($open instanceof RosterVersion && $open->status === 'submitted') {
            throw ValidationException::withMessages(['availability' => 'The roster for the week of '.$weekStart->format('j M Y').' is waiting for approval. Return it to draft, then use this time.']);
        }
        if ($open instanceof RosterVersion) {
            return $open;
        }
        $mode = $versions->contains(fn (RosterVersion $v): bool => $v->status === 'approved') ? 'change' : 'blank';

        return $this->roster->startDraft($actor, ['branch_id' => $branch->id, 'week' => $weekStart->toDateString(), 'mode' => $mode,
            'idempotency_key' => (string) Str::uuid()])['version']->refresh();
    }

    private function lockRow(User $actor, string $availabilityId): \stdClass
    {
        abort_if(! Str::isUuid($availabilityId), 404);
        $row = DB::table('hr_locum_availability')->where('organisation_id', $actor->organisation_id)->where('public_id', $availabilityId)->lockForUpdate()->first();
        abort_if(! $row instanceof \stdClass, 404);

        return $row;
    }

    /** The proposal's locum, read before any lock so the account joins the id-ordered lock. */
    private function locumOf(User $actor, string $availabilityId): int
    {
        abort_if(! Str::isUuid($availabilityId), 404);
        $id = DB::table('hr_locum_availability')->where('organisation_id', $actor->organisation_id)->where('public_id', $availabilityId)->value('locum_user_id');
        abort_if($id === null, 404);

        return (int) $id;
    }
}
