<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\GoodsReceipt;
use App\Domain\Organisation\Inventory\Models\GoodsReceiptLine;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\PurchaseOrderLine;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcurementService
{
    public const CREATE_PERMISSION = 'inventory.purchase_orders.create.organisation';

    public const APPROVE_PERMISSION = 'inventory.purchase_orders.approve.organisation';

    public const RECEIVE_PERMISSION = 'inventory.receiving.branch';

    public const WAREHOUSE_PERMISSION = 'inventory.warehouse.organisation';

    public function __construct(
        private InventoryAuthorityService $authority,
        private InventoryMovementService $movements,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): PurchaseOrder
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $lines = $this->validateLines($attributes['lines'] ?? null);

        return DB::transaction(function () use ($actor, $attributes, $branch, $lines): PurchaseOrder {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::CREATE_PERMISSION);
            [$supplier, $destination] = $this->lockSupplierAndLocation($lockedActor, (string) ($attributes['supplier_public_id'] ?? ''), (string) ($attributes['destination_location_public_id'] ?? ''));
            $this->assertLocationAuthority($lockedActor, $branch, $destination);
            $skus = $this->lockSkus($lockedActor, array_column($lines, 'sku_public_id'));
            $order = new PurchaseOrder;
            $order->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id,
                'supplier_id' => $supplier->id, 'destination_location_id' => $destination->id,
                'order_number' => 'PO-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(8)),
                'status' => PurchaseOrder::STATUS_DRAFT, 'lock_version' => 1, 'created_by_user_id' => $lockedActor->id,
            ])->save();
            $this->replaceDraftLines($order, $lines, $skus);
            $this->audit->record('inventory.purchase_order.created', $order, ['order_number' => $order->order_number, 'line_count' => count($lines)], $lockedActor, $branch);

            return $order->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateDraft(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $lines = $this->validateLines($attributes['lines'] ?? null);

        return DB::transaction(function () use ($actor, $order, $attributes, $branch, $lines): PurchaseOrder {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::CREATE_PERMISSION);
            $locked = $this->lockOrder($lockedActor, $order, $attributes);
            $this->requireStatus($locked, [PurchaseOrder::STATUS_DRAFT]);
            [$supplier, $destination] = $this->lockSupplierAndLocation($lockedActor, (string) ($attributes['supplier_public_id'] ?? ''), (string) ($attributes['destination_location_public_id'] ?? ''));
            $this->assertLocationAuthority($lockedActor, $branch, $destination);
            $skus = $this->lockSkus($lockedActor, array_column($lines, 'sku_public_id'));
            $locked->forceFill(['supplier_id' => $supplier->id, 'destination_location_id' => $destination->id, 'lock_version' => $locked->lock_version + 1])->save();
            DB::table('inventory_purchase_order_lines')->where('purchase_order_id', $locked->id)->delete();
            $this->replaceDraftLines($locked, $lines, $skus);
            $this->audit->record('inventory.purchase_order.draft_updated', $locked, ['line_count' => count($lines)], $lockedActor, $branch);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function submit(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        return $this->transition($actor, $order, $attributes, self::CREATE_PERMISSION, PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SUBMITTED, 'submitted', function (PurchaseOrder $locked, User $lockedActor): array {
            $supplier = InventorySupplier::query()->whereKey($locked->supplier_id)->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->first();
            $destination = InventoryLocation::query()->whereKey($locked->destination_location_id)->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->first();
            if (! $supplier || ! $destination || ! $locked->lines()->where('ordered_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'The Purchase Order is not ready for submission.']);
            }

            return ['submitted_by_user_id' => $lockedActor->id, 'submitted_at' => now()->utc()];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function approve(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        return $this->transition($actor, $order, $attributes, self::APPROVE_PERMISSION, PurchaseOrder::STATUS_SUBMITTED, PurchaseOrder::STATUS_APPROVED, 'approved', function (PurchaseOrder $locked, User $lockedActor): array {
            if ($locked->created_by_user_id === $lockedActor->id) {
                throw new AuthorizationException('Purchase Order approval requires a different authorized staff member.');
            }

            return ['approved_by_user_id' => $lockedActor->id, 'approved_at' => now()->utc()];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function cancel(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $order, $attributes, $branch): PurchaseOrder {
            $permission = $actor->can(self::APPROVE_PERMISSION) ? self::APPROVE_PERMISSION : self::CREATE_PERMISSION;
            $lockedActor = $this->authority->lockForBranch($actor, $branch, $permission);
            $locked = $this->lockOrder($lockedActor, $order, $attributes);
            $this->requireStatus($locked, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SUBMITTED, PurchaseOrder::STATUS_APPROVED]);
            if ($locked->status === PurchaseOrder::STATUS_APPROVED && ! $lockedActor->can(self::APPROVE_PERMISSION)) {
                throw new AuthorizationException('Cancelling an approved Purchase Order requires approval authority.');
            }
            if ($locked->lines()->where('received_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'A Purchase Order with received stock cannot be cancelled.']);
            }
            $reason = trim((string) ($attributes['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 300) {
                throw ValidationException::withMessages(['reason' => 'A cancellation reason is required and may not exceed 300 characters.']);
            }
            $locked->forceFill(['status' => PurchaseOrder::STATUS_CANCELLED, 'cancelled_by_user_id' => $lockedActor->id, 'cancelled_at' => now()->utc(), 'cancellation_reason' => $reason, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.purchase_order.cancelled', $locked, ['reason' => $reason], $lockedActor, $branch);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function close(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        return $this->transition($actor, $order, $attributes, self::APPROVE_PERMISSION, PurchaseOrder::STATUS_FULLY_RECEIVED, PurchaseOrder::STATUS_CLOSED, 'closed', fn (PurchaseOrder $locked, User $lockedActor): array => ['closed_by_user_id' => $lockedActor->id, 'closed_at' => now()->utc()]);
    }

    /** @param array<string, mixed> $attributes */
    public function receive(User $actor, PurchaseOrder $order, array $attributes): GoodsReceipt
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $key = Str::lower((string) ($attributes['idempotency_key'] ?? ''));
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid receipt idempotency key is required.']);
        }
        $inputLines = $this->validateReceiptLines($attributes['lines'] ?? null);
        $hash = hash('sha256', json_encode($inputLines, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $order, $attributes, $branch, $key, $inputLines, $hash): GoodsReceipt {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::RECEIVE_PERMISSION);
            $locked = $this->lockOrder($lockedActor, $order, $attributes, false);
            $destination = InventoryLocation::query()->whereKey($locked->destination_location_id)->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->assertLocationAuthority($lockedActor, $branch, $destination);
            $existing = GoodsReceipt::query()->where('organisation_id', $lockedActor->organisation_id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $this->matchingReceiptOrFail($existing, $locked, $hash);
            }
            $this->requireCurrentVersion($locked, $attributes);
            $this->requireStatus($locked, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED]);
            $orderLines = PurchaseOrderLine::query()->where('purchase_order_id', $locked->id)->where('organisation_id', $lockedActor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            $plans = [];
            foreach ($inputLines as $input) {
                $line = $orderLines->get($input['line_public_id']);
                if (! $line) {
                    abort(404);
                }
                $remaining = (float) $line->ordered_quantity - (float) $line->received_quantity;
                if ((float) $input['quantity'] > $remaining + 0.0001) {
                    throw ValidationException::withMessages(['lines' => 'A received quantity exceeds the remaining Purchase Order quantity.']);
                }
                $plans[] = ['line' => $line, 'input' => $input];
            }
            usort($plans, fn (array $left, array $right): int => $left['line']->inventory_sku_id <=> $right['line']->inventory_sku_id);
            $skus = InventorySku::query()->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->whereIn('id', array_map(fn (array $plan): int => $plan['line']->inventory_sku_id, $plans))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($skus->count() === count($plans), 404);
            $publicId = (string) Str::uuid();
            $timestamp = now()->utc();
            $inserted = DB::table('inventory_goods_receipts')->insertOrIgnore([
                'public_id' => $publicId,
                'organisation_id' => $lockedActor->organisation_id,
                'purchase_order_id' => $locked->id,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'received_by_user_id' => $lockedActor->id,
                'received_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            if ($inserted === 0) {
                $existing = GoodsReceipt::query()->where('organisation_id', $lockedActor->organisation_id)
                    ->where('idempotency_key', $key)->lockForUpdate()->firstOrFail();

                return $this->matchingReceiptOrFail($existing, $locked, $hash);
            }
            $receipt = GoodsReceipt::query()->where('public_id', $publicId)->where('organisation_id', $lockedActor->organisation_id)->firstOrFail();

            foreach ($plans as $plan) {
                $line = $plan['line'];
                $input = $plan['input'];
                $sku = $skus->get($line->inventory_sku_id);
                abort_unless($sku !== null, 404);
                $batch = $this->resolveBatch($lockedActor, $branch, $sku, $input);
                $receiptLine = new GoodsReceiptLine;
                $receiptLine->forceFill(['organisation_id' => $lockedActor->organisation_id, 'goods_receipt_id' => $receipt->id, 'purchase_order_line_id' => $line->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id, 'quantity' => $input['quantity']])->save();
                $this->movements->recordPurchaseReceipt($lockedActor, $destination, $sku, $batch, $input['quantity'], $receipt->public_id);
                $line->forceFill(['received_quantity' => number_format((float) $line->received_quantity + (float) $input['quantity'], 3, '.', '')])->save();
            }
            $hasRemaining = $locked->lines()->whereColumn('received_quantity', '<', 'ordered_quantity')->exists();
            $locked->forceFill(['status' => $hasRemaining ? PurchaseOrder::STATUS_PARTIALLY_RECEIVED : PurchaseOrder::STATUS_FULLY_RECEIVED, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.goods_receipt.posted', $receipt, ['purchase_order_public_id' => $locked->public_id, 'line_count' => count($inputLines)], $lockedActor, $branch);

            return $receipt->load('lines');
        }, 3);
    }

    private function matchingReceiptOrFail(GoodsReceipt $receipt, PurchaseOrder $order, string $requestHash): GoodsReceipt
    {
        if ($receipt->purchase_order_id !== $order->id || ! hash_equals($receipt->request_hash, $requestHash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for another receipt.']);
        }

        return $receipt->load('lines');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  callable(PurchaseOrder, User):array<string, mixed>  $extra
     */
    private function transition(User $actor, PurchaseOrder $order, array $attributes, string $permission, string $from, string $to, string $event, callable $extra): PurchaseOrder
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $order, $attributes, $permission, $from, $to, $event, $extra, $branch): PurchaseOrder {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, $permission);
            $locked = $this->lockOrder($lockedActor, $order, $attributes);
            $this->requireStatus($locked, [$from]);
            $locked->forceFill(['status' => $to, 'lock_version' => $locked->lock_version + 1, ...$extra($locked, $lockedActor)])->save();
            $this->audit->record('inventory.purchase_order.'.$event, $locked, ['from' => $from, 'to' => $to], $lockedActor, $branch);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function lockOrder(User $actor, PurchaseOrder $order, array $attributes, bool $checkVersion = true): PurchaseOrder
    {
        $locked = PurchaseOrder::query()->whereKey($order->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        if ($checkVersion) {
            $this->requireCurrentVersion($locked, $attributes);
        }

        return $locked;
    }

    /** @param array<string, mixed> $attributes */
    private function requireCurrentVersion(PurchaseOrder $order, array $attributes): void
    {
        if ((int) ($attributes['lock_version'] ?? 0) !== $order->lock_version) {
            throw ValidationException::withMessages(['lock_version' => 'The Purchase Order changed. Review and retry.']);
        }
    }

    /** @return array{InventorySupplier,InventoryLocation} */
    private function lockSupplierAndLocation(User $actor, string $supplierPublicId, string $locationPublicId): array
    {
        $supplier = InventorySupplier::query()->where('public_id', $supplierPublicId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $location = InventoryLocation::query()->where('public_id', $locationPublicId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();

        return [$supplier, $location];
    }

    private function assertLocationAuthority(User $actor, Branch $branch, InventoryLocation $location): void
    {
        if ($location->branch_id === null) {
            if (! $actor->can(self::WAREHOUSE_PERMISSION)) {
                throw new AuthorizationException('Organisation warehouse operations require explicit authority.');
            }

            return;
        }
        abort_unless($location->branch_id === $branch->id, 404);
    }

    /**
     * @param  list<string>  $publicIds
     * @return array<string, InventorySku>
     */
    private function lockSkus(User $actor, array $publicIds): array
    {
        $unique = array_values(array_unique($publicIds));
        $rows = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('public_id', $unique)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
        abort_unless($rows->count() === count($unique), 404);

        return $rows->all();
    }

    /**
     * @param  list<array{sku_public_id:string,quantity:string}>  $lines
     * @param  array<string, InventorySku>  $skus
     */
    private function replaceDraftLines(PurchaseOrder $order, array $lines, array $skus): void
    {
        foreach ($lines as $input) {
            $line = new PurchaseOrderLine;
            $line->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $order->organisation_id, 'purchase_order_id' => $order->id, 'inventory_sku_id' => $skus[$input['sku_public_id']]->id, 'ordered_quantity' => $input['quantity'], 'received_quantity' => '0.000'])->save();
        }
    }

    /** @return list<array{sku_public_id:string,quantity:string}> */
    private function validateLines(mixed $lines): array
    {
        $values = validator(['lines' => $lines], ['lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.sku_public_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/']])->validate()['lines'];
        foreach ($values as &$line) {
            if ((float) $line['quantity'] <= 0) {
                throw ValidationException::withMessages(['lines' => 'Ordered quantities must be positive.']);
            }
            $line['quantity'] = number_format((float) $line['quantity'], 3, '.', '');
        }

        return $values;
    }

    /** @return list<array{line_public_id:string,quantity:string,batch_number:?string,expiry_date:?string}> */
    private function validateReceiptLines(mixed $lines): array
    {
        $values = validator(['lines' => $lines], ['lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.line_public_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'], 'lines.*.batch_number' => ['nullable', 'string', 'max:100'], 'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d']])->validate()['lines'];
        foreach ($values as &$line) {
            if ((float) $line['quantity'] <= 0) {
                throw ValidationException::withMessages(['lines' => 'Received quantities must be positive.']);
            }
            $line['quantity'] = number_format((float) $line['quantity'], 3, '.', '');
            $line['batch_number'] = filled($line['batch_number'] ?? null) ? trim((string) $line['batch_number']) : null;
            $line['expiry_date'] = $line['expiry_date'] ?? null;
        }
        usort($values, fn (array $a, array $b): int => $a['line_public_id'] <=> $b['line_public_id']);

        return $values;
    }

    /** @param array{batch_number:?string,expiry_date:?string} $input */
    private function resolveBatch(User $actor, Branch $branch, InventorySku $sku, array $input): InventoryBatch
    {
        if ($sku->batch_tracking_required && ! filled($input['batch_number'])) {
            throw ValidationException::withMessages(['lines' => 'A batch number is required for this SKU.']);
        }
        if ($sku->expiry_tracking_required && ! filled($input['expiry_date'])) {
            throw ValidationException::withMessages(['lines' => 'An expiry date is required for this SKU.']);
        }
        $batchNumber = $sku->batch_tracking_required ? (string) $input['batch_number'] : 'UNTRACKED-'.$sku->id;
        $expiryDate = $sku->expiry_tracking_required ? (string) $input['expiry_date'] : '9999-12-31';
        if ($expiryDate <= now()->setTimezone($branch->timezone)->toDateString()) {
            throw ValidationException::withMessages(['lines' => 'Expired stock cannot be received as available inventory.']);
        }
        DB::table('inventory_batches')->insertOrIgnore([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
            'inventory_sku_id' => $sku->id, 'batch_number' => $batchNumber, 'expiry_date' => $expiryDate,
            'received_at' => now()->setTimezone($branch->timezone)->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $batch = InventoryBatch::query()->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->where('batch_number', $batchNumber)->whereDate('expiry_date', $expiryDate)->lockForUpdate()->firstOrFail();
        if ($batch->status !== InventoryBatch::STATUS_AVAILABLE) {
            throw ValidationException::withMessages(['lines' => 'Stock cannot be received into a non-available batch.']);
        }

        return $batch;
    }

    /** @param list<string> $statuses */
    private function requireStatus(PurchaseOrder $order, array $statuses): void
    {
        if (! in_array($order->status, $statuses, true)) {
            throw ValidationException::withMessages(['purchase_order' => 'The Purchase Order is not in a valid state for this action.']);
        }
    }
}
