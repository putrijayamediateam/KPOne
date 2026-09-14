<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Services\DispensaryAuthorityService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryMovementService
{
    public function __construct(private DispensaryAuthorityService $authority, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $attributes */
    public function openingBalance(User $actor, array $attributes): StockMovement
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $attributes, $branch): StockMovement {
            $lockedActor = $this->authority->lock($actor, $branch, 'inventory.opening_balance.branch');
            if (! $lockedActor->hasRole('ca_supervisor')) {
                throw new AuthorizationException('Opening Balance requires CA Supervisor authority.');
            }
            [$location, $sku, $batch] = $this->lockReferences($lockedActor, $attributes['location_public_id'], $attributes['sku_public_id'], $attributes['batch_public_id']);
            abort_unless($location->branch_id === $branch->id, 404);
            $quantity = $this->quantity($attributes['quantity']);
            $this->increase($lockedActor->organisation_id, $location->id, $sku->id, $batch->id, $quantity);
            $movement = $this->movement($lockedActor, $sku, $batch, null, $location, $quantity, StockMovement::TYPE_OPENING, 'inventory_opening_balance', (string) Str::uuid());
            $this->audit->record('inventory.opening_balance', $movement, ['movement_type' => StockMovement::TYPE_OPENING], $lockedActor, $branch);

            return $movement;
        }, 3);
    }

    /** @param array<string,mixed> $attributes */
    public function transfer(User $actor, array $attributes): StockMovement
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $attributes, $branch): StockMovement {
            $lockedActor = $this->authority->lock($actor, $branch, 'inventory.transfer.branch');
            $locations = InventoryLocation::query()
                ->where('organisation_id', $lockedActor->organisation_id)
                ->whereIn('public_id', [$attributes['source_location_public_id'], $attributes['destination_location_public_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $source = $locations->firstWhere('public_id', $attributes['source_location_public_id']);
            $destination = $locations->firstWhere('public_id', $attributes['destination_location_public_id']);
            abort_unless($locations->count() === 2 && $source && $destination && $source->id !== $destination->id, 404);
            abort_unless($source->is_active && $destination->is_active, 404);
            $organisationTransfer = $source->branch_id === null || $destination->branch_id === null;
            if ($organisationTransfer && (! $lockedActor->hasRole('ca_supervisor') || ! $lockedActor->can('inventory.transfer.organisation'))) {
                throw new AuthorizationException('Organisation stock transfer requires CA Supervisor authority.');
            }
            if (! $organisationTransfer) {
                abort_unless($source->branch_id === $branch->id && $destination->branch_id === $branch->id, 404);
            }
            [$sku, $batch] = $this->lockSkuAndBatch($lockedActor, $attributes['sku_public_id'], $attributes['batch_public_id']);
            $quantity = $this->quantity($attributes['quantity']);
            $this->move($lockedActor->organisation_id, $source->id, $destination->id, $sku->id, $batch->id, $quantity);
            $movement = $this->movement($lockedActor, $sku, $batch, $source, $destination, $quantity, StockMovement::TYPE_TRANSFER, 'inventory_transfer', (string) Str::uuid());
            $this->audit->record('inventory.transfer', $movement, ['movement_type' => StockMovement::TYPE_TRANSFER], $lockedActor, $branch);

            return $movement;
        }, 3);
    }

    public function debitForDispense(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, string $quantity, int $allocationId, string $allocationPublicId): StockMovement
    {
        $balance = $this->balance($actor->organisation_id, $location->id, $sku->id, $batch->id);
        $affected = DB::table('inventory_stock_balances')->where('id', $balance->id)->where('lock_version', $balance->lock_version)->where('quantity', '>=', $quantity)->decrement('quantity', (float) $quantity, ['lock_version' => $balance->lock_version + 1, 'updated_at' => now()]);
        if ($affected !== 1) {
            throw ValidationException::withMessages(['stock' => 'Available stock changed. Review current availability and retry.']);
        }
        $movement = $this->movement($actor, $sku, $batch, $location, null, $quantity, StockMovement::TYPE_DISPENSE, 'dispensary_allocation', $allocationPublicId, $allocationId);

        return $movement;
    }

    public function recordPurchaseReceipt(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, mixed $quantity, string $receiptPublicId): StockMovement
    {
        return $this->creditOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_PURCHASE_RECEIPT, 'inventory_goods_receipt', $receiptPublicId);
    }

    public function recordTransferDispatch(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, mixed $quantity, string $requestPublicId): StockMovement
    {
        return $this->debitOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_TRANSFER_DISPATCH, 'inventory_stock_request', $requestPublicId);
    }

    public function recordTransferReceipt(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, mixed $quantity, string $requestPublicId): StockMovement
    {
        return $this->creditOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_TRANSFER_RECEIPT, 'inventory_stock_request', $requestPublicId);
    }

    public function recordStocktakeVariance(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, mixed $variance, string $stocktakePublicId): ?StockMovement
    {
        $quantity = number_format(abs((float) $variance), 3, '.', '');
        if ((float) $quantity === 0.0) {
            return null;
        }

        return (float) $variance > 0
            ? $this->creditOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_STOCKTAKE_GAIN, 'inventory_stocktake', $stocktakePublicId)
            : $this->debitOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_STOCKTAKE_LOSS, 'inventory_stocktake', $stocktakePublicId);
    }

    public function recordAdjustment(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, string $direction, mixed $quantity, string $adjustmentPublicId): StockMovement
    {
        return $direction === 'in'
            ? $this->creditOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_ADJUSTMENT_IN, 'inventory_adjustment', $adjustmentPublicId)
            : $this->debitOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_ADJUSTMENT_OUT, 'inventory_adjustment', $adjustmentPublicId);
    }

    private function creditOperation(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, mixed $quantity, string $type, string $referenceType, string $referencePublicId): StockMovement
    {
        $this->assertOperationReferences($actor, $location, $sku, $batch);
        $value = $this->quantity($quantity);
        $this->increase($actor->organisation_id, $location->id, $sku->id, $batch->id, $value);

        return $this->movement($actor, $sku, $batch, null, $location, $value, $type, $referenceType, $referencePublicId);
    }

    private function debitOperation(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, mixed $quantity, string $type, string $referenceType, string $referencePublicId): StockMovement
    {
        $this->assertOperationReferences($actor, $location, $sku, $batch);
        $value = $this->quantity($quantity);
        $balance = $this->balance($actor->organisation_id, $location->id, $sku->id, $batch->id);
        $affected = DB::table('inventory_stock_balances')->where('id', $balance->id)->where('lock_version', $balance->lock_version)->where('quantity', '>=', $value)
            ->decrement('quantity', (float) $value, ['lock_version' => $balance->lock_version + 1, 'updated_at' => now()]);
        if ($affected !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient stock at the source location.']);
        }

        return $this->movement($actor, $sku, $batch, $location, null, $value, $type, $referenceType, $referencePublicId);
    }

    private function assertOperationReferences(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch): void
    {
        abort_unless($location->organisation_id === $actor->organisation_id && $location->is_active, 404);
        abort_unless($sku->organisation_id === $actor->organisation_id && $sku->is_active, 404);
        abort_unless($batch->organisation_id === $actor->organisation_id && $batch->inventory_sku_id === $sku->id, 404);
    }

    private function increase(int $organisationId, int $locationId, int $skuId, int $batchId, string $quantity): void
    {
        $balance = $this->balance($organisationId, $locationId, $skuId, $batchId);
        $affected = DB::table('inventory_stock_balances')->where('id', $balance->id)->where('lock_version', $balance->lock_version)->increment('quantity', (float) $quantity, ['lock_version' => $balance->lock_version + 1, 'updated_at' => now()]);
        if ($affected !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Stock changed while the movement was being posted. Review and retry.']);
        }
    }

    private function move(int $organisationId, int $sourceId, int $destinationId, int $skuId, int $batchId, string $quantity): void
    {
        $ids = [$sourceId, $destinationId];
        sort($ids);
        foreach ($ids as $id) {
            $this->balance($organisationId, $id, $skuId, $batchId);
        }
        $source = InventoryStockBalance::query()->where('organisation_id', $organisationId)->where('inventory_location_id', $sourceId)->where('inventory_sku_id', $skuId)->where('inventory_batch_id', $batchId)->lockForUpdate()->firstOrFail();
        if ((float) $source->quantity < (float) $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient stock at the source location.']);
        }
        $destination = InventoryStockBalance::query()->where('organisation_id', $organisationId)->where('inventory_location_id', $destinationId)->where('inventory_sku_id', $skuId)->where('inventory_batch_id', $batchId)->lockForUpdate()->firstOrFail();
        $debited = DB::table('inventory_stock_balances')->where('id', $source->id)->where('lock_version', $source->lock_version)->where('quantity', '>=', $quantity)->decrement('quantity', (float) $quantity, ['lock_version' => $source->lock_version + 1, 'updated_at' => now()]);
        if ($debited !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient stock at the source location.']);
        }
        DB::table('inventory_stock_balances')->where('id', $destination->id)->where('lock_version', $destination->lock_version)->increment('quantity', (float) $quantity, ['lock_version' => $destination->lock_version + 1, 'updated_at' => now()]);
    }

    private function balance(int $organisationId, int $locationId, int $skuId, int $batchId): InventoryStockBalance
    {
        DB::table('inventory_stock_balances')->insertOrIgnore(['organisation_id' => $organisationId, 'inventory_location_id' => $locationId, 'inventory_sku_id' => $skuId, 'inventory_batch_id' => $batchId, 'quantity' => 0, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return InventoryStockBalance::query()->where('organisation_id', $organisationId)->where('inventory_location_id', $locationId)->where('inventory_sku_id', $skuId)->where('inventory_batch_id', $batchId)->lockForUpdate()->firstOrFail();
    }

    /** @return array{InventoryLocation,InventorySku,InventoryBatch} */
    private function lockReferences(User $actor, string $locationPublicId, string $skuPublicId, string $batchPublicId): array
    {
        $location = InventoryLocation::query()->where('public_id', $locationPublicId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        [$sku, $batch] = $this->lockSkuAndBatch($actor, $skuPublicId, $batchPublicId);

        return [$location, $sku, $batch];
    }

    /** @return array{InventorySku,InventoryBatch} */
    private function lockSkuAndBatch(User $actor, string $skuPublicId, string $batchPublicId): array
    {
        $sku = InventorySku::query()->where('public_id', $skuPublicId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $batch = InventoryBatch::query()->where('public_id', $batchPublicId)->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();

        return [$sku, $batch];
    }

    private function movement(User $actor, InventorySku $sku, InventoryBatch $batch, ?InventoryLocation $source, ?InventoryLocation $destination, string $quantity, string $type, string $referenceType, string $referencePublicId, ?int $allocationId = null): StockMovement
    {
        $movement = new StockMovement;
        $movement->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id, 'source_location_id' => $source?->id, 'destination_location_id' => $destination?->id, 'quantity' => $quantity, 'movement_type' => $type, 'dispensary_item_batch_allocation_id' => $allocationId, 'reference_type' => $referenceType, 'reference_public_id' => $referencePublicId, 'actor_user_id' => $actor->id, 'occurred_at' => now()->utc()])->save();

        return $movement;
    }

    private function quantity(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', $value) !== 1 || (float) $value <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be positive with at most three decimal places.']);
        }

        return number_format((float) $value, 3, '.', '');
    }
}
