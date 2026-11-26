<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\HandCost;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Organisation-scoped cost evidence only: no stock valuation, sales, margin or COGS. */
final class HandCostPageService
{
    public function __construct(private BranchAccessService $branches, private InventoryStaffNames $names) {}

    public static function visible(User $actor): bool
    {
        return $actor->can(HandCostService::VIEW) && ($actor->can(HandCostService::ENTER) || $actor->can(HandCostService::CHECK));
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        abort_unless(self::visible($actor), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id && $this->branches->hasEffectiveAssignment($actor, $branch), 404);
        $data = Validator::make($query, ['q' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', Rule::in(['all', 'waiting', 'accepted', 'needs_cost'])], 'page' => ['nullable', 'integer', 'min:1']])->validate();
        $q = trim((string) ($data['q'] ?? ''));
        $state = (string) ($data['state'] ?? 'all');
        $rows = DB::table('stock_movements as m')
            ->join('inventory_skus as s', 's.id', '=', 'm.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->join('inventory_locations as l', 'l.id', '=', 'm.destination_location_id')->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('inventory_batches as batch', 'batch.id', '=', 'm.inventory_batch_id')
            ->where('m.organisation_id', $actor->organisation_id)
            ->where(function ($builder): void {
                $builder->where(function ($opening): void {
                    $opening->where('m.movement_type', StockMovement::TYPE_OPENING)->whereNull('m.cost_total_sen')->whereNull('m.unit_cost_sen');
                })->orWhere(function ($receipt): void {
                    $receipt->where('m.movement_type', StockMovement::TYPE_PURCHASE_RECEIPT)->whereNull('m.cost_total_sen')
                        ->where(fn ($source) => $source->whereNull('m.cost_source')->orWhere('m.cost_source', 'estimate'));
                });
            })
            ->select(['m.*', 's.sku_code', 's.stock_unit', 'i.generic_name', 'i.strength', 'b.name as branch_name', 'batch.batch_number'])
            ->selectSub(fn ($c) => $c->from('inventory_goods_receipts as r')->join('inventory_receipt_corrections as c', 'c.goods_receipt_id', '=', 'r.id')
                ->whereColumn('r.public_id', 'm.reference_public_id')->where('m.reference_type', 'inventory_goods_receipt')->selectRaw('count(*)'), 'reversed')
            ->when($q !== '', fn ($builder) => $builder->where(fn ($search) => $search->where('s.sku_code', 'ilike', '%'.$q.'%')->orWhere('i.generic_name', 'ilike', '%'.$q.'%')->orWhere('batch.batch_number', 'ilike', '%'.$q.'%')))
            ->when($state === 'waiting' || $state === 'accepted', fn ($builder) => $builder->whereExists(fn ($cost) => $cost->from('inventory_hand_costs as h')->whereColumn('h.stock_movement_id', 'm.id')->where('h.status', $state)))
            ->when($state === 'needs_cost', fn ($builder) => $builder->whereNotExists(fn ($cost) => $cost->from('inventory_hand_costs as h')->whereColumn('h.stock_movement_id', 'm.id')->whereIn('h.status', ['waiting', 'accepted'])))
            ->where(function ($builder): void {
                $builder->whereNotExists(fn ($c) => $c->from('inventory_goods_receipts as r')->join('inventory_receipt_corrections as c', 'c.goods_receipt_id', '=', 'r.id')
                    ->whereColumn('r.public_id', 'm.reference_public_id')->where('m.reference_type', 'inventory_goods_receipt'))
                    ->orWhereExists(fn ($cost) => $cost->from('inventory_hand_costs as h')->whereColumn('h.stock_movement_id', 'm.id'));
            })->orderByDesc('m.occurred_at')->orderByDesc('m.id')->paginate(20, ['*'], 'page', (int) ($data['page'] ?? 1));
        $history = HandCost::query()->where('organisation_id', $actor->organisation_id)->whereIn('stock_movement_id', $rows->pluck('id'))
            ->orderByDesc('id')->get();
        $name = $this->names->resolver($actor, $history->pluck('entered_by_user_id')->merge($history->pluck('decided_by_user_id')));
        $zone = (string) ($branch->timezone ?: config('app.timezone'));
        $date = fn ($value): ?string => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($zone)->format('j M Y, g:i A');
        $result = [];
        foreach ($rows as $row) {
            $costs = $history->where('stock_movement_id', $row->id);
            $waiting = $costs->firstWhere('status', 'waiting');
            $accepted = $costs->where('status', 'accepted')->sortByDesc(fn (HandCost $h): string => CarbonImmutable::parse($h->decided_at, 'UTC')->format('Y-m-d H:i:s.u').str_pad((string) $h->id, 20, '0', STR_PAD_LEFT))->first();
            $quantity = StockUnitQuantity::scaled((string) $row->quantity, true);
            $result[] = [
                'publicId' => (string) $row->public_id, 'skuCode' => (string) $row->sku_code, 'item' => trim($row->generic_name.' '.($row->strength ?? '')),
                'batch' => (string) ($row->batch_number ?? 'No batch'), 'branch' => (string) ($row->branch_name ?? 'Organisation store'),
                'date' => $date($row->occurred_at), 'quantity' => (string) $row->quantity, 'quantityScaled' => $quantity, 'unit' => (string) $row->stock_unit,
                'originalBasis' => 'Original entry: '.($row->unit_cost_sen === null ? 'No cost recorded' : ($row->cost_source === 'estimate' ? 'Estimate RM '.self::money((int) $row->unit_cost_sen).' each' : 'Pre-STK-10B cost RM '.self::money((int) $row->unit_cost_sen).' each')),
                'reversed' => (int) $row->reversed > 0, 'canEnter' => $actor->can(HandCostService::ENTER) && $waiting === null && (int) $row->reversed === 0,
                'acceptedId' => $accepted?->public_id,
                'history' => $costs->map(fn (HandCost $h): array => [
                    'publicId' => $h->public_id, 'status' => $h->status, 'totalSen' => $h->cost_total_sen, 'total' => self::money($h->cost_total_sen),
                    'perUnit' => self::perUnit($h->cost_total_sen, $quantity), 'reason' => $h->reason_code, 'note' => $h->note, 'reference' => $h->reference,
                    'enteredBy' => $name($h->entered_by_user_id), 'enteredAt' => $date($h->entered_at), 'decidedBy' => $name($h->decided_by_user_id), 'decidedAt' => $date($h->decided_at), 'decisionReason' => $h->decision_reason,
                    'selfEntered' => (int) $h->entered_by_user_id === $actor->id, 'version' => $h->lock_version,
                    'canCheck' => $actor->can(HandCostService::CHECK) && $h->status === 'waiting' && (int) $h->entered_by_user_id !== $actor->id,
                ])->values()->all(),
            ];
        }

        return ['expectedBranchId' => $branch->id, 'timezone' => $zone, 'banner' => StockValuationReportService::BANNER,
            'filters' => ['q' => $q, 'state' => $state], 'rows' => $result, 'page' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total()];
    }

    private static function money(int $sen): string
    {
        return intdiv($sen, 100).'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function perUnit(int $sen, int $quantity): string
    {
        $scaled = intdiv($sen * 100000 + intdiv($quantity, 2), $quantity);

        return intdiv($scaled, 10000).'.'.str_pad((string) ($scaled % 10000), 4, '0', STR_PAD_LEFT);
    }
}
