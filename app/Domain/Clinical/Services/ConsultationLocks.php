<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

/**
 * Hold/resume/call/automatic-return order: users (actor + assigned doctor, ascending id),
 * staff profile/assignments, patient (when needed), visit, queue, encounter,
 * clinical sources, dispensary case/handoff/items, holds. No doctor lock after a visit lock.
 * Numeric ids use numeric order; no locale-sensitive text order participates.
 */
final class ConsultationLocks
{
    /** @param list<int|null> $ids */
    public static function users(int $organisationId, array $ids): void
    {
        $users = User::query()->where('organisation_id', $organisationId)->whereIn('id', array_filter($ids))
            ->orderBy('id')->lockForUpdate()->get();
        $profiles = StaffProfile::query()->whereIn('user_id', $users->modelKeys())->orderBy('id')->lockForUpdate()->get();
        StaffBranchAssignment::query()->whereIn('staff_profile_id', $profiles->modelKeys())->orderBy('id')->lockForUpdate()->get();
    }

    /** Boolean only: never return another branch's patient identity to the caller. */
    public static function doctorBusy(User $doctor, ?Visit $except = null): bool
    {
        return QueueEntry::query()->where('organisation_id', $doctor->organisation_id)
            ->where('status', QueueEntry::STATUS_SERVING)
            ->when($except, fn ($q) => $q->where('visit_id', '!=', $except->id))
            ->whereHas('visit', fn ($q) => $q->where('assigned_doctor_user_id', $doctor->id))
            ->whereDoesntHave('visit.clinicalEncounter.activeHold')->exists();
    }
}
