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
        $data = Validator::make($input + ['context' => 'references', 'q' => '', 'limit' => self::DEFAULT_LIMIT, 'sku' => null], [
            'expected_branch_id' => ['required', 'integer', 'in:'.$branch->id],
            'kind' => ['required', Rule::in(self::KINDS)], 'context' => ['required', Rule::in(['references', 'operations', 'directory'])],
            'q' => ['nullable', 'string', 'max:100'], 'limit' => ['required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT], 'sku' => ['nullable', 'uuid'],
        ])->validate();

        return ['data' => $this->choices($actor, $branch, $data['kind'], $data['context'], (string) ($data['q'] ?? ''), (int) $data['limit'], $data['sku']), 'limit' => (int) $data['limit']];
    }

    /** Caller has already authorised Inventory and selected the effective branch.
     * @return list<array<string,mixed>>
     */
    public function choices(User $actor, Branch $branch, string $kind, string $context = 'references', string $search = '', int $limit = self::DEFAULT_LIMIT, ?string $sku = null): array
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
        if ($kind === 'batches') {
            $query->join('inventory_skus as sku', fn ($join) => $join->on('sku.id', '=', 'choice.inventory_sku_id')->on('sku.organisation_id', '=', 'choice.organisation_id'))
                ->join('inventory_items as item', fn ($join) => $join->on('item.id', '=', 'sku.inventory_item_id')->on('item.organisation_id', '=', 'choice.organisation_id'))
                ->where('sku.is_active', true)->where('item.is_active', true)->where('choice.status', 'available')
                ->whereDate('choice.expiry_date', '>', now()->setTimezone($branch->timezone)->toDateString());
            if ($sku !== null) {
                $query->where('sku.public_id', $sku);
            }
        }
        [$columns, $order] = match ($kind) {
            'items' => [['choice.public_id as publicId', 'choice.code', 'choice.generic_name as genericName'], 'choice.generic_name'],
            'skus' => [$context === 'operations' ? ['choice.public_id as publicId', 'choice.sku_code as code', 'choice.stock_unit as stockUnit', 'item.generic_name as name'] : ['choice.public_id as publicId', 'choice.sku_code as skuCode', 'choice.inventory_item_id as inventoryItemId'], 'choice.sku_code'],
            'locations' => [$context === 'operations' ? ['choice.public_id as publicId', 'choice.name', 'choice.type', 'choice.branch_id as branchId'] : ['choice.public_id as publicId', 'choice.name', 'choice.type'], 'choice.name'],
            'medicines' => [['choice.public_id as publicId', 'choice.code', 'choice.display_name as displayName'], 'choice.display_name'],
            'branches' => [['choice.id', 'choice.name'], 'choice.name'],
            'suppliers' => [['choice.public_id as publicId', 'choice.code', 'choice.name', 'choice.is_active as isActive'], 'choice.name'],
            'batches' => [['choice.public_id as publicId', 'sku.public_id as skuPublicId', 'choice.batch_number as batchNumber', 'choice.expiry_date as expiryDate'], 'choice.batch_number'],
        };
        if (trim($search) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
            $query->where(function (Builder $q) use ($order, $kind, $like): void {
                $q->whereRaw($order." ILIKE ? ESCAPE '\\'", [$like]);
                if (in_array($kind, ['items', 'medicines', 'suppliers'], true)) {
                    $q->orWhereRaw("choice.code ILIKE ? ESCAPE '\\'", [$like]);
                }
            });
        }
        if ($kind === 'batches') {
            $query->orderBy('choice.expiry_date');
        }
        $collation = DB::getDriverName() === 'pgsql' ? '"C"' : 'BINARY';

        return array_values($query->orderByRaw($order.' COLLATE '.$collation)->orderBy('choice.id')->limit($limit)->get($columns)->map(fn ($row): array => get_object_vars($row))->all());
    }
}
