<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Photos\ClockPhotoFiles;
use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-3a Clock in and Clock out on the staff member's own account. The time is the server's; no location, IP address
 * or device data is stored (TEAM.md decision 6). HR-PHOTO (owner, 2026-11-12, superseding decision 6 for photos only):
 * every press carries a live photo taken with a fresh capture token, stored encrypted in the same transaction; a press
 * without one is refused, here and by PostgreSQL. HR-16a: where a Director has turned the location check on for the
 * branch, the press also keeps a result (inside, outside or not available), never the position itself. One open session per person (service and PostgreSQL). A press
 * within a minute of the last one is a double tap and is refused. A clock-in older than 16 hours, or one whose shift
 * ended more than the forgotten window ago when the person's next shift that day is about to start (a split shift), is
 * not closed by the button: the next clock-in marks it "missing its clock-out", fixed by a correction someone else approves.
 */
final class ClockService
{
    /** Undo clock-out (owner, 16 Nov 2026): how long after their own clock-out a person may take it back. */
    public const UNDO_MINUTES = 15;

    public function __construct(private AttendanceSupport $support, private BranchAccessService $branches, private AttendanceDays $days,
        private LocationCheckService $location, private ClockPhotoService $photos) {}

    /** @param array<string, mixed> $input */
    public function clockIn(User $actor, array $input): ClockSession
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $written = [];

        return $this->transaction($written, function () use ($actor, $key, $input, &$written): ClockSession {
            $this->photos->forget($written); // a retried transaction must not leave the earlier attempt's photo behind
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
            $this->photos->attach($actor, $session, 'in', $input['photo_prepared'] ?? null, $input['capture_token'] ?? null, $written);
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
        $written = [];

        return $this->transaction($written, function () use ($actor, $key, $input, &$written): ClockSession {
            $this->photos->forget($written);
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
            $this->photos->attach($actor, $session, 'out', $input['photo_prepared'] ?? null, $input['capture_token'] ?? null, $written);
            $this->event($session, 'out', $now, $actor);
            $this->location->recordPress($actor, $session, 'out', $input); // HR-16a: only when on at the session's branch
            $this->support->remember($actor, $key, 'clock_out', $hash, $session->public_id);
            $this->support->audit->record('hr.attendance.clocked_out', $session, ['minutes' => (int) $session->clock_in_at->diffInMinutes($now)], $actor,
                Branch::query()->find($session->branch_id));

            return $session;
        });
    }

    /**
     * Undo clock-out (owner, 16 Nov 2026; staff only, HR uses the correction): the person's own latest clock-out, pressed
     * by mistake, is taken back within 15 minutes and the same session reopens. Nothing is deleted: the undone press keeps
     * its event and photo, an `out_undone` event records the undo, and the next Clock out is a new attempt with its own
     * photo. Refused once a correction or an overtime request points at the session, once a payroll run for that month
     * that includes the person has been sent, or once they have clocked in again. No photo is taken to undo.
     *
     * @param  array<string, mixed>  $input
     */
    public function undoClockOut(User $actor, array $input): ClockSession
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $key, $input): ClockSession {
            $actor = $this->support->lock($actor, ClockPermissions::CLOCK_OWN);
            $hash = $this->support->hash([$actor->id, 'undo_out', $input['session_id'] ?? null]);
            if (($replay = $this->support->replay($actor, $key, 'clock_out_undo', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $session = ClockSession::query()->where('user_id', $actor->id)->orderByDesc('clock_in_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($session === null || ! is_string($input['session_id'] ?? null) || $session->public_id !== $input['session_id']) {
                throw ValidationException::withMessages(['clock' => 'Only your latest clock-out can be undone. Reload the page.']);
            }
            if ($session->status === 'open') {
                throw ValidationException::withMessages(['clock' => 'You are clocked in. Reload the page.']);
            }
            $out = $session->clock_out_at;
            $now = CarbonImmutable::now('UTC');
            if ($session->status !== 'closed' || $out === null || $out->lessThan($now->subMinutes(self::UNDO_MINUTES))) {
                throw ValidationException::withMessages(['clock' => 'The time to undo has passed. Ask for a correction instead.']);
            }
            $this->support->assertLock($session, $input);
            $press = DB::table('hr_clock_events')->where('clock_session_id', $session->id)->where('kind', 'out')->orderByDesc('id')->first();
            if ($press === null || $press->method !== 'button' || (int) $press->recorded_by_user_id !== $actor->id
                || ! CarbonImmutable::parse((string) $press->occurred_at)->equalTo($out)) {
                throw ValidationException::withMessages(['clock' => 'This clock-out was not your own press. Ask for a correction instead.']);
            }
            if (DB::table('hr_attendance_corrections')->where('clock_session_id', $session->id)->exists()
                || DB::table('hr_overtime_requests')->where('clock_session_id', $session->id)->exists()) {
                throw ValidationException::withMessages(['clock' => 'A correction or overtime request already uses this day. Ask HR instead.']);
            }
            if ($this->inSentPayroll($actor, $session)) {
                throw ValidationException::withMessages(['clock' => 'Payroll for this month is already with the approver. Ask HR for a correction.']);
            }
            DB::table('hr_clock_events')->insert(['organisation_id' => $session->organisation_id, 'clock_session_id' => $session->id, 'kind' => 'out_undone',
                'occurred_at' => $out, 'method' => 'button', 'correction_id' => null, 'recorded_by_user_id' => $actor->id, 'created_at' => $now]);
            // The one reopen path PostgreSQL allows: this transaction names the session, within the window, after the event.
            DB::statement("SELECT set_config('kpone.hr_undo_clock_out', ?, true)", [(string) $session->id]);
            $session->forceFill(['clock_out_at' => null, 'status' => 'open', 'lock_version' => $session->lock_version + 1])->save();
            DB::statement("SELECT set_config('kpone.hr_undo_clock_out', '', true)");
            $this->support->remember($actor, $key, 'clock_out_undo', $hash, $session->public_id);
            $this->support->audit->record('hr.attendance.clock_out_undone', $session, ['seconds_after' => (int) $out->diffInSeconds($now)], $actor,
                Branch::query()->find($session->branch_id));

            return $session;
        }, 3);
    }

    /** Whether a payroll run for the session's month that includes this person has left Draft. */
    private function inSentPayroll(User $actor, ClockSession $session): bool
    {
        return DB::table('hr_payroll_runs as r')->join('hr_payroll_run_people as p', 'p.run_id', '=', 'r.id')->join('hr_workers as w', 'w.id', '=', 'p.worker_id')
            ->where('r.organisation_id', $actor->organisation_id)->whereIn('r.status', ['sent', 'approved', 'locked'])
            ->where('r.month', $session->local_date->startOfMonth()->toDateString())->where('w.user_id', $actor->id)->exists();
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
     * @param  list<string>  $written  photo files this press wrote, deleted again if it fails
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(array &$written, callable $callback): mixed
    {
        try {
            $result = DB::transaction(fn () => $callback(), 3);
            $written = []; // committed: these files now belong to a photo row (Suresh m8)

            return $result;
        } catch (\Throwable $e) {
            $this->photos->forget($written);
            if (! $e instanceof QueryException) {
                throw $e;
            }
            // PostgreSQL is the last line for one open session per person and no overlapping sessions.
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), 'hr_clock_photos')) {
                throw ValidationException::withMessages(['photo' => 'This photo was used before. Take a new one.']);
            }
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['clock' => 'You are already clocked in. Reload the page.']);
            }
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw ValidationException::withMessages(['clock' => 'This time overlaps another clock-in. Ask HR to check your times.']);
            }
            if (($e->errorInfo[0] ?? null) === '23514' && str_contains($e->getMessage(), 'needs a photo')) {
                throw ValidationException::withMessages(['photo' => ClockPhotoFiles::NEEDED]);
            }
            throw $e;
        }
    }
}
