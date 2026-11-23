<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalItem;
use App\Domain\Approvals\ApprovalSource;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Services\CashClosingService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A daily cash count waits for a second person to approve or return it (never the counter). Only the newest count of a
 * business date without a decision waits. The row shows the business date.
 */
final class CashCloseSource implements ApprovalSource
{
    public function key(): string
    {
        return 'cash_close';
    }

    public function label(): string
    {
        return 'Daily cash close';
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
        return [CashClosingService::APPROVE_PERMISSION];
    }

    public function branchScoped(): bool
    {
        return true;
    }

    public function route(): string
    {
        return 'cash-closings.index';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function hasReferences(): bool
    {
        return true;
    }

    /** The page's own "waiting" status (the newest count of the day has no decision) without counts this person made. */
    /** @return Builder<CashClosing> */
    public static function waitingFor(User $actor, Branch $branch): Builder
    {
        return CashClosing::query()->where('cash_closings.organisation_id', $actor->organisation_id)->where('cash_closings.branch_id', $branch->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cash_closing_decisions as d')->whereColumn('d.cash_closing_id', 'cash_closings.id'))
            ->whereRaw('cash_closings.revision = (select max(x.revision) from cash_closings as x where x.branch_id = cash_closings.branch_id and x.business_date = cash_closings.business_date)')
            ->where('cash_closings.counted_by_user_id', '<>', $actor->id);
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return $branch === null ? 0 : self::waitingFor($actor, $branch)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $at = $branch === null ? null : self::waitingFor($actor, $branch)->min('cash_closings.counted_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        if ($branch === null) {
            return [];
        }

        $items = [];
        foreach (self::waitingFor($actor, $branch)->orderBy('cash_closings.counted_at')->orderBy('cash_closings.id')->limit($limit)->get() as $closing) {
            $items[] = new ApprovalItem(
                $closing->business_date->format('j M Y'),
                CarbonImmutable::parse($closing->counted_at, 'UTC'),
                route($this->route(), ['date' => $closing->business_date->toDateString()], absolute: false),
            );
        }

        return $items;
    }
}
