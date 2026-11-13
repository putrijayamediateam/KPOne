<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Read-only, bounded alternatives to shipping whole reference tables to Inventory. */
final class InventoryPickerService
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public const KINDS = ['items', 'skus', 'locations', 'medicines', 'branches', 'suppliers', 'batches'];

    /** @param array<string,mixed> $input
     * @return array{data:list<array<string,mixed>>,limit:int}
     */
    public function search(User $actor, array $input): array
    {
        $branch = app(BranchAccessService::class)->activeBranch($actor);
        abort_unless($actor->is_active && $actor->can('inventory.view.branch'), 403);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $data = Validator::make($input + ['context' => 'references', 'q' => '', 'limit' => self::DEFAULT_LIMIT, 'sku' => null, 'location' => null], [
            'expected_branch_id' => ['required', 'integer', 'in:'.$branch->id],
            'kind' => ['required', Rule::in(self::KINDS)], 'context' => ['required', Rule::in(['references', 'operations', 'directory'])],
            'q' => ['nullable', 'string', 'max:100'], 'limit' => ['required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT], 'sku' => ['nullable', 'uuid'],
            'location' => ['nullable', 'uuid'],
        ])->validate();

        return ['data' => $this->choices($actor, $branch, $data['kind'], $data['context'], (string) ($data['q'] ?? ''), (int) $data['limit'], $data['sku'], $data['location']), 'limit' => (int) $data['limit']];
    }

    /** Caller has already authorised Inventory and selected the effective branch.
     * @return list<array<string,mixed>>
     */
    public function choices(User $actor, Branch $branch, string $kind, string $context = 'references', string $search = '', int $limit = self::DEFAULT_LIMIT, ?string $sku = null, ?string $location = null): array
    {
        abort_unless($actor->is_active && $actor->can('inventory.view.branch') && $actor->organisation_id === $branch->organisation_id, 403);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $table = match ($kind) {
            'items' => 'inventory_items', 'skus' => 'inventory_skus', 'locations' => 'inventory_locations', 'medicines' => 'medicine_catalogue_items',
            'branches' => 'branches', 'suppliers' => 'inventory_suppliers', 'batches' => 'inventory_batches',
            default => abort(404),
        };
        $query = DB::table($table.' as choice')->where('choice.organisation_id', $actor->organisation_id);
        if (! in_array($kind, ['suppliers', 'batches'], true)) {
            $query->where('choice.is_active', true);
        }
        if ($kind === 'locations') {
            $query->where(function (Builder $q) use ($actor, $branch, $context): void {
                $q->where('choice.branch_id', $branch->id);
                if ($context === 'references' || ($context === 'operations' && $actor->can(ProcurementService::WAREHOUSE_PERMISSION))) {
                    $q->orWhereNull('choice.branch_id');
                }
            });
        }
        if ($kind === 'skus' && $context === 'operations') {
            $query->join('inventory_items as item', fn ($join) => $join->on('item.id', '=', 'choice.inventory_item_id')->on('item.organisation_id', '=', 'choice.organisation_id'))->where('item.is_active', true);
        }
        // V2 page 2d (Daniel): Stock setup names the item and its stock unit beside each SKU. A left join, so every SKU the
        // references lists offered before is still offered, whatever its item's status.
        if ($kind === 'skus' && $context === 'references') {
            $query->leftJoin('inventory_items as item', fn ($join) => $join->on('item.id', '=', 'choice.inventory_item_id')->on('item.organisation_id', '=', 'choice.organisation_id'));
        }
        if ($kind === 'batches') {
            $query = DB::query()->fromSub(app(InventoryBatchEligibility::class)->query($actor->organisation_id, $branch), 'choice')
                ->join('inventory_skus as sku', 'sku.id', '=', 'choice.inventory_sku_id')
                ->where(fn ($q) => $q->whereNull('choice.eligibility_reason')->orWhereNotNull('choice.hold_reason'));
            if ($sku !== null) {
                $query->where('sku.public_id', $sku);
            }
            // V2 page 2b: how much of each batch sits at one location of this branch (or the organisation store), so a
            // person dispatching can see it before choosing. Batches stay listed and ordered as before.
            if ($location !== null) {
                $locationId = DB::table('inventory_locations')->where('organisation_id', $actor->organisation_id)->where('public_id', $location)->where('is_active', true)
                    // The same rule as the locations list: the organisation store only for references or warehouse holders.
                    ->where(function (Builder $q) use ($actor, $branch, $context): void {
                        $q->where('branch_id', $branch->id);
                        if ($context === 'references' || ($context === 'operations' && $actor->can(ProcurementService::WAREHOUSE_PERMISSION))) {
                            $q->orWhereNull('branch_id');
                        }
                    })->value('id');
                abort_unless($locationId !== null, 404);
                $query->leftJoin('inventory_stock_balances as here', fn ($join) => $join->on('here.inventory_batch_id', '=', 'choice.id')->where('here.inventory_location_id', (int) $locationId)->where('here.organisation_id', $actor->organisation_id));
            }
        }
        [$columns, $order] = match ($kind) {
            'items' => [['choice.public_id as publicId', 'choice.code', 'choice.generic_name as genericName'], 'choice.generic_name'],
            'skus' => [$context === 'operations' ? ['choice.public_id as publicId', 'choice.sku_code as code', 'choice.stock_unit as stockUnit', 'item.generic_name as name'] : ['choice.public_id as publicId', 'choice.sku_code as skuCode', 'choice.inventory_item_id as inventoryItemId', 'choice.stock_unit as stockUnit', 'item.generic_name as itemName'], 'choice.sku_code'],
            'locations' => [$context === 'operations' ? ['choice.public_id as publicId', 'choice.name', 'choice.type', 'choice.branch_id as branchId'] : ['choice.public_id as publicId', 'choice.name', 'choice.type'], 'choice.name'],
            'medicines' => [['choice.public_id as publicId', 'choice.code', 'choice.display_name as displayName'], 'choice.display_name'],
            'branches' => [['choice.id', 'choice.name'], 'choice.name'],
            'suppliers' => [['choice.public_id as publicId', 'choice.code', 'choice.name', 'choice.is_active as isActive'], 'choice.name'],
            'batches' => [['choice.public_id as publicId', 'sku.public_id as skuPublicId', 'choice.batch_number as batchNumber', 'choice.expiry_date as expiryDate', 'choice.eligibility_reason', 'choice.hold_reason'], 'choice.batch_number'],
        };
        if (trim($search) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
            $query->where(function (Builder $q) use ($order, $kind, $context, $like): void {
                $q->whereRaw($order." ILIKE ? ESCAPE '\\'", [$like]);
                if (in_array($kind, ['items', 'medicines', 'suppliers'], true)) {
                    $q->orWhereRaw("choice.code ILIKE ? ESCAPE '\\'", [$like]);
                }
                // Owner, 2026-11-13 ("fix stock search"): an operations SKU search also matches the item's name, so
                // typing "Paracetamol" finds its SKUs. The item is already joined for this context; same escaping. Stock setup's
                // references search (V2 page 2d) matches the item's name the same way.
                if ($kind === 'skus' && in_array($context, ['operations', 'references'], true)) {
                    $q->orWhereRaw("item.generic_name ILIKE ? ESCAPE '\\'", [$like]);
                }
            });
        }
        if ($kind === 'batches') {
            $query->orderBy('choice.expiry_date');
            if ($location !== null) {
                $columns[] = DB::raw('COALESCE(here.quantity, 0) as "onHand"');
            }
        }
        $collation = DB::getDriverName() === 'pgsql' ? '"C"' : 'BINARY';

        return array_values($query->orderByRaw($order.' COLLATE '.$collation)->orderBy('choice.id')->limit($limit)->get($columns)->map(function ($row): array {
            $values = get_object_vars($row);
            $held = ($values['hold_reason'] ?? null) !== null;
            unset($values['eligibility_reason'], $values['hold_reason']);

            return [...$values, ...($held ? ['held' => true, 'holdReason' => $row->hold_reason] : [])];
        })->all());
    }
}
