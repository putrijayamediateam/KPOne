<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveAdjustment;
use App\Domain\HR\Leave\Models\LeaveBalanceEntry;
use App\Domain\HR\Leave\Models\LeaveOpening;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-2a opening balances and adjustments. A manager enters one; a different manager confirms or rejects it (service
 * and PostgreSQL). Only a confirmed entry writes its ledger line. Audit holds ids, type codes and day counts; the
 * adjustment note stays encrypted and is never audited.
 */
final class LeaveEntryService
{
    public const ADJUSTMENT_REASONS = ['correction' => 'Correction', 'in_lieu' => 'Leave given in lieu', 'other' => 'Other'];

    public function __construct(private LeaveSupport $support, private LeaveBalanceService $balances) {}

    /**
     * @param  'opening'|'adjustment'  $kind
     * @param  array<string, mixed>  $input
     */
    public function enter(User $actor, string $kind, array $input): LeaveBalanceEntry
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $halves = $this->support->halves($input['days'] ?? null, 'days', $kind === 'adjustment');
        if ($kind === 'adjustment' && $halves === 0) {
            throw ValidationException::withMessages(['days' => 'Enter the days to add or remove, for example 1 or -0.5.']);
        }
        $reason = $kind === 'adjustment' ? ($input['reason_code'] ?? null) : null;
        if ($kind === 'adjustment' && (! is_string($reason) || ! array_key_exists($reason, self::ADJUSTMENT_REASONS))) {
            throw ValidationException::withMessages(['reason_code' => 'Choose a reason.']);
        }
        $note = $kind === 'adjustment' && is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null;
        if ($note !== null && mb_strlen($note) > 200) {
            throw ValidationException::withMessages(['note' => 'Use at most 200 characters.']);
        }

        return DB::transaction(function () use ($actor, $kind, $input, $key, $halves, $reason, $note): LeaveBalanceEntry {
            $person = User::query()->whereKey(filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT))->where('organisation_id', $actor->organisation_id)->first();
            if ($person === null) {
                throw ValidationException::withMessages(['user_id' => 'Choose a person.']);
            }
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE, [$person->id]);
            $hash = $this->support->hash([$actor->id, $kind, $person->id, $input['leave_type_id'] ?? null, $input['year'] ?? null, $halves, $reason, $note]);
            if (($replay = $this->support->replay($actor, $key, 'leave_'.$kind, $hash)) !== null) {
                return $this->find($actor, $kind, $replay);
            }
            $type = LeaveType::query()->whereKey(filter_var($input['leave_type_id'] ?? null, FILTER_VALIDATE_INT))->where('organisation_id', $actor->organisation_id)
                ->where('uses_balance', true)->first();
            if ($type === null) {
                throw ValidationException::withMessages(['leave_type_id' => 'Choose a leave type that counts against a balance.']);
            }
            $year = filter_var($input['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
            if ($year === false) {
                throw ValidationException::withMessages(['year' => 'Choose a year.']);
            }
            if ($kind === 'opening' && LeaveOpening::query()->where('user_id', $person->id)->where('leave_type_id', $type->id)->where('year', $year)->whereIn('status', ['pending', 'confirmed'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'This person already has an opening balance for this type and year.']);
            }
            $entry = $kind === 'opening' ? new LeaveOpening : new LeaveAdjustment;
            $entry->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'user_id' => $person->id, 'leave_type_id' => $type->id,
                'year' => $year, 'halves' => $halves, 'status' => 'pending', 'entered_by_user_id' => $actor->id,
                ...($kind === 'adjustment' ? ['reason_code' => $reason, 'note_encrypted' => $note] : [])])->save();
            $this->support->remember($actor, $key, 'leave_'.$kind, $hash, $entry->public_id);
            $this->support->audit->record("hr.leave.{$kind}_entered", $entry, ['subject_user_id' => $person->id, 'leave_type_code' => $type->code, 'year' => $year,
                'halves' => $halves, 'reason_code' => $reason, 'has_note' => $note !== null], $actor);

            return $entry;
        }, 3);
    }

    /**
     * @param  'opening'|'adjustment'  $kind
     * @param  'confirmed'|'rejected'  $decision
     * @param  array<string, mixed>  $input
     */
    public function decide(User $actor, string $kind, string $entryId, string $decision, array $input): LeaveBalanceEntry
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $kind, $entryId, $decision, $input, $key): LeaveBalanceEntry {
            $model = $kind === 'opening' ? LeaveOpening::class : LeaveAdjustment::class;
            abort_unless(Str::isUuid($entryId), 404);
            $personId = $model::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $entryId)->value('user_id');
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE, [$personId]);
            $hash = $this->support->hash([$actor->id, 'decide', $kind, $entryId, $decision]);
            if (($replay = $this->support->replay($actor, $key, 'leave_'.$kind.'_decide', $hash)) !== null) {
                return $this->find($actor, $kind, $replay);
            }
            /** @var LeaveBalanceEntry $entry */
            $entry = $model::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $entryId)->lockForUpdate()->firstOrFail();
            if ($entry->entered_by_user_id === $actor->id) {
                throw new AuthorizationException('Another manager must check this.');
            }
            $this->support->assertLock($entry, $input);
            if ($entry->status !== 'pending') {
                throw ValidationException::withMessages(['leave' => 'This was already checked. Reload the page.']);
            }
            $type = LeaveType::query()->whereKey($entry->leave_type_id)->firstOrFail();
            if ($decision === 'confirmed' && $entry->halves < 0) {
                $this->balances->assertYearOpen($type, $entry->user_id, $entry->year, 'leave');
                $this->balances->assertAffordable($type, $entry->user_id, $entry->year, -$entry->halves, null, 'leave');
            }
            $entry->forceFill(['status' => $decision, 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(), 'lock_version' => $entry->lock_version + 1])->save();
            if ($decision === 'confirmed' && $entry->halves !== 0) {
                DB::table('hr_leave_ledger')->insert(['organisation_id' => $entry->organisation_id, 'user_id' => $entry->user_id, 'leave_type_id' => $entry->leave_type_id,
                    'year' => $entry->year, 'kind' => $kind, 'halves' => $entry->halves, $kind === 'opening' ? 'leave_opening_id' : 'leave_adjustment_id' => $entry->id,
                    'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            }
            $this->support->remember($actor, $key, 'leave_'.$kind.'_decide', $hash, $entry->public_id);
            $this->support->audit->record("hr.leave.{$kind}_{$decision}", $entry, ['subject_user_id' => $entry->user_id, 'leave_type_code' => $type->code,
                'year' => $entry->year, 'halves' => $entry->halves], $actor);

            return $entry;
        }, 3);
    }

    private function find(User $actor, string $kind, string $publicId): LeaveBalanceEntry
    {
        $model = $kind === 'opening' ? LeaveOpening::class : LeaveAdjustment::class;

        return $model::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->firstOrFail();
    }
}
