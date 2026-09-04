<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

class BillingContextService
{
    public function __construct(private TransactionalActorAuthority $authority) {}

    /** @return array{User, Visit, Branch} */
    public function lock(User $actor, Visit $visit, mixed $expectedBranch, string $permission): array
    {
        $branch = $this->authority->branch($actor, $expectedBranch);
        $actor = $this->authority->lock($actor, $branch, $permission);
        abort_unless($visit->organisation_id === $actor->organisation_id && $visit->branch_id === $branch->id, 404);
        Patient::query()->whereKey($visit->patient_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        $visit = Visit::query()->whereKey($visit->id)->where('branch_id', $branch->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();

        return [$actor, $visit, $branch];
    }
}
