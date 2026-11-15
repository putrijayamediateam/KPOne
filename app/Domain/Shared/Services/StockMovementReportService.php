<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementProjection;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementReportService extends BranchReportService
{
    protected function supportsFilters(): bool
    {
        return false;
    }

    public function key(): string
    {
        return 'stock_movement';
    }

    public function title(): string
    {
        return 'Stock movement summary';
    }

    public function basis(): string
    {
        return 'Quantities only, by retained SKU and stock unit; no valuation or cost. Branch-local movement dates. Opening is all signed retained movements before the period; closing is all movements through the period end, not a live balance snapshot. Received is purchase receipts; dispensed is quantity out; adjusted includes stocktakes, adjustments and opening entries recorded during the period. Transfers are signed net; transfers between locations in the same branch cancel. Opening + received - dispensed + adjusted + transferred = closing. Three decimal places, exact integer milliunits. Units/SKUs are never summed together. Legacy balances without movements are not invented; only SKUs with retained branch movements appear. Archived locations and items remain included.';
    }

    protected function project(User $actor, Branch $branch, array $filters): array
    {
        $start = CarbonImmutable::parse($filters['from'], $branch->timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($filters['to'], $branch->timezone)->addDay()->startOfDay()->utc();
        $movements = InventoryMovementProjection::query($actor->organisation_id, $branch->id)
            ->where('movement.occurred_at', '<', $end)
            ->select(['movement.inventory_sku_id', 'movement.occurred_at', 'movement.movement_type', 's.sku_code', 's.stock_unit', 'i.generic_name'])
            ->selectRaw('(CASE WHEN destination.branch_id = ? THEN 1 ELSE 0 END - CASE WHEN source.branch_id = ? THEN 1 ELSE 0 END) * movement.quantity AS signed_quantity', [$branch->id, $branch->id]);
        $query = DB::query()->fromSub($movements, 'movement')
            ->select(['movement.inventory_sku_id', 'movement.sku_code', 'movement.stock_unit', 'movement.generic_name'])
            ->selectRaw('SUM(CASE WHEN movement.occurred_at < ? THEN signed_quantity ELSE 0 END) * 1000 AS opening', [$start])
            ->selectRaw('SUM(CASE WHEN movement.occurred_at >= ? AND movement.movement_type = ? THEN signed_quantity ELSE 0 END) * 1000 AS received', [$start, StockMovement::TYPE_PURCHASE_RECEIPT])
            ->selectRaw('SUM(CASE WHEN movement.occurred_at >= ? AND movement.movement_type = ? THEN -signed_quantity ELSE 0 END) * 1000 AS dispensed', [$start, StockMovement::TYPE_DISPENSE])
            ->selectRaw('SUM(CASE WHEN movement.occurred_at >= ? AND movement.movement_type NOT IN (?, ?, ?, ?, ?) THEN signed_quantity ELSE 0 END) * 1000 AS adjusted', [$start, StockMovement::TYPE_PURCHASE_RECEIPT, StockMovement::TYPE_DISPENSE, StockMovement::TYPE_TRANSFER, StockMovement::TYPE_TRANSFER_DISPATCH, StockMovement::TYPE_TRANSFER_RECEIPT])
            ->selectRaw('SUM(CASE WHEN movement.occurred_at >= ? AND movement.movement_type IN (?, ?, ?) THEN signed_quantity ELSE 0 END) * 1000 AS transferred', [$start, StockMovement::TYPE_TRANSFER, StockMovement::TYPE_TRANSFER_DISPATCH, StockMovement::TYPE_TRANSFER_RECEIPT])
            ->selectRaw('SUM(signed_quantity) * 1000 AS closing')
            ->selectRaw('SUM(CASE WHEN movement.movement_type NOT IN ('.implode(',', array_fill(0, count(InventoryDirectoryService::MOVEMENT_TYPES), '?')).') THEN 1 ELSE 0 END) AS invalid_types', InventoryDirectoryService::MOVEMENT_TYPES)
            ->groupBy('movement.inventory_sku_id', 'movement.sku_code', 'movement.stock_unit', 'movement.generic_name')
            ->orderByRaw('movement.sku_code COLLATE "C"')->orderBy('movement.inventory_sku_id');
        $groups = [];
        foreach ($query->cursor() as $movement) {
            if ((int) $movement->invalid_types !== 0) {
                throw ValidationException::withMessages(['report' => 'A retained movement type requires review before reporting.']);
            }
            // Quantity is numeric(15,3): sum in PostgreSQL, never through binary floats.
            $groups[] = ['name' => (string) $movement->generic_name, 'sku' => (string) $movement->sku_code, 'unit' => (string) $movement->stock_unit,
                'values' => [$this->aggregateMilli((string) $movement->opening), $this->aggregateMilli((string) $movement->received), $this->aggregateMilli((string) $movement->dispensed), $this->aggregateMilli((string) $movement->adjusted), $this->aggregateMilli((string) $movement->transferred), $this->aggregateMilli((string) $movement->closing)]];
        }

        $rows = [];
        foreach ($groups as $group) {
            $v = $group['values'];
            if ($v[5] !== $v[0] + $v[1] - $v[2] + $v[3] + $v[4]) {
                throw ValidationException::withMessages(['report' => 'Stock movement reconciliation failed. Review is required.']);
            }
            $rows[] = [$group['name'], $group['sku'], $group['unit'], ...array_map($this->quantity(...), $v), 'Yes'];
        }

        return ['columns' => ['Item', 'SKU', 'Stock unit', 'Opening quantity', 'Received quantity', 'Dispensed quantity', 'Adjusted quantity (net)', 'Transferred quantity (net)', 'Closing quantity', 'Reconciles'], 'rows' => $rows, 'totals' => []];
    }

    private function aggregateMilli(string $value): int
    {
        if (! preg_match('/\A(-?[0-9]+)(?:\.0+)?\z/', $value, $parts)) {
            throw ValidationException::withMessages(['report' => 'A retained stock quantity requires review.']);
        }
        $milli = filter_var($parts[1], FILTER_VALIDATE_INT);
        // abs(PHP_INT_MIN) is a float, so it cannot be formatted exactly by quantity().
        if ($milli === false || $milli === PHP_INT_MIN) {
            throw ValidationException::withMessages(['report' => 'A retained stock quantity requires review.']);
        }

        return $milli;
    }

    private function quantity(int $milli): string
    {
        return ($milli < 0 ? '-' : '').intdiv(abs($milli), 1000).'.'.str_pad((string) (abs($milli) % 1000), 3, '0', STR_PAD_LEFT);
    }
}
