<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Roster\Services\RosterAuthority;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-3a corrections. A staff member proposes the right time for their own clock-in or clock-out, or a whole day they
 * forgot to clock; a manager may propose on someone's behalf. The decider is never the subject and never the proposer
 * (service and PostgreSQL). An approved correction adds a correction event and moves the session's times; the original
 * presses stay. Notes are encrypted and never audited.
 */
final class CorrectionService
{
    public const KINDS = ['change_in' => 'Change my clock-in time', 'set_out' => 'Add or change my clock-out time', 'add_day' => 'Add a day I forgot to clock'];

    public const REASONS = ['forgot' => 'Forgot', 'system_problem' => 'Device or system problem', 'wrong_time' => 'Pressed at the wrong time',
        'away_from_branch' => 'Worked away from the branch', 'other' => 'Other'];

    public const REJECT_REASONS = ['not_confirmed' => 'Time not confirmed', 'wrong_kind' => 'Wrong kind of correction', 'other' => 'Other'];

    public function __construct(private AttendanceSupport $support, private AttendanceSettingsService $settings, private RosterAuthority $authority) {}

    /** @param array<string, mixed> $input */
    public function propose(User $actor, array $input): AttendanceCorrection
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $kind = $input['kind'] ?? null;
        if (! is_string($kind) || ! array_key_exists($kind, self::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose what to correct.']);
        }
        $reason = $input['reason_code'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::REASONS)) {
            throw ValidationException::withMessages(['reason_code' => 'Choose a reason.']);
        }
        $note = $this->note($input['note'] ?? null);
        $subjectId = $this->subjectId($actor, $input['user_id'] ?? null);
        $own = $subjectId === $actor->id;

        return $this->transaction(function () use ($actor, $input, $key, $kind, $reason, $note, $subjectId, $own): AttendanceCorrection {
            $actor = $this->support->lock($actor, $own ? ClockPermissions::CLOCK_OWN : ClockPermissions::REVIEW, [$subjectId]);
            $subject = User::query()->whereKey($subjectId)->where('organisation_id', $actor->organisation_id)->firstOrFail();
            $hash = $this->support->hash([$actor->id, 'propose', $subjectId, $kind, $reason, $note,
                ...array_map(fn (string $f): string => is_scalar($input[$f] ?? null) ? (string) $input[$f] : '', ['session_id', 'branch_id', 'in_time', 'out_time'])]);
            if (($replay = $this->support->replay($actor, $key, 'attendance_propose', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $session = null;
            if ($kind === 'add_day') {
                $branch = $this->branch($actor, $input['branch_id'] ?? null);
            } else {
                $session = $this->lockSession($actor, $input['session_id'] ?? null, $subject->id);
                $branch = Branch::query()->findOrFail($session->branch_id);
                if (AttendanceCorrection::query()->where('clock_session_id', $session->id)->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['attendance' => 'This day already has a correction waiting.']);
                }
            }
            $settings = $this->settings->forDate($actor->organisation_id, CarbonImmutable::now($branch->timezone));
            if ($settings === null) {
                throw ValidationException::withMessages(['attendance' => 'Attendance is not set up yet. Ask HR.']);
            }
            $earliest = CarbonImmutable::now($branch->timezone)->startOfDay()->subDays($settings->correctionDays);
            $in = $kind === 'set_out' ? null : $this->time($input['in_time'] ?? null, 'in_time', $branch, $earliest);
            $out = $kind === 'change_in' ? null : $this->time($input['out_time'] ?? null, 'out_time', $branch, $earliest);
            $this->assertShape($kind, $session, $in, $out, $subject, $branch);

            $correction = new AttendanceCorrection;
            $correction->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'user_id' => $subject->id,
                'branch_id' => $branch->id, 'clock_session_id' => $session?->id, 'kind' => $kind, 'proposed_in_at' => $in?->utc(), 'proposed_out_at' => $out?->utc(),
                'reason_code' => $reason, 'note_encrypted' => $note, 'status' => 'pending', 'proposed_by_user_id' => $actor->id])->save();
            $this->support->remember($actor, $key, 'attendance_propose', $hash, $correction->public_id);
            $this->support->audit->record('hr.attendance.correction_proposed', $correction, ['subject_user_id' => $subject->id, 'kind' => $kind,
                'reason_code' => $reason, 'on_behalf' => ! $own, 'has_note' => $note !== null], $actor, $branch);

            return $correction;
        });
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, string $correctionId, string $decision, array $input): AttendanceCorrection
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $approve = $decision === 'approve';
        $reason = $approve ? null : ($input['reason'] ?? null);
        if (! $approve && (! is_string($reason) || ! array_key_exists($reason, self::REJECT_REASONS))) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }

        return $this->transaction(function () use ($actor, $correctionId, $input, $key, $approve, $reason): AttendanceCorrection {
            $people = $this->people($actor, $correctionId);
            $actor = $this->support->lock($actor, ClockPermissions::REVIEW, $people);
            $hash = $this->support->hash([$actor->id, $approve ? 'approve' : 'reject', $correctionId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'attendance_decide', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $correction = $this->lockCorrection($actor, $correctionId);
            if ($correction->user_id === $actor->id) {
                throw new AuthorizationException('You may not decide a correction to your own times.');
            }
            if ($correction->proposed_by_user_id === $actor->id) {
                throw new AuthorizationException('You may not decide a correction you proposed.');
            }
            $this->support->assertLock($correction, $input);
            if ($correction->status !== 'pending') {
                throw ValidationException::withMessages(['attendance' => 'This correction was already decided. Reload the page.']);
            }
            $branch = Branch::query()->findOrFail($correction->branch_id);
            if ($approve) {
                $this->apply($actor, $correction, $branch);
            }
            $correction->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'decision_reason' => $reason, 'lock_version' => $correction->lock_version + 1])->save();
            $this->support->remember($actor, $key, 'attendance_decide', $hash, $correction->public_id);
            $this->support->audit->record($approve ? 'hr.attendance.correction_approved' : 'hr.attendance.correction_rejected', $correction,
                ['subject_user_id' => $correction->user_id, 'kind' => $correction->kind, 'reason_code' => $reason], $actor, $branch);

            return $correction;
        });
    }

    /**
     * The proposer withdraws a correction that is still waiting.
     *
     * @param  array<string, mixed>  $input
     */
    public function cancel(User $actor, string $correctionId, array $input): AttendanceCorrection
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $correctionId, $input, $key): AttendanceCorrection {
            $people = $this->people($actor, $correctionId);
            $own = ($people[0] ?? null) === $actor->id;
            $actor = $this->support->lock($actor, $own ? ClockPermissions::CLOCK_OWN : ClockPermissions::REVIEW, $people);
            $hash = $this->support->hash([$actor->id, 'cancel', $correctionId]);
            if (($replay = $this->support->replay($actor, $key, 'attendance_cancel', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $correction = $this->lockCorrection($actor, $correctionId);
            if ($correction->proposed_by_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who proposed a correction can withdraw it.');
            }
            $this->support->assertLock($correction, $input);
            if ($correction->status !== 'pending') {
                throw ValidationException::withMessages(['attendance' => 'This correction was already decided. Reload the page.']);
            }
            $correction->forceFill(['status' => 'cancelled', 'lock_version' => $correction->lock_version + 1])->save();
            $this->support->remember($actor, $key, 'attendance_cancel', $hash, $correction->public_id);
            $this->support->audit->record('hr.attendance.correction_cancelled', $correction, ['subject_user_id' => $correction->user_id, 'kind' => $correction->kind],
                $actor, Branch::query()->find($correction->branch_id));

            return $correction;
        });
    }

    private function apply(User $actor, AttendanceCorrection $correction, Branch $branch): void
    {
        $subject = User::query()->findOrFail($correction->user_id);
        if ($correction->kind === 'add_day') {
            $in = $correction->proposed_in_at ?? throw ValidationException::withMessages(['attendance' => 'Reload the page.']);
            $out = $correction->proposed_out_at;
            $this->assertShape('add_day', null, $in->setTimezone($branch->timezone), $out?->setTimezone($branch->timezone), $subject, $branch);
            $session = new ClockSession;
            $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $correction->organisation_id, 'user_id' => $subject->id,
                'branch_id' => $branch->id, 'local_date' => $in->setTimezone($branch->timezone)->toDateString(), 'timezone' => $branch->timezone,
                'clock_in_at' => $in, 'clock_out_at' => $out, 'status' => 'closed'])->save();
            $this->event($session, 'in', $in, $actor, $correction->id);
            $this->event($session, 'out', $out ?? $in, $actor, $correction->id);
            $correction->forceFill(['created_session_id' => $session->id]);

            return;
        }
        $session = ClockSession::query()->whereKey($correction->clock_session_id)->lockForUpdate()->firstOrFail();
        $in = $correction->proposed_in_at?->setTimezone($branch->timezone);
        $out = $correction->proposed_out_at?->setTimezone($branch->timezone);
        // Re-checked against the session as it is now: it may have been clocked out since the proposal.
        $this->assertShape($correction->kind, $session, $in, $out, $subject, $branch);
        if ($correction->kind === 'change_in' && $in !== null) {
            $session->forceFill(['clock_in_at' => $in->utc(), 'lock_version' => $session->lock_version + 1])->save();
            $this->event($session, 'in', $in->utc(), $actor, $correction->id);
        } elseif ($out !== null) {
            $session->forceFill(['clock_out_at' => $out->utc(), 'status' => 'closed', 'lock_version' => $session->lock_version + 1])->save();
            $this->event($session, 'out', $out->utc(), $actor, $correction->id);
        }
    }

    /** Times must keep the session in order, stay within 24 hours and not overlap another of the person's sessions. */
    private function assertShape(string $kind, ?ClockSession $session, ?CarbonImmutable $in, ?CarbonImmutable $out, User $subject, Branch $branch): void
    {
        // Everything in UTC from here: the query builder writes times without their offset.
        $start = ($kind === 'set_out' ? $session?->clock_in_at : $in)?->utc();
        $end = ($kind === 'change_in' ? $session?->clock_out_at : $out)?->utc();
        if ($start === null) {
            throw ValidationException::withMessages(['in_time' => 'Enter the clock-in time.']);
        }
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([$kind === 'change_in' ? 'in_time' : 'out_time' => 'The clock-out time must be after the clock-in time.']);
        }
        if ($end !== null && $start->diffInMinutes($end) > 24 * 60) {
            throw ValidationException::withMessages([$kind === 'change_in' ? 'in_time' : 'out_time' => 'A clocked day is at most 24 hours.']);
        }
        if ($kind === 'add_day' && ! $this->support->assigned($subject, $branch, $start->setTimezone($branch->timezone)->startOfDay(), true)) {
            throw ValidationException::withMessages(['branch_id' => "{$subject->name} is not assigned to {$branch->name} on that date."]);
        }
        $until = $end ?? $start->addSecond();
        $overlap = ClockSession::query()->where('user_id', $subject->id)->when($session !== null, fn ($q) => $q->whereKeyNot($session?->id))
            ->where('clock_in_at', '<', $until)->get()
            ->contains(fn (ClockSession $s): bool => ($s->clock_out_at ?? $s->clock_in_at->addSecond())->greaterThan($start));
        if ($overlap) {
            throw ValidationException::withMessages([$kind === 'set_out' ? 'out_time' : 'in_time' => 'Those times overlap another clock-in.']);
        }
    }

    private function time(mixed $value, string $field, Branch $branch, CarbonImmutable $earliest): CarbonImmutable
    {
        $message = $field === 'in_time' ? 'Enter the clock-in date and time.' : 'Enter the clock-out date and time.';
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $branch->timezone);
        if (! $at instanceof CarbonImmutable || $at->format('Y-m-d\TH:i') !== $value) {
            throw ValidationException::withMessages([$field => $message]);
        }
        if ($at->greaterThan(CarbonImmutable::now($branch->timezone))) {
            throw ValidationException::withMessages([$field => 'Choose a time that has passed.']);
        }
        if ($at->lessThan($earliest)) {
            throw ValidationException::withMessages([$field => 'Corrections can go back to '.AttendanceSupport::day($earliest).'. Ask HR about older days.']);
        }

        return $at;
    }

    private function subjectId(User $actor, mixed $userId): int
    {
        if ($userId === null || $userId === '' || (int) $userId === $actor->id) {
            return $actor->id;
        }
        $id = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || ! User::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Choose a staff member.']);
        }

        return (int) $id;
    }

    private function branch(User $actor, mixed $branchId): Branch
    {
        return $this->authority->branch($actor, $branchId);
    }

    private function lockSession(User $actor, mixed $sessionId, int $subjectId): ClockSession
    {
        $session = is_string($sessionId) && Str::isUuid($sessionId)
            ? ClockSession::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $sessionId)->where('user_id', $subjectId)->lockForUpdate()->first()
            : null;
        if ($session === null) {
            throw ValidationException::withMessages(['session_id' => 'Choose the day to correct.']);
        }

        return $session;
    }

    /** @return list<int> subject first, then the proposer */
    private function people(User $actor, string $correctionId): array
    {
        if (! Str::isUuid($correctionId)) {
            abort(404);
        }
        $row = AttendanceCorrection::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $correctionId)->first(['user_id', 'proposed_by_user_id']);
        abort_if($row === null, 404);

        return [$row->user_id, $row->proposed_by_user_id];
    }

    private function lockCorrection(User $actor, string $correctionId): AttendanceCorrection
    {
        return AttendanceCorrection::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $correctionId)->lockForUpdate()->firstOrFail();
    }

    private function find(User $actor, string $publicId): AttendanceCorrection
    {
        return AttendanceCorrection::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->firstOrFail();
    }

    private function note(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value) || mb_strlen(trim($value)) > 200) {
            throw ValidationException::withMessages(['note' => 'Use at most 200 characters.']);
        }

        return trim($value);
    }

    private function event(ClockSession $session, string $kind, CarbonImmutable $at, User $actor, int $correctionId): void
    {
        DB::table('hr_clock_events')->insert(['organisation_id' => $session->organisation_id, 'clock_session_id' => $session->id, 'kind' => $kind,
            'occurred_at' => $at->utc(), 'method' => 'correction', 'correction_id' => $correctionId, 'recorded_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
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
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw ValidationException::withMessages(['in_time' => 'Those times overlap another clock-in.']);
            }
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['attendance' => 'This day already has a correction waiting.']);
            }
            throw $e;
        }
    }
}
