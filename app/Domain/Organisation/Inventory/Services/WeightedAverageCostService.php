<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Organisation\Inventory\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * STK-27: weighted average cost per SKU per branch, worked out by replaying the stock ledger in time order. Read only: it
 * writes nothing, and no stock-writing code changes. These rules were set at the owner's instruction (2026-11-15) and
 * the clinic's accountant approved them verbally on 25 Nov 2026 (owner-confirmed, REL-STK). It is still a management
 * figure, not a statutory financial statement:
 *
 * - An accepted purchase receipt moves the average: (value before + receipt cost) / (quantity before + received). The
 *   receipt cost is the lot's actual total (STK-10B `cost_total_sen`), else unit cost x quantity. An order estimate is
 *   used but marked, so a position "includes estimates" until its quantity falls to zero.
 * - Opening stock enters at its entered unit cost.
 * - Dispense, treatment usage and waste, write-offs, supplier returns, counts and adjustments out leave at the average.
 * - A two-stage transfer leaves the sending branch at its average and arrives at that same cost (carried through an
 *   in-transit pool per stock request and SKU); a direct transfer between branches does the same at once.
 * - A receipt or opening with no cost, and stock that comes back without a purchase (patient returns, count gains,
 *   adjustments in), enters at the branch's current average. With no average yet, it is "no cost": counted apart and
 *   never valued at zero.
 * - Held stock is on hand and is valued. Rejected lots never enter stock. Supplier credit notes do not move the average.
 * - No landed-cost add-ons.
 *
 * Money is kept in thousandths of a sen and quantity in thousandths of a stock unit, with whole-number maths only;
 * rounding (half up) happens at each proportional split and when a figure is shown in sen. A branch key of 0 is the
 * organisation store (a location with no branch). Callers check permission: this is a calculator for a service that has.
 */
final class WeightedAverageCostService
{
    public const DISCLAIMER = "Weighted average cost, as approved verbally by the clinic's accountant on 25 Nov 2026; not a statutory financial statement.";

    /** Movements that bring stock in with a cost of their own (or none). */
    private const COSTED_IN = [StockMovement::TYPE_PURCHASE_RECEIPT, StockMovement::TYPE_OPENING];

    /**
     * Replay the ledger of one organisation up to an instant (UTC, inclusive), optionally for some SKUs only.
     *
     * @param  list<int>|null  $skuIds
     * @return array{positions: list<array<string, mixed>>, movements: array<int, array<string, mixed>>, inTransit: list<array<string, mixed>>}
     */
    public function replay(int $organisationId, ?CarbonInterface $until = null, ?array $skuIds = null): array
    {
        $branchOf = DB::table('inventory_locations')->where('organisation_id', $organisationId)->pluck('branch_id', 'id')
            ->map(fn ($branch): int => (int) ($branch ?? 0))->all();
        $rows = DB::table('stock_movements')->where('organisation_id', $organisationId)
            ->when($until !== null, fn ($q) => $q->where('occurred_at', '<=', $until->copy()->utc()->toDateTimeString()))
            ->when($skuIds !== null, fn ($q) => $q->whereIn('inventory_sku_id', $skuIds))
            ->orderBy('inventory_sku_id')->orderBy('occurred_at')->orderBy('id')
            ->select(['id', 'movement_type', 'inventory_sku_id', 'source_location_id', 'destination_location_id', 'quantity', 'unit_cost_sen', 'cost_total_sen', 'cost_source', 'reference_public_id', 'occurred_at'])
            ->cursor();

        return $this->replayRows($rows, $branchOf);
    }

    /**
     * The pure calculation, over movement rows already in time order per SKU.
     *
     * @param  iterable<\stdClass>  $rows  each with id, movement_type, inventory_sku_id, source_location_id, destination_location_id,
     *                                     quantity (a decimal string), unit_cost_sen, cost_total_sen, cost_source, reference_public_id
     * @param  array<int, int>  $branchOf  location id => branch id (0 for the organisation store)
     * @return array{positions: list<array<string, mixed>>, movements: array<int, array<string, mixed>>, inTransit: list<array<string, mixed>>}
     */
    public function replayRows(iterable $rows, array $branchOf): array
    {
        /** @var array<string, array{sku:int, branch:int, qty:int, value:int, uncosted:int, estimate:bool}> $positions */
        $positions = [];
        /** @var array<string, array{sku:int, reference:string, qty:int, value:int, uncosted:int, estimate:bool}> $transit */
        $transit = [];
        $movements = [];

        foreach ($rows as $row) {
            $sku = (int) $row->inventory_sku_id;
            $quantity = StockUnitQuantity::scaled((string) $row->quantity, true);
            $out = $row->source_location_id === null ? null : ($branchOf[(int) $row->source_location_id] ?? 0);
            $in = $row->destination_location_id === null ? null : ($branchOf[(int) $row->destination_location_id] ?? 0);
            $result = ['sku_id' => $sku, 'type' => (string) $row->movement_type, 'out_branch' => $out, 'in_branch' => $in, 'quantity' => $quantity, 'value_milli_sen' => 0, 'uncosted_quantity' => 0, 'basis' => 'average', 'estimate' => false];

            // Out of a branch: always at that branch's average.
            $taken = null;
            if ($out !== null && $out !== $in) {
                $taken = $this->take($positions, $sku, $out, $quantity);
                $result['value_milli_sen'] = $taken['value'];
                $result['uncosted_quantity'] = $taken['uncosted'];
                $result['estimate'] = $taken['estimate'];
            }
            if ($row->movement_type === StockMovement::TYPE_TRANSFER_DISPATCH && $taken !== null) {
                // Into the in-transit pool of this stock request, at the cost it left at.
                $key = $sku.'|'.$row->reference_public_id;
                $pool = $transit[$key] ?? ['sku' => $sku, 'reference' => (string) $row->reference_public_id, 'qty' => 0, 'value' => 0, 'uncosted' => 0, 'estimate' => false];
                $pool['qty'] += $quantity - $taken['uncosted'];
                $pool['value'] += $taken['value'];
                $pool['uncosted'] += $taken['uncosted'];
                $pool['estimate'] = $pool['estimate'] || $taken['estimate'];
                $transit[$key] = $pool;
                $result['basis'] = 'average';
            }

            // Into a branch.
            if ($in !== null && $in !== $out) {
                if ($taken !== null) {
                    // A direct transfer between branches: arrives at the cost it left at.
                    $this->give($positions, $sku, $in, $quantity - $taken['uncosted'], $taken['value'], $taken['uncosted'], $taken['estimate']);
                    $result['basis'] = 'transfer';
                } elseif ($row->movement_type === StockMovement::TYPE_TRANSFER_RECEIPT) {
                    $result = [...$result, ...$this->arrive($positions, $transit, $sku, $in, $quantity, (string) $row->reference_public_id)];
                } elseif (in_array($row->movement_type, self::COSTED_IN, true) && ($cost = $this->ownCost($row, $quantity)) !== null) {
                    $this->give($positions, $sku, $in, $quantity, $cost['value'], 0, $cost['estimate']);
                    $result['value_milli_sen'] = $cost['value'];
                    $result['estimate'] = $cost['estimate'];
                    $result['basis'] = $cost['estimate'] ? 'estimate' : ($row->movement_type === StockMovement::TYPE_OPENING ? 'opening' : 'receipt');
                } else {
                    // No cost of its own: at the current average, or "no cost" when there is none yet.
                    $at = $this->atAverage($positions, $sku, $in, $quantity);
                    $result['value_milli_sen'] = $at['value'];
                    $result['uncosted_quantity'] = $at['uncosted'];
                    $result['basis'] = $at['uncosted'] > 0 ? 'no_cost' : 'average';
                }
            }

            $movements[(int) $row->id] = $result;
        }

        return [
            'positions' => array_values(array_map(fn (array $p): array => [
                'sku_id' => $p['sku'], 'branch_key' => $p['branch'], 'quantity' => $p['qty'] + $p['uncosted'], 'costed_quantity' => $p['qty'],
                'value_milli_sen' => $p['value'], 'uncosted_quantity' => $p['uncosted'], 'includes_estimate' => $p['estimate'] && $p['qty'] > 0,
            ], $positions)),
            'movements' => $movements,
            'inTransit' => array_values(array_filter(array_map(fn (array $t): array => [
                'sku_id' => $t['sku'], 'reference' => $t['reference'], 'quantity' => $t['qty'] + $t['uncosted'], 'value_milli_sen' => $t['value'],
                'uncosted_quantity' => $t['uncosted'], 'includes_estimate' => $t['estimate'],
            ], $transit), fn (array $t): bool => $t['quantity'] > 0)),
        ];
    }

    /** Thousandths of a sen to whole sen, half up (negative values mirror). */
    public static function sen(int $milliSen): int
    {
        return $milliSen >= 0 ? intdiv($milliSen + 500, 1000) : -intdiv(-$milliSen + 500, 1000);
    }

    /** The average cost per stock unit in thousandths of a sen, or null with no costed stock. */
    /** @param array<string, mixed> $position */
    public static function average(array $position): ?int
    {
        $qty = (int) $position['costed_quantity'];

        return $qty > 0 ? self::share((int) $position['value_milli_sen'], 1000, $qty) : null;
    }

    /** value x part / whole, exactly, rounded half up, without overflowing 64 bits for clinic-sized numbers. */
    public static function share(int $value, int $part, int $whole): int
    {
        if ($part === $whole) {
            return $value;
        }
        $whole = max(1, $whole);

        return intdiv($value, $whole) * $part + intdiv(2 * ($value % $whole) * $part + $whole, 2 * $whole);
    }

    /** @return array{value:int, estimate:bool}|null the movement's own cost, or null when it has none */
    private function ownCost(\stdClass $row, int $quantity): ?array
    {
        if ($row->cost_total_sen !== null) {
            return ['value' => (int) $row->cost_total_sen * 1000, 'estimate' => false];
        }
        if ($row->unit_cost_sen !== null) {
            // Whole sen per stock unit x thousandths of a unit = thousandths of a sen.
            return ['value' => (int) $row->unit_cost_sen * $quantity, 'estimate' => $row->cost_source === 'estimate'];
        }

        return null;
    }

    /**
     * @param  array<string, array{sku:int, branch:int, qty:int, value:int, uncosted:int, estimate:bool}>  $positions
     * @return array{value:int, uncosted:int, estimate:bool}
     */
    private function take(array &$positions, int $sku, int $branch, int $quantity): array
    {
        $p = $positions[$sku.'|'.$branch] ?? ['sku' => $sku, 'branch' => $branch, 'qty' => 0, 'value' => 0, 'uncosted' => 0, 'estimate' => false];
        $fromCosted = min($quantity, $p['qty']);
        $value = $fromCosted > 0 ? self::share($p['value'], $fromCosted, $p['qty']) : 0;
        $estimate = $p['estimate'] && $fromCosted > 0;
        $p['qty'] -= $fromCosted;
        $p['value'] -= $value;
        // The rest comes from stock with no cost (never valued at zero; the movement says how much had none).
        $uncosted = $quantity - $fromCosted;
        $p['uncosted'] -= min($uncosted, $p['uncosted']);
        if ($p['qty'] === 0) {
            $p['value'] = 0;
            $p['estimate'] = false;
        }
        $positions[$sku.'|'.$branch] = $p;

        return ['value' => $value, 'uncosted' => $uncosted, 'estimate' => $estimate];
    }

    /** @param array<string, array{sku:int, branch:int, qty:int, value:int, uncosted:int, estimate:bool}> $positions */
    private function give(array &$positions, int $sku, int $branch, int $costedQuantity, int $value, int $uncosted, bool $estimate): void
    {
        $p = $positions[$sku.'|'.$branch] ?? ['sku' => $sku, 'branch' => $branch, 'qty' => 0, 'value' => 0, 'uncosted' => 0, 'estimate' => false];
        $p['qty'] += $costedQuantity;
        $p['value'] += $value;
        $p['uncosted'] += $uncosted;
        $p['estimate'] = $p['estimate'] || ($estimate && $costedQuantity > 0);
        $positions[$sku.'|'.$branch] = $p;
    }

    /**
     * @param  array<string, array{sku:int, branch:int, qty:int, value:int, uncosted:int, estimate:bool}>  $positions
     * @return array{value:int, uncosted:int}
     */
    private function atAverage(array &$positions, int $sku, int $branch, int $quantity): array
    {
        $p = $positions[$sku.'|'.$branch] ?? null;
        if ($p === null || $p['qty'] === 0) {
            $this->give($positions, $sku, $branch, 0, 0, $quantity, false);

            return ['value' => 0, 'uncosted' => $quantity];
        }
        $value = self::share($p['value'], $quantity, $p['qty']);
        $this->give($positions, $sku, $branch, $quantity, $value, 0, false);

        return ['value' => $value, 'uncosted' => 0];
    }

    /**
     * A two-stage transfer arriving: at the cost it left at, taken from its in-transit pool.
     *
     * @param  array<string, array{sku:int, branch:int, qty:int, value:int, uncosted:int, estimate:bool}>  $positions
     * @param  array<string, array{sku:int, reference:string, qty:int, value:int, uncosted:int, estimate:bool}>  $transit
     * @return array{value_milli_sen:int, uncosted_quantity:int, basis:string, estimate:bool}
     */
    private function arrive(array &$positions, array &$transit, int $sku, int $branch, int $quantity, string $reference): array
    {
        $key = $sku.'|'.$reference;
        $pool = $transit[$key] ?? null;
        if ($pool === null || $pool['qty'] + $pool['uncosted'] === 0) {
            // Nothing recorded in transit (for example stock that arrived later than its pool was emptied): rule (c).
            $at = $this->atAverage($positions, $sku, $branch, $quantity);

            return ['value_milli_sen' => $at['value'], 'uncosted_quantity' => $at['uncosted'], 'basis' => $at['uncosted'] > 0 ? 'no_cost' : 'average', 'estimate' => false];
        }
        $fromCosted = min($quantity, $pool['qty']);
        $value = $fromCosted > 0 ? self::share($pool['value'], $fromCosted, $pool['qty']) : 0;
        $uncosted = min($quantity - $fromCosted, $pool['uncosted']);
        $extra = $quantity - $fromCosted - $uncosted;
        $pool['qty'] -= $fromCosted;
        $pool['value'] -= $value;
        $pool['uncosted'] -= $uncosted;
        $transit[$key] = $pool;
        $this->give($positions, $sku, $branch, $fromCosted, $value, $uncosted, $pool['estimate']);
        // More arrived than was sent (STK-6 extra stock never enters by transfer, but stay safe): at the average.
        if ($extra > 0) {
            $at = $this->atAverage($positions, $sku, $branch, $extra);
            $value += $at['value'];
            $uncosted += $at['uncosted'];
        }

        return ['value_milli_sen' => $value, 'uncosted_quantity' => $uncosted, 'basis' => 'transfer', 'estimate' => $pool['estimate'] && $fromCosted > 0];
    }
}
