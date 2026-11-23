<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\HR\Approvals\HrWaiting;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * APPR-1d: An overtime request waits for an approver who is neither the person nor the requester. Counted from {@see HrWaiting::overtime()}, the rule the
 * page's own count uses. No document number, so no expand.
 */
final class OvertimeRequestSource implements ApprovalSource
{
    public function key(): string
    {
        return 'hr_overtime';
    }

    public function label(): string
    {
        return 'Overtime requests';
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
        return [ClockPermissions::OVERTIME_APPROVE];
    }

    public function branchScoped(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'hr.attendance.index';
    }

    public function routeParameters(): array
    {
        return ['tab' => 'overtime'];
    }

    public function hasReferences(): bool
    {
        return false;
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return HrWaiting::overtime($actor)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $at = HrWaiting::overtime($actor)->min('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        return [];
    }
}
