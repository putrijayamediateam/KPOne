<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-2a apply, approve, reject and cancel (B.E.R.N.I.S.S for the owner, 2026-11-05). Nobody decides their own request
 * (service and PostgreSQL); approval re-checks the balance under the applicant's account lock; cancelling approved
 * leave writes a reversal line. Audit rows hold ids, type codes and day counts, never reasons or evidence references.
 */
final class LeaveRequestService
{
    public const REJECT_REASONS = ['not_enough_cover' => 'Not enough cover', 'dates_not_allowed' => 'Dates not allowed', 'wrong_type' => 'Wrong type', 'other' => 'Other'];

    public const CANCEL_REASONS = ['staff_changed_plans' => 'Staff changed plans', 'entered_in_error' => 'Entered in error', 'other' => 'Other'];

    public function __construct(private LeaveSupport $support, private LeaveCalendar $calendar, private LeaveBalanceService $balances) {}

    /**
     * The server's day count for the apply form; nothing is written.
     *
     * @param  array<string, mixed>  $input
     * @return array{halves:int, days:string, skipped:list<string>, leftAfter:string|null, shortfall:string|null}
     */
    public function preview(User $actor, array $input): array
    {
        abort_unless($actor->can(LeavePermissions::REQUEST_OWN), 403);
        $parsed = $this->parse($actor, $input);
        $type = $parsed['type'];
        $left = $type->uses_balance ? $this->balances->left($actor->id, $type->id, $parsed['year']) - $parsed['halves'] : null;
        $short = null;
        if ($left !== null && $left < 0 && ! $type->allow_negative) {
            try {
                $this->balances->assertAffordable($type, $actor->id, $parsed['year'], $parsed['halves']);
            } catch (ValidationException $e) {
                $short = (string) collect($e->errors())->flatten()->first();
            }
        }

        // A shortfall is said in words (Daniel); a negative figure is only shown where the type allows one.
        return ['halves' => $parsed['halves'], 'days' => LeaveSupport::days($parsed['halves']), 'skipped' => $parsed['skipped'],
            'leftAfter' => $left === null || $short !== null ? null : LeaveSupport::days($left), 'shortfall' => $short];
    }

    /** @param array<string, mixed> $input */
    public function apply(User $actor, array $input): LeaveRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $this->text($input['reason'] ?? null, 300, 'reason');
        $evidence = $this->text($input['evidence_reference'] ?? null, 100, 'evidence_reference');

        return $this->transaction(function () use ($actor, $input, $key, $reason, $evidence): LeaveRequest {
            $actor = $this->support->lock($actor, LeavePermissions::REQUEST_OWN);
            $hash = $this->support->hash([$actor->id, 'apply', ...array_map(fn (string $f): string => is_scalar($input[$f] ?? null) ? (string) $input[$f] : '',
                ['leave_type_id', 'start_date', 'end_date', 'start_part', 'end_part']), $reason, $evidence]);
            if (($replay = $this->support->replay($actor, $key, 'leave_apply', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $parsed = $this->parse($actor, $input);
            $type = $parsed['type'];
            if ($evidence !== null && ! $type->evidence_needed) {
                $evidence = null;
            }
            $this->assertNoOverlap($actor->id, $parsed['slot_start'], $parsed['slot_end']);
            $this->balances->assertYearOpen($type, $actor->id, $parsed['year']);
            $this->balances->assertAffordable($type, $actor->id, $parsed['year'], $parsed['halves']);
            $today = CarbonImmutable::now($parsed['branch']->timezone)->startOfDay();

            $request = new LeaveRequest;
            $request->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'user_id' => $actor->id, 'leave_type_id' => $type->id,
                'branch_id' => $parsed['branch']->id, 'start_date' => $parsed['start']->toDateString(), 'end_date' => $parsed['end']->toDateString(),
                'start_part' => $parsed['start_part'], 'end_part' => $parsed['end_part'],
                'slot_start' => $parsed['slot_start']->format('Y-m-d H:i:s'), 'slot_end' => $parsed['slot_end']->format('Y-m-d H:i:s'),
                'year' => $parsed['year'], 'halves' => $parsed['halves'], 'status' => 'pending', 'reason_encrypted' => $reason,
                'evidence_reference_encrypted' => $evidence, 'late' => $parsed['start']->toDateString() <= $today->toDateString(),
            ])->save();
            $this->action($request, 'applied', $actor);
            $this->support->remember($actor, $key, 'leave_apply', $hash, $request->public_id);
            $this->support->audit->record('hr.leave.applied', $request, ['leave_type_code' => $type->code, 'halves' => $request->halves,
                'late' => $request->late, 'has_reason' => $reason !== null, 'has_evidence_reference' => $evidence !== null], $actor, $parsed['branch']);

            return $request;
        });
    }

    /** @param array<string, mixed> $input */
    public function approve(User $actor, string $requestId, array $input): LeaveRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $requestId, $input, $key): LeaveRequest {
            $actor = $this->support->lock($actor, LeavePermissions::APPROVE, [$this->applicantId($actor, $requestId)]);
            $evidenceSeen = filter_var($input['evidence_seen'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $hash = $this->support->hash([$actor->id, 'approve', $requestId, $evidenceSeen]);
            if (($replay = $this->support->replay($actor, $key, 'leave_approve', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $request = $this->lockRequest($actor, $requestId);
            $this->assertDecidable($actor, $request, $input);
            $type = $this->type($request);
            if ($type->evidence_needed && ! $evidenceSeen) {
                throw ValidationException::withMessages(['evidence_seen' => 'Tick "Evidence seen" after checking the paper copy.']);
            }
            $this->balances->assertYearOpen($type, $request->user_id, $request->year, 'leave');
            $this->balances->assertAffordable($type, $request->user_id, $request->year, $request->halves, $request->id, 'leave');
            $request->forceFill(['status' => 'approved', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(), 'evidence_seen' => $evidenceSeen,
                'lock_version' => $request->lock_version + 1])->save();
            if ($type->uses_balance && $request->halves > 0) {
                $this->ledger($request, 'taken', -$request->halves, $actor);
            }
            $this->action($request, 'approved', $actor);
            $this->support->remember($actor, $key, 'leave_approve', $hash, $request->public_id);
            $this->support->audit->record('hr.leave.approved', $request, ['applicant_user_id' => $request->user_id, 'leave_type_code' => $type->code,
                'halves' => $request->halves, 'evidence_seen' => $evidenceSeen], $actor, $this->branch($request));

            return $request;
        });
    }

    /** @param array<string, mixed> $input */
    public function reject(User $actor, string $requestId, array $input): LeaveRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::REJECT_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }

        return $this->transaction(function () use ($actor, $requestId, $input, $key, $reason): LeaveRequest {
            $actor = $this->support->lock($actor, LeavePermissions::APPROVE, [$this->applicantId($actor, $requestId)]);
            $hash = $this->support->hash([$actor->id, 'reject', $requestId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'leave_reject', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $request = $this->lockRequest($actor, $requestId);
            $this->assertDecidable($actor, $request, $input);
            $request->forceFill(['status' => 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(), 'decision_reason' => $reason,
                'lock_version' => $request->lock_version + 1])->save();
            $this->action($request, 'rejected', $actor, $reason);
            $this->support->remember($actor, $key, 'leave_reject', $hash, $request->public_id);
            $this->support->audit->record('hr.leave.rejected', $request, ['applicant_user_id' => $request->user_id, 'leave_type_code' => $this->type($request)->code,
                'halves' => $request->halves, 'reason_code' => $reason], $actor, $this->branch($request));

            return $request;
        });
    }

    /**
     * The applicant cancels their own pending request at any time, or their approved request before its start date.
     * After the start date only an approver who is not the applicant cancels approved leave, with a reason code.
     *
     * @param  array<string, mixed>  $input
     */
    public function cancel(User $actor, string $requestId, array $input): LeaveRequest
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $requestId, $input, $key): LeaveRequest {
            $applicant = $this->applicantId($actor, $requestId);
            $own = $applicant === $actor->id;
            $actor = $this->support->lock($actor, $own ? LeavePermissions::REQUEST_OWN : LeavePermissions::APPROVE, [$applicant]);
            $reason = $own ? null : ($input['reason'] ?? null);
            if (! $own && (! is_string($reason) || ! array_key_exists($reason, self::CANCEL_REASONS))) {
                throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
            }
            $hash = $this->support->hash([$actor->id, 'cancel', $requestId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'leave_cancel', $hash)) !== null) {
                return $this->find($actor, $replay);
            }
            $request = $this->lockRequest($actor, $requestId);
            $this->support->assertLock($request, $input);
            $branch = $this->branch($request);
            $started = $request->start_date->toDateString() <= CarbonImmutable::now($branch->timezone)->toDateString();
            if ($own) {
                if ($request->status === 'approved' && $started) {
                    throw ValidationException::withMessages(['leave' => 'This leave has started. Ask HR to cancel it.']);
                }
                if (! in_array($request->status, ['pending', 'approved'], true)) {
                    throw ValidationException::withMessages(['leave' => 'This request can no longer be cancelled.']);
                }
            } elseif ($request->status !== 'approved') {
                throw ValidationException::withMessages(['leave' => 'Only approved leave can be cancelled here. Reject a request that is waiting.']);
            }
            $wasApproved = $request->status === 'approved';
            $request->forceFill(['status' => 'cancelled', 'cancelled_by_user_id' => $actor->id, 'cancelled_at' => now()->utc(),
                'decision_reason' => $reason ?? $request->decision_reason, 'lock_version' => $request->lock_version + 1])->save();
            $type = $this->type($request);
            if ($wasApproved && $type->uses_balance && $request->halves > 0) {
                $this->ledger($request, 'reversal', $request->halves, $actor);
            }
            $this->action($request, 'cancelled', $actor, $reason);
            $this->support->remember($actor, $key, 'leave_cancel', $hash, $request->public_id);
            $this->support->audit->record('hr.leave.cancelled', $request, ['applicant_user_id' => $request->user_id, 'leave_type_code' => $type->code,
                'halves' => $request->halves, 'was_approved' => $wasApproved, 'by_applicant' => $own, 'reason_code' => $reason], $actor, $branch);

            return $request;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{type:LeaveType, branch:Branch, start:CarbonImmutable, end:CarbonImmutable, start_part:string, end_part:string, year:int, halves:int, slot_start:CarbonImmutable, slot_end:CarbonImmutable, skipped:list<string>}
     */
    private function parse(User $actor, array $input): array
    {
        $typeId = filter_var($input['leave_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = $typeId === false ? null : LeaveType::query()->whereKey($typeId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->first();
        if ($type === null) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose a leave type.']);
        }
        $start = $this->date($input['start_date'] ?? null, 'start_date', 'Choose a start date.');
        $end = $this->date($input['end_date'] ?? null, 'end_date', 'Choose an end date.');
        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['end_date' => 'End date must be on or after the start date.']);
        }
        if ($start->year !== $end->year) {
            throw ValidationException::withMessages(['end_date' => 'Leave across the new year needs two requests, one for each year.']);
        }
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['end_date' => 'That is longer than allowed.']);
        }
        $startPart = $input['start_part'] ?? 'full';
        $endPart = $input['end_part'] ?? 'full';
        $single = $start->equalTo($end);
        if (! in_array($startPart, ['full', 'am', 'pm'], true)) {
            throw ValidationException::withMessages(['start_part' => 'Choose none, morning or afternoon.']);
        }
        // A single-day request uses only the first-day choice; a longer one may start in the afternoon and end at midday.
        if ($single) {
            $endPart = 'full';
        } elseif ($startPart === 'am') {
            throw ValidationException::withMessages(['start_part' => 'A leave of more than one day can start in the afternoon, not end its first day at midday.']);
        } elseif (! in_array($endPart, ['full', 'am'], true)) {
            throw ValidationException::withMessages(['end_part' => 'A leave of more than one day can end at midday, not start its last day in the afternoon.']);
        }
        if (! $type->allow_half_days && ($startPart !== 'full' || $endPart !== 'full')) {
            throw ValidationException::withMessages(['start_part' => "{$type->name} is taken in whole days."]);
        }
        $branch = $this->calendar->primaryBranch($actor, $start);
        if ($branch === null) {
            throw ValidationException::withMessages(['start_date' => 'You have no primary branch on that date. Ask HR.']);
        }
        $measure = $this->calendar->measure($branch, $start, $end, $startPart, $endPart);
        if ($measure['halves'] === 0) {
            throw ValidationException::withMessages(['start_date' => 'Those dates have no working days to take as leave.']);
        }

        return ['type' => $type, 'branch' => $branch, 'start' => $start, 'end' => $end, 'start_part' => $startPart, 'end_part' => $endPart,
            'year' => $start->year, 'halves' => $measure['halves'], 'slot_start' => $measure['slot_start'], 'slot_end' => $measure['slot_end'], 'skipped' => $measure['skipped']];
    }

    private function date(mixed $value, string $field, string $message): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value || $date->year < 2000 || $date->year > 2100) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $date;
    }

    private function text(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value) || mb_strlen(trim($value)) > $max) {
            throw ValidationException::withMessages([$field => "Use at most {$max} characters."]);
        }

        return trim($value);
    }

    private function assertNoOverlap(int $userId, CarbonImmutable $slotStart, CarbonImmutable $slotEnd): void
    {
        $other = LeaveRequest::query()->where('user_id', $userId)->whereIn('status', ['pending', 'approved'])
            ->where('slot_start', '<', $slotEnd->format('Y-m-d H:i:s'))->where('slot_end', '>', $slotStart->format('Y-m-d H:i:s'))->orderBy('slot_start')->first();
        if ($other !== null) {
            $day = $other->slot_start->greaterThan($slotStart->toMutable()) ? $other->slot_start : $slotStart;
            throw ValidationException::withMessages(['start_date' => 'You already have leave on '.$day->format('j M Y').'.']);
        }
    }

    /** @param array<string, mixed> $input */
    private function assertDecidable(User $actor, LeaveRequest $request, array $input): void
    {
        if ($request->user_id === $actor->id) {
            throw new AuthorizationException('You may not decide your own leave.');
        }
        $this->support->assertLock($request, $input);
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['leave' => 'This request was already decided. Reload the page.']);
        }
    }

    private function applicantId(User $actor, string $requestId): ?int
    {
        if (! Str::isUuid($requestId)) {
            return null;
        }
        $id = LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->value('user_id');

        return $id === null ? null : (int) $id;
    }

    private function lockRequest(User $actor, string $requestId): LeaveRequest
    {
        if (! Str::isUuid($requestId)) {
            abort(404);
        }

        return LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->lockForUpdate()->firstOrFail();
    }

    private function find(User $actor, string $publicId): LeaveRequest
    {
        return LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->firstOrFail();
    }

    private function type(LeaveRequest $request): LeaveType
    {
        return LeaveType::query()->whereKey($request->leave_type_id)->firstOrFail();
    }

    private function branch(LeaveRequest $request): Branch
    {
        return Branch::query()->whereKey($request->branch_id)->firstOrFail();
    }

    private function ledger(LeaveRequest $request, string $kind, int $halves, User $actor): void
    {
        DB::table('hr_leave_ledger')->insert(['organisation_id' => $request->organisation_id, 'user_id' => $request->user_id, 'leave_type_id' => $request->leave_type_id,
            'year' => $request->year, 'kind' => $kind, 'halves' => $halves, 'leave_request_id' => $request->id, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
    }

    private function action(LeaveRequest $request, string $action, User $actor, ?string $reason = null): void
    {
        DB::table('hr_leave_request_actions')->insert(['organisation_id' => $request->organisation_id, 'leave_request_id' => $request->id, 'action' => $action,
            'actor_user_id' => $actor->id, 'reason' => $reason, 'occurred_at' => now()->utc()]);
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
            // The PostgreSQL overlap guard is the last line; a race past the friendly check lands here.
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw ValidationException::withMessages(['start_date' => 'You already have leave on one of those days.']);
            }
            throw $e;
        }
    }
}
