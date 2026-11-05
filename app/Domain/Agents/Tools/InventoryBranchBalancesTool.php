<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Inventory\Services\InventoryBatchEligibility;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * `inventory.branch_balances`: per SKU, the quantity on hand, the eligible (dispensable) quantity and
 * the reorder level at every active branch of the organisation, at active locations. Needs
 * `inventory.view.branch` at the requesting branch (contract section 5).
 */
final class InventoryBranchBalancesTool implements AgentTool
{
    use ReadsBranches;

    public function __construct(private InventoryBatchEligibility $eligibility) {}

    public function code(): string
    {
        return 'inventory.branch_balances';
    }

    public function requiredPermission(): string
    {
        return 'inventory.view.branch';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        assert($branch !== null);
        $organisationId = $requester->organisation_id;
        $branches = $this->branchesFor($requester, null);
        $skus = [];
        foreach ($branches as $each) {
            $stock = DB::table('inventory_stock_balances as b')
                ->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')
                ->joinSub($this->eligibility->query($organisationId, $each), 'e', fn ($j) => $j->on('e.id', '=', 'b.inventory_batch_id')->on('e.organisation_id', '=', 'b.organisation_id'))
                ->where('b.organisation_id', $organisationId)->where('l.organisation_id', $organisationId)->where('l.branch_id', $each->id)->where('l.is_active', true)
                ->groupBy('b.inventory_sku_id')
                ->selectRaw('b.inventory_sku_id as sku_id, SUM(b.quantity) as on_hand, SUM(CASE WHEN e.eligibility_reason IS NULL THEN b.quantity ELSE 0 END) as eligible')
                ->get()->keyBy('sku_id');
            $levels = DB::table('inventory_reorder_levels as r')
                ->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')
                ->where('r.organisation_id', $organisationId)->where('l.organisation_id', $organisationId)->where('l.branch_id', $each->id)->where('l.is_active', true)
                ->groupBy('r.inventory_sku_id')->selectRaw('r.inventory_sku_id as sku_id, SUM(r.reorder_level) as reorder_level')
                ->pluck('reorder_level', 'sku_id');
            foreach ($stock->keys()->merge($levels->keys())->unique() as $skuId) {
                $skus[$skuId][$each->code] = [
                    'branch' => $each->code,
                    'onHand' => $this->quantity($stock->get($skuId)?->on_hand),
                    'eligible' => $this->quantity($stock->get($skuId)?->eligible),
                    'reorderLevel' => $this->quantity($levels->get($skuId)),
                ];
            }
        }
        $references = DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('s.organisation_id', $organisationId)->where('s.is_active', true)->where('i.is_active', true)->whereIn('s.id', array_keys($skus))
            ->orderBy('s.sku_code')->get(['s.id', 's.public_id', 's.sku_code', 'i.generic_name', 's.stock_unit']);

        return [
            'requestedAt' => $branch->code,
            'branches' => $branches->pluck('code')->values()->all(),
            'skus' => $references->map(fn ($sku): array => [
                'sku' => $sku->public_id,
                'skuCode' => $sku->sku_code,
                'item' => $sku->generic_name,
                'stockUnit' => $sku->stock_unit,
                'perBranch' => $branches->map(fn (Branch $each): array => $skus[$sku->id][$each->code]
                    ?? ['branch' => $each->code, 'onHand' => '0.000', 'eligible' => '0.000', 'reorderLevel' => '0.000'])->values()->all(),
            ])->values()->all(),
        ];
    }
}
