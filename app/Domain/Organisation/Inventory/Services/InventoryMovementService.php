<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Services\DispensaryAuthorityService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
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
            $openingBalanceExists = StockMovement::query()
                ->where('organisation_id', $lockedActor->organisation_id)
                ->where('destination_location_id', $location->id)
                ->where('inventory_sku_id', $sku->id)
                ->where('inventory_batch_id', $batch->id)
                ->where('movement_type', StockMovement::TYPE_OPENING)
                ->exists();
            if ($openingBalanceExists) {
                throw ValidationException::withMessages([
                    'opening_balance' => 'An opening balance has already been recorded for this location, SKU, and batch. Use Stock count (governed) or Write-off (governed) for a count or loss, or a stock transfer for a transfer.',
                ]);
            }
            $quantity = StockUnitQuantity::base($attributes['quantity'], $sku->stock_unit, $attributes['unit'] ?? null);
            $unitCostSen = $this->unitCostSen($attributes['unit_cost_sen'] ?? null);
            $supplier = null;
            if (filled($attributes['supplier_public_id'] ?? null)) {
                $supplier = InventorySupplier::query()->where('organisation_id', $lockedActor->organisation_id)
                    ->where('public_id', $attributes['supplier_public_id'])->where('is_active', true)
                    ->lockForUpdate()->firstOrFail();
            }
            $this->increase($lockedActor->organisation_id, $location->id, $sku->id, $batch->id, $quantity);
            $movement = $this->movement(
                $lockedActor,
                $sku,
                $batch,
                null,
                $location,
                $quantity,
                StockMovement::TYPE_OPENING,
                'inventory_opening_balance',
                (string) Str::uuid(),
                unitCostSen: $unitCostSen,
                supplierId: $supplier?->id,
            );
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
            $quantity = StockUnitQuantity::base($attributes['quantity'], $sku->stock_unit, $attributes['unit'] ?? null);
            $this->move($lockedActor->organisation_id, $source->id, $destination->id, $sku->id, $batch->id, $quantity);
            $movement = $this->movement($lockedActor, $sku, $batch, $source, $destination, $quantity, StockMovement::TYPE_TRANSFER, 'inventory_transfer', (string) Str::uuid());
            $this->audit->record('inventory.transfer', $movement, ['movement_type' => StockMovement::TYPE_TRANSFER], $lockedActor, $branch);

            return $movement;
        }, 3);
    }

    public function debitForDispense(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, string $quantity, int $allocationId, string $allocationPublicId): StockMovement
    {
        StockUnitQuantity::base($quantity, $sku->stock_unit, $sku->dispensing_unit);
        $balance = $this->balance($actor->organisation_id, $location->id, $sku->id, $batch->id);
        $affected = $this->changeBalance($balance, $quantity, false);
        if ($affected !== 1) {
            throw ValidationException::withMessages(['stock' => 'Available stock changed. Review current availability and retry.']);
        }
        $movement = $this->movement($actor, $sku, $batch, $location, null, $quantity, StockMovement::TYPE_DISPENSE, 'dispensary_allocation', $allocationPublicId, $allocationId);

        return $movement;
    }

    public function recordPurchaseReceipt(
        User $actor,
        InventoryLocation $location,
        InventorySku $sku,
        InventoryBatch $batch,
        mixed $quantity,
        string $receiptPublicId,
        mixed $unitCostSen = null,
        ?int $supplierId = null,
    ): StockMovement {
        $this->assertOperationReferences($actor, $location, $sku, $batch);
        $value = $this->quantity($quantity);
        $cost = $this->unitCostSen($unitCostSen);
        $this->increase($actor->organisation_id, $location->id, $sku->id, $batch->id, $value);

        return $this->movement(
            $actor,
            $sku,
            $batch,
            null,
            $location,
            $value,
            StockMovement::TYPE_PURCHASE_RECEIPT,
            'inventory_goods_receipt',
            $receiptPublicId,
            unitCostSen: $cost,
            supplierId: $supplierId,
        );
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
        LegacyStockPath::refuseForLocation($actor, InventoryControlService::STOCKTAKE_PERMISSION, $location);
    }

    public function recordAdjustment(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, string $direction, mixed $quantity, string $adjustmentPublicId): StockMovement
    {
        LegacyStockPath::refuseForLocation($actor, InventoryControlService::ADJUST_PERMISSION, $location);
    }

    public function recordReceiptCorrection(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, string $quantity, string $correctionPublicId): StockMovement
    {
        return $this->debitOperation($actor, $location, $sku, $batch, $quantity, StockMovement::TYPE_ADJUSTMENT_OUT, 'inventory_receipt_correction', $correctionPublicId);
    }

    /** New governed caller holds SKU, batch and balance locks inside its approval transaction. */
    public function recordGovernedChange(User $actor, InventoryLocation $location, InventorySku $sku, InventoryBatch $batch, string $quantity, string $type, string $casePublicId): StockMovement
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        abort_unless(in_array($type, [StockMovement::TYPE_STOCKTAKE_GAIN, StockMovement::TYPE_STOCKTAKE_LOSS, StockMovement::TYPE_EXPIRY_WRITEOFF, StockMovement::TYPE_STOCK_WRITEOFF, StockMovement::TYPE_ADJUSTMENT_IN, StockMovement::TYPE_ADJUSTMENT_OUT], true), 422);

        return in_array($type, [StockMovement::TYPE_STOCKTAKE_GAIN, StockMovement::TYPE_ADJUSTMENT_IN], true)
            ? $this->creditOperation($actor, $location, $sku, $batch, $quantity, $type, 'inventory_governed_case', $casePublicId)
            : $this->debitOperation($actor, $location, $sku, $batch, $quantity, $type, 'inventory_governed_case', $casePublicId);
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
        $affected = $this->changeBalance($balance, $value, false);
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
        $affected = $this->changeBalance($balance, $quantity, true);
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
        if (StockUnitQuantity::scaled($source->quantity, true) < StockUnitQuantity::scaled($quantity)) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient stock at the source location.']);
        }
        $destination = InventoryStockBalance::query()->where('organisation_id', $organisationId)->where('inventory_location_id', $destinationId)->where('inventory_sku_id', $skuId)->where('inventory_batch_id', $batchId)->lockForUpdate()->firstOrFail();
        $debited = $this->changeBalance($source, $quantity, false);
        if ($debited !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient stock at the source location.']);
        }
        if ($this->changeBalance($destination, $quantity, true) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Destination quantity would overflow or changed.']);
        }
    }

    private function changeBalance(InventoryStockBalance $balance, string $quantity, bool $increase): int
    {
        $quantity = StockUnitQuantity::base($quantity, 'base');
        $current = StockUnitQuantity::scaled($balance->quantity, true);
        $delta = StockUnitQuantity::scaled($quantity);
        $next = $increase ? $current + $delta : $current - $delta;
        if ($next < 0 || $next > StockUnitQuantity::MAX) {
            throw ValidationException::withMessages(['quantity' => 'Stock quantity would be negative or overflow.']);
        }

        return DB::table('inventory_stock_balances')->where('id', $balance->id)->where('lock_version', $balance->lock_version)->update(['quantity' => StockUnitQuantity::format($next), 'lock_version' => $balance->lock_version + 1, 'updated_at' => now()]);
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

    private function movement(
        User $actor,
        InventorySku $sku,
        InventoryBatch $batch,
        ?InventoryLocation $source,
        ?InventoryLocation $destination,
        string $quantity,
        string $type,
        string $referenceType,
        string $referencePublicId,
        ?int $allocationId = null,
        ?int $unitCostSen = null,
        ?int $supplierId = null,
    ): StockMovement {
        $movement = new StockMovement;
        $movement->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $actor->organisation_id,
            'inventory_sku_id' => $sku->id,
            'inventory_batch_id' => $batch->id,
            'source_location_id' => $source?->id,
            'destination_location_id' => $destination?->id,
            'quantity' => StockUnitQuantity::base($quantity, $sku->stock_unit),
            'unit_snapshot' => ['base_unit' => $sku->stock_unit, 'input_unit' => $sku->stock_unit, 'direction' => 'input_to_base', 'factor' => '1.000', 'precision' => 3],
            'movement_type' => $type,
            'dispensary_item_batch_allocation_id' => $allocationId,
            'reference_type' => $referenceType,
            'reference_public_id' => $referencePublicId,
            'actor_user_id' => $actor->id,
            'occurred_at' => now()->utc(),
            'unit_cost_sen' => $unitCostSen,
            'supplier_id' => $supplierId,
        ])->save();

        return $movement;
    }

    private function quantity(mixed $value): string
    {
        return StockUnitQuantity::base($value, 'base');
    }

    private function unitCostSen(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d{1,12}$/D', (string) $value)) {
            throw ValidationException::withMessages(['unit_cost_sen' => 'Unit cost must be entered as whole sen.']);
        }

        return (int) $value;
    }
}
