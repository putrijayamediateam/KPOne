<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AttendanceAuthority
{
    /** @return array{User, Branch, Visit, ClinicalEncounter} */
    public function lock(User $actor, Visit $visit, mixed $expected, string $permission): array
    {
        $authority = app(TransactionalActorAuthority::class);
        $branch = $authority->branch($actor, $expected);
        abort_unless(DB::table('organisations')->where('id', $branch->organisation_id)->where('is_active', true)->exists(), 403);
        $actor = $authority->lock($actor, $branch, $permission);
        abort_unless($actor->getAllPermissions()->contains('name', $permission) && $actor->getAllPermissions()->contains('name', 'attendance_documents.view.own'), 403);
        $branch = Branch::query()->whereKey($branch->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        // Match clinical/Dispensary order: patient -> Visit -> Queue -> Encounter.
        // In particular, do not hold Visit while waiting for a CA-held patient lock.
        $stub = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail(['id', 'patient_id']);
        Patient::query()->whereKey($stub->patient_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail(['id']);
        $visit = Visit::query()->whereKey($stub->id)->where('patient_id', $stub->patient_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
        $visit->setRelation('queueEntry', $visit->queueEntry()->lockForUpdate()->firstOrFail());
        $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('attending_clinician_user_id', $actor->id)->lockForUpdate()->firstOrFail();

        return [$actor, $branch, $visit, $encounter];
    }

    /** Issued-document reads never load clinical content or require source ownership for a branch viewer.
     * @return array{User, Branch, Visit}
     */
    public function lockRead(User $actor, Visit $visit, bool $printing = false): array
    {
        $authority = app(TransactionalActorAuthority::class);
        $branch = $authority->branch($actor, app(BranchAccessService::class)->activeBranch($actor)?->id);
        abort_unless(DB::table('organisations')->where('id', $branch->organisation_id)->where('is_active', true)->exists(), 403);
        $actor = $authority->lock($actor, $branch, $actor->can('attendance_documents.view.branch') ? 'attendance_documents.view.branch' : 'attendance_documents.view.own');
        $permissions = $actor->getAllPermissions()->pluck('name');
        $scope = $permissions->contains('attendance_documents.view.branch') ? 'branch' : 'own';
        abort_unless($permissions->contains('attendance_documents.view.'.$scope), 403);
        if ($printing) {
            abort_unless($permissions->contains('attendance_documents.print.'.$scope), 403);
        }
        $branch = Branch::query()->whereKey($branch->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $stub = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail(['id', 'patient_id']);
        Patient::query()->whereKey($stub->patient_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail(['id']);
        $visit = Visit::query()->whereKey($stub->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
        if ($scope === 'own') {
            abort_unless(ClinicalEncounter::query()->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('attending_clinician_user_id', $actor->id)->exists(), 404);
        }

        return [$actor, $branch, $visit];
    }

    public function canFirstIssue(Visit $visit, ClinicalEncounter $encounter, User $actor): bool
    {
        return $visit->visit_type === 'consultation' && $visit->status === 'registered' && $visit->assigned_doctor_user_id === $actor->id
            && $visit->queueEntry?->status === 'serving' && $encounter->status === 'in_progress'
            && ! $encounter->holds()->whereNull('resumed_at')->exists()
            && ! ConsultationCheckout::query()->where('visit_id', $visit->id)->exists();
    }
}
