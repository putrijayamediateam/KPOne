<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryReferenceAdministrationService
{
    public const PERMISSION = 'inventory.references.manage.organisation';

    private const SKU_IDENTITY_FIELDS = [
        'sku_code', 'pack_size', 'purchase_unit', 'stock_unit', 'dispensing_unit',
        'unit_conversion', 'batch_tracking_required', 'expiry_tracking_required',
    ];

    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function createItem(User $actor, array $attributes): InventoryItem
    {
        $this->authorize($actor);
        $values = $this->itemValues($attributes, false);

        return DB::transaction(function () use ($actor, $values): InventoryItem {
            $this->lockOrganisation($actor);
            $this->assertUnique('inventory_items', 'code', $actor->organisation_id, $values['code'], 'code', 'An Inventory Item with this code already exists.');
            $item = new InventoryItem;
            $item->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                ...$values,
                'is_active' => true,
            ])->save();
            $this->record('inventory_item.created', $item, $actor, array_keys($values));

            return $item;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateItem(User $actor, InventoryItem $item, array $attributes): InventoryItem
    {
        return $this->updateReference($actor, $item, $attributes, function (InventoryItem $locked, array $input) use ($actor): array {
            $values = $this->itemValues($input, true);
            $changes = $this->changes($locked, $values);
            if (array_key_exists('code', $changes)) {
                if (DB::table('inventory_skus')->where('organisation_id', $actor->organisation_id)->where('inventory_item_id', $locked->id)->exists()) {
                    $this->invalid('code', 'The Inventory Item code cannot change after a SKU has been created.');
                }
                $this->assertUnique('inventory_items', 'code', $actor->organisation_id, $changes['code'], 'code', 'An Inventory Item with this code already exists.', $locked->id);
            }

            return $changes;
        }, 'inventory_item.updated');
    }

    public function activateItem(User $actor, InventoryItem $item): InventoryItem
    {
        return $this->setActive($actor, $item, true, 'inventory_item.activated');
    }

    public function deactivateItem(User $actor, InventoryItem $item): InventoryItem
    {
        return $this->setActive($actor, $item, false, 'inventory_item.deactivated', function (InventoryItem $locked): void {
            if (InventorySku::query()->where('organisation_id', $locked->organisation_id)->where('inventory_item_id', $locked->id)->where('is_active', true)->exists()) {
                $this->invalid('item', 'Deactivate this Inventory Item’s active SKUs first.');
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createSku(User $actor, InventoryItem $item, array $attributes): InventorySku
    {
        $this->authorize($actor);
        $values = $this->skuValues($attributes, false);

        return DB::transaction(function () use ($actor, $item, $values): InventorySku {
            $this->lockOrganisation($actor);
            $lockedItem = $this->lockOwned($actor, $item);
            if (! $lockedItem->is_active) {
                $this->invalid('inventory_item', 'Select an active Inventory Item.');
            }
            $this->assertUnique('inventory_skus', 'sku_code', $actor->organisation_id, $values['sku_code'], 'sku_code', 'An Inventory SKU with this code already exists.');
            $sku = new InventorySku;
            $sku->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'inventory_item_id' => $lockedItem->id,
                ...$values,
                'is_active' => true,
            ])->save();
            $this->record('inventory_sku.created', $sku, $actor, ['inventory_item_id', ...array_keys($values)]);

            return $sku;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateSku(User $actor, InventorySku $sku, array $attributes): InventorySku
    {
        return $this->updateReference($actor, $sku, $attributes, function (InventorySku $locked, array $input) use ($actor): array {
            $values = $this->skuValues($input, true, $locked);
            $changes = $this->changes($locked, $values);
            if (array_intersect(array_keys($changes), self::SKU_IDENTITY_FIELDS) !== [] && $this->skuHasEvidence($locked)) {
                $this->invalid('sku', 'SKU identity and unit fields cannot change after batch, mapping, stock, or Dispensary evidence exists.');
            }
            if (array_key_exists('sku_code', $changes)) {
                $this->assertUnique('inventory_skus', 'sku_code', $actor->organisation_id, $changes['sku_code'], 'sku_code', 'An Inventory SKU with this code already exists.', $locked->id);
            }

            return $changes;
        }, 'inventory_sku.updated');
    }

    public function activateSku(User $actor, InventorySku $sku): InventorySku
    {
        return $this->setActive($actor, $sku, true, 'inventory_sku.activated', function (InventorySku $locked) use ($actor): void {
            $activeItem = InventoryItem::query()->whereKey($locked->inventory_item_id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->exists();
            if (! $activeItem) {
                $this->invalid('inventory_item', 'Activate the Inventory Item before activating its SKU.');
            }
        });
    }

    public function deactivateSku(User $actor, InventorySku $sku): InventorySku
    {
        return $this->setActive($actor, $sku, false, 'inventory_sku.deactivated', function (InventorySku $locked): void {
            if (MedicineCatalogueInventorySku::query()->where('organisation_id', $locked->organisation_id)->where('inventory_sku_id', $locked->id)->where('is_active', true)->exists()) {
                $this->invalid('sku', 'Deactivate this SKU’s active Medicine mapping first.');
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createLocation(User $actor, ?Branch $branch, ?InventoryLocation $parent, array $attributes): InventoryLocation
    {
        $this->authorize($actor);
        $values = $this->locationValues($attributes, false);

        return DB::transaction(function () use ($actor, $branch, $parent, $values): InventoryLocation {
            $this->lockOrganisation($actor);
            $lockedBranch = $branch === null ? null : $this->lockBranch($actor, $branch);
            $lockedParent = $parent === null ? null : $this->lockOwned($actor, $parent);
            $this->assertLocationHierarchy($lockedBranch, $lockedParent);
            $this->assertUnique('inventory_locations', 'code', $actor->organisation_id, $values['code'], 'code', 'An Inventory Location with this code already exists.');
            $location = new InventoryLocation;
            $location->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $lockedBranch?->id,
                'parent_id' => $lockedParent?->id,
                ...$values,
                'is_active' => true,
            ])->save();
            $this->record('inventory_location.created', $location, $actor, ['branch_id', 'parent_id', ...array_keys($values)]);

            return $location;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateLocation(User $actor, InventoryLocation $location, array $attributes): InventoryLocation
    {
        foreach (['organisation_id', 'branch_id', 'parent_id'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $this->invalid($field, 'Location ownership and hierarchy are not editable.');
            }
        }

        return $this->updateReference($actor, $location, $attributes, function (InventoryLocation $locked, array $input) use ($actor): array {
            $values = $this->locationValues($input, true);
            $changes = $this->changes($locked, $values);
            if ((array_key_exists('code', $changes) || array_key_exists('type', $changes)) && $this->locationHasEvidence($locked)) {
                $this->invalid('location', 'Location code and type cannot change after stock or Dispensary evidence exists.');
            }
            if (array_key_exists('code', $changes)) {
                $this->assertUnique('inventory_locations', 'code', $actor->organisation_id, $changes['code'], 'code', 'An Inventory Location with this code already exists.', $locked->id);
            }

            return $changes;
        }, 'inventory_location.updated');
    }

    public function activateLocation(User $actor, InventoryLocation $location): InventoryLocation
    {
        return $this->setActive($actor, $location, true, 'inventory_location.activated', function (InventoryLocation $locked) use ($actor): void {
            if ($locked->branch_id !== null) {
                $activeBranch = Branch::query()->whereKey($locked->branch_id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->exists();
                if (! $activeBranch) {
                    $this->invalid('branch', 'Activate the branch before activating this Inventory Location.');
                }
            }
            if ($locked->parent_id !== null) {
                $activeParent = InventoryLocation::query()->whereKey($locked->parent_id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->exists();
                if (! $activeParent) {
                    $this->invalid('parent', 'Activate the parent Inventory Location first.');
                }
            }
        });
    }

    public function deactivateLocation(User $actor, InventoryLocation $location): InventoryLocation
    {
        return $this->setActive($actor, $location, false, 'inventory_location.deactivated');
    }

    /** @param array<string, mixed> $attributes */
    public function createBatch(User $actor, InventorySku $sku, array $attributes): InventoryBatch
    {
        $this->authorize($actor);
        $values = $this->batchValues($attributes, false);

        return DB::transaction(function () use ($actor, $sku, $values): InventoryBatch {
            $this->lockOrganisation($actor);
            $lockedSku = $this->lockOwned($actor, $sku);
            if (! $lockedSku->is_active) {
                $this->invalid('sku', 'Select an active Inventory SKU.');
            }
            $this->assertUniqueBatch($actor->organisation_id, $lockedSku->id, $values['batch_number'], $values['expiry_date']);
            $batch = new InventoryBatch;
            $batch->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'inventory_sku_id' => $lockedSku->id,
                ...$values,
            ])->save();
            $this->record('inventory_batch.created', $batch, $actor, ['inventory_sku_id', ...array_keys($values)]);

            return $batch;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateBatch(User $actor, InventoryBatch $batch, array $attributes): InventoryBatch
    {
        return $this->updateReference($actor, $batch, $attributes, function (InventoryBatch $locked, array $input) use ($actor): array {
            $values = $this->batchValues($input, true, $locked);
            $changes = $this->changes($locked, $values);
            $identity = array_intersect(array_keys($changes), ['batch_number', 'expiry_date', 'received_at']);
            if ($identity !== [] && $this->batchHasEvidence($locked)) {
                $this->invalid('batch', 'Batch identity and dates cannot change after stock or Dispensary evidence exists.');
            }
            if ($identity !== []) {
                $this->assertUniqueBatch(
                    $actor->organisation_id,
                    $locked->inventory_sku_id,
                    $changes['batch_number'] ?? $locked->batch_number,
                    $changes['expiry_date'] ?? $this->dateString($locked->expiry_date),
                    $locked->id,
                );
            }

            return $changes;
        }, 'inventory_batch.updated');
    }

    /** @param array<string, mixed> $attributes */
    public function createMapping(User $actor, MedicineCatalogueItem $medicine, InventorySku $sku, array $attributes = []): MedicineCatalogueInventorySku
    {
        $this->authorize($actor);
        $active = $this->boolean($attributes['is_active'] ?? true, 'is_active');

        return DB::transaction(function () use ($actor, $medicine, $sku, $active): MedicineCatalogueInventorySku {
            $this->lockOrganisation($actor);
            $lockedMedicine = $this->lockOwned($actor, $medicine);
            $lockedSku = $this->lockOwned($actor, $sku);
            if (MedicineCatalogueInventorySku::query()->where('organisation_id', $actor->organisation_id)->where('medicine_catalogue_item_id', $lockedMedicine->id)->where('inventory_sku_id', $lockedSku->id)->exists()) {
                $this->invalid('mapping', 'This Medicine and Inventory SKU mapping already exists.');
            }
            if ($active) {
                $this->assertMappingCanActivate($lockedMedicine, $lockedSku);
            }
            $mapping = new MedicineCatalogueInventorySku;
            $mapping->forceFill([
                'organisation_id' => $actor->organisation_id,
                'medicine_catalogue_item_id' => $lockedMedicine->id,
                'inventory_sku_id' => $lockedSku->id,
                'is_active' => $active,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now()->utc(),
            ])->save();
            $this->recordMapping('medicine_inventory_mapping.created', $mapping, $actor, $lockedMedicine, $lockedSku, ['medicine_catalogue_item_id', 'inventory_sku_id', 'is_active']);

            return $mapping;
        }, 3);
    }

    public function activateMapping(User $actor, MedicineCatalogueInventorySku $mapping): MedicineCatalogueInventorySku
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $mapping): MedicineCatalogueInventorySku {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $mapping);
            if ($locked->is_active) {
                return $locked;
            }
            $medicine = $this->lockOwnedById($actor, new MedicineCatalogueItem, $locked->medicine_catalogue_item_id);
            $sku = $this->lockOwnedById($actor, new InventorySku, $locked->inventory_sku_id);
            $this->assertMappingCanActivate($medicine, $sku, $locked->id);
            $locked->forceFill(['is_active' => true, 'approved_by_user_id' => $actor->id, 'approved_at' => now()->utc()])->save();
            $this->recordMapping('medicine_inventory_mapping.activated', $locked, $actor, $medicine, $sku, ['is_active', 'approved_by_user_id', 'approved_at']);

            return $locked;
        }, 3);
    }

    public function deactivateMapping(User $actor, MedicineCatalogueInventorySku $mapping): MedicineCatalogueInventorySku
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $mapping): MedicineCatalogueInventorySku {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $mapping);
            if (! $locked->is_active) {
                return $locked;
            }
            $locked->forceFill(['is_active' => false])->save();
            $medicine = $this->lockOwnedById($actor, new MedicineCatalogueItem, $locked->medicine_catalogue_item_id);
            $sku = $this->lockOwnedById($actor, new InventorySku, $locked->inventory_sku_id);
            $this->recordMapping('medicine_inventory_mapping.deactivated', $locked, $actor, $medicine, $sku, ['is_active']);

            return $locked;
        }, 3);
    }

    /** @template T of Model
     * @param  T  $reference
     * @param  array<string, mixed>  $attributes
     * @param  callable(T, array<string, mixed>): array<string, mixed>  $evaluate
     * @return T
     */
    private function updateReference(User $actor, Model $reference, array $attributes, callable $evaluate, string $event): Model
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $reference, $attributes, $evaluate, $event): Model {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $reference);
            $changes = $evaluate($locked, $attributes);
            if ($changes === []) {
                return $locked;
            }
            $locked->forceFill($changes)->save();
            $this->record($event, $locked, $actor, array_keys($changes));

            return $locked;
        }, 3);
    }

    /** @template T of Model
     * @param  T  $reference
     * @param  null|callable(T): void  $before
     * @return T
     */
    private function setActive(User $actor, Model $reference, bool $active, string $event, ?callable $before = null): Model
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $reference, $active, $event, $before): Model {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $reference);
            if ((bool) $locked->getAttribute('is_active') === $active) {
                return $locked;
            }
            if ($before !== null) {
                $before($locked);
            }
            $locked->forceFill(['is_active' => $active])->save();
            $this->record($event, $locked, $actor, ['is_active']);

            return $locked;
        }, 3);
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        if (! $actor->is_active || ! $actor->organisation_id) {
            throw new AuthorizationException('You may not manage Inventory references.');
        }
    }

    private function lockOrganisation(User $actor): Organisation
    {
        return Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
    }

    /** @template T of Model
     * @param  T  $model
     * @return T
     */
    private function lockOwned(User $actor, Model $model): Model
    {
        return $this->lockOwnedById($actor, $model, $model->getKey());
    }

    /** @template T of Model
     * @param  T  $prototype
     * @return T
     */
    private function lockOwnedById(User $actor, Model $prototype, mixed $id): Model
    {
        $owned = $prototype->newQuery()->whereKey($id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
        if (! $owned instanceof $prototype) {
            throw new ModelNotFoundException;
        }

        return $owned;
    }

    private function lockBranch(User $actor, Branch $branch): Branch
    {
        return Branch::query()->whereKey($branch->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOr(function (): never {
            throw new ModelNotFoundException;
        });
    }

    private function assertLocationHierarchy(?Branch $branch, ?InventoryLocation $parent): void
    {
        if ($parent === null) {
            return;
        }
        if (! $parent->is_active || ($branch === null && $parent->branch_id !== null) || ($branch !== null && $parent->branch_id !== null && $parent->branch_id !== $branch->id)) {
            $this->invalid('parent', 'Select an active parent location from the same organisation and compatible branch scope.');
        }
    }

    private function assertMappingCanActivate(MedicineCatalogueItem $medicine, InventorySku $sku, ?int $exceptId = null): void
    {
        if (! $medicine->is_active || ! $sku->is_active) {
            $this->invalid('mapping', 'Only active Medicine and Inventory SKU references can be mapped.');
        }
        $query = MedicineCatalogueInventorySku::query()
            ->where('organisation_id', $medicine->organisation_id)
            ->where('medicine_catalogue_item_id', $medicine->id)
            ->where('is_active', true);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            $this->invalid('mapping', 'This Medicine already has an active Inventory SKU mapping. Deactivate it before activating another.');
        }
    }

    private function skuHasEvidence(InventorySku $sku): bool
    {
        foreach (['inventory_batches', 'medicine_catalogue_inventory_skus', 'inventory_stock_balances', 'stock_movements', 'dispensary_item_batch_allocations'] as $table) {
            if (DB::table($table)->where('organisation_id', $sku->organisation_id)->where('inventory_sku_id', $sku->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function locationHasEvidence(InventoryLocation $location): bool
    {
        return DB::table('inventory_stock_balances')->where('organisation_id', $location->organisation_id)->where('inventory_location_id', $location->id)->exists()
            || DB::table('stock_movements')->where('organisation_id', $location->organisation_id)->where(fn ($query) => $query->where('source_location_id', $location->id)->orWhere('destination_location_id', $location->id))->exists()
            || DB::table('dispensary_item_batch_allocations')->where('organisation_id', $location->organisation_id)->where('inventory_location_id', $location->id)->exists();
    }

    private function batchHasEvidence(InventoryBatch $batch): bool
    {
        return DB::table('inventory_stock_balances')->where('organisation_id', $batch->organisation_id)->where('inventory_batch_id', $batch->id)->exists()
            || DB::table('stock_movements')->where('organisation_id', $batch->organisation_id)->where('inventory_batch_id', $batch->id)->exists()
            || DB::table('dispensary_item_batch_allocations')->where('organisation_id', $batch->organisation_id)->where('inventory_batch_id', $batch->id)->exists();
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function itemValues(array $attributes, bool $partial): array
    {
        $rules = [
            'code' => fn (mixed $value): string => $this->code($value, 'code'),
            'generic_name' => fn (mixed $value): string => $this->text($value, 'generic_name', 300, true),
            'brand_name' => fn (mixed $value): ?string => $this->nullableText($value, 'brand_name', 300, true),
            'strength' => fn (mixed $value): ?string => $this->nullableText($value, 'strength', 100),
            'dosage_form' => fn (mixed $value): ?string => $this->nullableText($value, 'dosage_form', 100),
            'route' => fn (mixed $value): ?string => $this->nullableText($value, 'route', 100),
            'manufacturer' => fn (mixed $value): ?string => $this->nullableText($value, 'manufacturer', 200, true),
            'mal_number' => fn (mixed $value): ?string => $this->nullableText($value, 'mal_number', 100),
        ];

        return $this->values($attributes, $rules, $partial, ['code', 'generic_name']);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function skuValues(array $attributes, bool $partial, ?InventorySku $current = null): array
    {
        $rules = [
            'sku_code' => fn (mixed $value): string => $this->code($value, 'sku_code'),
            'barcode' => fn (mixed $value): ?string => $this->nullableText($value, 'barcode', 100),
            'pack_size' => fn (mixed $value): string => $this->decimal($value, 'pack_size'),
            'purchase_unit' => fn (mixed $value): string => $this->text($value, 'purchase_unit', 100),
            'stock_unit' => fn (mixed $value): string => $this->text($value, 'stock_unit', 100),
            'dispensing_unit' => fn (mixed $value): string => $this->text($value, 'dispensing_unit', 100),
            'unit_conversion' => fn (mixed $value): string => $this->decimal($value, 'unit_conversion'),
            'storage_type' => fn (mixed $value): string => $this->text($value, 'storage_type', 40),
            'minimum_temperature' => fn (mixed $value): ?string => $this->temperature($value, 'minimum_temperature'),
            'maximum_temperature' => fn (mixed $value): ?string => $this->temperature($value, 'maximum_temperature'),
            'cold_chain_required' => fn (mixed $value): bool => $this->boolean($value, 'cold_chain_required'),
            'do_not_freeze' => fn (mixed $value): bool => $this->boolean($value, 'do_not_freeze'),
            'protect_from_light' => fn (mixed $value): bool => $this->boolean($value, 'protect_from_light'),
            'batch_tracking_required' => fn (mixed $value): bool => $this->boolean($value, 'batch_tracking_required'),
            'expiry_tracking_required' => fn (mixed $value): bool => $this->boolean($value, 'expiry_tracking_required'),
        ];
        $defaults = [
            'barcode' => null,
            'storage_type' => 'ambient',
            'minimum_temperature' => null,
            'maximum_temperature' => null,
            'cold_chain_required' => false,
            'do_not_freeze' => false,
            'protect_from_light' => false,
            'batch_tracking_required' => true,
            'expiry_tracking_required' => true,
        ];
        $values = $this->values($attributes, $rules, $partial, ['sku_code', 'pack_size', 'purchase_unit', 'stock_unit', 'dispensing_unit', 'unit_conversion'], $defaults);
        $minimum = array_key_exists('minimum_temperature', $values) ? $values['minimum_temperature'] : $current?->minimum_temperature;
        $maximum = array_key_exists('maximum_temperature', $values) ? $values['maximum_temperature'] : $current?->maximum_temperature;
        if ($minimum !== null && $maximum !== null && (float) $minimum > (float) $maximum) {
            $this->invalid('maximum_temperature', 'Maximum temperature must be at least the minimum temperature.');
        }

        return $values;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function locationValues(array $attributes, bool $partial): array
    {
        $rules = [
            'code' => fn (mixed $value): string => $this->code($value, 'code'),
            'name' => fn (mixed $value): string => $this->text($value, 'name', 200, true),
            'type' => function (mixed $value): string {
                $value = $this->text($value, 'type', 40);
                if (! in_array($value, [InventoryLocation::TYPE_MEDICAL_STOCK, InventoryLocation::TYPE_BRANCH_STORE, InventoryLocation::TYPE_DISPENSARY], true)) {
                    $this->invalid('type', 'Select a valid Inventory Location type.');
                }

                return $value;
            },
        ];

        return $this->values($attributes, $rules, $partial, ['code', 'name', 'type']);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function batchValues(array $attributes, bool $partial, ?InventoryBatch $current = null): array
    {
        $rules = [
            'batch_number' => fn (mixed $value): string => $this->code($value, 'batch_number', 100),
            'expiry_date' => fn (mixed $value): string => $this->date($value, 'expiry_date'),
            'received_at' => fn (mixed $value): ?string => $value === null || $value === '' ? null : $this->date($value, 'received_at'),
            'status' => function (mixed $value): string {
                if (! is_string($value) || ! in_array($value, [InventoryBatch::STATUS_AVAILABLE, InventoryBatch::STATUS_QUARANTINED, InventoryBatch::STATUS_DAMAGED], true)) {
                    $this->invalid('status', 'Select a valid Inventory Batch status.');
                }

                return $value;
            },
        ];
        $values = $this->values($attributes, $rules, $partial, ['batch_number', 'expiry_date'], ['received_at' => null, 'status' => InventoryBatch::STATUS_AVAILABLE]);
        $received = $values['received_at'] ?? ($current === null || $current->received_at === null ? null : $this->dateString($current->received_at));
        $expiry = $values['expiry_date'] ?? ($current === null ? null : $this->dateString($current->expiry_date));
        if ($received !== null && $expiry !== null && $received > $expiry) {
            $this->invalid('received_at', 'Received date must not be after expiry date.');
        }

        return $values;
    }

    /** @param array<string, mixed> $attributes
     * @param  array<string, callable(mixed): mixed>  $rules
     * @param  list<string>  $required
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function values(array $attributes, array $rules, bool $partial, array $required, array $defaults = []): array
    {
        $values = [];
        foreach ($rules as $field => $normalize) {
            if (! array_key_exists($field, $attributes)) {
                if (! $partial && array_key_exists($field, $defaults)) {
                    $values[$field] = $defaults[$field];
                } elseif (! $partial && in_array($field, $required, true)) {
                    $this->invalid($field, "Enter a valid {$field}.");
                }

                continue;
            }
            $values[$field] = $normalize($attributes[$field]);
        }

        return $values;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function changes(Model $model, array $values): array
    {
        return array_filter($values, function (mixed $value, string $field) use ($model): bool {
            $current = $model->getAttribute($field);
            if ($current instanceof \DateTimeInterface && is_string($value)) {
                return $current->format('Y-m-d') !== $value;
            }

            return (string) $current !== (string) $value;
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function assertUnique(string $table, string $column, int $organisationId, string $value, string $field, string $message, ?int $exceptId = null): void
    {
        $caseInsensitiveColumn = match ($column) {
            'code' => 'LOWER(code)',
            'sku_code' => 'LOWER(sku_code)',
            default => throw new \LogicException('Unsupported case-insensitive identity column.'),
        };
        $query = DB::table($table)->where('organisation_id', $organisationId)->whereRaw($caseInsensitiveColumn.' = ?', [Str::lower($value)]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            $this->invalid($field, $message);
        }
    }

    private function assertUniqueBatch(int $organisationId, int $skuId, string $number, string $expiry, ?int $exceptId = null): void
    {
        $query = DB::table('inventory_batches')->where('organisation_id', $organisationId)->where('inventory_sku_id', $skuId)->whereRaw('LOWER(batch_number) = ?', [Str::lower($number)])->whereDate('expiry_date', $expiry);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            $this->invalid('batch_number', 'This Inventory SKU already has the same batch number and expiry date.');
        }
    }

    private function text(mixed $value, string $field, int $maximum, bool $collapse = false): string
    {
        if (! is_string($value)) {
            $this->invalid($field, "Enter a valid {$field}.");
        }
        $value = trim($value);
        if ($collapse) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        }
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\p{C}]/u', $value) === 1) {
            $this->invalid($field, "Enter a readable {$field} of {$maximum} characters or fewer.");
        }

        return $value;
    }

    private function nullableText(mixed $value, string $field, int $maximum, bool $collapse = false): ?string
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : $this->text($value, $field, $maximum, $collapse);
    }

    private function code(mixed $value, string $field, int $maximum = 64): string
    {
        return Str::upper($this->text($value, $field, $maximum));
    }

    private function decimal(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $value) !== 1 || (float) $value <= 0) {
            $this->invalid($field, 'Enter a positive value with at most three decimal places.');
        }

        return number_format((float) $value, 3, '.', '');
    }

    private function temperature(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/^-?\d{1,3}(?:\.\d{1,2})?$/D', $value) !== 1 || (float) $value < -999.99 || (float) $value > 999.99) {
            $this->invalid($field, 'Enter a valid temperature with at most two decimal places.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            $this->invalid($field, "{$field} must be true or false.");
        }

        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $this->invalid($field, "Enter a valid {$field} date.");
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $value) {
            $this->invalid($field, "Enter a valid {$field} date.");
        }

        return $value;
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d');
    }

    /** @param list<string> $changedFields */
    private function record(string $event, Model $subject, User $actor, array $changedFields): void
    {
        $this->audit->record($event, $subject, [
            'reference_id' => $subject->getAttribute('public_id'),
            'changed_fields' => $changedFields,
        ], $actor, organisationId: $actor->organisation_id);
    }

    /** @param list<string> $changedFields */
    private function recordMapping(string $event, MedicineCatalogueInventorySku $mapping, User $actor, MedicineCatalogueItem $medicine, InventorySku $sku, array $changedFields): void
    {
        $this->audit->record($event, $mapping, [
            'medicine_public_id' => $medicine->public_id,
            'inventory_sku_public_id' => $sku->public_id,
            'changed_fields' => $changedFields,
        ], $actor, organisationId: $actor->organisation_id);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
