<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `inventory.stock_counts`: governed stock counts (STK-5) per SKU and location at one branch: the
 * last completed count (posted, or closed with no variance), open count cases, and cases with an
 * unresolved variance (awaiting recount or approval). SKU and location pairs holding stock with no
 * count are included, so a checker can see what is due; `stocked` says whether a pair holds stock now
 * (a pair with only case history may be empty).
 */
final class InventoryStockCountsTool implements SystemReadableTool
{
    use ReadsBranches;

    public const OPEN = ['awaiting_count', 'awaiting_recount', 'awaiting_approval'];

    public const VARIANCE = ['awaiting_recount', 'awaiting_approval'];

    public const COMPLETED = ['closed_zero', 'posted'];

    public function code(): string
    {
        return 'inventory.stock_counts';
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
        // AG-8: an organisation scope, never a person (see ReadsBranches::organisationScope).
        $requester = $this->organisationScope($organisationId);
        assert($branch !== null);
        // The gateway has checked the branch is in the requester's organisation; reading it from the branch
        // keeps this tool usable by AG-8's system path, which has no requester.
        $organisationId = $branch->organisation_id;
        $latest = DB::table('inventory_governed_actions')->selectRaw('case_id, MAX(lock_version) as version')->groupBy('case_id');
        $cases = DB::table('inventory_governed_cases as c')
            ->joinSub($latest, 'v', 'v.case_id', '=', 'c.id')
            ->join('inventory_governed_actions as a', fn ($j) => $j->on('a.case_id', '=', 'c.id')->on('a.lock_version', '=', 'v.version'))
            ->where('c.organisation_id', $organisationId)->where('c.branch_id', $branch->id)->where('c.kind', 'count')
            ->get(['c.inventory_sku_id as sku_id', 'c.inventory_location_id as location_id', 'a.state', 'a.created_at']);
        $stocked = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')
            ->where('b.organisation_id', $organisationId)->where('l.organisation_id', $organisationId)->where('l.branch_id', $branch->id)->where('l.is_active', true)
            ->groupBy('b.inventory_sku_id', 'b.inventory_location_id')->havingRaw('SUM(b.quantity) > 0')
            ->get(['b.inventory_sku_id as sku_id', 'b.inventory_location_id as location_id']);

        $pairs = [];
        foreach ($stocked as $row) {
            $pairs[$row->sku_id.':'.$row->location_id] = ['sku_id' => $row->sku_id, 'location_id' => $row->location_id, 'last' => null, 'open' => 0, 'variance' => 0, 'stocked' => true];
        }
        foreach ($cases as $case) {
            $key = $case->sku_id.':'.$case->location_id;
            $pair = $pairs[$key] ?? ['sku_id' => $case->sku_id, 'location_id' => $case->location_id, 'last' => null, 'open' => 0, 'variance' => 0, 'stocked' => false];
            if (in_array($case->state, self::OPEN, true)) {
                $pair['open']++;
            }
            if (in_array($case->state, self::VARIANCE, true)) {
                $pair['variance']++;
            }
            if (in_array($case->state, self::COMPLETED, true)) {
                $date = CarbonImmutable::parse((string) $case->created_at, 'UTC')->setTimezone($branch->timezone)->toDateString();
                $pair['last'] = $pair['last'] === null || $date > $pair['last'] ? $date : $pair['last'];
            }
            $pairs[$key] = $pair;
        }
        $skus = DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('s.organisation_id', $organisationId)->whereIn('s.id', array_column($pairs, 'sku_id'))->get(['s.id', 's.public_id', 's.sku_code', 'i.generic_name', 's.stock_unit'])->keyBy('id');
        $locations = DB::table('inventory_locations')->where('organisation_id', $organisationId)->whereIn('id', array_column($pairs, 'location_id'))->get(['id', 'public_id', 'name'])->keyBy('id');
        $rows = array_values(array_map(fn (array $pair): array => [
            'sku' => $skus[$pair['sku_id']]->public_id,
            'skuCode' => $skus[$pair['sku_id']]->sku_code,
            'item' => $skus[$pair['sku_id']]->generic_name,
            'location' => $locations[$pair['location_id']]->public_id,
            'locationLabel' => $locations[$pair['location_id']]->name,
            'lastCompletedCountDate' => $pair['last'],
            'openCases' => $pair['open'],
            'unresolvedVariances' => $pair['variance'],
            'stocked' => $pair['stocked'],
        ], $pairs));
        usort($rows, fn (array $a, array $b): int => [$a['skuCode'], $a['locationLabel']] <=> [$b['skuCode'], $b['locationLabel']]);

        return [
            'branch' => $branch->code,
            'date' => CarbonImmutable::now($branch->timezone)->toDateString(),
            'totals' => ['openCases' => array_sum(array_column($rows, 'openCases')), 'unresolvedVariances' => array_sum(array_column($rows, 'unresolvedVariances'))],
            'rows' => $rows,
        ];
    }
}
