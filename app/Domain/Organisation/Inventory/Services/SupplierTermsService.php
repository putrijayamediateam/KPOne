<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\InventorySupplierSkuTerm;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * STK-13: the supplier price list and lead time per SKU. Managed with the existing supplier permission; the pack
 * price is inventory cost, so only holders of the cost permission may see or set it (owner decision 2026-11-11).
 */
final class SupplierTermsService
{
    public const PERMISSION = 'inventory.suppliers.manage.organisation';

    public const COST_PERMISSION = 'inventory.cost.view.organisation';

    public function __construct(
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): InventorySupplierSkuTerm
    {
        $cost = $this->canSeeCost($actor);
        $data = $this->validate($cost, $input, [
            'supplier_public_id' => ['required', 'uuid'],
            'sku_public_id' => ['required', 'uuid'],
        ]);
        $hash = $this->hash($actor, 'create', $data);

        return DB::transaction(function () use ($actor, $data, $hash, $cost): InventorySupplierSkuTerm {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            $cost = $this->confirmCost($actor, $cost);
            if ($replayed = $this->replay($actor, $branchId, $data['idempotency_key'], 'create', $hash)) {
                return $replayed;
            }
            $supplier = InventorySupplier::query()->where('organisation_id', $actor->organisation_id)
                ->where('public_id', $data['supplier_public_id'])->lockForUpdate()->first();
            $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)
                ->where('public_id', $data['sku_public_id'])->lockForUpdate()->first();
            if (! $supplier?->is_active) {
                throw ValidationException::withMessages(['supplier_public_id' => 'Choose an active supplier.']);
            }
            if (! $sku?->is_active) {
                throw ValidationException::withMessages(['sku_public_id' => 'Choose an active SKU.']);
            }
            if (InventorySupplierSkuTerm::query()->where('organisation_id', $actor->organisation_id)
                ->where('inventory_supplier_id', $supplier->id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['sku_public_id' => 'This supplier already has a price list row for this SKU. Edit that row instead.']);
            }
            if ($data['is_preferred']) {
                $this->clearPreferred($actor, $sku->id, null);
            }
            $term = new InventorySupplierSkuTerm;
            $term->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                'inventory_supplier_id' => $supplier->id, 'inventory_sku_id' => $sku->id,
                ...$this->terms($data, null, $cost), 'is_active' => true, 'lock_version' => 1,
            ])->save();
            $this->revision($actor, $term, 'created');
            $this->remember($actor, $branchId, $term, $data['idempotency_key'], 'create', $hash);
            $this->audit->record('inventory.supplier_terms.created', $term, $this->auditFields($term, ['pack_description', 'pack_quantity', 'pack_price', 'lead_time_days', 'minimum_order_packs', 'is_preferred']), $actor);

            return $term;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, string $publicId, array $input): InventorySupplierSkuTerm
    {
        $cost = $this->canSeeCost($actor);
        $data = $this->validate($cost, $input, [
            'lock_version' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);
        $hash = $this->hash($actor, 'update:'.$publicId, $data);

        return DB::transaction(function () use ($actor, $publicId, $data, $hash, $cost): InventorySupplierSkuTerm {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            $cost = $this->confirmCost($actor, $cost);
            if ($replayed = $this->replay($actor, $branchId, $data['idempotency_key'], 'update', $hash)) {
                return $replayed;
            }
            $term = InventorySupplierSkuTerm::query()->where('organisation_id', $actor->organisation_id)
                ->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($term->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This price list row changed. Reload and try again.']);
            }
            $active = (bool) $data['is_active'];
            if ($data['is_preferred'] && ! $active) {
                throw ValidationException::withMessages(['is_preferred' => 'An inactive row cannot be the preferred supplier.']);
            }
            if ($active && ! $term->is_active) {
                $this->assertActiveReferences($term);
            }
            $before = $term->only(['pack_description', 'pack_quantity', 'pack_price_sen', 'lead_time_days', 'minimum_order_packs', 'is_preferred', 'is_active']);
            $after = [...$this->terms($data, $term, $cost), 'is_active' => $active];
            $changed = array_keys(array_filter($after, fn ($value, $key) => (string) $value !== (string) $before[$key], ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                // Nothing to save: no new version, history row or audit entry.
                return $term;
            }
            if ($data['is_preferred'] && ! $term->is_preferred) {
                $this->clearPreferred($actor, $term->inventory_sku_id, $term->id);
            }
            $term->forceFill([...$after, 'lock_version' => $term->lock_version + 1])->save();
            $action = $before['is_active'] && ! $active ? 'deactivated' : (! $before['is_active'] && $active ? 'activated' : 'updated');
            $this->revision($actor, $term, $action);
            $this->remember($actor, $branchId, $term, $data['idempotency_key'], 'update', $hash);
            $this->audit->record('inventory.supplier_terms.'.$action, $term, $this->auditFields($term, array_map(fn (string $field) => $field === 'pack_price_sen' ? 'pack_price' : $field, $changed)), $actor);

            return $term;
        }, 3);
    }

    /**
     * The price list page: anyone who may view inventory sees pack sizes and lead times; only cost holders see prices.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
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
            'status' => ['nullable', 'in:active,inactive'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $cost = $this->canSeeCost($actor);
        $needle = filled($data['q'] ?? null) ? '%'.addcslashes((string) $data['q'], '%_\\').'%' : null;
        $rows = DB::table('inventory_supplier_sku_terms as t')
            ->join('inventory_suppliers as sup', fn ($join) => $join->on('sup.id', '=', 't.inventory_supplier_id')->on('sup.organisation_id', '=', 't.organisation_id'))
            ->join('inventory_skus as s', fn ($join) => $join->on('s.id', '=', 't.inventory_sku_id')->on('s.organisation_id', '=', 't.organisation_id'))
            ->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('t.organisation_id', $actor->organisation_id)
            ->when(($data['status'] ?? 'active') === 'active', fn ($query) => $query->where('t.is_active', true))
            ->when(($data['status'] ?? null) === 'inactive', fn ($query) => $query->where('t.is_active', false))
            ->when($needle, fn ($query) => $query->where(fn ($match) => $match->where('s.sku_code', 'ilike', $needle)
                ->orWhere('i.generic_name', 'ilike', $needle)->orWhere('sup.name', 'ilike', $needle)))
            ->orderBy('s.sku_code')->orderByDesc('t.is_preferred')->orderBy('sup.name')->orderBy('t.id')
            ->paginate(25, ['t.public_id', 't.pack_description', 't.pack_quantity', 't.pack_price_sen', 't.lead_time_days', 't.minimum_order_packs',
                't.is_preferred', 't.is_active', 't.lock_version', 'sup.name as supplier_name', 'sup.public_id as supplier_public_id',
                's.sku_code', 's.public_id as sku_public_id', 's.stock_unit', 'i.generic_name as item_name'], 'page', (int) ($data['page'] ?? 1));

        /** @var list<\stdClass> $items */
        $items = $rows->items();

        return [
            'branchName' => $branch->name,
            'expectedBranchId' => $branch->id,
            'canManage' => $actor->can(self::PERMISSION),
            'canSeeCost' => $cost,
            'filters' => ['q' => $data['q'] ?? '', 'status' => $data['status'] ?? 'active'],
            'rows' => array_map(fn (\stdClass $row): array => [
                'publicId' => $row->public_id, 'supplier' => $row->supplier_name, 'supplierPublicId' => $row->supplier_public_id,
                'skuCode' => $row->sku_code, 'skuPublicId' => $row->sku_public_id, 'itemName' => $row->item_name, 'stockUnit' => $row->stock_unit,
                'packDescription' => $row->pack_description, 'packQuantity' => (string) $row->pack_quantity,
                // Cost never leaves the server for someone without the cost permission.
                ...($cost ? ['packPriceSen' => $row->pack_price_sen === null ? null : (int) $row->pack_price_sen] : []),
                'leadTimeDays' => (int) $row->lead_time_days, 'minimumOrderPacks' => $row->minimum_order_packs === null ? null : (int) $row->minimum_order_packs,
                'preferred' => (bool) $row->is_preferred, 'active' => (bool) $row->is_active, 'lockVersion' => (int) $row->lock_version,
            ], $items),
            'page' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total(),
        ];
    }

    public function canSeeCost(User $actor): bool
    {
        return $actor->is_active && $actor->can(self::COST_PERMISSION);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validate(bool $cost, array $input, array $extra): array
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'pack_description' => ['required', 'string', 'max:60', 'regex:/\S/'],
            'pack_quantity' => ['required', 'string', 'max:20'],
            'pack_price_sen' => $cost ? ['nullable', 'integer', 'min:0', 'max:999999999999'] : ['prohibited'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'minimum_order_packs' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_preferred' => ['required', 'boolean'],
            'organisation_id' => ['prohibited'], 'inventory_supplier_id' => ['prohibited'], 'inventory_sku_id' => ['prohibited'],
            ...$extra,
        ], [
            'pack_price_sen.prohibited' => 'Only a Director or Finance Officer may set a supplier price.',
            'pack_description.regex' => 'Describe the pack, for example "Box of 100".',
        ])->validate();
        try {
            StockUnitQuantity::scaled($data['pack_quantity']);
        } catch (ValidationException $e) {
            // StockUnitQuantity files its message under "quantity"; show it beside the pack field.
            throw ValidationException::withMessages(['pack_quantity' => collect($e->errors())->flatten()->first() ?? 'Enter the stock units in one pack.']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function terms(array $data, ?InventorySupplierSkuTerm $existing, bool $cost): array
    {
        $packQuantity = StockUnitQuantity::format(StockUnitQuantity::scaled($data['pack_quantity']));

        return [
            'pack_description' => trim((string) $data['pack_description']),
            'pack_quantity' => $packQuantity,
            // Without the cost permission the price is neither shown nor changed.
            // Only an explicit value (or an explicit null) from a cost holder changes the price.
            'pack_price_sen' => $cost && array_key_exists('pack_price_sen', $data) ? $data['pack_price_sen'] : $existing?->pack_price_sen,
            'lead_time_days' => (int) $data['lead_time_days'],
            'minimum_order_packs' => isset($data['minimum_order_packs']) ? (int) $data['minimum_order_packs'] : null,
            'is_preferred' => (bool) $data['is_preferred'],
        ];
    }

    private function clearPreferred(User $actor, int $skuId, ?int $exceptId): void
    {
        $others = InventorySupplierSkuTerm::query()->where('organisation_id', $actor->organisation_id)
            ->where('inventory_sku_id', $skuId)->where('is_preferred', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->orderBy('id')->lockForUpdate()->get();
        foreach ($others as $other) {
            $other->forceFill(['is_preferred' => false, 'lock_version' => $other->lock_version + 1])->save();
            $this->revision($actor, $other, 'updated');
            $this->audit->record('inventory.supplier_terms.updated', $other, $this->auditFields($other, ['is_preferred']), $actor);
        }
    }

    private function revision(User $actor, InventorySupplierSkuTerm $term, string $action): void
    {
        DB::table('inventory_supplier_sku_term_revisions')->insert([
            'organisation_id' => $term->organisation_id, 'inventory_supplier_sku_term_id' => $term->id,
            'revision' => $term->lock_version, 'actor_user_id' => $actor->id, 'action' => $action,
            ...$term->only(['pack_description', 'pack_quantity', 'pack_price_sen', 'lead_time_days', 'minimum_order_packs', 'is_preferred', 'is_active']),
            'created_at' => now()->utc(),
        ]);
    }

    /**
     * Ids, version and field names only: never the price.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function auditFields(InventorySupplierSkuTerm $term, array $fields): array
    {
        return ['supplier_id' => $term->inventory_supplier_id, 'sku_id' => $term->inventory_sku_id, 'lock_version' => $term->lock_version, 'fields' => array_values($fields)];
    }

    /** @param array<string, mixed> $data */
    private function hash(User $actor, string $operation, array $data): string
    {
        unset($data['idempotency_key']);
        ksort($data);

        return hash('sha256', json_encode([$operation, $actor->id, $data], JSON_THROW_ON_ERROR));
    }

    /** The cost permission is checked on the request and again on the locked actor; both must agree. */
    private function confirmCost(User $locked, bool $requested): bool
    {
        $now = $this->canSeeCost($locked);
        if ($requested && ! $now) {
            throw ValidationException::withMessages(['pack_price_sen' => 'Only a Director or Finance Officer may set a supplier price.']);
        }

        // A permission gained after the request was checked is not used: the request was validated without it.
        return $requested;
    }

    private function assertActiveReferences(InventorySupplierSkuTerm $term): void
    {
        $supplier = InventorySupplier::query()->whereKey($term->inventory_supplier_id)->where('organisation_id', $term->organisation_id)->lockForUpdate()->first();
        $sku = InventorySku::query()->whereKey($term->inventory_sku_id)->where('organisation_id', $term->organisation_id)->lockForUpdate()->first();
        if (! $supplier?->is_active) {
            throw ValidationException::withMessages(['is_active' => 'Activate the supplier before this row.']);
        }
        if (! $sku?->is_active) {
            throw ValidationException::withMessages(['is_active' => 'Activate the SKU before this row.']);
        }
    }

    /** @return array{0: User, 1: int} */
    private function context(User $actor, mixed $expectedBranch): array
    {
        $branch = $this->authority->branch($actor, $expectedBranch);
        // Serialise scoped idempotency keys across actors, including simultaneous first use.
        Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $actor = $this->authority->lock($actor, $branch, self::PERMISSION);

        return [$actor, $branch->id];
    }

    private function replay(User $actor, int $branchId, string $key, string $operation, string $hash): ?InventorySupplierSkuTerm
    {
        $command = DB::table('inventory_supplier_sku_term_commands')->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($command === null) {
            return null;
        }
        if ((int) $command->branch_id !== $branchId || $command->operation !== $operation || $command->payload_hash !== $hash) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different details.']);
        }

        return InventorySupplierSkuTerm::query()->whereKey($command->inventory_supplier_sku_term_id)
            ->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
    }

    private function remember(User $actor, int $branchId, InventorySupplierSkuTerm $term, string $key, string $operation, string $hash): void
    {
        DB::table('inventory_supplier_sku_term_commands')->insert([
            'organisation_id' => $actor->organisation_id, 'branch_id' => $branchId, 'inventory_supplier_sku_term_id' => $term->id,
            'idempotency_key' => $key, 'operation' => $operation, 'payload_hash' => $hash, 'created_at' => now()->utc(),
        ]);
    }
}
