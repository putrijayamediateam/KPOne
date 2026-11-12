<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * STK-31 (approved by B.E.R.N.I.S.S for the owner): read-only suggestions to send surplus stock from the active branch
 * to another branch of the same organisation that is at or below its reorder level. It writes nothing; a person checks a
 * suggestion and sends it as an ordinary stock request.
 *
 * Their need = their target − on hand − on order − incoming (approved or dispatched, plus requests still waiting), where the target is the maximum when set (STK-30) and
 * otherwise the reorder level. Our surplus at a location = usable on hand − our own target there (0 when no level is set)
 * − what that location is already due to send on requests not yet dispatched. Suggested = the smaller of the two, in
 * whole stock units, from the one location of ours with the most surplus. Only usable stock (STK-2 eligibility) counts,
 * and patient returns locations are never used. All arithmetic is in exact thousandths (STK-1).
 */
final class BranchReplenishmentService
{
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
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $organisationId = (int) $actor->organisation_id;
        $needle = filled($data['q'] ?? null) ? mb_strtolower((string) $data['q']) : null;

        // Low-stock rows at every other active branch, with each branch's own eligibility (holds are per branch).
        $needs = [];
        $others = Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->whereKeyNot($branch->id)->orderBy('name')->get();
        foreach ($others as $other) {
            foreach (app(InventoryStockReviewService::class)->lowStockQuery($actor, $other)
                ->addSelect(['s.public_id as sku_public_id', 'l.public_id as location_public_id', 'r.max_level'])
                ->orderBy('s.sku_code')->orderBy('l.name')->orderBy('l.id')->get() as $row) {
                $needs[] = [$other, $row];
            }
        }
        $locationIds = array_values(array_unique(array_map(fn (array $need): int => (int) $need[1]->location_id, $needs)));
        $skuIds = array_values(array_unique(array_map(fn (array $need): int => (int) $need[1]->sku_id, $needs)));
        $reorder = app(ReorderSuggestionService::class);
        $onOrder = $reorder->onOrder($organisationId, $locationIds, $skuIds);
        $incoming = $reorder->incoming($organisationId, $locationIds, $skuIds);
        // Requests already raised for them but not yet approved: STK-16 does not count these as incoming, but here they
        // must come off their need, or the same shortage is suggested again (Mei Ling).
        $requested = $this->requestedFor($organisationId, $locationIds, $skuIds);
        $surplus = $this->surplus($actor, $branch, $skuIds);

        $rows = [];
        /** @var array<string, int> $used what earlier suggestions already take from a location, keyed "sku:location" */
        $used = [];
        // By reference: an arrow function would copy $used once and never see later suggestions.
        $left = function (int $skuId, int $locationId) use ($surplus, &$used): int {
            return $surplus[$skuId][$locationId]['surplus'] - ($used[$skuId.':'.$locationId] ?? 0);
        };
        foreach ($needs as [$other, $row]) {
            $key = $row->location_id.':'.$row->sku_id;
            $level = StockUnitQuantity::scaled((string) $row->reorder_level, true);
            $max = $row->max_level === null ? null : StockUnitQuantity::scaled((string) $row->max_level, true);
            $onHand = StockUnitQuantity::scaled((string) $row->quantity, true);
            $need = max(0, ($max ?? $level) - $onHand - ($onOrder[$key] ?? 0) - ($incoming[$key] ?? 0) - ($requested[$key] ?? 0));
            // Whole stock units: their need rounds up, our surplus rounds down.
            $need = intdiv($need + 999, 1000) * 1000;
            if ($need === 0) {
                continue;
            }
            // The one location of ours with the most surplus of this SKU; ties go to the lower location id.
            $source = null;
            foreach (array_keys($surplus[(int) $row->sku_id] ?? []) as $locationId) {
                if ($source === null || $left((int) $row->sku_id, $locationId) > $left((int) $row->sku_id, $source)) {
                    $source = $locationId;
                }
            }
            if ($source === null) {
                continue;
            }
            $from = $surplus[(int) $row->sku_id][$source];
            $suggested = min($need, intdiv(max(0, $left((int) $row->sku_id, $source)), 1000) * 1000);
            if ($suggested === 0) {
                continue;
            }
            // Later rows of the same SKU see what this suggestion already uses.
            $earlier = $used[$row->sku_id.':'.$source] ?? 0;
            $used[$row->sku_id.':'.$source] = $earlier + $suggested;
            if ($needle !== null && ! str_contains(mb_strtolower($row->sku_code.' '.$row->item.' '.$other->name.' '.$row->location.' '.$from['name']), $needle)) {
                continue;
            }
            $rows[] = [
                'key' => $key.':'.$source,
                'skuPublicId' => $row->sku_public_id,
                'skuCode' => $row->sku_code,
                'itemName' => $row->item,
                'stockUnit' => $row->stock_unit,
                'to' => ['branch' => $other->name, 'location' => $row->location, 'locationPublicId' => $row->location_public_id,
                    'onHand' => StockUnitQuantity::format($onHand), 'reorderLevel' => StockUnitQuantity::format($level),
                    'maxLevel' => $max === null ? null : StockUnitQuantity::format($max),
                    'onOrder' => StockUnitQuantity::format($onOrder[$key] ?? 0), 'incoming' => StockUnitQuantity::format(($incoming[$key] ?? 0) + ($requested[$key] ?? 0)),
                    'need' => StockUnitQuantity::format($need)],
                'from' => ['location' => $from['name'], 'locationPublicId' => $from['publicId'], 'usable' => StockUnitQuantity::format($from['usable']),
                    'target' => StockUnitQuantity::format($from['target']), 'sending' => StockUnitQuantity::format($from['sending']),
                    // What earlier suggestions on this page already take from the same location and SKU.
                    'alreadySuggested' => StockUnitQuantity::format($earlier),
                    // The most a request may send of this SKU from this location (the server refuses more).
                    'spare' => StockUnitQuantity::format(intdiv($from['surplus'], 1000) * 1000)],
                'suggestedQuantity' => StockUnitQuantity::format($suggested),
            ];
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min((int) ($data['page'] ?? 1), $lastPage);

        return [
            'branchName' => $branch->name,
            'expectedBranchId' => $branch->id,
            'canRequest' => $actor->can(StockRequestService::CREATE_PERMISSION),
            'filters' => ['q' => $data['q'] ?? ''],
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
        ];
    }

    /**
     * Daniel's cap: what one of our locations can spare of each SKU (usable − our own target − already waiting to be
     * sent), 0 when nothing. StockRequestService refuses a cross-branch line above it.
     *
     * @param  list<int>  $skuIds
     * @return array<int, int> spare in thousandths, keyed by SKU id
     */
    public function spareAt(User $actor, Branch $branch, int $locationId, array $skuIds): array
    {
        $spare = array_fill_keys($skuIds, 0);
        foreach ($this->surplus($actor, $branch, $skuIds) as $skuId => $locations) {
            if (isset($locations[$locationId])) {
                $spare[$skuId] = $locations[$locationId]['surplus'];
            }
        }

        return $spare;
    }

    /**
     * Lines of requests still waiting for approval, per destination location and SKU.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $skuIds
     * @return array<string, int>
     */
    private function requestedFor(int $organisationId, array $locationIds, array $skuIds): array
    {
        if ($locationIds === []) {
            return [];
        }
        $requested = [];
        foreach (DB::table('inventory_stock_request_lines as line')
            ->join('inventory_stock_requests as request', fn ($j) => $j->on('request.id', '=', 'line.stock_request_id')->on('request.organisation_id', '=', 'line.organisation_id'))
            ->where('line.organisation_id', $organisationId)->where('request.status', 'requested')
            ->whereIn('request.destination_location_id', $locationIds)->whereIn('line.inventory_sku_id', $skuIds)
            ->groupBy('request.destination_location_id', 'line.inventory_sku_id')
            ->selectRaw('request.destination_location_id as location_id, line.inventory_sku_id as sku_id, SUM(line.requested_quantity)::text as quantity')->get() as $row) {
            $requested[$row->location_id.':'.$row->sku_id] = StockUnitQuantity::scaled((string) $row->quantity, true);
        }

        return $requested;
    }

    /**
     * Usable stock above our own target, per SKU and active non-returns location at the active branch, less what each
     * location is already due to send on requests that are requested or approved but not yet dispatched.
     *
     * @param  list<int>  $skuIds
     * @return array<int, array<int, array{name: string, publicId: string, usable: int, target: int, sending: int, surplus: int}>>
     */
    private function surplus(User $actor, Branch $branch, array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }
        $organisationId = (int) $actor->organisation_id;
        $usable = DB::table('inventory_stock_balances as balance')
            ->joinSub(app(InventoryBatchEligibility::class)->query($organisationId, $branch), 'e', fn ($j) => $j->on('e.id', '=', 'balance.inventory_batch_id')->on('e.organisation_id', '=', 'balance.organisation_id'))
            ->join('inventory_locations as l', fn ($j) => $j->on('l.id', '=', 'balance.inventory_location_id')->on('l.organisation_id', '=', 'balance.organisation_id'))
            ->where('balance.organisation_id', $organisationId)->whereIn('balance.inventory_sku_id', $skuIds)
            ->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)
            ->whereNull('e.eligibility_reason')
            ->groupBy('l.id', 'l.name', 'l.public_id', 'balance.inventory_sku_id')->orderBy('l.id')
            ->selectRaw('l.id as location_id, l.name, l.public_id, balance.inventory_sku_id as sku_id, SUM(balance.quantity)::text as quantity')
            ->get();
        if ($usable->isEmpty()) {
            return [];
        }
        $locationIds = $usable->pluck('location_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $targets = [];
        foreach (DB::table('inventory_reorder_levels')->where('organisation_id', $organisationId)->whereIn('inventory_location_id', $locationIds)->whereIn('inventory_sku_id', $skuIds)
            ->get(['inventory_location_id', 'inventory_sku_id', 'reorder_level', 'max_level']) as $level) {
            $targets[$level->inventory_location_id.':'.$level->inventory_sku_id] = StockUnitQuantity::scaled((string) ($level->max_level ?? $level->reorder_level), true);
        }
        $sending = [];
        foreach (DB::table('inventory_stock_request_lines as line')
            ->join('inventory_stock_requests as request', fn ($j) => $j->on('request.id', '=', 'line.stock_request_id')->on('request.organisation_id', '=', 'line.organisation_id'))
            ->where('line.organisation_id', $organisationId)->whereIn('request.status', ['requested', 'approved'])
            ->whereIn('request.source_location_id', $locationIds)->whereIn('line.inventory_sku_id', $skuIds)
            ->groupBy('request.source_location_id', 'line.inventory_sku_id')
            ->selectRaw('request.source_location_id as location_id, line.inventory_sku_id as sku_id, SUM(line.requested_quantity)::text as quantity')->get() as $row) {
            $sending[$row->location_id.':'.$row->sku_id] = StockUnitQuantity::scaled((string) $row->quantity, true);
        }

        $surplus = [];
        foreach ($usable as $row) {
            $key = $row->location_id.':'.$row->sku_id;
            $quantity = StockUnitQuantity::scaled((string) $row->quantity, true);
            $target = $targets[$key] ?? 0;
            $out = $sending[$key] ?? 0;
            $left = $quantity - $target - $out;
            if ($left > 0) {
                $surplus[(int) $row->sku_id][(int) $row->location_id] = ['name' => (string) $row->name, 'publicId' => (string) $row->public_id,
                    'usable' => $quantity, 'target' => $target, 'sending' => $out, 'surplus' => $left];
            }
        }

        return $surplus;
    }
}
