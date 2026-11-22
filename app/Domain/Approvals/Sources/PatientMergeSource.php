<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Patient\Services\PatientMergeService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** A proposed patient merge or unmerge waits for a second person (never the proposer). No document number, so no expand. */
final class PatientMergeSource implements ApprovalSource
{
    public function key(): string
    {
        return 'patient_merge';
    }

    public function label(): string
    {
        return 'Patient merges to decide';
    }

    public function area(): string
    {
        return 'Patients and clinical';
    }

    public function kind(): string
    {
        return self::APPROVAL;
    }

    public function permissions(): array
    {
        return [PatientMergeService::APPROVE];
    }

    public function branchScoped(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'patient-merges.index';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function hasReferences(): bool
    {
        return false;
    }

    /** The page's own `canDecide`: proposed, and not proposed by this person. */
    /** @return Builder<PatientMergeRequest> */
    public static function waitingFor(User $actor): Builder
    {
        return PatientMergeRequest::query()->where('organisation_id', $actor->organisation_id)
            ->where('status', PatientMergeRequest::STATUS_PROPOSED)->where('proposed_by_user_id', '<>', $actor->id);
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return self::waitingFor($actor)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $at = self::waitingFor($actor)->min('proposed_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        return [];
    }
}
