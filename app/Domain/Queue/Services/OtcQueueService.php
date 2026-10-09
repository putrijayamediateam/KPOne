<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The OTC waiting list (B series). A patient who only buys medicine still waits their turn: the CA sends the
 * OTC visit to Waiting (taking a B number), calls the number to the dispensary on the TV, and then dispenses.
 * Dispensing never requires a call first: a patient already at the counter can be dispensed straight away.
 */
class OtcQueueService
{
    public function __construct(private BranchAccessService $branches, private QueueNumberGenerator $numbers, private AuditRecorder $audit) {}

    /**
     * Idempotent: a visit already waiting returns its entry.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function enter(User $actor, Visit $visit, array $attributes): OtcQueueEntry
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $visit->branch_id || (int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed or does not own this Visit. Review and retry.']);
        }

        try {
            return DB::transaction(function () use ($actor, $visit, $branch): OtcQueueEntry {
                $lockedActor = $this->lockActor($actor, $branch, 'queue.enter.branch');
                $lockedVisit = Visit::query()->whereKey($visit->id)->where('organisation_id', $lockedActor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
                $existing = OtcQueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->status === OtcQueueEntry::STATUS_REMOVED) {
                        throw ValidationException::withMessages(['queue' => 'This OTC visit has already left the waiting list.']);
                    }

                    return $existing;
                }
                if ($lockedVisit->visit_type !== 'otc' || $lockedVisit->status !== Visit::STATUS_REGISTERED) {
                    throw ValidationException::withMessages(['visit' => 'Only a registered OTC visit can be sent to the OTC waiting list.']);
                }
                $date = now()->setTimezone($branch->timezone)->toDateString();
                $entry = new OtcQueueEntry;
                $entry->forceFill([
                    'organisation_id' => $lockedVisit->organisation_id, 'branch_id' => $lockedVisit->branch_id, 'visit_id' => $lockedVisit->id,
                    'operational_date' => $date, 'queue_number' => $this->numbers->next($branch, $date, QueueNumberFormat::OTC),
                    'status' => OtcQueueEntry::STATUS_WAITING, 'queued_at' => now()->utc(), 'queued_by_user_id' => $lockedActor->id,
                    'updated_by_user_id' => $lockedActor->id, 'lock_version' => 1,
                ])->save();
                $this->audit->record('otc_queue.entered', $entry, ['record_version' => 1], $lockedActor, $branch, $lockedActor->organisation_id);

                return $entry->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $existing = OtcQueueEntry::query()->where('visit_id', $visit->id)->first();
            if ($existing && $existing->status === OtcQueueEntry::STATUS_WAITING) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** Called inside the transaction that opens the OTC Dispensary case (visit already locked). */
    public function leaveForDispensing(Visit $lockedVisit, User $actor, Branch $branch): void
    {
        $this->remove($lockedVisit, $actor, $branch, OtcQueueEntry::REASON_DISPENSING);
    }

    /** Called inside the transaction that cancels an OTC visit (visit already locked). */
    public function leaveForCancellation(Visit $lockedVisit, User $actor, Branch $branch): void
    {
        $this->remove($lockedVisit, $actor, $branch, OtcQueueEntry::REASON_CANCELLED);
    }

    private function remove(Visit $lockedVisit, User $actor, Branch $branch, string $reason): void
    {
        $entry = OtcQueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->first();
        if (! $entry || $entry->status !== OtcQueueEntry::STATUS_WAITING) {
            return;
        }
        $entry->forceFill(['status' => OtcQueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => $reason, 'updated_by_user_id' => $actor->id, 'lock_version' => $entry->lock_version + 1])->save();
        $this->audit->record('otc_queue.removed', $entry, ['record_version' => $entry->lock_version, 'removal_reason' => $reason], $actor, $branch, $actor->organisation_id);
    }

    private function lockActor(User $actor, Branch $branch, string $permission): User
    {
        $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked->load('roles.permissions');
        $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
        if ($profile) {
            StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->lockForUpdate()->get();
            $locked->unsetRelation('staffProfile');
        }
        if (! $locked->is_active || ! $locked->can($permission) || ! $this->branches->canSelect($locked, $branch)) {
            throw new AuthorizationException('You may not change the OTC waiting list.');
        }

        return $locked;
    }
}
