<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\HR\Memos\MemoPermissions;
use App\Domain\HR\Memos\Services\MemosToRead;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * APPR-1d task: An open memo addressed to the person waits until they acknowledge it. Counts from {@see MemosToRead}, the same rule the
 * page uses. A task never grants the number. No title is shown, so no expand.
 */
final class MemoToReadSource implements ApprovalSource
{
    public function __construct(private MemosToRead $toRead) {}

    public function key(): string
    {
        return 'memos_to_read';
    }

    public function label(): string
    {
        return 'Memos to read';
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
        return [MemoPermissions::READ_OWN];
    }

    public function branchScoped(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'hr.memos.index';
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
