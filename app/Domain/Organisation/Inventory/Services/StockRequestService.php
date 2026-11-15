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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StockRequestService
{
    public const CREATE_PERMISSION = 'inventory.stock_requests.create.branch';

    public const APPROVE_PERMISSION = 'inventory.stock_requests.approve.branch';

    public const DISPATCH_PERMISSION = 'inventory.transfers.dispatch.branch';

    public const RECEIVE_PERMISSION = 'inventory.transfers.receive.branch';

    public const WAREHOUSE_PERMISSION = 'inventory.warehouse.organisation';

    /** Transfer differences are resolved by the write-off approvers, never by the receiver. */
    public const RESOLVE_PERMISSION = 'inventory.variances.approve.branch';

    public const DIFFERENCE_KINDS = ['short', 'damaged', 'extra'];

    /** @var array<string, list<string>> */
    public const OUTCOMES = [
        'short' => ['lost_in_transit', 'arrived_later'],
        'damaged' => ['written_off', 'accepted_after_inspection'],
        'extra' => ['returned_to_source', 'recorded_by_stock_count'],
    ];

    /** Outcomes that credit the receiving location through the transfer receipt ledger writer. */
    public const CREDIT_OUTCOMES = ['arrived_later', 'accepted_after_inspection'];

    /** Every outcome, including the two that add stock, needs a delivery note, inspection log or other reference. */
    public const REFERENCE_REQUIRED = ['lost_in_transit', 'arrived_later', 'written_off', 'accepted_after_inspection', 'returned_to_source', 'recorded_by_stock_count'];

    public function __construct(
        private InventoryAuthorityService $authority,
        private InventoryMovementService $movements,
        private AuditRecorder $audit,
        private InventoryBatchEligibility $eligibility,
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
            $this->assertRequestDestination($lockedActor, $branch, $source, $destination);
            // STK-11: a two-stage transfer never touches a patient returns location.
            if (in_array(InventoryLocation::TYPE_DISPENSARY_RETURNS, [$source->type, $destination->type], true)) {
                throw ValidationException::withMessages(['location' => 'Patient returns stock is not sent on a stock request. Write it off, or move it to another returns location.']);
            }
            $skus = InventorySku::query()->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->whereIn('public_id', array_column($lines, 'sku_public_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            abort_unless($skus->count() === count($lines), 404);
            // STK-31 (Daniel): a cross-branch request never sends more than this location can spare (usable stock above
            // its own maximum or reorder level, less what is already waiting to be sent). Same-branch requests are unchanged.
            if ($source->branch_id !== null && $destination->branch_id !== null && $destination->branch_id !== $source->branch_id) {
                $spare = app(BranchReplenishmentService::class)->spareAt($lockedActor, $branch, $source->id, array_values(array_map(fn (InventorySku $sku): int => (int) $sku->id, $skus->all())));
                foreach ($lines as $index => $input) {
                    $available = $spare[(int) $skus[$input['sku_public_id']]->id] ?? 0;
                    if (StockUnitQuantity::scaled($input['quantity'], true) > $available) {
                        throw ValidationException::withMessages(["lines.$index.quantity" => 'This branch can spare '.rtrim(rtrim(StockUnitQuantity::format(intdiv($available, 1000) * 1000), '0'), '.').' of this item. Lower the quantity.']);
                    }
                }
            }
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
            $this->audit->record('inventory.stock_request.requested', $request, ['request_number' => $request->request_number, 'line_count' => count($lines),
                // STK-31: a request may now send stock to another branch; the audit names where it goes.
                'destination_branch_id' => $destination->branch_id, 'cross_branch' => $destination->branch_id !== null && $destination->branch_id !== $branch->id], $lockedActor, $branch);

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
            $sourceBranch = $source->branch_id === null ? $branch : Branch::query()->findOrFail($source->branch_id);
            $skus = InventorySku::query()->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->whereIn('id', array_map(fn (array $plan): int => $plan['line']->inventory_sku_id, $plans))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($skus->count() === count($plans), 404);
            foreach ($plans as $plan) {
                $line = $plan['line'];
                $input = $plan['input'];
                $sku = $skus->get($line->inventory_sku_id);
                abort_unless($sku !== null, 404);
                $batch = InventoryBatch::query()->where('public_id', $input['batch_public_id'])->where('organisation_id', $lockedActor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
                // STK-37: a stock request sends stock towards use, so it takes only usable stock, by the STK-2 rule
                // dispensing uses, at the source's branch. Stock in an organisation store has no branch, so the
                // active branch's date applies and no branch hold can.
                $problem = $this->eligibility->problem($lockedActor->organisation_id, $sourceBranch, $batch->id, $source->branch_id !== null);
                if ($problem !== null) {
                    throw ValidationException::withMessages(['lines' => $sku->sku_code.': this batch can\'t be sent: '.$problem.'. Write it off, or move it to a store at this branch.']);
                }
                $this->movements->recordTransferDispatch($lockedActor, $source, $sku, $batch, $input['quantity'], $locked->public_id);
                $line->forceFill(['inventory_batch_id' => $batch->id, 'dispatched_quantity' => $input['quantity']])->save();
            }
            $locked->forceFill(['status' => StockRequest::STATUS_DISPATCHED, 'dispatched_by_user_id' => $lockedActor->id, 'dispatched_at' => now()->utc(), 'dispatch_idempotency_key' => $key, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.stock_request.dispatched', $locked, ['line_count' => count($allocations)], $lockedActor, $branch);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /**
     * Receipt records what physically arrived for every dispatched line. Only accepted quantity enters the
     * destination; short, damaged and extra quantities stay open as append-only differences until a
     * different, authorised person resolves them. Omitting `lines` keeps the original full-receipt contract.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function receive(User $actor, StockRequest $request, array $attributes): StockRequest
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $key = $this->idempotencyKey($attributes, 'receive_idempotency_key');
        $declared = array_key_exists('lines', $attributes) ? $this->validateReceiptLines($attributes['lines']) : null;
        $hash = $this->fingerprint(['receive', $request->public_id, $declared]);

        return DB::transaction(function () use ($actor, $request, $attributes, $branch, $key, $declared, $hash): StockRequest {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::RECEIVE_PERMISSION);
            $locked = $this->lockRequest($lockedActor, $request, $attributes, false);
            $destination = InventoryLocation::query()->whereKey($locked->destination_location_id)->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->assertDestinationAuthority($lockedActor, $branch, $destination);
            if ($locked->status === StockRequest::STATUS_RECEIVED) {
                if ($locked->receive_idempotency_key === $key) {
                    if ($locked->receive_payload_hash !== null && ! hash_equals($locked->receive_payload_hash, $hash)) {
                        throw ValidationException::withMessages(['receive_idempotency_key' => 'This retry key was used for a different receipt.']);
                    }

                    return $locked->load('lines');
                }
                throw ValidationException::withMessages(['receive_idempotency_key' => 'This Stock Request was already received.']);
            }
            $this->requireCurrentVersion($locked, $attributes);
            $this->requireStatus($locked, StockRequest::STATUS_DISPATCHED);
            $lines = StockRequestLine::query()->where('stock_request_id', $locked->id)->where('organisation_id', $lockedActor->organisation_id)->orderBy('inventory_sku_id')->orderBy('id')->lockForUpdate()->get();
            if ($declared !== null && ($lines->count() !== count($declared) || $lines->pluck('public_id')->diff(array_keys($declared))->isNotEmpty())) {
                throw ValidationException::withMessages(['lines' => 'Record exactly one receipt line for every dispatched line.']);
            }
            $differenceCount = 0;
            foreach ($lines as $line) {
                if (! $line->inventory_batch_id || ! $line->dispatched_quantity) {
                    throw ValidationException::withMessages(['stock_request' => 'The dispatch evidence is incomplete.']);
                }
                $sku = InventorySku::query()->whereKey($line->inventory_sku_id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
                $batch = InventoryBatch::query()->whereKey($line->inventory_batch_id)->where('organisation_id', $lockedActor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
                $dispatched = StockUnitQuantity::scaled((string) $line->dispatched_quantity);
                $counts = $declared === null
                    ? ['accepted' => $dispatched, 'short' => 0, 'damaged' => 0, 'extra' => 0]
                    : $declared[$line->public_id];
                if ($dispatched !== $counts['accepted'] + $counts['short'] + $counts['damaged']) {
                    throw ValidationException::withMessages(['lines' => 'For every line, accepted + short + damaged must equal the dispatched quantity ('.StockUnitQuantity::format($dispatched).' '.$sku->stock_unit.' of '.$sku->sku_code.').']);
                }
                $movement = $counts['accepted'] > 0
                    ? $this->movements->recordTransferReceipt($lockedActor, $destination, $sku, $batch, StockUnitQuantity::format($counts['accepted']), $locked->public_id)
                    : null;
                $line->forceFill(['received_quantity' => $counts['accepted'] > 0 ? StockUnitQuantity::format($counts['accepted']) : null])->save();
                $receiptLineId = DB::table('inventory_transfer_receipt_lines')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, 'stock_request_id' => $locked->id,
                    'stock_request_line_id' => $line->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id, 'base_unit' => $sku->stock_unit,
                    'dispatched_quantity' => StockUnitQuantity::format($dispatched), 'accepted_quantity' => StockUnitQuantity::format($counts['accepted']),
                    'short_quantity' => StockUnitQuantity::format($counts['short']), 'damaged_quantity' => StockUnitQuantity::format($counts['damaged']),
                    'extra_quantity' => StockUnitQuantity::format($counts['extra']), 'received_by_user_id' => $lockedActor->id, 'movement_id' => $movement?->id,
                    'created_at' => now()->utc(),
                ]);
                foreach (self::DIFFERENCE_KINDS as $kind) {
                    if ($counts[$kind] > 0) {
                        DB::table('inventory_transfer_differences')->insert([
                            'public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, 'stock_request_id' => $locked->id,
                            'receipt_line_id' => $receiptLineId, 'kind' => $kind, 'quantity' => StockUnitQuantity::format($counts[$kind]),
                            'received_by_user_id' => $lockedActor->id, 'created_at' => now()->utc(),
                        ]);
                        $differenceCount++;
                    }
                }
            }
            $locked->forceFill(['status' => StockRequest::STATUS_RECEIVED, 'received_by_user_id' => $lockedActor->id, 'received_at' => now()->utc(), 'receive_idempotency_key' => $key, 'receive_payload_hash' => $hash, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audited($this->audit->record('inventory.stock_request.received', $locked, ['line_count' => $lines->count()], $lockedActor, $branch));
            if ($differenceCount > 0) {
                $this->audited($this->audit->record('inventory.transfer_difference.recorded', $locked, ['difference_count' => $differenceCount], $lockedActor, $branch));
            }

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /**
     * Resolves one whole open difference. The resolver needs the write-off approval permission at the
     * receiving branch and must not be the person who received the transfer (owner decision 2026-11-05).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function resolveDifference(User $actor, StockRequest $request, array $attributes): StockRequest
    {
        $data = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'retry_key' => ['required', 'uuid'],
            'difference_public_id' => ['required', 'uuid'], 'outcome' => ['required', 'string', Rule::in(array_merge(...array_values(self::OUTCOMES)))],
            'reference' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ])->validate();
        $data['reference'] = isset($data['reference']) ? trim((string) $data['reference']) : '';
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['resolve', $request->public_id, $data]);

        return DB::transaction(function () use ($actor, $request, $data, $branch, $hash): StockRequest {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::RESOLVE_PERMISSION);
            $locked = $this->lockRequest($lockedActor, $request, $data, false);
            $destination = InventoryLocation::query()->whereKey($locked->destination_location_id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
            $this->assertDestinationAuthority($lockedActor, $branch, $destination);
            // Checked under the request lock, so a concurrent replay of the same key waits and then replays.
            $retry = DB::table('inventory_transfer_difference_resolutions')->where('organisation_id', $lockedActor->organisation_id)->where('resolved_by_user_id', $lockedActor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry !== null) {
                if (! hash_equals((string) $retry->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['retry_key' => 'This retry key was used for a different resolution.']);
                }

                return $locked->load('lines');
            }
            $this->requireStatus($locked, StockRequest::STATUS_RECEIVED);
            $this->requireCurrentVersion($locked, $data);
            $difference = DB::table('inventory_transfer_differences')->where('organisation_id', $lockedActor->organisation_id)->where('stock_request_id', $locked->id)->where('public_id', $data['difference_public_id'])->lockForUpdate()->first();
            abort_unless($difference !== null, 404);
            if (DB::table('inventory_transfer_difference_resolutions')->where('difference_id', $difference->id)->exists()) {
                throw ValidationException::withMessages(['difference_public_id' => 'This difference is already resolved.']);
            }
            if ((int) $difference->received_by_user_id === $lockedActor->id) {
                throw new AuthorizationException('The person who received this transfer may not resolve its differences.');
            }
            if (! in_array($data['outcome'], self::OUTCOMES[(string) $difference->kind] ?? [], true)) {
                throw ValidationException::withMessages(['outcome' => 'Choose an outcome that fits this '.$difference->kind.' difference.']);
            }
            if (in_array($data['outcome'], self::REFERENCE_REQUIRED, true) && $data['reference'] === '') {
                throw ValidationException::withMessages(['reference' => 'Enter a delivery note, inspection log or other document reference for this outcome.']);
            }
            $movement = null;
            if (in_array($data['outcome'], self::CREDIT_OUTCOMES, true)) {
                abort_unless($destination->is_active, 404);
                $receiptLine = DB::table('inventory_transfer_receipt_lines')->where('id', $difference->receipt_line_id)->where('organisation_id', $lockedActor->organisation_id)->sole();
                $sku = InventorySku::query()->whereKey($receiptLine->inventory_sku_id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
                $batch = InventoryBatch::query()->whereKey($receiptLine->inventory_batch_id)->where('organisation_id', $lockedActor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
                $movement = $this->movements->recordTransferReceipt($lockedActor, $destination, $sku, $batch, StockUnitQuantity::format(StockUnitQuantity::scaled((string) $difference->quantity)), $locked->public_id);
            }
            $resolutionId = DB::table('inventory_transfer_difference_resolutions')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, 'difference_id' => $difference->id,
                'outcome' => $data['outcome'], 'quantity' => $difference->quantity, 'reference' => $data['reference'] === '' ? null : $data['reference'],
                'resolved_by_user_id' => $lockedActor->id, 'movement_id' => $movement?->id, 'retry_key' => $data['retry_key'], 'payload_hash' => $hash,
                'created_at' => now()->utc(),
            ]);
            $locked->forceFill(['lock_version' => $locked->lock_version + 1])->save();
            $this->audited($this->audit->record('inventory.transfer_difference.resolved', $locked, ['difference_id' => (int) $difference->id, 'resolution_id' => $resolutionId, 'kind' => $difference->kind, 'outcome' => $data['outcome']], $lockedActor, $branch));

            return $locked->refresh()->load('lines');
        }, 3);
    }

    private function audited(mixed $record): void
    {
        abort_if($record === null, 503, 'The transfer receipt could not be audited. Nothing was saved or posted.');
    }

    /** @param array<mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @return array<string, array{accepted:int,short:int,damaged:int,extra:int}> */
    private function validateReceiptLines(mixed $lines): array
    {
        $values = validator(['lines' => $lines], [
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.line_public_id' => ['required', 'uuid', 'distinct'],
            'lines.*.accepted_quantity' => ['required'], 'lines.*.short_quantity' => ['required'], 'lines.*.damaged_quantity' => ['required'], 'lines.*.extra_quantity' => ['required'],
        ])->validate()['lines'];
        $declared = [];
        foreach ($values as $line) {
            $declared[(string) $line['line_public_id']] = [
                'accepted' => $this->receiptQuantity($line['accepted_quantity']),
                'short' => $this->receiptQuantity($line['short_quantity']),
                'damaged' => $this->receiptQuantity($line['damaged_quantity']),
                'extra' => $this->receiptQuantity($line['extra_quantity']),
            ];
        }
        ksort($declared);

        return $declared;
    }

    /** Exact thousandths; zero allowed; non-string, non-integer input is refused by StockUnitQuantity. */
    private function receiptQuantity(mixed $value): int
    {
        return StockUnitQuantity::scaled(is_int($value) ? $value : (is_string($value) ? $value : ''), true);
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
                $this->assertRequestDestination($lockedActor, $branch, $source, $destination);
                if ($destination->type === InventoryLocation::TYPE_DISPENSARY_RETURNS) {
                    throw ValidationException::withMessages(['location' => 'Patient returns stock is not sent on a stock request. Write it off, or move it to another returns location.']);
                }
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
        // The same location twice is a mistake the person can fix, so it gets a reason, not a "not found" page.
        if ($sourcePublicId !== '' && $sourcePublicId === $destinationPublicId) {
            throw ValidationException::withMessages(['destination_location_public_id' => 'Choose a different location from the one you take from.']);
        }
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

    /**
     * STK-31 (approved by B.E.R.N.I.S.S for the owner): the branch that holds the stock may send it to another branch of
     * the same organisation, from its own location. The source stays at the requester's own branch (assertSourceAuthority) and only the
     * destination branch's own receivers receive it (assertDestinationAuthority at receive). The organisation is enforced
     * by the location foreign keys; the returns rule by the callers and PostgreSQL.
     */
    private function assertRequestDestination(User $actor, Branch $branch, InventoryLocation $source, InventoryLocation $destination): void
    {
        // Only a branch sending its own stock may point at another branch. Warehouse stock keeps the old rule (the
        // destination is the requester's own branch or the warehouse), so no warehouse holder sends to any branch.
        if ($destination->branch_id === null || $source->branch_id === null) {
            $this->assertDestinationAuthority($actor, $branch, $destination);

            return;
        }
        abort_unless($destination->organisation_id === $actor->organisation_id, 404);
        // A branch that is no longer active never receives.
        abort_unless(Branch::query()->whereKey($destination->branch_id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->exists(), 404);
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
