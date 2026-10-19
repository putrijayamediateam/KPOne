<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

final class MedicalCertificateAuthority
{
    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority) {}

    /**
     * Called within the document transaction for both reads and writes.
     *
     * @return array{User, Branch, Visit, ClinicalEncounter}
     */
    public function lock(User $actor, Visit $visit, mixed $expected, bool $write = false, bool $print = false): array
    {
        $branch = $this->authority->branch($actor, $expected);
        abort_unless($branch->is_active && $visit->branch_id === $branch->id && $visit->organisation_id === $branch->organisation_id, 404);
        $fresh = User::query()->findOrFail($actor->id);
        $fresh->load(['roles.permissions', 'permissions']);
        $permission = $write ? 'medical_certificates.issue.own' : ($fresh->getAllPermissions()->contains('name', 'medical_certificates.view.branch') ? 'medical_certificates.view.branch' : 'medical_certificates.view.own');
        $fresh = $this->authority->lock($actor, $branch, $permission);
        abort_unless($fresh->getAllPermissions()->contains('name', $permission), 403);
        if ($print) {
            abort_unless($fresh->getAllPermissions()->contains('name', 'medical_certificates.print.branch'), 403);
        }
        $branch = Branch::query()->whereKey($branch->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $visit = Visit::query()->whereKey($visit->id)->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
        $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
        if ($write || $permission === 'medical_certificates.view.own') {
            abort_unless($encounter->attending_clinician_user_id === $fresh->id, 404);
        }

        return [$fresh, $branch, $visit, $encounter];
    }

    public function branchId(User $actor): int
    {
        return $this->branches->activeBranch($actor)->id ?? 0;
    }
}
