<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * STK-16: read-only reorder suggestions for the active branch. It writes nothing.
 *
 * A row is every SKU and location that Stock review lists as low stock (usable quantity at or below a positive
 * reorder level). Suggested = reorder level − on hand − on order − incoming + average daily use × lead time,
 * floored at zero, then rounded up to whole packs and at least the preferred supplier's minimum order. All
 * arithmetic is in exact thousandths of the SKU's base stock unit, as STK-1 requires.
 */
final class ReorderSuggestionService
{
    public const LOOK_BACK_DAYS = 30;

    public const PER_PAGE = 25;

    /** Orders that are still expected to arrive. */
    private const OPEN_ORDER_STATUSES = ['approved', 'partially_received'];

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
            'need' => ['nullable', 'in:all,order'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $cost = $actor->can(SupplierTermsService::COST_PERMISSION);
        $organisationId = (int) $actor->organisation_id;

        $needle = filled($data['q'] ?? null) ? '%'.addcslashes((string) $data['q'], '%_\\').'%' : null;
        /** @var list<\stdClass> $low */
        $low = app(InventoryStockReviewService::class)->lowStockQuery($actor, $branch)
            ->when($needle, fn ($query) => $query->where(fn ($match) => $match->where('s.sku_code', 'ilike', $needle)->orWhere('i.generic_name', 'ilike', $needle)->orWhere('l.name', 'ilike', $needle)))
            ->addSelect(['s.public_id as sku_public_id', 'l.public_id as location_public_id'])
            ->orderBy('s.sku_code')->orderBy('l.name')->orderBy('l.id')
            ->get()->all();

        $locationIds = array_values(array_unique(array_map(fn (\stdClass $row): int => (int) $row->location_id, $low)));
        $skuIds = array_values(array_unique(array_map(fn (\stdClass $row): int => (int) $row->sku_id, $low)));
        $onOrder = $this->onOrder($organisationId, $locationIds, $skuIds);
        $incoming = $this->incoming($organisationId, $locationIds, $skuIds);
        $used = $this->used($organisationId, $branch->timezone, $locationIds, $skuIds);
        $terms = $this->preferredTerms($organisationId, $skuIds);

        $rows = [];
        foreach ($low as $row) {
            $key = $row->location_id.':'.$row->sku_id;
            $level = StockUnitQuantity::scaled((string) $row->reorder_level, true);
            $onHand = StockUnitQuantity::scaled((string) $row->quantity, true);
            $ordered = $onOrder[$key] ?? 0;
            $coming = $incoming[$key] ?? 0;
            $usedMilli = $used[$key] ?? 0;
            $term = $terms[(int) $row->sku_id] ?? null;
            $lead = $term === null ? 0 : (int) $term->lead_time_days;
            // Average daily use × lead time, rounded up to the next thousandth.
            $cover = intdiv($usedMilli * $lead + self::LOOK_BACK_DAYS - 1, self::LOOK_BACK_DAYS);
            $need = max(0, $level - $onHand - $ordered - $coming + $cover);

            $packs = null;
            $suggested = 0;
            $packQuantity = null;
            if ($need > 0 && $term !== null) {
                $packQuantity = StockUnitQuantity::scaled((string) $term->pack_quantity);
                $packs = max(intdiv($need + $packQuantity - 1, $packQuantity), (int) ($term->minimum_order_packs ?? 1));
                $suggested = $packs * $packQuantity;
            } elseif ($need > 0) {
                // No preferred supplier: whole stock units only.
                $suggested = intdiv($need + 999, 1000) * 1000;
            }
            if (($data['need'] ?? 'order') === 'order' && $suggested === 0) {
                continue;
            }
            $priceSen = $term?->pack_price_sen;
            $rows[] = [
                'key' => $key,
                'skuCode' => $row->sku_code,
                'skuPublicId' => $row->sku_public_id,
                // STK-18: "Request these" sends the row to a purchase request for this location.
                'locationPublicId' => $row->location_public_id,
                'itemName' => $row->item,
                'location' => $row->location,
                'stockUnit' => $row->stock_unit,
                'reorderLevel' => StockUnitQuantity::format($level),
                'onHand' => StockUnitQuantity::format($onHand),
                'onOrder' => StockUnitQuantity::format($ordered),
                'incoming' => StockUnitQuantity::format($coming),
                'averageDailyUse' => StockUnitQuantity::format(intdiv($usedMilli + self::LOOK_BACK_DAYS - 1, self::LOOK_BACK_DAYS)),
                'supplier' => $term === null ? null : [
                    'name' => $term->supplier_name,
                    'leadTimeDays' => $lead,
                    'packDescription' => $term->pack_description,
                    'packQuantity' => (string) $term->pack_quantity,
                    'minimumOrderPacks' => $term->minimum_order_packs === null ? null : (int) $term->minimum_order_packs,
                ],
                'suggestedPacks' => $packs,
                'suggestedQuantity' => StockUnitQuantity::format($suggested),
                // "Covered by orders" only when something is actually on its way; otherwise the level is simply met.
                'coveredByOrders' => $suggested === 0 && $ordered + $coming > 0,
                // Cost never leaves the server for someone without the cost permission.
                ...($cost ? ['estimateSen' => ($packs === null || $priceSen === null) ? null : $packs * (int) $priceSen] : []),
            ];
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min((int) ($data['page'] ?? 1), $lastPage);

        return [
            'branchName' => $branch->name,
            'canSeeCost' => $cost, 'canRequest' => $actor->can(PurchaseRequestService::CREATE),
            'lookBackDays' => self::LOOK_BACK_DAYS,
            'filters' => ['q' => $data['q'] ?? '', 'need' => $data['need'] ?? 'order'],
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
        ];
    }

    /** Quantity still to arrive on approved or partly received purchase orders, per destination location and SKU.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $skuIds
     * @return array<string,int>
     */
    private function onOrder(int $organisationId, array $locationIds, array $skuIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return $this->keyed(DB::table('inventory_purchase_order_lines as line')
            ->join('inventory_purchase_orders as po', fn ($join) => $join->on('po.id', '=', 'line.purchase_order_id')->on('po.organisation_id', '=', 'line.organisation_id'))
            ->where('line.organisation_id', $organisationId)
            ->whereIn('po.status', self::OPEN_ORDER_STATUSES)
            ->whereIn('po.destination_location_id', $locationIds)->whereIn('line.inventory_sku_id', $skuIds)
            ->whereColumn('line.received_quantity', '<', 'line.ordered_quantity')
            ->groupBy('po.destination_location_id', 'line.inventory_sku_id')
            ->selectRaw('po.destination_location_id as location_id, line.inventory_sku_id as sku_id, SUM(line.ordered_quantity - line.received_quantity)::text as quantity')
            ->get()->all());
    }

    /** Approved or dispatched two-stage transfers into the location that have not been received yet.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $skuIds
     * @return array<string,int>
     */
    private function incoming(int $organisationId, array $locationIds, array $skuIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return $this->keyed(DB::table('inventory_stock_request_lines as line')
            ->join('inventory_stock_requests as request', fn ($join) => $join->on('request.id', '=', 'line.stock_request_id')->on('request.organisation_id', '=', 'line.organisation_id'))
            ->where('line.organisation_id', $organisationId)
            ->whereIn('request.status', ['approved', 'dispatched'])
            ->whereIn('request.destination_location_id', $locationIds)->whereIn('line.inventory_sku_id', $skuIds)
            ->groupBy('request.destination_location_id', 'line.inventory_sku_id')
            ->selectRaw("request.destination_location_id as location_id, line.inventory_sku_id as sku_id, SUM(CASE WHEN request.status = 'dispatched' THEN COALESCE(line.dispatched_quantity, 0) ELSE line.requested_quantity END)::text as quantity")
            ->get()->all());
    }

    /** Dispensed plus unreversed treatment-room usage out of each location over the look-back window.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $skuIds
     * @return array<string,int>
     */
    private function used(int $organisationId, string $timezone, array $locationIds, array $skuIds): array
    {
        if ($locationIds === []) {
            return [];
        }
        $since = CarbonImmutable::now($timezone)->startOfDay()->subDays(self::LOOK_BACK_DAYS - 1)->utc()->format('Y-m-d H:i:sP');
        $dispensed = $this->keyed(DB::table('stock_movements as m')
            ->where('m.organisation_id', $organisationId)->where('m.movement_type', StockMovement::TYPE_DISPENSE)
            ->whereIn('m.source_location_id', $locationIds)->whereIn('m.inventory_sku_id', $skuIds)
            ->where('m.occurred_at', '>=', $since)
            ->groupBy('m.source_location_id', 'm.inventory_sku_id')
            ->selectRaw('m.source_location_id as location_id, m.inventory_sku_id as sku_id, SUM(m.quantity)::text as quantity')
            ->get()->all());
        $treatment = $this->keyed(DB::table('inventory_treatment_usages as u')
            ->leftJoin('inventory_treatment_usage_reversals as r', 'r.usage_id', '=', 'u.id')
            ->where('u.organisation_id', $organisationId)->where('u.kind', 'usage')->whereNull('r.id')
            ->whereIn('u.inventory_location_id', $locationIds)->whereIn('u.inventory_sku_id', $skuIds)
            ->where('u.created_at', '>=', $since)
            ->groupBy('u.inventory_location_id', 'u.inventory_sku_id')
            ->selectRaw('u.inventory_location_id as location_id, u.inventory_sku_id as sku_id, SUM(u.quantity)::text as quantity')
            ->get()->all());
        foreach ($treatment as $key => $quantity) {
            $dispensed[$key] = ($dispensed[$key] ?? 0) + $quantity;
        }

        return $dispensed;
    }

    /** The one active preferred supplier term per SKU, from an active supplier (STK-13).
     *
     * @param  list<int>  $skuIds
     * @return array<int,\stdClass>
     */
    private function preferredTerms(int $organisationId, array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }
        $terms = [];
        foreach (DB::table('inventory_supplier_sku_terms as t')
            ->join('inventory_suppliers as sup', fn ($join) => $join->on('sup.id', '=', 't.inventory_supplier_id')->on('sup.organisation_id', '=', 't.organisation_id'))
            ->where('t.organisation_id', $organisationId)->where('t.is_active', true)->where('t.is_preferred', true)->where('sup.is_active', true)
            ->whereIn('t.inventory_sku_id', $skuIds)
            ->get(['t.inventory_sku_id', 't.pack_description', 't.pack_quantity', 't.pack_price_sen', 't.lead_time_days', 't.minimum_order_packs', 'sup.name as supplier_name']) as $term) {
            $terms[(int) $term->inventory_sku_id] = $term;
        }

        return $terms;
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
