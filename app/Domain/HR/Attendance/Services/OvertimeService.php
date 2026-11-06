<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Models\OvertimeRequest;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-3b overtime requests. A staff member asks for overtime minutes on one of their closed clock sessions (or a manager
 * asks on their behalf); someone who is neither the subject nor the requester approves, approves fewer minutes or
 * rejects it (service and PostgreSQL). Minutes, day type and the settings version only: no money. Notes are encrypted
 * and never audited. Nothing is deleted: a request is withdrawn, rejected, or reversed after approval.
 */
final class OvertimeService
{
    public const REASONS = ['patient_load' => 'Busy clinic', 'cover_absence' => 'Covered for someone absent', 'stayed_to_finish' => 'Stayed to finish work',
        'asked_by_manager' => 'Asked by a manager', 'other' => 'Other'];

    public const LOWER_REASONS = ['not_needed' => 'Not all of it was needed', 'more_than_agreed' => 'More than was agreed', 'other' => 'Other'];

    public const REJECT_REASONS = ['not_agreed' => 'Not agreed in advance', 'not_overtime' => 'Not overtime', 'wrong_day' => 'Wrong day', 'other' => 'Other'];

    public const REVERSE_REASONS = ['mistake' => 'Approved by mistake', 'hours_changed' => 'A correction changed the hours', 'other' => 'Other'];

    public function __construct(
        private AttendanceSupport $support,
        private AttendanceSettingsService $attendance,
        private OvertimeSettingsService $settings,
        private AttendanceDays $days,
        private OvertimeDayTypes $dayTypes,
    ) {}

    /**
     * What a closed session allows: on a normal day the minutes outside the matched shift's window (all of the worked
     * minutes when the session is not on the roster); on a rest day or public holiday all of the worked minutes.
     *
     * @return array{max: int, worked: int, rostered: int|null, dayType: string, dayLabel: string}
     */
    public function allowance(ClockSession $session, Branch $branch): array
    {
        $shift = $this->days->matchedShift($session);
        $worked = AttendanceDays::worked($session, $shift) ?? 0;
        $rostered = $shift === null ? null : AttendanceDays::rostered($shift);
        $day = $this->dayTypes->of($branch, $session->local_date);
        $max = $day['type'] === 'normal' && $shift !== null ? AttendanceDays::beyondRoster($session, $shift) : $worked;

        return ['max' => min($max, 1440), 'worked' => $worked, 'rostered' => $rostered, 'dayType' => $day['type'], 'dayLabel' => $day['label']];
    }

    /** @param array<string, mixed> $input */
    public function ask(User $actor, array $input): OvertimeRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $input['reason_code'] ?? null;
        // A missing reason is reported together with a minutes error, in one submit (Daniel).
        $reasonError = ! is_string($reason) || ! array_key_exists($reason, self::REASONS) ? ['reason_code' => 'Choose a reason.'] : [];
        $note = $this->note($input['note'] ?? null);
        $onBehalf = filter_var($input['on_behalf'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // From Review overtime is always for someone else, never the reviewer's own (Suresh).
        if ($onBehalf && (in_array($input['user_id'] ?? null, [null, ''], true) || (int) $input['user_id'] === $actor->id)) {
            throw ValidationException::withMessages(['user_id' => 'Choose a staff member.']);
        }
        $subjectId = $this->subjectId($actor, $input['user_id'] ?? null);
        $own = $subjectId === $actor->id;

        return $this->transaction(function () use ($actor, $input, $key, $reason, $reasonError, $note, $subjectId, $own): OvertimeRequest {
            $actor = $this->support->lock($actor, $own ? ClockPermissions::CLOCK_OWN : ClockPermissions::REVIEW, [$subjectId]);
            $subject = User::query()->whereKey($subjectId)->where('organisation_id', $actor->organisation_id)->firstOrFail();
            $minutesInput = is_scalar($input['minutes'] ?? null) ? (string) $input['minutes'] : '';
            $hash = $this->support->hash([$actor->id, 'overtime_ask', $subjectId, is_scalar($input['session_id'] ?? null) ? (string) $input['session_id'] : '', $minutesInput, is_string($reason) ? $reason : '', $note]);
            if (($replay = $this->support->replay($actor, $key, 'overtime_ask', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $session = $this->lockSession($actor, $input['session_id'] ?? null, $subject->id);
            $branch = Branch::query()->findOrFail($session->branch_id);
            if ($session->status !== 'closed') {
                throw ValidationException::withMessages(['session_id' => 'Clock out, or add the clock-out time, before asking for overtime.']);
            }
            $attendance = $this->attendance->forDate($actor->organisation_id, CarbonImmutable::now($branch->timezone));
            if ($attendance === null) {
                throw ValidationException::withMessages(['overtime' => 'Attendance is not set up yet. Ask HR.']);
            }
            $earliest = CarbonImmutable::now($branch->timezone)->startOfDay()->subDays($attendance->correctionDays);
            if ($session->local_date->toDateString() < $earliest->toDateString()) {
                throw ValidationException::withMessages(['session_id' => 'Overtime can be asked for back to '.AttendanceSupport::day($earliest).'. Ask HR about older days.']);
            }
            $version = $this->settings->forDate($actor->organisation_id, $session->local_date);
            if ($version === null) {
                throw ValidationException::withMessages(['overtime' => 'Overtime is not set up yet. Ask HR.']);
            }
            if (! $this->settings->eligible($version, $subject)) {
                throw ValidationException::withMessages(['overtime' => $own ? 'Your role cannot claim overtime. Ask HR if this is wrong.' : "{$subject->name}'s role cannot claim overtime."]);
            }
            if (AttendanceCorrection::query()->where('clock_session_id', $session->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['session_id' => 'This day has a correction waiting. Ask for overtime once it is decided.']);
            }
            if (OvertimeRequest::query()->where('clock_session_id', $session->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['session_id' => 'Overtime was already asked for on this day.']);
            }
            $allowance = $this->allowance($session, $branch);
            if ($allowance['max'] < 1) {
                throw ValidationException::withMessages(['session_id' => 'There is no time beyond the roster on this day.']);
            }
            try {
                $minutes = $this->minutes($input['minutes'] ?? null, $allowance['max'], 'minutes');
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([...$reasonError, ...array_map(fn (array $m): string => (string) $m[0], $e->errors())]);
            }
            if ($reasonError !== []) {
                throw ValidationException::withMessages($reasonError);
            }

            $request = new OvertimeRequest;
            $request->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'user_id' => $subject->id,
                'requested_by_user_id' => $actor->id, 'clock_session_id' => $session->id, 'branch_id' => $branch->id, 'local_date' => $session->local_date->toDateString(),
                'minutes_requested' => $minutes, 'reason_code' => $reason, 'note_encrypted' => $note, 'day_type' => $allowance['dayType'], 'status' => 'pending'])->save();
            $this->support->remember($actor, $key, 'overtime_ask', $hash, $request->public_id);
            $this->support->audit->record('hr.overtime.requested', $request, ['subject_user_id' => $subject->id, 'minutes' => $minutes, 'day_type' => $allowance['dayType'],
                'reason_code' => $reason, 'on_behalf' => ! $own, 'has_note' => $note !== null], $actor, $branch);

            return $request;
        });
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, string $requestId, string $decision, array $input): OvertimeRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $approve = $decision === 'approve';
        $reason = $input['reason'] ?? null;
        $reason = is_string($reason) && $reason !== '' ? $reason : null;
        if (! $approve && ($reason === null || ! array_key_exists($reason, self::REJECT_REASONS))) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }

        return $this->transaction(function () use ($actor, $requestId, $input, $key, $approve, $reason): OvertimeRequest {
            $people = $this->people($actor, $requestId);
            $actor = $this->support->lock($actor, ClockPermissions::OVERTIME_APPROVE, $people);
            $minutesInput = is_scalar($input['minutes'] ?? null) ? (string) $input['minutes'] : '';
            $hash = $this->support->hash([$actor->id, $approve ? 'approve' : 'reject', $requestId, $minutesInput, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'overtime_decide', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $request = $this->lockRequest($actor, $requestId);
            if ($request->user_id === $actor->id) {
                throw new AuthorizationException('You may not decide your own overtime.');
            }
            if ($request->requested_by_user_id === $actor->id) {
                throw new AuthorizationException('You may not decide overtime you asked for.');
            }
            $this->support->assertLock($request, $input);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['overtime' => 'This request was already decided. Reload the page.']);
            }
            $branch = Branch::query()->findOrFail($request->branch_id);
            $fill = ['status' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(), 'lock_version' => $request->lock_version + 1];
            $audit = ['subject_user_id' => $request->user_id, 'minutes_requested' => $request->minutes_requested];
            if ($approve) {
                $session = ClockSession::query()->whereKey($request->clock_session_id)->lockForUpdate()->firstOrFail();
                $version = $this->settings->forDate($actor->organisation_id, $request->local_date) ?? throw ValidationException::withMessages(['overtime' => 'Overtime is not set up for that day.']);
                // Worked out again now: a correction may have changed the hours, or the calendar the day type.
                $allowance = $this->allowance($session, $branch);
                if ($allowance['max'] < 1) {
                    throw ValidationException::withMessages(['minutes' => 'The hours on this day changed and there is no time beyond the roster now. Reject it instead.']);
                }
                $minutes = $minutesInput === '' ? $request->minutes_requested : $this->minutes($input['minutes'], $request->minutes_requested, 'minutes');
                if ($minutes > $allowance['max']) {
                    throw ValidationException::withMessages(['minutes' => "The hours on this day changed. Approve at most {$allowance['max']} minutes."]);
                }
                if ($minutes < $request->minutes_requested && ($reason === null || ! array_key_exists($reason, self::LOWER_REASONS))) {
                    throw ValidationException::withMessages(['reason' => 'Choose why fewer minutes are approved.']);
                }
                $fill += ['minutes_approved' => $minutes, 'day_type' => $allowance['dayType'], 'overtime_setting_id' => $version->id,
                    'multiplier' => $version->multiplier($allowance['dayType']), 'worked_minutes_at_decision' => min($allowance['worked'], 1440), 'decision_reason' => $minutes < $request->minutes_requested ? $reason : null];
                $audit += ['minutes_approved' => $minutes, 'day_type' => $allowance['dayType'], 'settings_id' => $version->id, 'reason_code' => $fill['decision_reason']];
            } else {
                $fill['decision_reason'] = $reason;
                $audit['reason_code'] = $reason;
            }
            $request->forceFill($fill)->save();
            $this->support->remember($actor, $key, 'overtime_decide', $hash, $request->public_id);
            $this->support->audit->record($approve ? 'hr.overtime.approved' : 'hr.overtime.rejected', $request, $audit, $actor, $branch);

            return $request;
        });
    }

    /**
     * The requester withdraws a request that is still waiting.
     *
     * @param  array<string, mixed>  $input
     */
    public function withdraw(User $actor, string $requestId, array $input): OvertimeRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $requestId, $input, $key): OvertimeRequest {
            $people = $this->people($actor, $requestId);
            $own = ($people[0] ?? null) === $actor->id;
            $actor = $this->support->lock($actor, $own ? ClockPermissions::CLOCK_OWN : ClockPermissions::REVIEW, $people);
            $hash = $this->support->hash([$actor->id, 'withdraw', $requestId]);
            if (($replay = $this->support->replay($actor, $key, 'overtime_withdraw', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $request = $this->lockRequest($actor, $requestId);
            if ($request->requested_by_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who asked for the overtime can withdraw it.');
            }
            $this->support->assertLock($request, $input);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['overtime' => 'This request was already decided. Reload the page.']);
            }
            $request->forceFill(['status' => 'withdrawn', 'lock_version' => $request->lock_version + 1])->save();
            $this->support->remember($actor, $key, 'overtime_withdraw', $hash, $request->public_id);
            $this->support->audit->record('hr.overtime.withdrawn', $request, ['subject_user_id' => $request->user_id], $actor, Branch::query()->find($request->branch_id));

            return $request;
        });
    }

    /**
     * A manager who is not the subject reverses an approved request; the day may then be asked for again.
     *
     * @param  array<string, mixed>  $input
     */
    public function reverse(User $actor, string $requestId, array $input): OvertimeRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::REVERSE_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }

        return $this->transaction(function () use ($actor, $requestId, $input, $key, $reason): OvertimeRequest {
            $people = $this->people($actor, $requestId);
            $actor = $this->support->lock($actor, ClockPermissions::OVERTIME_APPROVE, $people);
            $hash = $this->support->hash([$actor->id, 'reverse', $requestId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'overtime_reverse', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $request = $this->lockRequest($actor, $requestId);
            if ($request->user_id === $actor->id) {
                throw new AuthorizationException('You may not reverse your own overtime.');
            }
            $this->support->assertLock($request, $input);
            if ($request->status !== 'approved') {
                throw ValidationException::withMessages(['overtime' => 'Only approved overtime can be reversed. Reload the page.']);
            }
            $request->forceFill(['status' => 'reversed', 'reversed_by_user_id' => $actor->id, 'reversed_at' => now()->utc(), 'reverse_reason' => $reason,
                'lock_version' => $request->lock_version + 1])->save();
            $this->support->remember($actor, $key, 'overtime_reverse', $hash, $request->public_id);
            $this->support->audit->record('hr.overtime.reversed', $request, ['subject_user_id' => $request->user_id, 'minutes_approved' => $request->minutes_approved,
                'reason_code' => $reason], $actor, Branch::query()->find($request->branch_id));

            return $request;
        });
    }

    private function minutes(mixed $value, int $max, string $field): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => max(1, $max)]]);
        if ($n === false) {
            throw ValidationException::withMessages([$field => "Enter whole minutes from 1 to {$max}."]);
        }

        return $n;
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

    private function lockSession(User $actor, mixed $sessionId, int $subjectId): ClockSession
    {
        $session = is_string($sessionId) && Str::isUuid($sessionId)
            ? ClockSession::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $sessionId)->where('user_id', $subjectId)->lockForUpdate()->first()
            : null;
        if ($session === null) {
            throw ValidationException::withMessages(['session_id' => 'Choose the day.']);
        }

        return $session;
    }

    /** @return list<int> subject first, then the requester */
    private function people(User $actor, string $requestId): array
    {
        if (! Str::isUuid($requestId)) {
            abort(404);
        }
        $row = OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->first(['user_id', 'requested_by_user_id']);
        abort_if($row === null, 404);

        return [$row->user_id, $row->requested_by_user_id];
    }

    private function lockRequest(User $actor, string $requestId): OvertimeRequest
    {
        return OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->lockForUpdate()->firstOrFail();
    }

    private function find(User $actor, string $publicId): OvertimeRequest
    {
        return OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->firstOrFail();
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
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['session_id' => 'Overtime was already asked for on this day.']);
            }
            throw $e;
        }
    }
}
