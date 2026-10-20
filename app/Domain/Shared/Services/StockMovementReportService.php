<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementProjection;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $query = InventoryMovementProjection::query($actor->organisation_id, $branch->id)
            ->where('movement.occurred_at', '<', $end)->orderBy('s.sku_code')->orderBy('movement.id')
            ->select(['movement.inventory_sku_id', 'movement.quantity', 'movement.movement_type', 'movement.occurred_at', 's.sku_code', 's.stock_unit', 'i.generic_name', 'source.branch_id as source_branch', 'destination.branch_id as destination_branch']);
        $groups = [];
        foreach ($query->cursor() as $movement) {
            $type = (string) $movement->movement_type;
            if (! in_array($type, InventoryDirectoryService::MOVEMENT_TYPES, true)) {
                throw ValidationException::withMessages(['report' => 'A retained movement type requires review before reporting.']);
            }
            $milli = $this->milli((string) $movement->quantity);
            $signed = (((int) $movement->destination_branch === $branch->id ? 1 : 0) - ((int) $movement->source_branch === $branch->id ? 1 : 0)) * $milli;
            $id = (int) $movement->inventory_sku_id;
            $group = $groups[$id] ?? ['name' => (string) $movement->generic_name, 'sku' => (string) $movement->sku_code, 'unit' => (string) $movement->stock_unit, 'values' => array_fill(0, 6, 0)];
            $group['values'][5] += $signed;
            if (CarbonImmutable::parse((string) $movement->occurred_at, 'UTC')->lt($start)) {
                $group['values'][0] += $signed;
            } else {
                $index = match ($type) {
                    StockMovement::TYPE_PURCHASE_RECEIPT => 1,
                    StockMovement::TYPE_DISPENSE => 2,
                    StockMovement::TYPE_TRANSFER, StockMovement::TYPE_TRANSFER_DISPATCH, StockMovement::TYPE_TRANSFER_RECEIPT => 4,
                    default => 3,
                };
                $group['values'][$index] += $index === 2 ? -$signed : $signed;
            }
            $groups[$id] = $group;
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

    private function milli(string $quantity): int
    {
        if (! preg_match('/\A([0-9]{1,12})\.([0-9]{3})\z/', $quantity, $parts)) {
            throw ValidationException::withMessages(['report' => 'A retained stock quantity requires review.']);
        }

        return (int) $parts[1] * 1000 + (int) $parts[2];
    }

    private function quantity(int $milli): string
    {
        return ($milli < 0 ? '-' : '').intdiv(abs($milli), 1000).'.'.str_pad((string) (abs($milli) % 1000), 3, '0', STR_PAD_LEFT);
    }
}
