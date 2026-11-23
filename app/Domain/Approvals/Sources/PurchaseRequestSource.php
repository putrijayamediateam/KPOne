<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalItem;
use App\Domain\Approvals\ApprovalSource;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A purchase request waits for a reviewer at the branch to convert or reject it (never the requester). Shows the request number. */
final class PurchaseRequestSource implements ApprovalSource
{
    public function key(): string
    {
        return 'purchase_request';
    }

    public function label(): string
    {
        return 'Purchase requests to review';
    }

    public function area(): string
    {
        return 'Stock';
    }

    public function kind(): string
    {
        return self::APPROVAL;
    }

    public function permissions(): array
    {
        return [ProcurementService::CREATE_PERMISSION];
    }

    public function branchScoped(): bool
    {
        return true;
    }

    public function route(): string
    {
        return 'inventory.purchase-requests';
    }

    public function routeParameters(): array
    {
        return ['tab' => 'waiting'];
    }

    public function hasReferences(): bool
    {
        return true;
    }

    /** The page's own "waiting" tab at the branch, without requests this person sent (`refuseRequester`). */
    public static function waitingFor(User $actor, Branch $branch): Builder
    {
        return DB::table('inventory_purchase_requests as r')->where('r.organisation_id', $actor->organisation_id)
            ->where('r.branch_id', $branch->id)->where('r.status', 'waiting')->where('r.requested_by_user_id', '<>', $actor->id);
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return $branch === null ? 0 : self::waitingFor($actor, $branch)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $at = $branch === null ? null : self::waitingFor($actor, $branch)->min('r.requested_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        if ($branch === null) {
            return [];
        }
        $href = route($this->route(), $this->routeParameters(), absolute: false);

        return array_values(self::waitingFor($actor, $branch)->orderBy('r.requested_at')->orderBy('r.id')->limit($limit)->get(['r.request_number', 'r.requested_at'])
            ->map(fn (object $row): ApprovalItem => new ApprovalItem((string) $row->request_number, CarbonImmutable::parse((string) $row->requested_at, 'UTC'), $href))->all());
    }
}
