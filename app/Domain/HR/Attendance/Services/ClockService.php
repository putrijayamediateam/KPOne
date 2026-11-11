<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-3a Clock in and Clock out on the staff member's own account. The time is the server's; no location, IP address,
 * photo or device data is stored (TEAM.md decision 6). HR-16a: where a Director has turned the location check on for the
 * branch, the press also keeps a result (inside, outside or not available), never the position itself. One open session per person (service and PostgreSQL). A press
 * within a minute of the last one is a double tap and is refused. A clock-in older than 16 hours, or one whose shift
 * ended more than the forgotten window ago when the person's next shift that day is about to start (a split shift), is
 * not closed by the button: the next clock-in marks it "missing its clock-out", fixed by a correction someone else approves.
 */
final class ClockService
{
    public function __construct(private AttendanceSupport $support, private BranchAccessService $branches, private AttendanceDays $days,
        private LocationCheckService $location) {}

    /** @param array<string, mixed> $input */
    public function clockIn(User $actor, array $input): ClockSession
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $key, $input): ClockSession {
            $actor = $this->support->lock($actor, ClockPermissions::CLOCK_OWN);
            $hash = $this->support->hash([$actor->id, 'in']);
            if (($replay = $this->support->replay($actor, $key, 'clock_in', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $branch = $this->branches->activeBranch($actor);
            $now = CarbonImmutable::now('UTC');
            $local = $branch === null ? null : $now->setTimezone($branch->timezone)->startOfDay();
            if ($branch === null || $local === null || ! $this->support->assigned($actor, $branch, $local, true)) {
                throw ValidationException::withMessages(['clock' => 'Choose a branch you work at in the top bar.']);
            }
            $open = ClockSession::query()->where('user_id', $actor->id)->where('status', 'open')->lockForUpdate()->first();
            if ($open !== null) {
                if ($open->clock_in_at->lessThan($now->subHours(AttendanceSupport::MAX_SESSION_HOURS)) || $this->days->forgottenBeforeNextShift($open, $now)) {
                    $this->markMissingOut($actor, $open);
                } else {
                    throw ValidationException::withMessages(['clock' => 'You are already clocked in since '.AttendanceSupport::time($open->clock_in_at, $open->timezone).'.']);
                }
            }
            $last = ClockSession::query()->where('user_id', $actor->id)->where('status', 'closed')->orderByDesc('clock_out_at')->first();
            if ($last !== null && $last->clock_out_at !== null && $last->clock_out_at->greaterThan($now->subSeconds(AttendanceSupport::DOUBLE_TAP_SECONDS))) {
                throw ValidationException::withMessages(['clock' => 'You clocked out less than a minute ago.']);
            }
            $session = new ClockSession;
            $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'user_id' => $actor->id,
                'branch_id' => $branch->id, 'local_date' => $local->toDateString(), 'timezone' => $branch->timezone,
                'clock_in_at' => $now, 'status' => 'open'])->save();
            $this->event($session, 'in', $now, $actor);
            $this->location->recordPress($actor, $session, 'in', $input); // HR-16a: only when on at this branch
            $this->support->remember($actor, $key, 'clock_in', $hash, $session->public_id);
            $this->support->audit->record('hr.attendance.clocked_in', $session, ['local_date' => $session->local_date->toDateString()], $actor, $branch);

            return $session;
        });
    }

    /** @param array<string, mixed> $input */
    public function clockOut(User $actor, array $input): ClockSession
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $key, $input): ClockSession {
            $actor = $this->support->lock($actor, ClockPermissions::CLOCK_OWN);
            $hash = $this->support->hash([$actor->id, 'out']);
            if (($replay = $this->support->replay($actor, $key, 'clock_out', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $now = CarbonImmutable::now('UTC');
            $session = ClockSession::query()->where('user_id', $actor->id)->where('status', 'open')->lockForUpdate()->first();
            if ($session === null) {
                throw ValidationException::withMessages(['clock' => 'You are not clocked in. Reload the page.']);
            }
            if ($session->clock_in_at->lessThan($now->subHours(AttendanceSupport::MAX_SESSION_HOURS))) {
                throw ValidationException::withMessages(['clock' => 'Your clock-in on '.AttendanceSupport::day($session->local_date).' is over 16 hours old. Add your clock-out time as a correction.']);
            }
            if ($session->clock_in_at->greaterThan($now->subSeconds(AttendanceSupport::DOUBLE_TAP_SECONDS))) {
                throw ValidationException::withMessages(['clock' => 'You clocked in less than a minute ago.']);
            }
            $session->forceFill(['clock_out_at' => $now, 'status' => 'closed', 'lock_version' => $session->lock_version + 1])->save();
            $this->event($session, 'out', $now, $actor);
            $this->location->recordPress($actor, $session, 'out', $input); // HR-16a: only when on at the session's branch
            $this->support->remember($actor, $key, 'clock_out', $hash, $session->public_id);
            $this->support->audit->record('hr.attendance.clocked_out', $session, ['minutes' => (int) $session->clock_in_at->diffInMinutes($now)], $actor,
                Branch::query()->find($session->branch_id));

            return $session;
        });
    }

    private function markMissingOut(User $actor, ClockSession $session): void
    {
        $session->forceFill(['status' => 'missing_out', 'lock_version' => $session->lock_version + 1])->save();
        $this->support->audit->record('hr.attendance.missing_clock_out', $session, ['local_date' => $session->local_date->toDateString()], $actor,
            Branch::query()->find($session->branch_id));
    }

    private function event(ClockSession $session, string $kind, CarbonImmutable $at, User $actor, ?int $correctionId = null): void
    {
        DB::table('hr_clock_events')->insert(['organisation_id' => $session->organisation_id, 'clock_session_id' => $session->id, 'kind' => $kind,
            'occurred_at' => $at, 'method' => $correctionId === null ? 'button' : 'correction', 'correction_id' => $correctionId,
            'recorded_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
    }

    private function find(User $actor, string $publicId): ClockSession
    {
        return ClockSession::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction(fn () => $callback(), 3);
        } catch (QueryException $e) {
            // PostgreSQL is the last line for one open session per person and no overlapping sessions.
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['clock' => 'You are already clocked in. Reload the page.']);
            }
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw ValidationException::withMessages(['clock' => 'This time overlaps another clock-in. Ask HR to check your times.']);
            }
            throw $e;
        }
    }
}
