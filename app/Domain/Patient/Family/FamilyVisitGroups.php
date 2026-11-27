<?php

namespace App\Domain\Patient\Family;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * FAM-1: the shared visits.family_group_id. Family members registered at the same branch on the same branch-local day get
 * the same key, so Billing (BIL-39) can show their invoices together. The key is derived from the family, branch and day,
 * so it never needs to be copied from another member's visit and no other member's visit is locked at registration.
 *
 * Only Patients & Registration writes the key: at registration (VisitRegistrationService) and when a patient joins a
 * family (PatientFamilyService stamps that patient's open visits of the day). It is never rewritten once set, and leaving a
 * family does not clear it: "Remove from family bill" is Billing's own exclusion, and an old bill never changes.
 */
final class FamilyVisitGroups
{
    /** Fixed namespace for KPOne family visit group keys (a random constant; do not change once data exists). */
    private const NAMESPACE = '3f6c2a1e-8d4b-4f7a-9c35-6b1e0d2f9a47';

    public function key(int $organisationId, int $familyId, int $branchId, string $localDate): string
    {
        return Uuid::uuid5(self::NAMESPACE, "kpone:family-visit-group:{$organisationId}:{$familyId}:{$branchId}:{$localDate}")->toString();
    }

    /**
     * The key for a visit being registered now. The caller holds the patient row lock, so membership cannot change
     * underneath (every membership change locks the patient row first).
     */
    public function forRegistration(Patient $lockedPatient, Branch $branch, string $localDate): ?string
    {
        $familyId = PatientFamilyMember::query()
            ->where('organisation_id', $lockedPatient->organisation_id)
            ->where('patient_id', $lockedPatient->id)
            ->whereNull('left_at')
            ->value('patient_family_id');

        return $familyId === null ? null : $this->key($lockedPatient->organisation_id, (int) $familyId, $branch->id, $localDate);
    }

    /**
     * Stamp the joining patient's still-registered visits of today at the actor's active branch that have no key yet.
     * Visits are branch-owned, so a visit at another branch is left alone. The caller holds the patient row lock and the
     * family row lock; visit rows are locked here, after them. Each stamped visit records the actor and a new version,
     * like every other visit writer, so a reader holding the old version sees the change.
     *
     * @return int the number of visits stamped
     */
    public function stampOpenVisits(User $actor, Patient $lockedPatient, PatientFamily $family, Branch $branch): int
    {
        $visits = Visit::query()
            ->where('organisation_id', $lockedPatient->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('patient_id', $lockedPatient->id)
            ->where('status', Visit::STATUS_REGISTERED)
            ->whereNull('family_group_id')
            ->where('registered_at', '>=', now()->utc()->subDays(2))
            ->with('branch:id,organisation_id,timezone')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $stamped = 0;
        foreach ($visits as $visit) {
            $timezone = $visit->branch->timezone;
            $localDate = Carbon::parse($visit->registered_at)->setTimezone($timezone)->toDateString();
            if ($localDate !== now($timezone)->toDateString()) {
                continue;
            }
            $visit->forceFill([
                'family_group_id' => $this->key($family->organisation_id, $family->id, $visit->branch_id, $localDate),
                'lock_version' => $visit->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $stamped++;
        }

        return $stamped;
    }
}
