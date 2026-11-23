<?php

namespace App\Domain\Approvals;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * APPR-1: one kind of thing that waits for a person to decide (an approval) or to do (a task). The owning team writes the
 * source; the inbox only counts and lists. A source never decides anything and never carries a name, amount or reason.
 *
 * The count and the items must come from the same predicate as the owning page's own waiting list, with the people the
 * rule bars (the maker) already excluded; a contract test per source compares the two.
 */
interface ApprovalSource
{
    public const APPROVAL = 'approval';

    public const TASK = 'task';

    /** Stable key, for example "cash_close". */
    public function key(): string;

    /** Fixed English label, for example "Daily cash close". */
    public function label(): string;

    /** Area heading; it matches a Clinic management group name (for example "Money", "Stock"). */
    public function area(): string;

    /** self::APPROVAL or self::TASK. */
    public function kind(): string;

    /**
     * The decide permission(s); holding any one makes the source visible. This is the same permission the owning page's decide
     * action checks.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /** True when the source belongs to the active branch; false when it is organisation-wide. */
    public function branchScoped(): bool;

    /** Named route of the owning page (a list page, never a record). */
    public function route(): string;

    /** @return array<string, mixed> parameters for the route */
    public function routeParameters(): array;

    /** True when its items have a document number or a business date the row may show. */
    public function hasReferences(): bool;

    /** How many items wait for this person, already without anything the person is barred from deciding. */
    public function count(User $actor, ?Branch $branch): int;

    /** When the oldest waiting item began waiting, or null when none waits. */
    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable;

    /**
     * Up to $limit items, oldest first, only when {@see hasReferences()}.
     *
     * @return list<ApprovalItem>
     */
    public function items(User $actor, ?Branch $branch, int $limit): array;
}
