<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\HR\Approvals\HrWaiting;
use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * APPR-1d: A leave request waits for an approver who is not the applicant. Counted from {@see HrWaiting::leave()}, the rule the
 * page's own count uses. No document number, so no expand.
 */
final class LeaveRequestSource implements ApprovalSource
{
    public function key(): string
    {
        return 'hr_leave';
    }

    public function label(): string
    {
        return 'Leave requests';
    }

    public function area(): string
    {
        return 'HR';
    }

    public function kind(): string
    {
        return self::APPROVAL;
    }

    public function permissions(): array
    {
        return [LeavePermissions::APPROVE];
    }

    public function branchScoped(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'hr.leave.index';
    }

    public function routeParameters(): array
    {
        return ['tab' => 'approvals'];
    }

    public function hasReferences(): bool
    {
        return false;
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return HrWaiting::leave($actor)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $at = HrWaiting::leave($actor)->min('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        return [];
    }
}
