<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * STK-44: the medicine sales page (owner decision 26; permission P9, Director and Finance Officer). Read only: nothing is
 * written except one audit row each time the page is opened. Figures are KPOne defaults, not accounting advice
 * (StockValuationReportService::BANNER).
 *
 * Everything follows the invoice, so a line's sale, quantity and cost stay together:
 * - Sales: medicine lines of invoices finalised in the month (each invoice's branch time zone) and still finalised,
 *   before invoice-level adjustments (an adjustment is not split across lines), as STK-28 and Insights do.
 * - Units and cost: the dispensing movements of each line's dispensary item, valued by the STK-27 engine replayed up to
 *   now for those SKUs only. A movement's cost is fixed when it happens, so replaying further changes nothing earlier.
 * - A line at RM 0.00 is "given free": its cost counts, it is not "sold below cost".
 * - A medicine with any uncosted dispensed quantity has "cost not complete": no cost, profit or margin, and it is left
 *   out of the profit and margin totals.
 */
final class MedicineSalesReportService
{
    public const PERMISSION = 'inventory.medicine_sales.view.organisation';

    public const TOP = 25;

    public const PAGE = 25;

    public const MONTHS = 24;

    public const RANKS = ['sales', 'profit'];

    public function __construct(private WeightedAverageCostService $cost, private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function report(User $actor, Branch $active, array $query): array
    {
        abort_unless($actor->can(self::PERMISSION), 403);
        $organisationId = (int) $actor->organisation_id;
        $branches = Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'timezone']);
        $zone = (string) ($active->timezone ?: config('app.timezone'));
        $now = CarbonImmutable::now($zone);
        $months = $this->months($now);
        $filters = $this->filters($query, array_values($branches->pluck('id')->map(fn ($id): int => (int) $id)->all()), (int) $active->id, array_keys($months));
        $scope = $filters['branch'];
        // "All branches" reads every branch of the organisation, active or not, so a branch closed later never drops
        // out of past months. The picker offers active branches only.
        $inScope = $scope === 'all'
            ? Branch::query()->where('organisation_id', $organisationId)->orderBy('name')->get(['id', 'name', 'timezone'])
            : $branches->where('id', (int) $scope)->values();

        $lines = $this->lines($organisationId, array_values($inScope->all()), $filters['month']);
        $computed = $this->compute($organisationId, $lines);
        $rows = $this->rank($computed['medicines'], $filters['rank']);
        $below = array_values(array_filter($computed['medicines'], fn (array $m): bool => $m['belowLines'] > 0));
        usort($below, fn (array $a, array $b): int => [$b['shortfallSen'], $a['medicine'], $a['id']] <=> [$a['shortfallSen'], $b['medicine'], $b['id']]);
        $belowPages = max(1, (int) ceil(count($below) / self::PAGE));
        $belowPage = min($filters['belowPage'], $belowPages);

        abort_if($this->audit->record('inventory.medicine_sales.viewed', null, [
            'month' => $filters['month'], 'scope' => $scope === 'all' ? 'all' : 'branch', 'branch_id' => $scope === 'all' ? null : (int) $scope, 'rank' => $filters['rank'],
        ], $actor, $active) === null, 503, 'The page could not be recorded, so it was not shown.');

        return [
            'banner' => StockValuationReportService::BANNER,
            'filters' => ['month' => $filters['month'], 'branch' => $scope === 'all' ? 'all' : (string) $scope, 'rank' => $filters['rank'], 'belowPage' => $belowPage],
            'months' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($months), array_values($months)),
            'monthLabel' => $months[$filters['month']],
            'branches' => $branches->map(fn (Branch $b): array => ['id' => (string) $b->id, 'name' => (string) $b->name])->values()->all(),
            'scopeLabel' => $scope === 'all' ? 'All branches' : (string) $inScope->first()?->name,
            'generatedAt' => $now->format('j M Y, g:i A'),
            'summary' => $computed['summary'],
            'rows' => array_slice($rows, 0, self::TOP),
            'medicineCount' => count($rows),
            'below' => ['rows' => array_slice($below, ($belowPage - 1) * self::PAGE, self::PAGE), 'page' => $belowPage, 'lastPage' => $belowPages, 'total' => count($below)],
        ];
    }

    /** @return array<string, string> month value (Y-m) => label, newest first; the current month is "(to date)" */
    private function months(CarbonImmutable $now): array
    {
        $months = [];
        $first = $now->startOfMonth();
        for ($i = 0; $i < self::MONTHS; $i++) {
            $m = $first->subMonthsNoOverflow($i);
            $months[$m->format('Y-m')] = $m->format('F Y').($i === 0 ? ' (to date)' : '');
        }

        return $months;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<int>  $branchIds
     * @param  list<string>  $months
     * @return array{month: string, branch: string, rank: string, belowPage: int}
     */
    private function filters(array $query, array $branchIds, int $active, array $months): array
    {
        $data = Validator::make($query, [
            'month' => ['nullable', 'string', Rule::in($months)],
            'branch' => ['nullable', 'string', 'max:20'],
            'rank' => ['nullable', 'string', Rule::in(self::RANKS)],
            'below_page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ], [
            'month.in' => 'Choose one of the last '.self::MONTHS.' months.',
            'rank.in' => 'Rank by Sales or Gross profit.',
        ])->validate();
        $scope = (string) ($data['branch'] ?? $active);
        if ($scope !== 'all' && ! in_array((int) $scope, $branchIds, true)) {
            throw ValidationException::withMessages(['branch' => 'Choose a branch, or All branches.']);
        }

        return [
            'month' => (string) ($data['month'] ?? $months[0]),
            'branch' => $scope === 'all' ? 'all' : (string) (int) $scope,
            'rank' => (string) ($data['rank'] ?? 'sales'),
            'belowPage' => (int) ($data['below_page'] ?? 1),
        ];
    }

    /**
     * Medicine lines of invoices finalised in the month, still finalised, each cut in its own branch's time zone.
     *
     * @param  list<Branch>  $branches
     * @return list<\stdClass>
     */
    private function lines(int $organisationId, array $branches, string $month): array
    {
        if ($branches === []) {
            return [];
        }

        $rows = DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->join('dispensary_items as d', 'd.id', '=', 'l.dispensary_item_id')
            ->where('l.organisation_id', $organisationId)->where('i.organisation_id', $organisationId)
            ->where('l.line_type', 'medicine')->where('i.status', 'finalized')
            ->where(function ($q) use ($branches, $month): void {
                foreach ($branches as $branch) {
                    $zone = (string) ($branch->timezone ?: config('app.timezone'));
                    $start = CarbonImmutable::parse($month.'-01 00:00:00', $zone);
                    $q->orWhere(fn ($b) => $b->where('i.branch_id', $branch->id)
                        ->where('i.finalized_at', '>=', $start->utc()->toDateTimeString())
                        ->where('i.finalized_at', '<', $start->addMonthNoOverflow()->utc()->toDateTimeString()));
                }
            })
            ->orderBy('l.id')
            ->get(['l.id', 'l.line_total_sen', 'l.dispensary_item_id', 'd.medicine_catalogue_item_id'])->all();

        return array_values($rows);
    }

    /**
     * @param  list<\stdClass>  $lines
     * @return array{medicines: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function compute(int $organisationId, array $lines): array
    {
        $items = array_values(array_unique(array_map(fn (\stdClass $l): int => (int) $l->dispensary_item_id, $lines)));
        // One query: every dispensing movement of those dispensary items, through their batch allocations.
        $movements = $items === [] ? collect() : DB::table('stock_movements as m')
            ->join('dispensary_item_batch_allocations as a', 'a.id', '=', 'm.dispensary_item_batch_allocation_id')
            ->join('inventory_skus as s', 's.id', '=', 'm.inventory_sku_id')
            ->where('m.organisation_id', $organisationId)->where('m.movement_type', StockMovement::TYPE_DISPENSE)
            ->whereIn('a.dispensary_item_id', $items)
            ->get(['m.id', 'm.inventory_sku_id', 'a.dispensary_item_id', 's.stock_unit']);
        $skuIds = array_values(array_unique($movements->pluck('inventory_sku_id')->map(fn ($id): int => (int) $id)->all()));
        $replay = $skuIds === [] ? ['movements' => []] : $this->cost->replay($organisationId, null, $skuIds);

        /** @var array<int, array{milliSen: int, uncosted: int, estimate: bool, units: array<string, int>}> $byItem */
        $byItem = [];
        foreach ($movements as $row) {
            $m = $replay['movements'][(int) $row->id] ?? null;
            $item = (int) $row->dispensary_item_id;
            $entry = $byItem[$item] ?? ['milliSen' => 0, 'uncosted' => 0, 'estimate' => false, 'units' => []];
            if ($m === null) {
                // Not replayed (should not happen): treat the whole line as uncosted rather than free.
                $entry['uncosted']++;
            } else {
                $entry['milliSen'] += (int) $m['value_milli_sen'];
                $entry['uncosted'] += (int) $m['uncosted_quantity'];
                $entry['estimate'] = $entry['estimate'] || (bool) $m['estimate'];
                $unit = (string) $row->stock_unit;
                $entry['units'][$unit] = ($entry['units'][$unit] ?? 0) + (int) $m['quantity'];
            }
            $byItem[$item] = $entry;
        }

        /** @var array<int, array<string, mixed>> $medicines */
        $medicines = [];
        foreach ($lines as $line) {
            $id = (int) $line->medicine_catalogue_item_id;
            $c = $byItem[(int) $line->dispensary_item_id] ?? ['milliSen' => 0, 'uncosted' => 0, 'estimate' => false, 'units' => []];
            $sales = (int) $line->line_total_sen;
            $med = $medicines[$id] ?? ['id' => $id, 'salesSen' => 0, 'costMilliSen' => 0, 'uncosted' => false, 'estimate' => false, 'units' => [], 'belowLines' => 0, 'shortfallMilliSen' => 0, 'freeLines' => 0];
            $med['salesSen'] += $sales;
            $med['costMilliSen'] += $c['milliSen'];
            // A line with no dispensing movement at all has no cost to show.
            $lineUncosted = $c['uncosted'] > 0 || $c['units'] === [];
            $med['uncosted'] = $med['uncosted'] || $lineUncosted;
            $med['estimate'] = $med['estimate'] || $c['estimate'];
            foreach ($c['units'] as $unit => $qty) {
                $med['units'][$unit] = ($med['units'][$unit] ?? 0) + $qty;
            }
            if ($sales === 0) {
                $med['freeLines']++;
            } elseif (! $lineUncosted && $sales * 1000 < $c['milliSen']) {
                $med['belowLines']++;
                $med['shortfallMilliSen'] += $c['milliSen'] - $sales * 1000;
            }
            $medicines[$id] = $med;
        }

        $names = $medicines === [] ? [] : DB::table('medicine_catalogue_items')->where('organisation_id', $organisationId)
            ->whereIn('id', array_keys($medicines))->get(['id', 'display_name', 'code'])->keyBy('id')->all();
        $out = [];
        $totals = ['salesSen' => 0, 'costMilliSen' => 0, 'completeSalesSen' => 0, 'completeCostMilliSen' => 0, 'incomplete' => 0, 'belowLines' => 0, 'freeLines' => 0, 'estimate' => false];
        foreach ($medicines as $id => $med) {
            $complete = ! $med['uncosted'];
            $costSen = WeightedAverageCostService::sen($med['costMilliSen']);
            $profitSen = $complete ? $med['salesSen'] - $costSen : null;
            $units = $med['units'];
            ksort($units);
            $out[] = [
                'id' => $id,
                // Defensive only: dispensary_items.medicine_catalogue_item_id is NOT NULL (2026_09_04_000100), so 0 can't occur.
                'medicine' => $id === 0 ? 'Dispensed without a medicine link' : (string) ($names[$id]->display_name ?? 'Medicine'),
                'code' => (string) ($names[$id]->code ?? ''),
                'units' => array_map(fn (int|string $unit, int $milli): array => ['unit' => (string) $unit, 'quantity' => self::quantity($milli)], array_keys($units), array_values($units)),
                'salesSen' => $med['salesSen'],
                'costSen' => $complete ? $costSen : null,
                'profitSen' => $profitSen,
                'marginPercent' => $profitSen !== null && $med['salesSen'] > 0 ? round($profitSen / $med['salesSen'] * 100, 1) : null,
                'costComplete' => $complete,
                'estimate' => $complete && $med['estimate'],
                'belowLines' => $med['belowLines'],
                'shortfallSen' => WeightedAverageCostService::sen($med['shortfallMilliSen']),
                'freeLines' => $med['freeLines'],
            ];
            $totals['salesSen'] += $med['salesSen'];
            $totals['belowLines'] += $med['belowLines'];
            $totals['freeLines'] += $med['freeLines'];
            if ($complete) {
                $totals['completeSalesSen'] += $med['salesSen'];
                $totals['completeCostMilliSen'] += $med['costMilliSen'];
                $totals['estimate'] = $totals['estimate'] || $med['estimate'];
            } else {
                $totals['incomplete']++;
            }
        }
        $profit = $totals['completeSalesSen'] - WeightedAverageCostService::sen($totals['completeCostMilliSen']);

        return [
            'medicines' => $out,
            'summary' => [
                'salesSen' => $totals['salesSen'],
                'costSen' => WeightedAverageCostService::sen($totals['completeCostMilliSen']),
                'profitSen' => $profit,
                // The margin's denominator is the sales of medicines whose cost is complete only.
                'marginPercent' => $totals['completeSalesSen'] > 0 ? round($profit / $totals['completeSalesSen'] * 100, 1) : null,
                'estimate' => $totals['estimate'],
                'incompleteMedicines' => $totals['incomplete'],
                'belowLines' => $totals['belowLines'],
                'freeLines' => $totals['freeLines'],
                'lineCount' => count($lines),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $medicines
     * @return list<array<string, mixed>>
     */
    private function rank(array $medicines, string $rank): array
    {
        usort($medicines, function (array $a, array $b) use ($rank): int {
            if ($rank === 'profit') {
                // Complete costs first, by profit; incomplete ones after, by sales.
                $ka = [$a['profitSen'] === null ? 1 : 0, $a['profitSen'] === null ? -$a['salesSen'] : -$a['profitSen']];
                $kb = [$b['profitSen'] === null ? 1 : 0, $b['profitSen'] === null ? -$b['salesSen'] : -$b['profitSen']];
            } else {
                $ka = [0, -$a['salesSen']];
                $kb = [0, -$b['salesSen']];
            }

            return [...$ka, $a['medicine'], $a['id']] <=> [...$kb, $b['medicine'], $b['id']];
        });
        foreach ($medicines as $i => $m) {
            $medicines[$i]['rank'] = $i + 1;
        }

        return $medicines;
    }

    private static function quantity(int $milli): string
    {
        $whole = intdiv($milli, 1000);
        $fraction = $milli % 1000;

        return number_format($whole).($fraction === 0 ? '' : rtrim('.'.str_pad((string) $fraction, 3, '0', STR_PAD_LEFT), '0'));
    }
}
