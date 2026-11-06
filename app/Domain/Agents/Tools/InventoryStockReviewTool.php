<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\InventoryBatchEligibility;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `inventory.stock_review`: low-stock, expiring (90 days) and expired counts per SKU at one branch.
 * Uses the same rules as the Stock review page (InventoryStockReviewService); the list totals are
 * proven equal to that page's totals by test. `rows` (AG-6) lists each low-stock SKU and location with its
 * reorder level, and each expiring or expired batch at its location, by public id, for the inventory analyst.
 */
final class InventoryStockReviewTool implements SystemReadableTool
{
    use ReadsBranches;

    public function __construct(private InventoryBatchEligibility $eligibility) {}

    public function code(): string
    {
        return 'inventory.stock_review';
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
        return $this->runForOrganisation($requester->organisation_id, $branch, $input);
    }

    public function runForOrganisation(int $organisationId, ?Branch $branch, array $input): array
    {
        assert($branch !== null);
        $skus = [];
        $totals = [];
        $detail = ['lowStock' => [], 'expiring' => [], 'expired' => []];
        foreach (['low_stock', 'expiring', 'expired'] as $kind) {
            $rows = $this->rows($organisationId, $branch, $kind)->get();
            $totals[$kind] = $rows->count();
            foreach ($rows as $row) {
                $detail[$kind === 'low_stock' ? 'lowStock' : $kind][] = $kind === 'low_stock'
                    ? ['sku' => $row->sku_public_id, 'location' => $row->location_public_id, 'quantity' => $this->quantity($row->quantity), 'reorderLevel' => $this->quantity($row->reorder_level)]
                    : ['sku' => $row->sku_public_id, 'batch' => $row->batch_public_id, 'location' => $row->location_public_id, 'quantity' => $this->quantity($row->quantity), 'expiryDate' => (string) $row->expiry_date];
                $sku = $skus[$row->sku_id] ?? ['sku' => $row->sku_public_id, 'skuCode' => $row->sku_code, 'item' => $row->item, 'stockUnit' => $row->stock_unit,
                    'lowStockLocations' => 0, 'expiringBatches' => 0, 'expiringQuantity' => 0, 'expiredBatches' => 0, 'expiredQuantity' => 0];
                if ($kind === 'low_stock') {
                    $sku['lowStockLocations']++;
                } elseif ($kind === 'expiring') {
                    $sku['expiringBatches']++;
                    $sku['expiringQuantity'] += $this->milli($row->quantity);
                } else {
                    $sku['expiredBatches']++;
                    $sku['expiredQuantity'] += $this->milli($row->quantity);
                }
                $skus[$row->sku_id] = $sku;
            }
        }
        $rows = array_values(array_map(fn (array $sku): array => [...$sku, 'expiringQuantity' => $this->fromMilli($sku['expiringQuantity']), 'expiredQuantity' => $this->fromMilli($sku['expiredQuantity'])], $skus));
        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['skuCode'], (string) $b['skuCode']));

        return [
            'branch' => $branch->code,
            'date' => $this->eligibility->localDate($branch),
            'expiryWindowDays' => InventoryStockReviewService::EXPIRY_WINDOW_DAYS,
            'totals' => ['lowStock' => $totals['low_stock'], 'expiring' => $totals['expiring'], 'expired' => $totals['expired']],
            'skus' => $rows,
            'rows' => array_map(function (array $list): array {
                usort($list, fn (array $a, array $b): int => [$a['sku'], $a['location'], $a['batch'] ?? ''] <=> [$b['sku'], $b['location'], $b['batch'] ?? '']);

                return $list;
            }, $detail),
        ];
    }

    /** Mirrors InventoryStockReviewService::items() for one list. */
    private function rows(int $organisationId, Branch $branch, string $kind): Builder
    {
        if ($kind === 'low_stock') {
            $totals = DB::table('inventory_stock_balances as balance')
                ->joinSub($this->eligibility->query($organisationId, $branch), 'e', fn ($j) => $j->on('e.id', '=', 'balance.inventory_batch_id')->on('e.organisation_id', '=', 'balance.organisation_id'))
                ->where('balance.organisation_id', $organisationId)
                ->groupBy('balance.inventory_location_id', 'balance.inventory_sku_id')
                ->selectRaw('balance.inventory_location_id, balance.inventory_sku_id, SUM(CASE WHEN e.eligibility_reason IS NULL THEN balance.quantity ELSE 0 END) as eligible');

            return DB::table('inventory_reorder_levels as r')
                ->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')
                ->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')
                ->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
                ->leftJoinSub($totals, 't', fn ($j) => $j->on('t.inventory_location_id', '=', 'l.id')->on('t.inventory_sku_id', '=', 's.id'))
                ->where('r.organisation_id', $organisationId)->where('l.organisation_id', $organisationId)->where('s.organisation_id', $organisationId)->where('i.organisation_id', $organisationId)
                ->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)->where('s.is_active', true)->where('i.is_active', true)
                ->where('r.reorder_level', '>', 0)->whereRaw('COALESCE(t.eligible, 0) <= r.reorder_level')
                ->selectRaw('s.id as sku_id, s.public_id as sku_public_id, s.sku_code, i.generic_name as item, s.stock_unit, COALESCE(t.eligible, 0) as quantity, l.public_id as location_public_id, r.reorder_level');
        }
        $query = DB::table('inventory_stock_balances as r')
            ->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')
            ->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')
            ->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->joinSub($this->eligibility->query($organisationId, $branch), 'e', fn ($j) => $j->on('e.id', '=', 'r.inventory_batch_id')->on('e.organisation_id', '=', 'r.organisation_id'))
            ->where('r.organisation_id', $organisationId)->where('l.organisation_id', $organisationId)->where('s.organisation_id', $organisationId)->where('i.organisation_id', $organisationId)
            ->where('l.branch_id', $branch->id)->where('r.quantity', '>', 0);
        if ($kind === 'expiring') {
            // STK-11: returned medicine is not usable stock, so it never counts as expiring. Expired returned
            // stock stays listed below, because it still has to be written off.
            $query->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS);
            $query->where('l.is_active', true)->whereNull('e.eligibility_reason')
                ->whereDate('e.expiry_date', '<=', CarbonImmutable::parse($this->eligibility->localDate($branch))->addDays(InventoryStockReviewService::EXPIRY_WINDOW_DAYS)->toDateString());
        } else {
            $query->whereDate('e.expiry_date', '<=', $this->eligibility->localDate($branch));
        }

        return $query->selectRaw('s.id as sku_id, s.public_id as sku_public_id, s.sku_code, i.generic_name as item, s.stock_unit, r.quantity, l.public_id as location_public_id, e.public_id as batch_public_id, e.expiry_date');
    }
}
