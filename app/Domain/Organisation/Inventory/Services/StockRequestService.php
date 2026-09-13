<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Models\StockRequestLine;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StockRequestService
{
    public const CREATE_PERMISSION = 'inventory.stock_requests.create.branch';

    public const APPROVE_PERMISSION = 'inventory.stock_requests.approve.branch';

    public const DISPATCH_PERMISSION = 'inventory.transfers.dispatch.branch';

    public const RECEIVE_PERMISSION = 'inventory.transfers.receive.branch';

    public const WAREHOUSE_PERMISSION = 'inventory.warehouse.organisation';

    public function __construct(
        private InventoryAuthorityService $authority,
        private InventoryMovementService $movements,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): StockRequest
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $lines = $this->validateRequestLines($attributes['lines'] ?? null);

        return DB::transaction(function () use ($actor, $attributes, $branch, $lines): StockRequest {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::CREATE_PERMISSION);
            [$source, $destination] = $this->lockLocations($lockedActor, (string) ($attributes['source_location_public_id'] ?? ''), (string) ($attributes['destination_location_public_id'] ?? ''));
            $this->assertSourceAuthority($lockedActor, $branch, $source);
            $this->assertDestinationAuthority($lockedActor, $branch, $destination);
            $skus = InventorySku::query()->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->whereIn('public_id', array_column($lines, 'sku_public_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            abort_unless($skus->count() === count($lines), 404);
            $request = new StockRequest;
            $request->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, 'requesting_branch_id' => $branch->id,
                'source_location_id' => $source->id, 'destination_location_id' => $destination->id,
                'request_number' => 'SR-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(8)), 'status' => StockRequest::STATUS_REQUESTED,
                'lock_version' => 1, 'requested_by_user_id' => $lockedActor->id, 'requested_at' => now()->utc(),
            ])->save();
            foreach ($lines as $input) {
                $line = new StockRequestLine;
                $line->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, 'stock_request_id' => $request->id, 'inventory_sku_id' => $skus[$input['sku_public_id']]->id, 'requested_quantity' => $input['quantity']])->save();
            }
            $this->audit->record('inventory.stock_request.requested', $request, ['request_number' => $request->request_number, 'line_count' => count($lines)], $lockedActor, $branch);

            return $request->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function approve(User $actor, StockRequest $request, array $attributes): StockRequest
    {
        return $this->decision($actor, $request, $attributes, StockRequest::STATUS_APPROVED, null);
    }

    /** @param array<string, mixed> $attributes */
    public function reject(User $actor, StockRequest $request, array $attributes): StockRequest
    {
        $reason = trim((string) ($attributes['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required and may not exceed 300 characters.']);
        }

        return $this->decision($actor, $request, $attributes, StockRequest::STATUS_REJECTED, $reason);
    }

    /** @param array<string, mixed> $attributes */
    public function dispatch(User $actor, StockRequest $request, array $attributes): StockRequest
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $key = $this->idempotencyKey($attributes, 'dispatch_idempotency_key');
        $allocations = $this->validateDispatchLines($attributes['lines'] ?? null);

        return DB::transaction(function () use ($actor, $request, $attributes, $branch, $key, $allocations): StockRequest {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::DISPATCH_PERMISSION);
            $locked = $this->lockRequest($lockedActor, $request, $attributes, false);
            $source = InventoryLocation::query()->whereKey($locked->source_location_id)->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->assertSourceAuthority($lockedActor, $branch, $source);
            if (in_array($locked->status, [StockRequest::STATUS_DISPATCHED, StockRequest::STATUS_RECEIVED], true)) {
                if ($locked->dispatch_idempotency_key === $key) {
                    return $locked->load('lines');
                }
                throw ValidationException::withMessages(['dispatch_idempotency_key' => 'This Stock Request was already dispatched.']);
            }
            $this->requireCurrentVersion($locked, $attributes);
            $this->requireStatus($locked, StockRequest::STATUS_APPROVED);
            $lines = StockRequestLine::query()->where('stock_request_id', $locked->id)->where('organisation_id', $lockedActor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            if ($lines->count() !== count($allocations)) {
                throw ValidationException::withMessages(['lines' => 'Every approved Stock Request line must be dispatched exactly once.']);
            }
            $plans = [];
            foreach ($allocations as $input) {
                $line = $lines->get($input['line_public_id']);
                if (! $line || abs((float) $line->requested_quantity - (float) $input['quantity']) > 0.0001) {
                    throw ValidationException::withMessages(['lines' => 'Dispatch quantities must match the approved request.']);
                }
                $plans[] = ['line' => $line, 'input' => $input];
            }
            usort($plans, fn (array $left, array $right): int => $left['line']->inventory_sku_id <=> $right['line']->inventory_sku_id);
            $skus = InventorySku::query()->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->whereIn('id', array_map(fn (array $plan): int => $plan['line']->inventory_sku_id, $plans))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($skus->count() === count($plans), 404);
            foreach ($plans as $plan) {
                $line = $plan['line'];
                $input = $plan['input'];
                $sku = $skus->get($line->inventory_sku_id);
                abort_unless($sku !== null, 404);
                $batch = InventoryBatch::query()->where('public_id', $input['batch_public_id'])->where('organisation_id', $lockedActor->organisation_id)->where('inventory_sku_id', $sku->id)->where('status', InventoryBatch::STATUS_AVAILABLE)->lockForUpdate()->firstOrFail();
                if (CarbonImmutable::parse($batch->expiry_date)->toDateString() <= now()->setTimezone($branch->timezone)->toDateString()) {
                    throw ValidationException::withMessages(['lines' => 'Expired stock cannot be dispatched.']);
                }
                $this->movements->recordTransferDispatch($lockedActor, $source, $sku, $batch, $input['quantity'], $locked->public_id);
                $line->forceFill(['inventory_batch_id' => $batch->id, 'dispatched_quantity' => $input['quantity']])->save();
            }
            $locked->forceFill(['status' => StockRequest::STATUS_DISPATCHED, 'dispatched_by_user_id' => $lockedActor->id, 'dispatched_at' => now()->utc(), 'dispatch_idempotency_key' => $key, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.stock_request.dispatched', $locked, ['line_count' => count($allocations)], $lockedActor, $branch);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function receive(User $actor, StockRequest $request, array $attributes): StockRequest
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $key = $this->idempotencyKey($attributes, 'receive_idempotency_key');

        return DB::transaction(function () use ($actor, $request, $attributes, $branch, $key): StockRequest {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::RECEIVE_PERMISSION);
            $locked = $this->lockRequest($lockedActor, $request, $attributes, false);
            $destination = InventoryLocation::query()->whereKey($locked->destination_location_id)->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->assertDestinationAuthority($lockedActor, $branch, $destination);
            if ($locked->status === StockRequest::STATUS_RECEIVED) {
                if ($locked->receive_idempotency_key === $key) {
                    return $locked->load('lines');
                }
                throw ValidationException::withMessages(['receive_idempotency_key' => 'This Stock Request was already received.']);
            }
            $this->requireCurrentVersion($locked, $attributes);
            $this->requireStatus($locked, StockRequest::STATUS_DISPATCHED);
            $lines = StockRequestLine::query()->where('stock_request_id', $locked->id)->where('organisation_id', $lockedActor->organisation_id)->orderBy('inventory_sku_id')->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                if (! $line->inventory_batch_id || ! $line->dispatched_quantity) {
                    throw ValidationException::withMessages(['stock_request' => 'The dispatch evidence is incomplete.']);
                }
                $sku = InventorySku::query()->whereKey($line->inventory_sku_id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
                $batch = InventoryBatch::query()->whereKey($line->inventory_batch_id)->where('organisation_id', $lockedActor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
                $this->movements->recordTransferReceipt($lockedActor, $destination, $sku, $batch, $line->dispatched_quantity, $locked->public_id);
                $line->forceFill(['received_quantity' => $line->dispatched_quantity])->save();
            }
            $locked->forceFill(['status' => StockRequest::STATUS_RECEIVED, 'received_by_user_id' => $lockedActor->id, 'received_at' => now()->utc(), 'receive_idempotency_key' => $key, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.stock_request.received', $locked, ['line_count' => $lines->count()], $lockedActor, $branch);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function decision(User $actor, StockRequest $request, array $attributes, string $status, ?string $reason): StockRequest
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $request, $attributes, $branch, $status, $reason): StockRequest {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::APPROVE_PERMISSION);
            $locked = $this->lockRequest($lockedActor, $request, $attributes);
            abort_unless($locked->requesting_branch_id === $branch->id, 404);
            $this->requireStatus($locked, StockRequest::STATUS_REQUESTED);
            if ($status === StockRequest::STATUS_APPROVED) {
                [$source, $destination] = $this->lockStoredLocations($lockedActor, $locked);
                $this->assertSourceAuthority($lockedActor, $branch, $source);
                $this->assertDestinationAuthority($lockedActor, $branch, $destination);
            }
            $locked->forceFill(['status' => $status, 'decided_by_user_id' => $lockedActor->id, 'decided_at' => now()->utc(), 'rejection_reason' => $reason, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.stock_request.'.$status, $locked, ['from' => StockRequest::STATUS_REQUESTED, 'to' => $status], $lockedActor, $branch);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function lockRequest(User $actor, StockRequest $request, array $attributes, bool $checkVersion = true): StockRequest
    {
        $locked = StockRequest::query()->whereKey($request->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        if ($checkVersion) {
            $this->requireCurrentVersion($locked, $attributes);
        }

        return $locked;
    }

    /** @param array<string, mixed> $attributes */
    private function requireCurrentVersion(StockRequest $request, array $attributes): void
    {
        if ((int) ($attributes['lock_version'] ?? 0) !== $request->lock_version) {
            throw ValidationException::withMessages(['lock_version' => 'The Stock Request changed. Review and retry.']);
        }
    }

    /** @return array{InventoryLocation,InventoryLocation} */
    private function lockLocations(User $actor, string $sourcePublicId, string $destinationPublicId): array
    {
        $locations = InventoryLocation::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('public_id', [$sourcePublicId, $destinationPublicId])->orderBy('id')->lockForUpdate()->get();
        $source = $locations->firstWhere('public_id', $sourcePublicId);
        $destination = $locations->firstWhere('public_id', $destinationPublicId);
        abort_unless($locations->count() === 2 && $source && $destination && $source->id !== $destination->id, 404);

        return [$source, $destination];
    }

    /** @return array{InventoryLocation,InventoryLocation} */
    private function lockStoredLocations(User $actor, StockRequest $request): array
    {
        $locations = InventoryLocation::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->whereIn('id', [$request->source_location_id, $request->destination_location_id])->orderBy('id')->lockForUpdate()->get();
        $source = $locations->firstWhere('id', $request->source_location_id);
        $destination = $locations->firstWhere('id', $request->destination_location_id);
        abort_unless($locations->count() === 2 && $source && $destination && $source->id !== $destination->id, 404);

        return [$source, $destination];
    }

    private function assertSourceAuthority(User $actor, Branch $branch, InventoryLocation $source): void
    {
        if ($source->branch_id === null) {
            if (! $actor->can(self::WAREHOUSE_PERMISSION)) {
                throw new AuthorizationException('Organisation warehouse dispatch requires explicit authority.');
            }

            return;
        }
        abort_unless($source->branch_id === $branch->id, 404);
    }

    private function assertDestinationAuthority(User $actor, Branch $branch, InventoryLocation $destination): void
    {
        if ($destination->branch_id === null) {
            if (! $actor->can(self::WAREHOUSE_PERMISSION)) {
                throw new AuthorizationException('Organisation warehouse receipt requires explicit authority.');
            }

            return;
        }
        abort_unless($destination->branch_id === $branch->id, 404);
    }

    /** @return list<array{sku_public_id:string,quantity:string}> */
    private function validateRequestLines(mixed $lines): array
    {
        $values = validator(['lines' => $lines], ['lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.sku_public_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/']])->validate()['lines'];
        foreach ($values as &$line) {
            if ((float) $line['quantity'] <= 0) {
                throw ValidationException::withMessages(['lines' => 'Requested quantities must be positive.']);
            }
            $line['quantity'] = number_format((float) $line['quantity'], 3, '.', '');
        }

        return $values;
    }

    /** @return list<array{line_public_id:string,batch_public_id:string,quantity:string}> */
    private function validateDispatchLines(mixed $lines): array
    {
        $values = validator(['lines' => $lines], ['lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.line_public_id' => ['required', 'uuid', 'distinct'], 'lines.*.batch_public_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/']])->validate()['lines'];
        foreach ($values as &$line) {
            if ((float) $line['quantity'] <= 0) {
                throw ValidationException::withMessages(['lines' => 'Dispatch quantities must be positive.']);
            }
            $line['quantity'] = number_format((float) $line['quantity'], 3, '.', '');
        }
        usort($values, fn (array $a, array $b): int => $a['line_public_id'] <=> $b['line_public_id']);

        return $values;
    }

    /** @param array<string, mixed> $attributes */
    private function idempotencyKey(array $attributes, string $field): string
    {
        $key = (string) ($attributes[$field] ?? '');
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages([$field => 'A valid idempotency key is required.']);
        }

        return $key;
    }

    private function requireStatus(StockRequest $request, string $status): void
    {
        if ($request->status !== $status) {
            throw ValidationException::withMessages(['stock_request' => 'The Stock Request is not in a valid state for this action.']);
        }
    }
}
