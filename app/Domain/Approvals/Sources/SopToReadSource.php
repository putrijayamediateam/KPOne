<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\HR\Sops\Services\SopsToRead;
use App\Domain\HR\Sops\SopPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * APPR-1d task: An active SOP published to one of the person's roles waits until they acknowledge its current version. Counts from {@see SopsToRead}, the same rule the
 * page uses. A task never grants the number. No title is shown, so no expand.
 */
final class SopToReadSource implements ApprovalSource
{
    public function __construct(private SopsToRead $toRead) {}

    public function key(): string
    {
        return 'sops_to_read';
    }

    public function label(): string
    {
        return 'SOPs to read';
    }

    public function area(): string
    {
        return 'HR';
    }

    public function kind(): string
    {
        return self::TASK;
    }

    public function permissions(): array
    {
        return [SopPermissions::ACKNOWLEDGE_OWN];
    }

    public function branchScoped(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'hr.sops.index';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function hasReferences(): bool
    {
        return false;
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return $this->toRead->count($actor);
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        return $this->toRead->oldest($actor);
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        return [];
    }
}
