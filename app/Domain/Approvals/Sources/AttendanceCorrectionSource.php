<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\HR\Approvals\HrWaiting;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * APPR-1d: An attendance correction waits for a reviewer who is neither the person nor the proposer. Counted from {@see HrWaiting::corrections()}, the rule the
 * page's own count uses. No document number, so no expand.
 */
final class AttendanceCorrectionSource implements ApprovalSource
{
    public function key(): string
    {
        return 'hr_attendance_correction';
    }

    public function label(): string
    {
        return 'Attendance corrections';
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
        return [ClockPermissions::REVIEW];
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
        return ['tab' => 'review'];
    }

    public function hasReferences(): bool
    {
        return false;
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return HrWaiting::corrections($actor)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $at = HrWaiting::corrections($actor)->min('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        return [];
    }
}
