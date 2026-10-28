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
        $payload = app(ReceivingPayload::class)->normalise($attributes);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $order, $attributes, $branch, $key, $payload, $hash): GoodsReceipt {
            $actor = $this->authority->lockForBranch($actor, $branch, self::RECEIVE_PERMISSION);
            $locked = $this->lockOrder($actor, $order, $attributes, false);
            $destination = InventoryLocation::query()->whereKey($locked->destination_location_id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->assertLocationAuthority($actor, $branch, $destination);
            abort_unless($destination->branch_id === $branch->id, 403, 'Verified receiving requires a branch destination.');
            $existing = GoodsReceipt::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $this->matchingReceiptOrFail($existing, $locked, $hash);
            }
            $this->requireCurrentVersion($locked, $attributes);
            $this->requireStatus($locked, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED]);
            $reference = $payload['delivery_reference'];
            $referenceKey = (string) DB::scalar('SELECT upper(?)', [$reference]);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['verified-receiving-delivery', $actor->organisation_id.':'.$locked->supplier_id.':'.$branch->id.':'.$referenceKey]);
            if (DB::table('inventory_receipt_delivery_evidence')->where('organisation_id', $actor->organisation_id)->where('supplier_id', $locked->supplier_id)->where('branch_id', $branch->id)->where('reference_key', $referenceKey)->exists()) {
                throw ValidationException::withMessages(['delivery_reference' => 'This delivery reference is already recorded for this supplier at this branch.']);
            }
            $plans = $this->receiptPlan($actor, $locked, $branch, $payload);
            $reasons = $this->exceptionReasons($plans);
            $review = app(ReceivingReviewService::class)->approvedForPosting($actor, $locked, $branch, $payload, $attributes, $reasons);
            $publicId = (string) Str::uuid();
            $timestamp = now()->utc();
            $inserted = DB::table('inventory_goods_receipts')->insertOrIgnore(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'purchase_order_id' => $locked->id, 'idempotency_key' => $key, 'request_hash' => $hash, 'received_by_user_id' => $actor->id, 'received_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
            if ($inserted === 0) {
                return $this->matchingReceiptOrFail(GoodsReceipt::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->lockForUpdate()->firstOrFail(), $locked, $hash);
            }
            $receipt = GoodsReceipt::query()->where('public_id', $publicId)->firstOrFail();
            DB::table('inventory_receipt_delivery_evidence')->insert(['organisation_id' => $actor->organisation_id, 'goods_receipt_id' => $receipt->id, 'branch_id' => $branch->id, 'supplier_id' => $locked->supplier_id, 'delivery_reference' => $reference, 'reference_key' => $referenceKey, 'received_by_user_id' => $actor->id, 'received_at' => $receipt->received_at, 'lock_version' => 1]);
            foreach ($plans as $plan) {
                $line = $plan['line'];
                $sku = $plan['sku'];
                $stockIn = $plan['accepted'] + $plan['quarantined'];
                $applied = min($stockIn, $plan['remaining']);
                $lineId = DB::table('inventory_receipt_line_evidence')->insertGetId(['organisation_id' => $actor->organisation_id, 'goods_receipt_id' => $receipt->id, 'purchase_order_line_id' => $line->id, 'physical_quantity' => $plan['input']['physical_quantity'], 'accepted_quantity' => StockUnitQuantity::format($plan['accepted']), 'quarantined_quantity' => StockUnitQuantity::format($plan['quarantined']), 'rejected_quantity' => StockUnitQuantity::format($plan['rejected']), 'applied_order_quantity' => StockUnitQuantity::format($applied), 'overage_quantity' => StockUnitQuantity::format($stockIn - $applied)]);
                foreach ($plan['input']['lots'] as $lot) {
                    $batch = $lot['outcome'] === 'rejected' ? null : $this->resolveBatch($actor, $branch, $sku, $lot);
                    $cost = $lot['unit_cost_sen'] ?? $line->estimated_unit_cost_sen;
                    DB::table('inventory_receipt_lots')->insert(['line_evidence_id' => $lineId, 'inventory_batch_id' => $batch?->id, 'batch_number' => $lot['batch_number'], 'expiry_date' => $lot['expiry_date'], 'quantity' => $lot['quantity'], 'outcome' => $lot['outcome'], 'reason' => $lot['reason'], 'unit_cost_sen' => $cost]);
                    if (! $batch) {
                        continue;
                    }
                    $this->movements->recordPurchaseReceipt($actor, $destination, $sku, $batch, $lot['quantity'], $receipt->public_id, $cost, $locked->supplier_id);
                    // Retain the legacy single-lot relation without fabricating a batch aggregate for split lots.
                    if (count($plan['input']['lots']) === 1) {
                        (new GoodsReceiptLine)->forceFill(['organisation_id' => $actor->organisation_id, 'goods_receipt_id' => $receipt->id, 'purchase_order_line_id' => $line->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id, 'quantity' => $lot['quantity'], 'unit_cost_sen' => $cost])->save();
                    }
                    if ($lot['outcome'] === 'quarantined') {
                        $state = DB::table('inventory_batch_hold_states')->where('inventory_batch_id', $batch->id)->where('branch_id', $branch->id)->first();
                        if (! ($state->active_action_id ?? null)) {
                            app(InventoryBatchHoldService::class)->act($actor, $batch->public_id, 'place', ['expected_branch_id' => $branch->id, 'lock_version' => $state->lock_version ?? 0, 'retry_key' => (string) Str::uuid(), 'reason' => $lot['reason'] === 'damaged' ? 'damaged' : 'other_safety']);
                        }
                    }
                }
                $next = StockUnitQuantity::scaled($line->received_quantity, true) + $applied;
                $line->forceFill(['received_quantity' => StockUnitQuantity::format($next)])->save();
            }
            if ($review !== null) {
                DB::table('inventory_receiving_consumptions')->insert(['review_id' => $review, 'goods_receipt_id' => $receipt->id, 'created_at' => now()->utc()]);
            }
            $hasRemaining = $locked->lines()->whereColumn('received_quantity', '<', 'ordered_quantity')->exists();
            $hasReceived = $locked->lines()->where('received_quantity', '>', 0)->exists();
            $status = ! $hasRemaining ? PurchaseOrder::STATUS_FULLY_RECEIVED : ($hasReceived ? PurchaseOrder::STATUS_PARTIALLY_RECEIVED : PurchaseOrder::STATUS_APPROVED);
            $locked->forceFill(['status' => $status, 'lock_version' => $locked->lock_version + 1])->save();
            abort_if($this->audit->record('inventory.goods_receipt.posted', $receipt, ['purchase_order_public_id' => $locked->public_id, 'line_count' => count($plans)], $actor, $branch) === null, 503, 'The receipt could not be audited. Nothing was posted.');

            return $receipt->load('lines');
        }, 3);
    }

    /**
     * Caller owns the order lock.
     *
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    public function receiptPlan(User $actor, PurchaseOrder $order, Branch $branch, array $payload): array
    {
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->where('organisation_id', $actor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
        $ids = [];
        foreach ($payload['lines'] as $input) {
            $line = $lines->get($input['line_public_id']);
            abort_unless($line !== null, 404);
            $ids[] = $line->inventory_sku_id;
        }
        $skus = InventorySku::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $plans = [];
        foreach ($payload['lines'] as $input) {
            $line = $lines->get($input['line_public_id']);
            $sku = $skus->get($line->inventory_sku_id);
            abort_unless($sku && $sku->is_active && $sku->item()->where('is_active', true)->exists(), 404);
            if (! $sku->expiry_tracking_required) {
                throw ValidationException::withMessages(['lines' => 'This item has no expiry tracking. A real expiry date is required before it can be received. Review the item setup.']);
            }
            $totals = ['accepted' => 0, 'quarantined' => 0, 'rejected' => 0];
            foreach ($input['lots'] as $lot) {
                StockUnitQuantity::base($lot['quantity'], $sku->stock_unit, $lot['unit'] ?? null);
                if ($lot['expiry_date'] <= now()->setTimezone($branch->timezone)->toDateString()) {
                    throw ValidationException::withMessages(['lines' => 'Expired stock cannot be received as available inventory.']);
                }
                $totals[$lot['outcome']] += StockUnitQuantity::scaled($lot['quantity']);
            }
            $remaining = StockUnitQuantity::scaled($line->ordered_quantity, true) - StockUnitQuantity::scaled($line->received_quantity, true);
            $plans[] = [...$totals, 'input' => $input, 'line' => $line, 'sku' => $sku, 'remaining' => $remaining];
        }

        return $plans;
    }

    /**
     * @param  list<array<string,mixed>>  $plans
     * @return list<string>
     */
    public function exceptionReasons(array $plans): array
    {
        $reasons = [];
        foreach ($plans as $plan) {
            if (StockUnitQuantity::scaled($plan['input']['physical_quantity']) > $plan['remaining']) {
                $reasons[] = 'overage';
            }
            if ($plan['input']['short_delivery']) {
                $reasons[] = 'short';
            }
            foreach ($plan['input']['lots'] as $lot) {
                if ($lot['outcome'] !== 'accepted') {
                    $reasons[] = match ($lot['reason']) {
                        'damaged' => 'damaged', 'wrong_item' => 'wrong_item', 'short_expiry' => 'expiry_problem', default => 'documentation'
                    };
                }
            }
        }

        return array_values(array_unique($reasons));
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
     * @param  list<array{sku_public_id:string,quantity:string,unit_cost_sen:int|null}>  $lines
     * @param  array<string, InventorySku>  $skus
     */
    private function replaceDraftLines(PurchaseOrder $order, array $lines, array $skus): void
    {
        foreach ($lines as $input) {
            $line = new PurchaseOrderLine;
            $line->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $order->organisation_id,
                'purchase_order_id' => $order->id,
                'inventory_sku_id' => $skus[$input['sku_public_id']]->id,
                'ordered_quantity' => $input['quantity'],
                'received_quantity' => '0.000',
                'estimated_unit_cost_sen' => $input['unit_cost_sen'],
            ])->save();
        }
    }

    /** @return list<array{sku_public_id:string,quantity:string,unit_cost_sen:int|null}> */
    private function validateLines(mixed $lines): array
    {
        $values = validator(['lines' => $lines], [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sku_public_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'],
            'lines.*.unit_cost_sen' => ['nullable', 'integer', 'min:0'],
        ])->validate()['lines'];
        foreach ($values as &$line) {
            if (StockUnitQuantity::scaled($line['quantity'], true) === 0) {
                throw ValidationException::withMessages(['lines' => 'Ordered quantities must be positive.']);
            }
            $line['quantity'] = StockUnitQuantity::format(StockUnitQuantity::scaled($line['quantity']));
            $line['unit_cost_sen'] = isset($line['unit_cost_sen']) ? (int) $line['unit_cost_sen'] : null;
        }

        return $values;
    }

    /** @param array{batch_number:?string,expiry_date:?string} $input */
    private function resolveBatch(User $actor, Branch $branch, InventorySku $sku, array $input): InventoryBatch
    {
        if ($sku->batch_tracking_required && ! filled($input['batch_number'])) {
            throw ValidationException::withMessages(['lines' => 'A batch number is required for this SKU.']);
        }
        if (! $sku->expiry_tracking_required) {
            throw ValidationException::withMessages(['lines' => 'This item has no expiry tracking. A real expiry date is required before it can be received. Review the item setup.']);
        }
        if (! filled($input['expiry_date'])) {
            throw ValidationException::withMessages(['lines' => 'An expiry date is required for this SKU.']);
        }
        $batchNumber = (string) $input['batch_number'];
        $expiryDate = (string) $input['expiry_date'];
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
