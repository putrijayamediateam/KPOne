<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A proposed Panel price change waits for Finance (never the proposer). The agreement reference is free text, so no expand. */
final class PanelPriceChangeSource implements ApprovalSource
{
    public function key(): string
    {
        return 'panel_price_change';
    }

    public function label(): string
    {
        return 'Panel price changes to approve';
    }

    public function area(): string
    {
        return 'Money';
    }

    public function kind(): string
    {
        return self::APPROVAL;
    }

    public function permissions(): array
    {
        return ['panel.prices.approve.organisation'];
    }

    public function branchScoped(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'panel-price-changes.index';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function hasReferences(): bool
    {
        return false;
    }

    /** The page's own waiting rows (status proposed) without this person's own proposals (`assertDecidable`). */
    public static function waitingFor(User $actor): Builder
    {
        return DB::table('price_change_proposals')->where('organisation_id', $actor->organisation_id)
            ->where('status', 'proposed')->where('proposed_by_user_id', '<>', $actor->id);
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
