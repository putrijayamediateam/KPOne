<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `inventory.dispensing_activity`: per SKU at one branch, the quantity on hand, the last dispensing
 * date (branch-local) and the quantity dispensed in the window (default 60 days, ending today).
 * Dispensing is a `dispense` stock movement out of one of the branch's locations.
 */
final class InventoryDispensingActivityTool implements AgentTool
{
    use ReadsBranches;

    public const DEFAULT_WINDOW_DAYS = 60;

    public function code(): string
    {
        return 'inventory.dispensing_activity';
    }

    public function requiredPermission(): string
    {
        return 'inventory.view.branch';
    }

    public function inputSchema(): array
    {
        return ['window_days' => ['sometimes', 'integer', 'min:1', 'max:365']];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        assert($branch !== null);
        $organisationId = $requester->organisation_id;
        $days = (int) ($input['window_days'] ?? self::DEFAULT_WINDOW_DAYS);
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $windowStart = $today->subDays($days - 1);

        $onHand = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')
            ->where('b.organisation_id', $organisationId)->where('l.organisation_id', $organisationId)->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)
            ->groupBy('b.inventory_sku_id')->havingRaw('SUM(b.quantity) > 0')->selectRaw('b.inventory_sku_id as sku_id, SUM(b.quantity) as quantity')->pluck('quantity', 'sku_id');
        $dispensed = DB::table('stock_movements as m')->join('inventory_locations as l', 'l.id', '=', 'm.source_location_id')
            ->where('m.organisation_id', $organisationId)->where('l.organisation_id', $organisationId)->where('l.branch_id', $branch->id)
            ->where('m.movement_type', StockMovement::TYPE_DISPENSE)
            ->groupBy('m.inventory_sku_id')
            ->selectRaw('m.inventory_sku_id as sku_id, MAX(m.occurred_at) as last_at, SUM(CASE WHEN m.occurred_at >= ? THEN m.quantity ELSE 0 END) as in_window', [$windowStart->utc()->toDateTimeString()])
            ->get()->keyBy('sku_id');
        $ids = $onHand->keys()->merge($dispensed->keys())->unique()->all();
        $skus = DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('s.organisation_id', $organisationId)->whereIn('s.id', $ids)->orderBy('s.sku_code')
            ->get(['s.id', 's.public_id', 's.sku_code', 'i.generic_name', 's.stock_unit']);

        return [
            'branch' => $branch->code,
            'date' => $today->toDateString(),
            'windowDays' => $days,
            'windowStart' => $windowStart->toDateString(),
            'skus' => $skus->map(function ($sku) use ($onHand, $dispensed, $branch, $today): array {
                $activity = $dispensed->get($sku->id);
                $last = $activity?->last_at ? CarbonImmutable::parse((string) $activity->last_at, 'UTC')->setTimezone($branch->timezone)->startOfDay() : null;

                return [
                    'sku' => $sku->public_id,
                    'skuCode' => $sku->sku_code,
                    'item' => $sku->generic_name,
                    'stockUnit' => $sku->stock_unit,
                    'onHand' => $this->quantity($onHand->get($sku->id)),
                    'lastDispensedDate' => $last?->toDateString(),
                    'daysSinceDispensed' => $last ? (int) $last->diffInDays($today) : null,
                    'dispensedInWindow' => $this->quantity($activity?->in_window),
                ];
            })->values()->all(),
        ];
    }
}
