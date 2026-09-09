<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class InventoryDirectoryService
{
    private const PER_PAGE = 25;

    public function __construct(private BranchAccessService $branches) {}

    /**
     * @param  array{tab:string,search:?string,location:?string,status:?string,movement_type:?string,batch:?string,page:int}  $filters
     * @return array<string, mixed>
     */
    public function directory(User $actor, array $filters): array
    {
        abort_unless($actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);

        $locationId = $this->locationId($actor, $branch, $filters['location']);
        $results = match ($filters['tab']) {
            'batches' => $this->batches($actor, $branch, $filters, $locationId),
            'movements' => $this->movements($actor, $branch, $filters, $locationId),
            default => $this->stock($actor, $branch, $filters, $locationId),
        };

        return [
            'tab' => $filters['tab'],
            'branch' => $branch->name,
            'branchTimezone' => $branch->timezone,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'location' => $filters['location'] ?? '',
                'status' => $filters['status'] ?? '',
                'movementType' => $filters['movement_type'] ?? '',
                'batch' => $filters['batch'] ?? '',
            ],
            'locations' => $this->locations($actor, $branch),
            'summary' => $this->summary($actor, $branch),
            ...$results,
        ];
    }

    private function locationId(User $actor, Branch $branch, ?string $publicId): ?int
    {
        if (! $publicId) {
            return null;
        }
        $id = DB::table('inventory_locations')->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->where('public_id', $publicId)->value('id');
        abort_unless($id, 404);

        return (int) $id;
    }

    /** @return list<array{publicId:string,name:string,type:string}> */
    private function locations(User $actor, Branch $branch): array
    {
        $locations = [];
        $rows = DB::table('inventory_locations')->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->where('is_active', true)->orderBy('name')->get(['public_id', 'name', 'type']);
        foreach ($rows as $row) {
            $values = (array) $row;
            $locations[] = ['publicId' => (string) $values['public_id'], 'name' => (string) $values['name'], 'type' => $this->label((string) $values['type'])];
        }

        return $locations;
    }

    /** @param array{tab:string,search:?string,location:?string,status:?string,movement_type:?string,batch:?string,page:int} $filters
     * @return array{data:list<array<string, mixed>>,total:int,currentPage:int,lastPage:int}
     */
    private function stock(User $actor, Branch $branch, array $filters, ?int $locationId): array
    {
        $query = $this->balanceQuery($actor, $branch);
        $this->applyCommonFilters($query, $filters, $locationId);
        $results = $query->orderBy('s.sku_code')->orderBy('l.name')->orderBy('batch.expiry_date')->paginate(self::PER_PAGE, page: $filters['page']);
        $medicineNames = $this->medicineNames($actor, $this->skuIds($results->items()));
        $data = [];
        foreach ($results->items() as $row) {
            $values = (array) $row;
            $data[] = [
                'skuCode' => (string) $values['sku_code'], 'itemName' => $this->itemName($values),
                'medicineName' => $medicineNames[(int) $values['inventory_sku_id']] ?? null,
                'location' => (string) $values['location_name'], 'locationType' => $this->label((string) $values['location_type']),
                'branch' => $branch->name, 'quantity' => $this->quantity((string) $values['quantity']),
                'stockUnit' => (string) $values['stock_unit'], 'batchNumber' => (string) $values['batch_number'],
                'expiryDate' => CarbonImmutable::parse($values['expiry_date'])->format('j M Y'),
                'active' => (bool) $values['item_active'] && (bool) $values['sku_active'],
            ];
        }

        return $this->page($results, $data);
    }

    /** @param array{tab:string,search:?string,location:?string,status:?string,movement_type:?string,batch:?string,page:int} $filters
     * @return array{data:list<array<string, mixed>>,total:int,currentPage:int,lastPage:int}
     */
    private function batches(User $actor, Branch $branch, array $filters, ?int $locationId): array
    {
        $query = $this->balanceQuery($actor, $branch);
        $this->applyCommonFilters($query, $filters, $locationId);
        if ($filters['batch']) {
            $query->whereRaw('LOWER(batch.batch_number) LIKE ?', ['%'.mb_strtolower($filters['batch']).'%']);
        }
        $localDate = now()->setTimezone($branch->timezone)->toDateString();
        $results = $query->orderBy('batch.expiry_date')->orderBy('s.sku_code')->paginate(self::PER_PAGE, page: $filters['page']);
        $medicineNames = $this->medicineNames($actor, $this->skuIds($results->items()));
        $data = [];
        foreach ($results->items() as $row) {
            $values = (array) $row;
            $data[] = [
                'skuCode' => (string) $values['sku_code'], 'itemName' => $this->itemName($values),
                'medicineName' => $medicineNames[(int) $values['inventory_sku_id']] ?? null,
                'batchNumber' => (string) $values['batch_number'], 'location' => (string) $values['location_name'],
                'quantity' => $this->quantity((string) $values['quantity']), 'stockUnit' => (string) $values['stock_unit'],
                'expiryDate' => CarbonImmutable::parse($values['expiry_date'])->format('j M Y'),
                'expiryStatus' => (string) $values['expiry_date'] <= $localDate ? 'Expired' : 'Valid',
                'receivedDate' => $values['received_at'] ? CarbonImmutable::parse($values['received_at'])->format('j M Y') : null,
                'batchStatus' => $this->label((string) $values['batch_status']),
            ];
        }

        return $this->page($results, $data);
    }

    /** @param array{tab:string,search:?string,location:?string,status:?string,movement_type:?string,batch:?string,page:int} $filters
     * @return array{data:list<array<string, mixed>>,total:int,currentPage:int,lastPage:int}
     */
    private function movements(User $actor, Branch $branch, array $filters, ?int $locationId): array
    {
        $query = DB::table('stock_movements as movement')
            ->join('inventory_skus as s', fn ($join) => $join->on('s.id', '=', 'movement.inventory_sku_id')->on('s.organisation_id', '=', 'movement.organisation_id'))
            ->join('inventory_items as i', fn ($join) => $join->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 'movement.organisation_id'))
            ->join('inventory_batches as batch', fn ($join) => $join->on('batch.id', '=', 'movement.inventory_batch_id')->on('batch.organisation_id', '=', 'movement.organisation_id'))
            ->leftJoin('inventory_locations as source', fn ($join) => $join->on('source.id', '=', 'movement.source_location_id')->on('source.organisation_id', '=', 'movement.organisation_id'))
            ->leftJoin('inventory_locations as destination', fn ($join) => $join->on('destination.id', '=', 'movement.destination_location_id')->on('destination.organisation_id', '=', 'movement.organisation_id'))
            ->where('movement.organisation_id', $actor->organisation_id)
            ->where(fn (Builder $scope) => $scope->where('source.branch_id', $branch->id)->orWhere('destination.branch_id', $branch->id))
            ->select(['movement.id', 'movement.movement_type', 'movement.quantity', 'movement.reference_type', 'movement.occurred_at',
                's.id as inventory_sku_id', 's.sku_code', 's.stock_unit', 'i.generic_name', 'i.brand_name', 'i.strength', 'i.dosage_form',
                'batch.batch_number', 'source.name as source_name', 'destination.name as destination_name']);
        $this->applySearch($query, $filters['search']);
        if ($filters['movement_type']) {
            $query->where('movement.movement_type', $filters['movement_type']);
        }
        if ($filters['batch']) {
            $query->whereRaw('LOWER(batch.batch_number) LIKE ?', ['%'.mb_strtolower($filters['batch']).'%']);
        }
        if ($locationId) {
            $query->where(fn (Builder $location) => $location->where('movement.source_location_id', $locationId)->orWhere('movement.destination_location_id', $locationId));
        }
        $results = $query->orderByDesc('movement.occurred_at')->orderByDesc('movement.id')->paginate(self::PER_PAGE, page: $filters['page']);
        $medicineNames = $this->medicineNames($actor, $this->skuIds($results->items()));
        $data = [];
        foreach ($results->items() as $row) {
            $values = (array) $row;
            $type = (string) $values['movement_type'];
            $quantity = $this->quantity((string) $values['quantity']);
            $data[] = [
                'occurredAt' => CarbonImmutable::parse($values['occurred_at'])->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'type' => $type, 'typeLabel' => ['opening_balance' => 'Opening Balance', 'transfer' => 'Transfer', 'dispense' => 'Dispense'][$type] ?? $this->label($type),
                'skuCode' => (string) $values['sku_code'], 'itemName' => $this->itemName($values),
                'medicineName' => $medicineNames[(int) $values['inventory_sku_id']] ?? null,
                'quantity' => $quantity, 'quantityDisplay' => match ($type) {
                    'opening_balance' => '+'.$quantity, 'dispense' => '-'.$quantity, default => $quantity
                },
                'stockUnit' => (string) $values['stock_unit'], 'source' => $values['source_name'], 'destination' => $values['destination_name'],
                'direction' => match ($type) {
                    'opening_balance' => 'To '.$values['destination_name'], 'dispense' => 'From '.$values['source_name'], default => $values['source_name'].' → '.$values['destination_name']
                },
                'batchNumber' => (string) $values['batch_number'],
                'reference' => match ((string) $values['reference_type']) {
                    'dispensary_allocation' => 'Dispensary', 'inventory_transfer' => 'Inventory transfer', 'inventory_opening_balance' => 'Opening balance', default => 'Inventory movement'
                },
            ];
        }

        return $this->page($results, $data);
    }

    private function balanceQuery(User $actor, Branch $branch): Builder
    {
        return DB::table('inventory_stock_balances as balance')
            ->join('inventory_locations as l', fn ($join) => $join->on('l.id', '=', 'balance.inventory_location_id')->on('l.organisation_id', '=', 'balance.organisation_id'))
            ->join('inventory_skus as s', fn ($join) => $join->on('s.id', '=', 'balance.inventory_sku_id')->on('s.organisation_id', '=', 'balance.organisation_id'))
            ->join('inventory_items as i', fn ($join) => $join->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 'balance.organisation_id'))
            ->join('inventory_batches as batch', fn ($join) => $join->on('batch.id', '=', 'balance.inventory_batch_id')->on('batch.organisation_id', '=', 'balance.organisation_id'))
            ->where('balance.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)
            ->select(['balance.id', 'balance.quantity', 's.id as inventory_sku_id', 's.sku_code', 's.stock_unit', 's.is_active as sku_active',
                'i.generic_name', 'i.brand_name', 'i.strength', 'i.dosage_form', 'i.is_active as item_active',
                'l.name as location_name', 'l.type as location_type', 'batch.batch_number', 'batch.expiry_date', 'batch.received_at', 'batch.status as batch_status']);
    }

    /** @param array{tab:string,search:?string,location:?string,status:?string,movement_type:?string,batch:?string,page:int} $filters */
    private function applyCommonFilters(Builder $query, array $filters, ?int $locationId): void
    {
        $this->applySearch($query, $filters['search']);
        if ($locationId) {
            $query->where('balance.inventory_location_id', $locationId);
        }
        if ($filters['status'] === 'active') {
            $query->where('s.is_active', true)->where('i.is_active', true);
        } elseif ($filters['status'] === 'inactive') {
            $query->where(fn (Builder $inactive) => $inactive->where('s.is_active', false)->orWhere('i.is_active', false));
        }
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        if (! $search) {
            return;
        }
        $needle = '%'.mb_strtolower($search).'%';
        $query->where(function (Builder $match) use ($needle): void {
            $match->whereRaw('LOWER(s.sku_code) LIKE ?', [$needle])->orWhereRaw('LOWER(i.generic_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(i.brand_name, ?)) LIKE ?', ['', $needle])
                ->orWhereExists(function (Builder $mapped) use ($needle): void {
                    $mapped->selectRaw('1')->from('medicine_catalogue_inventory_skus as search_map')
                        ->join('medicine_catalogue_items as search_medicine', fn ($join) => $join->on('search_medicine.id', '=', 'search_map.medicine_catalogue_item_id')->on('search_medicine.organisation_id', '=', 'search_map.organisation_id'))
                        ->whereColumn('search_map.inventory_sku_id', 's.id')->whereColumn('search_map.organisation_id', 's.organisation_id')
                        ->where('search_map.is_active', true)->whereRaw('LOWER(search_medicine.display_name) LIKE ?', [$needle]);
                });
        });
    }

    /** @param array<mixed> $rows
     * @return list<int>
     */
    private function skuIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $ids[] = (int) $values['inventory_sku_id'];
        }

        return array_values(array_unique($ids));
    }

    /** @param list<int> $skuIds
     * @return array<int, string>
     */
    private function medicineNames(User $actor, array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }
        $grouped = [];
        $rows = DB::table('medicine_catalogue_inventory_skus as map')
            ->join('medicine_catalogue_items as medicine', fn ($join) => $join->on('medicine.id', '=', 'map.medicine_catalogue_item_id')->on('medicine.organisation_id', '=', 'map.organisation_id'))
            ->where('map.organisation_id', $actor->organisation_id)->where('map.is_active', true)->whereIn('map.inventory_sku_id', $skuIds)
            ->orderBy('medicine.display_name')->get(['map.inventory_sku_id', 'medicine.display_name']);
        foreach ($rows as $row) {
            $values = (array) $row;
            $grouped[(int) $values['inventory_sku_id']][] = (string) $values['display_name'];
        }

        return array_map(fn (array $names): string => implode(', ', $names), $grouped);
    }

    /** @return array{skusWithStock:int,totalBatches:int,expiredBatches:int} */
    private function summary(User $actor, Branch $branch): array
    {
        $localDate = now()->setTimezone($branch->timezone)->toDateString();
        $base = DB::table('inventory_stock_balances as balance')
            ->join('inventory_locations as l', fn ($join) => $join->on('l.id', '=', 'balance.inventory_location_id')->on('l.organisation_id', '=', 'balance.organisation_id'))
            ->join('inventory_batches as batch', fn ($join) => $join->on('batch.id', '=', 'balance.inventory_batch_id')->on('batch.organisation_id', '=', 'balance.organisation_id'))
            ->where('balance.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id);

        return [
            'skusWithStock' => (clone $base)->where('balance.quantity', '>', 0)->distinct()->count('balance.inventory_sku_id'),
            'totalBatches' => (clone $base)->distinct()->count('balance.inventory_batch_id'),
            'expiredBatches' => (clone $base)->where('balance.quantity', '>', 0)->whereDate('batch.expiry_date', '<=', $localDate)->distinct()->count('balance.inventory_batch_id'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function itemName(array $row): string
    {
        return collect([$row['generic_name'], $row['brand_name'], $row['strength'], $row['dosage_form']])->filter()->join(' · ');
    }

    /** @param LengthAwarePaginator<(int|string), mixed> $results
     * @param  list<array<string, mixed>>  $data
     * @return array{data:list<array<string, mixed>>,total:int,currentPage:int,lastPage:int}
     */
    private function page(LengthAwarePaginator $results, array $data): array
    {
        return ['data' => $data, 'total' => $results->total(), 'currentPage' => $results->currentPage(), 'lastPage' => $results->lastPage()];
    }

    private function quantity(string $quantity): string
    {
        $trimmed = str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;

        return $trimmed === '' ? '0' : $trimmed;
    }

    private function label(string $value): string
    {
        return str($value)->replace('_', ' ')->title()->toString();
    }
}
