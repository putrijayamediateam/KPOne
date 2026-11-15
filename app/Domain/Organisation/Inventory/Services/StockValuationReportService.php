<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * STK-28: stock value, cost of goods sold, margin and losses, read from the STK-27 weighted average cost engine and,
 * read only, from finalised invoice lines. Director only (`inventory.valuation.view.organisation`). Nothing is written
 * except one audit row each time the report is opened. Every figure is a KPOne default set at the owner's instruction,
 * not professional accounting advice (WeightedAverageCostService::DISCLAIMER).
 *
 * - Stock value: each SKU's position at the end of the period (or now, if earlier), in the chosen branch or every
 *   branch plus the organisation store. Stock with no cost is counted apart and named, never valued at zero.
 * - Cost of goods sold: the cost at which stock left by dispensing and by treatment-room use in the period.
 * - Medicine sales: medicine lines of invoices finalised in the period and not voided, before invoice-level discounts or
 *   surcharges (an invoice adjustment is not split across its lines).
 * - Margin: medicine sales minus the cost of what was dispensed, per medicine. Treatment-room use is not billed, so it
 *   is a cost with no sale.
 * - Losses: expiry and other write-offs, treatment-room waste, count differences (losses minus gains, and legacy
 *   adjustments) and the supplier credit difference (cost of the returned stock minus the credit note amount).
 */
final class StockValuationReportService
{
    public const PERMISSION = 'inventory.valuation.view.organisation';

    public const MAX_DAYS = 92;

    public const BANNER = "Management report — cost policy to be confirmed by the clinic's accountant. ".WeightedAverageCostService::DISCLAIMER;

    private const LOSS_TYPES = [
        'expiry' => [StockMovement::TYPE_EXPIRY_WRITEOFF],
        'writeoff' => [StockMovement::TYPE_STOCK_WRITEOFF],
        'waste' => [StockMovement::TYPE_TREATMENT_WASTE],
    ];

    public function __construct(private WeightedAverageCostService $cost, private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function report(User $actor, Branch $active, array $query): array
    {
        abort_unless($actor->can(self::PERMISSION), 403);
        $organisationId = (int) $actor->organisation_id;
        $branches = Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'timezone']);
        $zone = (string) ($active->timezone ?: config('app.timezone'));
        $today = CarbonImmutable::now($zone)->toDateString();
        [$scope, $from, $to] = $this->filters($query, array_values($branches->pluck('id')->map(fn ($id): int => (int) $id)->all()), (int) $active->id, $today);

        $start = CarbonImmutable::parse($from.' 00:00:00', $zone)->utc();
        $end = CarbonImmutable::parse($to.' 23:59:59', $zone)->utc();
        $end = $end->greaterThan(CarbonImmutable::now('UTC')) ? CarbonImmutable::now('UTC') : $end;
        // Branch keys in scope: one branch, or every branch and the organisation store (key 0).
        $keys = $scope === 'all' ? [0, ...array_values($branches->pluck('id')->map(fn ($id): int => (int) $id)->all())] : [(int) $scope];

        $replay = $this->cost->replay($organisationId, $end);
        $period = DB::table('stock_movements')->where('organisation_id', $organisationId)
            ->where('occurred_at', '>=', $start->toDateTimeString())->where('occurred_at', '<=', $end->toDateTimeString())
            ->orderBy('id')->get(['id', 'movement_type', 'inventory_sku_id', 'dispensary_item_batch_allocation_id']);
        $moved = [];
        foreach ($period as $row) {
            $m = $replay['movements'][(int) $row->id] ?? null;
            if ($m !== null) {
                $moved[] = ['row' => $row, 'm' => $m];
            }
        }
        $outOfScope = fn (array $m): bool => $m['out_branch'] !== null && $m['out_branch'] !== $m['in_branch'] && in_array($m['out_branch'], $keys, true);
        $intoScope = fn (array $m): bool => $m['in_branch'] !== null && $m['in_branch'] !== $m['out_branch'] && in_array($m['in_branch'], $keys, true);

        $skus = $this->skus($organisationId);
        $value = $this->stockValue($replay['positions'], $keys, $skus);
        $margin = $this->margin($organisationId, $keys, $start, $end, $moved, $outOfScope);
        $usage = ['milliSen' => 0, 'uncosted' => 0, 'count' => 0];
        foreach ($moved as $x) {
            if ($x['row']->movement_type === StockMovement::TYPE_TREATMENT_USAGE && $outOfScope($x['m'])) {
                $usage['milliSen'] += $x['m']['value_milli_sen'];
                $usage['uncosted'] += $x['m']['uncosted_quantity'];
                $usage['count']++;
            }
        }
        $losses = $this->losses($organisationId, $keys, $start, $end, $moved, $outOfScope, $intoScope, $replay['movements']);
        $inTransit = $scope === 'all' ? array_sum(array_column($replay['inTransit'], 'value_milli_sen')) : 0;

        $dispenseCost = $margin['costMilliSen'];
        $cogs = $dispenseCost + $usage['milliSen'];
        $grossMargin = $margin['salesSen'] * 1000 - $dispenseCost;
        $lossTotal = array_sum(array_column($losses['rows'], 'milliSen'));

        $scopeBranch = $scope === 'all' ? null : $branches->firstWhere('id', (int) $scope);
        abort_if($this->audit->record('inventory.valuation.viewed', null, ['scope' => $scope === 'all' ? 'all' : 'branch', 'branch_id' => $scope === 'all' ? null : (int) $scope, 'from' => $from, 'to' => $to], $actor, $active) === null, 503, 'The report could not be recorded, so it was not shown.');

        return [
            'banner' => self::BANNER,
            'filters' => ['branch' => $scope === 'all' ? 'all' : (string) $scope, 'from' => $from, 'to' => $to, 'today' => $today, 'maxDays' => self::MAX_DAYS],
            'branches' => $branches->map(fn (Branch $b): array => ['id' => (string) $b->id, 'name' => $b->name])->values()->all(),
            'scopeLabel' => $scopeBranch === null ? 'All branches' : (string) $scopeBranch->name,
            'periodEnd' => CarbonImmutable::parse($end)->setTimezone($zone)->toDateString(),
            'generatedAt' => CarbonImmutable::now($zone)->format('j M Y, g:i A'),
            'timezone' => $zone,
            'summary' => [
                'stockValueSen' => WeightedAverageCostService::sen($value['totalMilliSen']),
                'cogsSen' => WeightedAverageCostService::sen($cogs),
                'dispenseCostSen' => WeightedAverageCostService::sen($dispenseCost),
                'usageCostSen' => WeightedAverageCostService::sen($usage['milliSen']),
                'salesSen' => $margin['salesSen'],
                'marginSen' => WeightedAverageCostService::sen($grossMargin),
                'marginPercent' => $margin['salesSen'] > 0 ? round($grossMargin / ($margin['salesSen'] * 1000) * 100, 1) : null,
                'lossesSen' => WeightedAverageCostService::sen($lossTotal),
                'inTransitSen' => WeightedAverageCostService::sen($inTransit),
                'uncostedSkus' => $value['uncostedSkus'],
                'estimateSkus' => $value['estimateSkus'],
            ],
            'stock' => $value['rows'],
            'medicines' => $margin['rows'],
            'losses' => $losses,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<int>  $branchIds
     * @return array{0: string, 1: string, 2: string}
     */
    private function filters(array $query, array $branchIds, int $active, string $today): array
    {
        $monthStart = substr($today, 0, 8).'01';
        $data = Validator::make($query, [
            'branch' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $scope = (string) ($data['branch'] ?? $active);
        if ($scope !== 'all' && ! in_array((int) $scope, $branchIds, true)) {
            throw ValidationException::withMessages(['branch' => 'Choose a branch, or All branches.']);
        }
        $from = (string) ($data['from'] ?? $monthStart);
        $to = (string) ($data['to'] ?? $today);
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'From must be on or before To.']);
        }
        if ($to > $today) {
            throw ValidationException::withMessages(['to' => 'Choose a date up to today.']);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose '.self::MAX_DAYS.' days or fewer.']);
        }

        return [$scope === 'all' ? 'all' : (string) (int) $scope, $from, $to];
    }

    /** @return array<int, array{name: string, code: string, unit: string}> */
    private function skus(int $organisationId): array
    {
        return DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->where('s.organisation_id', $organisationId)
            ->get(['s.id', 's.sku_code', 's.stock_unit', 'i.generic_name', 'i.strength'])
            ->mapWithKeys(fn ($s): array => [(int) $s->id => ['name' => trim($s->generic_name.' '.($s->strength ?? '')), 'code' => (string) $s->sku_code, 'unit' => (string) $s->stock_unit]])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  list<int>  $keys
     * @param  array<int, array{name: string, code: string, unit: string}>  $skus
     * @return array{rows: list<array<string, mixed>>, totalMilliSen: int, uncostedSkus: int, estimateSkus: int}
     */
    private function stockValue(array $positions, array $keys, array $skus): array
    {
        $by = [];
        foreach ($positions as $p) {
            if (! in_array($p['branch_key'], $keys, true)) {
                continue;
            }
            $sku = (int) $p['sku_id'];
            $row = $by[$sku] ?? ['quantity' => 0, 'costed_quantity' => 0, 'value_milli_sen' => 0, 'uncosted_quantity' => 0, 'includes_estimate' => false];
            $row['quantity'] += $p['quantity'];
            $row['costed_quantity'] += $p['costed_quantity'];
            $row['value_milli_sen'] += $p['value_milli_sen'];
            $row['uncosted_quantity'] += $p['uncosted_quantity'];
            $row['includes_estimate'] = $row['includes_estimate'] || $p['includes_estimate'];
            $by[$sku] = $row;
        }
        $rows = [];
        $total = 0;
        foreach ($by as $sku => $row) {
            if ($row['quantity'] === 0) {
                continue;
            }
            $average = WeightedAverageCostService::average($row);
            $total += $row['value_milli_sen'];
            $rows[] = [
                'sku' => $skus[$sku]['code'] ?? '', 'medicine' => $skus[$sku]['name'] ?? '', 'unit' => $skus[$sku]['unit'] ?? '',
                'quantity' => self::quantity($row['quantity']), 'uncostedQuantity' => self::quantity($row['uncosted_quantity']),
                'averageMilliSen' => $average, 'valueSen' => WeightedAverageCostService::sen($row['value_milli_sen']),
                'includesEstimate' => $row['includes_estimate'],
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['medicine'], $a['sku']] <=> [$b['medicine'], $b['sku']]);

        return [
            'rows' => $rows, 'totalMilliSen' => $total,
            'uncostedSkus' => count(array_filter($rows, fn (array $r): bool => $r['uncostedQuantity'] !== '0')),
            'estimateSkus' => count(array_filter($rows, fn (array $r): bool => $r['includesEstimate'])),
        ];
    }

    /**
     * @param  list<int>  $keys
     * @param  list<array{row: \stdClass, m: array<string, mixed>}>  $moved
     * @return array{rows: list<array<string, mixed>>, salesSen: int, costMilliSen: int}
     */
    private function margin(int $organisationId, array $keys, CarbonImmutable $start, CarbonImmutable $end, array $moved, callable $outOfScope): array
    {
        $branchKeys = array_values(array_filter($keys, fn (int $k): bool => $k !== 0));
        // Sales, read only: medicine lines of invoices finalised in the period and not voided.
        $sales = DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->join('dispensary_items as d', 'd.id', '=', 'l.dispensary_item_id')
            ->where('l.organisation_id', $organisationId)->where('l.line_type', 'medicine')->where('i.status', 'finalized')
            ->whereIn('i.branch_id', $branchKeys === [] ? [-1] : $branchKeys)
            ->where('i.finalized_at', '>=', $start->toDateTimeString())->where('i.finalized_at', '<=', $end->toDateTimeString())
            ->groupBy('d.medicine_catalogue_item_id')->selectRaw('d.medicine_catalogue_item_id as medicine, sum(l.line_total_sen) as sen')
            ->pluck('sen', 'medicine')->map(fn ($v): int => (int) $v)->all();
        // Cost of what was dispensed, per medicine, through the dispensing batch allocation.
        $allocationIds = [];
        foreach ($moved as $x) {
            if ($x['row']->movement_type === StockMovement::TYPE_DISPENSE && $outOfScope($x['m']) && $x['row']->dispensary_item_batch_allocation_id !== null) {
                $allocationIds[] = (int) $x['row']->dispensary_item_batch_allocation_id;
            }
        }
        $medicineOf = $allocationIds === [] ? [] : DB::table('dispensary_item_batch_allocations as a')->join('dispensary_items as d', 'd.id', '=', 'a.dispensary_item_id')
            ->whereIn('a.id', array_values(array_unique($allocationIds)))->pluck('d.medicine_catalogue_item_id', 'a.id')->map(fn ($v): int => (int) $v)->all();
        $cost = [];
        $total = 0;
        foreach ($moved as $x) {
            if ($x['row']->movement_type !== StockMovement::TYPE_DISPENSE || ! $outOfScope($x['m'])) {
                continue;
            }
            $medicine = $medicineOf[(int) $x['row']->dispensary_item_batch_allocation_id] ?? 0;
            $c = $cost[$medicine] ?? ['milliSen' => 0, 'uncosted' => 0];
            $c['milliSen'] += $x['m']['value_milli_sen'];
            $c['uncosted'] += $x['m']['uncosted_quantity'];
            $cost[$medicine] = $c;
            $total += $x['m']['value_milli_sen'];
        }
        $ids = array_values(array_unique([...array_keys($sales), ...array_keys($cost)]));
        $names = DB::table('medicine_catalogue_items')->where('organisation_id', $organisationId)->whereIn('id', $ids === [] ? [-1] : $ids)->pluck('display_name', 'id')->all();
        $rows = [];
        foreach ($ids as $id) {
            $salesSen = $sales[$id] ?? 0;
            $c = $cost[$id] ?? null;
            $known = $c !== null && $c['uncosted'] === 0;
            $costSen = $c === null ? null : WeightedAverageCostService::sen($c['milliSen']);
            $marginSen = $known ? $salesSen - (int) $costSen : null;
            $rows[] = [
                'medicine' => $id === 0 ? 'Dispensed without a medicine link' : (string) ($names[$id] ?? 'Medicine'),
                'salesSen' => $salesSen, 'costSen' => $costSen, 'marginSen' => $marginSen,
                'marginPercent' => $marginSen !== null && $salesSen > 0 ? round($marginSen / $salesSen * 100, 1) : null,
                'reason' => match (true) {
                    $c === null => 'No dispensing recorded in this period',
                    ! $known => 'Part of the stock dispensed had no cost recorded',
                    $salesSen === 0 => 'No sales recorded in this period',
                    default => null,
                },
            ];
        }
        usort($rows, fn (array $a, array $b): int => $a['medicine'] <=> $b['medicine']);

        return ['rows' => $rows, 'salesSen' => array_sum($sales), 'costMilliSen' => $total];
    }

    /**
     * @param  list<int>  $keys
     * @param  list<array{row: \stdClass, m: array<string, mixed>}>  $moved
     * @param  array<int, array<string, mixed>>  $allMovements
     * @return array{rows: list<array<string, mixed>>, creditsWithoutAmount: int}
     */
    private function losses(int $organisationId, array $keys, CarbonImmutable $start, CarbonImmutable $end, array $moved, callable $outOfScope, callable $intoScope, array $allMovements): array
    {
        $rows = [];
        foreach (['expiry' => 'Expiry write-offs', 'writeoff' => 'Other write-offs', 'waste' => 'Treatment-room waste'] as $key => $label) {
            $row = ['key' => $key, 'label' => $label, 'count' => 0, 'milliSen' => 0, 'uncosted' => 0];
            foreach ($moved as $x) {
                if (in_array($x['row']->movement_type, self::LOSS_TYPES[$key], true) && $outOfScope($x['m'])) {
                    $row['count']++;
                    $row['milliSen'] += $x['m']['value_milli_sen'];
                    $row['uncosted'] += $x['m']['uncosted_quantity'];
                }
            }
            $rows[] = $row;
        }
        // Count differences: losses and adjustments out, less gains and adjustments in.
        $count = ['key' => 'count', 'label' => 'Count differences (losses less gains)', 'count' => 0, 'milliSen' => 0, 'uncosted' => 0];
        foreach ($moved as $x) {
            $type = $x['row']->movement_type;
            if (in_array($type, [StockMovement::TYPE_STOCKTAKE_LOSS, StockMovement::TYPE_ADJUSTMENT_OUT], true) && $outOfScope($x['m'])) {
                $count['count']++;
                $count['milliSen'] += $x['m']['value_milli_sen'];
                $count['uncosted'] += $x['m']['uncosted_quantity'];
            } elseif (in_array($type, [StockMovement::TYPE_STOCKTAKE_GAIN, StockMovement::TYPE_ADJUSTMENT_IN], true) && $intoScope($x['m'])) {
                $count['count']++;
                $count['milliSen'] -= $x['m']['value_milli_sen'];
            }
        }
        $rows[] = $count;
        // Supplier credit difference: credit notes recorded in the period, against the cost the returned stock left at.
        $branchKeys = array_values(array_filter($keys, fn (int $k): bool => $k !== 0));
        $credits = DB::table('inventory_supplier_return_actions as c')->join('inventory_supplier_returns as r', 'r.id', '=', 'c.return_id')
            ->where('c.organisation_id', $organisationId)->where('c.action', 'credit_recorded')
            ->whereIn('r.branch_id', $branchKeys === [] ? [-1] : $branchKeys)
            ->where('c.created_at', '>=', $start->toDateTimeString())->where('c.created_at', '<=', $end->toDateTimeString())
            ->get(['c.return_id', 'c.credit_amount_sen', 'r.group_id']);
        $credit = ['key' => 'credit', 'label' => 'Supplier credit difference', 'count' => 0, 'milliSen' => 0, 'uncosted' => 0];
        $withoutAmount = 0;
        foreach ($credits as $c) {
            if ($c->credit_amount_sen === null) {
                $withoutAmount++;

                continue;
            }
            $movementIds = DB::table('inventory_supplier_return_actions')->where('return_id', $c->return_id)->where('action', 'dispatched')->whereNotNull('movement_id')->pluck('movement_id');
            $returnedCost = 0;
            foreach ($movementIds as $id) {
                $returnedCost += (int) ($allMovements[(int) $id]['value_milli_sen'] ?? 0);
                $credit['uncosted'] += (int) ($allMovements[(int) $id]['uncosted_quantity'] ?? 0);
            }
            $credit['count']++;
            $credit['milliSen'] += $returnedCost - (int) $c->credit_amount_sen * 1000;
        }
        $rows[] = $credit;

        return [
            'rows' => array_map(fn (array $r): array => ['key' => $r['key'], 'label' => $r['label'], 'count' => $r['count'], 'milliSen' => $r['milliSen'],
                'sen' => WeightedAverageCostService::sen($r['milliSen']), 'uncosted' => $r['uncosted'] > 0], $rows),
            'creditsWithoutAmount' => $withoutAmount,
        ];
    }

    private static function quantity(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);
        $whole = intdiv($milli, 1000);
        $fraction = rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $sign.$whole.($fraction === '' ? '' : '.'.$fraction);
    }
}
