<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * STK-33 (approved by B.E.R.N.I.S.S for the owner, 2026-11-12): read-only stock health for the active branch, one row
 * per SKU and location, in quantities only (no cost: valuation waits for the accountant's cost policy). It writes
 * nothing. All arithmetic is in exact thousandths of the SKU's base stock unit (STK-1).
 *
 * - Used: dispensed plus unreversed treatment-room usage out of the location in the period (the STK-16 use figure).
 * - Days on hand: usable on hand (the STK-2 rule) ÷ average daily use; none when nothing was used.
 * - Turnover: used ÷ average on hand, where average on hand is the mean of the opening and closing quantity, the
 *   opening rebuilt from the ledger (closing now, less what came in, plus what went out, since the period began).
 * - Expiry loss: approved governed expiry write-offs ÷ (used + all approved governed write-offs); a write-off whose
 *   reversal was approved is left out, as on Insights (STK-15b).
 */
final class StockHealthService
{
    public const PERIODS = [30, 90, 365];

    public const DEFAULT_PERIOD = 90;

    /** A SKU and location with more than this many days of stock is counted as "slow" in the summary. */
    public const SLOW_DAYS = 180;

    public const PER_PAGE = 25;

    /** @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branches->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, [
            'days' => ['nullable', 'integer', 'in:'.implode(',', self::PERIODS)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $days = (int) ($data['days'] ?? self::DEFAULT_PERIOD);
        $organisationId = (int) $actor->organisation_id;
        $since = CarbonImmutable::now($branch->timezone)->startOfDay()->subDays($days - 1)->utc()->format('Y-m-d H:i:sP');

        $pairs = $this->pairs($organisationId, $branch)->get()->all();
        $locationIds = array_values(array_unique(array_map(fn (\stdClass $row): int => (int) $row->location_id, $pairs)));
        $usable = $this->usable($organisationId, $branch, $locationIds);
        $used = $this->used($organisationId, $since, $locationIds);
        [$in, $out] = $this->flows($organisationId, $since, $locationIds);
        [$expired, $writtenOff] = $this->writeOffs($organisationId, $since, $locationIds);

        $rows = [];
        $summary = ['slow' => 0, 'noUse' => 0, 'expiryLoss' => 0];
        foreach ($pairs as $pair) {
            $key = $pair->location_id.':'.$pair->sku_id;
            $closing = StockUnitQuantity::scaled((string) $pair->quantity, true);
            $usableNow = $usable[$key] ?? 0;
            $usedMilli = $used[$key] ?? 0;
            $expiredMilli = $expired[$key] ?? 0;
            $lossBase = $usedMilli + ($writtenOff[$key] ?? 0);
            if ($closing === 0 && $usedMilli === 0 && $lossBase === 0) {
                continue;
            }
            $opening = max(0, $closing - ($in[$key] ?? 0) + ($out[$key] ?? 0));
            $average = intdiv($opening + $closing, 2);
            // Whole days of usable stock at the period's average daily use, rounded down.
            $daysOnHand = $usedMilli === 0 ? null : intdiv($usableNow * $days, $usedMilli);
            $noUse = $usedMilli === 0 && $usableNow > 0;
            $summary['noUse'] += $noUse ? 1 : 0;
            $summary['slow'] += $daysOnHand !== null && $daysOnHand > self::SLOW_DAYS ? 1 : 0;
            $summary['expiryLoss'] += $expiredMilli > 0 ? 1 : 0;
            $rows[] = [
                'key' => $key,
                'skuCode' => $pair->sku_code,
                'itemName' => $pair->item,
                'location' => $pair->location,
                'locationCode' => $pair->location_code,
                'stockUnit' => $pair->stock_unit,
                'onHand' => StockUnitQuantity::format($closing),
                'usable' => StockUnitQuantity::format($usableNow),
                'used' => StockUnitQuantity::format($usedMilli),
                'averageOnHand' => StockUnitQuantity::format($average),
                'daysOnHand' => $daysOnHand,
                'noUse' => $noUse,
                // Two decimals, rounded down, as a string so the browser never does the arithmetic.
                'turnover' => $average === 0 ? null : $this->ratio($usedMilli, $average, 100),
                'expired' => StockUnitQuantity::format($expiredMilli),
                // One decimal place of a per cent, rounded down.
                'expiryLossPercent' => $lossBase === 0 ? null : $this->ratio($expiredMilli * 100, $lossBase, 10),
            ];
        }
        // The summary is for the whole branch; a search only narrows the list below it.
        $needle = mb_strtolower(trim((string) ($data['q'] ?? '')));
        if ($needle !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower($row['skuCode'].' '.$row['itemName'].' '.$row['location'].' '.$row['locationCode']), $needle)));
        }
        // Stock nobody used first, then the most days on hand, then by code and location.
        usort($rows, fn (array $a, array $b): int => [$b['noUse'], $b['daysOnHand'] ?? -1, $a['skuCode'], $a['location']] <=> [$a['noUse'], $a['daysOnHand'] ?? -1, $b['skuCode'], $b['location']]);

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min((int) ($data['page'] ?? 1), $lastPage);

        return [
            'branchName' => $branch->name,
            'periods' => self::PERIODS,
            'slowDays' => self::SLOW_DAYS,
            'filters' => ['days' => $days, 'q' => $data['q'] ?? ''],
            'summary' => $summary,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
        ];
    }

    /** Every active SKU and active, non-returns location of the branch with a balance row, and its quantity now. */
    private function pairs(int $organisationId, Branch $branch): Builder
    {
        return DB::table('inventory_stock_balances as b')
            ->join('inventory_locations as l', fn ($join) => $join->on('l.id', '=', 'b.inventory_location_id')->on('l.organisation_id', '=', 'b.organisation_id'))
            ->join('inventory_skus as s', fn ($join) => $join->on('s.id', '=', 'b.inventory_sku_id')->on('s.organisation_id', '=', 'b.organisation_id'))
            ->join('inventory_items as i', fn ($join) => $join->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 's.organisation_id'))
            ->where('b.organisation_id', $organisationId)->where('l.branch_id', $branch->id)->where('l.is_active', true)
            ->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)->where('s.is_active', true)->where('i.is_active', true)
            ->groupBy('l.id', 's.id', 's.sku_code', 'i.generic_name', 'l.name', 'l.code', 's.stock_unit')
            ->selectRaw('l.id as location_id, s.id as sku_id, s.sku_code, i.generic_name as item, l.name as location, l.code as location_code, s.stock_unit, SUM(b.quantity)::text as quantity');
    }

    /** @param  list<int>  $locationIds
     * @return array<string,int>
     */
    private function usable(int $organisationId, Branch $branch, array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return $this->keyed(DB::table('inventory_stock_balances as b')
            ->joinSub(app(InventoryBatchEligibility::class)->query($organisationId, $branch), 'e', fn ($join) => $join->on('e.id', '=', 'b.inventory_batch_id')->on('e.organisation_id', '=', 'b.organisation_id'))
            ->where('b.organisation_id', $organisationId)->whereIn('b.inventory_location_id', $locationIds)->whereNull('e.eligibility_reason')
            ->groupBy('b.inventory_location_id', 'b.inventory_sku_id')
            ->selectRaw('b.inventory_location_id as location_id, b.inventory_sku_id as sku_id, SUM(b.quantity)::text as quantity')
            ->get()->all());
    }

    /** Dispensed plus unreversed treatment-room usage out of each location since the period began.
     *
     * @param  list<int>  $locationIds
     * @return array<string,int>
     */
    private function used(int $organisationId, string $since, array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }
        $dispensed = $this->keyed(DB::table('stock_movements as m')
            ->where('m.organisation_id', $organisationId)->where('m.movement_type', StockMovement::TYPE_DISPENSE)
            ->whereIn('m.source_location_id', $locationIds)->where('m.occurred_at', '>=', $since)
            ->groupBy('m.source_location_id', 'm.inventory_sku_id')
            ->selectRaw('m.source_location_id as location_id, m.inventory_sku_id as sku_id, SUM(m.quantity)::text as quantity')
            ->get()->all());
        $treatment = $this->keyed(DB::table('inventory_treatment_usages as u')
            ->leftJoin('inventory_treatment_usage_reversals as r', 'r.usage_id', '=', 'u.id')
            ->where('u.organisation_id', $organisationId)->where('u.kind', 'usage')->whereNull('r.id')
            ->whereIn('u.inventory_location_id', $locationIds)->where('u.created_at', '>=', $since)
            ->groupBy('u.inventory_location_id', 'u.inventory_sku_id')
            ->selectRaw('u.inventory_location_id as location_id, u.inventory_sku_id as sku_id, SUM(u.quantity)::text as quantity')
            ->get()->all());
        foreach ($treatment as $key => $quantity) {
            $dispensed[$key] = ($dispensed[$key] ?? 0) + $quantity;
        }

        return $dispensed;
    }

    /** Everything that came into and went out of each location since the period began, by any movement.
     *
     * @param  list<int>  $locationIds
     * @return array{array<string,int>, array<string,int>}
     */
    private function flows(int $organisationId, string $since, array $locationIds): array
    {
        if ($locationIds === []) {
            return [[], []];
        }
        $flow = fn (string $column): array => $this->keyed(DB::table('stock_movements as m')
            ->where('m.organisation_id', $organisationId)->whereIn('m.'.$column, $locationIds)->where('m.occurred_at', '>=', $since)
            ->groupBy('m.'.$column, 'm.inventory_sku_id')
            ->select(['m.'.$column.' as location_id', 'm.inventory_sku_id as sku_id'])->selectRaw('SUM(m.quantity)::text as quantity')
            ->get()->all());

        return [$flow('destination_location_id'), $flow('source_location_id')];
    }

    /** Approved governed write-offs out of each location since the period began, less any whose reversal was approved.
     *
     * @param  list<int>  $locationIds
     * @return array{array<string,int>, array<string,int>} expiry write-offs, and all write-offs
     */
    private function writeOffs(int $organisationId, string $since, array $locationIds): array
    {
        if ($locationIds === []) {
            return [[], []];
        }
        $query = fn (array $types): array => $this->keyed(DB::table('stock_movements as m')
            ->join('inventory_governed_cases as cases', fn ($join) => $join->on('cases.public_id', '=', 'm.reference_public_id')->on('cases.organisation_id', '=', 'm.organisation_id'))
            ->where('m.organisation_id', $organisationId)->whereIn('m.movement_type', $types)
            ->where('m.reference_type', 'inventory_governed_case')->where('cases.kind', 'writeoff')
            ->whereNotExists(fn (Builder $reversed) => $reversed->selectRaw('1')->from('inventory_governed_cases as reversals')
                ->join('inventory_governed_actions as approvals', 'approvals.case_id', '=', 'reversals.id')
                ->whereColumn('reversals.reverses_case_id', 'cases.id')->where('approvals.action', 'approved'))
            ->whereIn('m.source_location_id', $locationIds)->where('m.occurred_at', '>=', $since)
            ->groupBy('m.source_location_id', 'm.inventory_sku_id')
            ->selectRaw('m.source_location_id as location_id, m.inventory_sku_id as sku_id, SUM(m.quantity)::text as quantity')
            ->get()->all());

        return [$query([StockMovement::TYPE_EXPIRY_WRITEOFF]), $query([StockMovement::TYPE_EXPIRY_WRITEOFF, StockMovement::TYPE_STOCK_WRITEOFF])];
    }

    /** $numerator ÷ $denominator to 1/$scale, rounded down, as a decimal string. */
    private function ratio(int $numerator, int $denominator, int $scale): string
    {
        $scaled = intdiv($numerator * $scale, $denominator);
        $places = strlen((string) $scale) - 1;

        return intdiv($scaled, $scale).'.'.str_pad((string) ($scaled % $scale), $places, '0', STR_PAD_LEFT);
    }

    /** @param  array<int,\stdClass>  $rows
     * @return array<string,int>
     */
    private function keyed(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->location_id.':'.$row->sku_id] = StockUnitQuantity::scaled((string) $row->quantity, true);
        }

        return $keyed;
    }
}
