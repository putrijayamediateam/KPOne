<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalItem;
use App\Domain\Approvals\ApprovalSource;
use App\Domain\HR\Approvals\HrWaiting;
use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Claims\Services\ClaimService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * APPR-1d: a staff claim waits for an approver. The Approvals tab lists every waiting claim; this counts only the ones the
 * person may decide: never their own ({@see HrWaiting::claims()}), and only where {@see ClaimService::deciderRefusal()}, the
 * service's own rank rule, has no refusal (an HR Manager's claim and a claim over the Director level go to a Director). The
 * row shows the claim number (SC-), never a name, amount or reason.
 *
 * So this count (and the HR space's "Staff claims" count) can be lower than the number of rows on the Approvals tab, which
 * lists every waiting claim with the reason the viewer may not decide it. That difference is intended.
 */
final class StaffClaimSource implements ApprovalSource
{
    /** @var array<int, Collection<int, \stdClass>> the decidable claims per viewer, worked out once per request */
    private array $decidable = [];

    public function __construct(private ClaimService $claims) {}

    public function key(): string
    {
        return 'hr_staff_claim';
    }

    public function label(): string
    {
        return 'Staff claims';
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
        return [ClaimPermissions::APPROVE];
    }

    public function branchScoped(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'hr.claims.index';
    }

    public function routeParameters(): array
    {
        return ['tab' => 'approvals'];
    }

    public function hasReferences(): bool
    {
        return true;
    }

    /** @return Collection<int, \stdClass> oldest first */
    private function decidable(User $actor): Collection
    {
        return $this->decidable[$actor->id] ??= HrWaiting::claims($actor)->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (\stdClass $claim): bool => $this->claims->deciderRefusal($actor, $claim) === null)->values();
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return $this->decidable($actor)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $first = $this->decidable($actor)->first();

        return $first === null ? null : CarbonImmutable::parse((string) $first->created_at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        return array_values($this->decidable($actor)->take($limit)->map(fn (\stdClass $claim): ApprovalItem => new ApprovalItem(
            (string) $claim->number,
            CarbonImmutable::parse((string) $claim->created_at, 'UTC'),
            route($this->route(), $this->routeParameters(), absolute: false),
        ))->all());
    }
}
