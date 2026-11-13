<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use stdClass;

/**
 * STK-7 supplier returns. A CA Supervisor requests a return of stock from a verified receipt lot; a Finance
 * Officer or Director who is not the requester approves or rejects it (owner decision 2026-11-05). The
 * approved return is dispatched through the stock ledger and closed with the supplier's credit-note
 * reference. No money moves in KPOne; nothing is restocked or credited automatically.
 */
final class SupplierReturnService
{
    public const REQUEST = 'inventory.supplier_returns.request.branch';

    public const APPROVE = 'inventory.supplier_returns.approve.branch';

    public const REASONS = ['damaged', 'short_expiry', 'quality_issue', 'recall', 'wrong_item', 'overstock_agreed', 'other_agreed'];

    public const REJECT_REASONS = ['not_agreed_by_supplier', 'stock_still_usable', 'wrong_quantity', 'other'];

    /** STK-17: at most this many lots in one multi-lot return. */
    public const GROUP_MAX_LINES = 20;

    /** @var array<string, array{permission:string, from:list<string>, state:string}> */
    private const ACTIONS = [
        'approved' => ['permission' => self::APPROVE, 'from' => ['awaiting_approval'], 'state' => 'approved'],
        'rejected' => ['permission' => self::APPROVE, 'from' => ['awaiting_approval'], 'state' => 'rejected'],
        'cancelled' => ['permission' => self::REQUEST, 'from' => ['awaiting_approval', 'approved'], 'state' => 'cancelled'],
        'dispatched' => ['permission' => self::REQUEST, 'from' => ['approved'], 'state' => 'dispatched'],
        'credit_recorded' => ['permission' => self::APPROVE, 'from' => ['dispatched'], 'state' => 'credited'],
    ];

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $input */
    public function request(User $actor, array $input): int
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'retry_key' => ['required', 'uuid'], 'receipt_lot_id' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'string', 'max:100'], 'quantity' => ['required'], 'reason' => ['required', 'string', Rule::in(self::REASONS)],
            'supplier_reference' => ['nullable', 'string', 'max:100', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ])->validate();
        $data['supplier_reference'] = isset($data['supplier_reference']) ? trim((string) $data['supplier_reference']) : '';
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['request', $data]);

        return DB::transaction(function () use ($actor, $branch, $data, $hash): int {
            $actor = $this->authority->lockForBranch($actor, $branch, self::REQUEST);
            $candidate = $this->returnableLots($actor, $branch)->where('lot.id', (int) $data['receipt_lot_id'])->first(['lot.id', 'receipt.id as goods_receipt_id', 'delivery.supplier_id', 'po.destination_location_id', 'lot.inventory_batch_id']);
            abort_unless($candidate !== null, 404);
            // Lot lock first, so a concurrent replay of the same key waits and then replays.
            $lot = DB::table('inventory_receipt_lots')->where('id', $candidate->id)->lockForUpdate()->sole();
            $retry = $this->retry($actor, (string) $data['retry_key'], $hash);
            if ($retry !== null) {
                return $retry;
            }
            // SKU before batch, the shared inventory lock order (receipt correction, governed stock, dispatch).
            $sku = InventorySku::query()->whereKey(DB::table('inventory_batches')->where('id', $lot->inventory_batch_id)->value('inventory_sku_id'))->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $batch = InventoryBatch::query()->whereKey($lot->inventory_batch_id)->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
            $location = InventoryLocation::query()->whereKey($candidate->destination_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
            $quantity = StockUnitQuantity::scaled(StockUnitQuantity::base($data['quantity'], $sku->stock_unit, (string) $data['unit']));
            $returnable = StockUnitQuantity::scaled((string) $lot->quantity) - $this->committed((int) $lot->id);
            if ($quantity > $returnable) {
                $this->refuse('quantity', 'You can return at most '.StockUnitQuantity::format(max(0, $returnable)).' '.$sku->stock_unit.' from this receipt lot.');
            }
            $onHand = (string) (DB::table('inventory_stock_balances')->where('organisation_id', $actor->organisation_id)->where('inventory_location_id', $location->id)->where('inventory_sku_id', $sku->id)->where('inventory_batch_id', $batch->id)->value('quantity') ?? '0');
            if ($quantity > StockUnitQuantity::scaled($onHand, true)) {
                $this->refuse('quantity', 'Only '.StockUnitQuantity::format(StockUnitQuantity::scaled($onHand, true)).' '.$sku->stock_unit.' of this batch is on hand here.');
            }
            $id = DB::table('inventory_supplier_returns')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'return_number' => 'SRN-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(8)), 'supplier_id' => $candidate->supplier_id,
                'goods_receipt_id' => $candidate->goods_receipt_id, 'receipt_lot_id' => $lot->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id,
                'inventory_location_id' => $location->id, 'base_unit' => $sku->stock_unit, 'quantity' => StockUnitQuantity::format($quantity),
                'reason' => $data['reason'], 'supplier_reference' => $data['supplier_reference'] === '' ? null : $data['supplier_reference'],
                'requester_user_id' => $actor->id, 'created_at' => now()->utc(),
            ]);
            $this->append($actor, $branch, $id, 'requested', 'awaiting_approval', 1, (string) $data['retry_key'], $hash, []);

            return $id;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function act(User $actor, int $returnId, string $action, array $input): int
    {
        abort_unless(array_key_exists($action, self::ACTIONS), 404);
        $rule = self::ACTIONS[$action];
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'retry_key' => ['required', 'uuid'],
            'reason_code' => [$action === 'rejected' ? 'required' : 'prohibited', 'string', Rule::in(self::REJECT_REASONS)],
            'reference' => [in_array($action, ['dispatched', 'credit_recorded'], true) ? 'required' : 'prohibited', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'credit_amount_sen' => [$action === 'credit_recorded' ? 'nullable' : 'prohibited', 'regex:/^\d{1,12}$/'],
            'quantity' => ['prohibited'], 'receipt_lot_id' => ['prohibited'],
        ])->validate();
        if (isset($data['reference'])) {
            $data['reference'] = trim((string) $data['reference']);
            if ($data['reference'] === '') {
                $this->refuse('reference', $action === 'dispatched' ? 'Enter the return delivery note or courier reference.' : 'Enter the supplier credit-note reference.');
            }
        }
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint([$returnId, $action, $data]);

        return DB::transaction(function () use ($actor, $branch, $returnId, $action, $rule, $data, $hash): int {
            $actor = $this->authority->lockForBranch($actor, $branch, $rule['permission']);
            $return = DB::table('inventory_supplier_returns')->where('id', $returnId)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            abort_unless($return !== null, 404);
            if ($return->group_id !== null) {
                $this->refuse('state', 'This return is one line of a multi-lot return. Approve, dispatch or close the whole return together.');
            }
            // Checked under the return lock, so a concurrent replay of the same key waits and then replays.
            $retry = $this->retry($actor, (string) $data['retry_key'], $hash);
            if ($retry !== null) {
                return $retry;
            }
            $previous = DB::table('inventory_supplier_return_actions')->where('return_id', $return->id)->orderByDesc('lock_version')->first();
            if ($previous === null || (int) $previous->lock_version !== (int) $data['lock_version']) {
                $this->refuse('lock_version', 'This return changed. Reload before continuing.');
            }
            if (! in_array($previous->state, $rule['from'], true)) {
                $this->refuse('state', 'This return is not in a state that allows that action.');
            }
            $requester = (int) $return->requester_user_id === $actor->id;
            if (in_array($action, ['approved', 'rejected'], true) && $requester) {
                $this->refuse('state', 'You requested this return, so a different Finance Officer or Director must approve or reject it.');
            }
            if ($action === 'cancelled' && ! $requester) {
                $this->refuse('state', 'Only the person who requested this return may cancel it.');
            }
            if (in_array($action, ['approved', 'dispatched'], true)) {
                // Same receipt row lock as ReceiptCorrectionService, taken before SKU and batch as it does.
                DB::table('inventory_goods_receipts')->where('id', $return->goods_receipt_id)->lockForUpdate()->sole();
                if (DB::table('inventory_receipt_corrections')->where('goods_receipt_id', $return->goods_receipt_id)->exists()) {
                    $this->refuse('state', 'The delivery for this return was reversed by a receipt correction, so this stock is no longer here to return. Cancel or reject this return.');
                }
            }
            $extra = ['reason_code' => $data['reason_code'] ?? null, 'reference' => $data['reference'] ?? null];
            if ($action === 'credit_recorded') {
                DB::table('inventory_suppliers')->where('id', $return->supplier_id)->lockForUpdate()->sole();
                $duplicate = DB::table('inventory_supplier_return_actions as a')->join('inventory_supplier_returns as r', 'r.id', '=', 'a.return_id')
                    ->where('a.action', 'credit_recorded')->where('r.organisation_id', $actor->organisation_id)->where('r.supplier_id', $return->supplier_id)
                    ->whereRaw('upper(trim(a.reference)) = ?', [Str::upper((string) $data['reference'])])->exists();
                if ($duplicate) {
                    $this->refuse('reference', 'This credit-note reference is already recorded for this supplier.');
                }
                $extra['credit_amount_sen'] = isset($data['credit_amount_sen']) && $data['credit_amount_sen'] !== '' ? (int) $data['credit_amount_sen'] : null;
            }
            if ($action === 'dispatched') {
                $sku = InventorySku::query()->whereKey($return->inventory_sku_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
                $batch = InventoryBatch::query()->whereKey($return->inventory_batch_id)->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
                $location = InventoryLocation::query()->whereKey($return->inventory_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
                if (! $sku->is_active || ! $location->is_active) {
                    $this->refuse('state', 'This SKU or stock location is inactive, so stock cannot be dispatched from it. Reactivate it in Stock setup first.');
                }
                if ($sku->stock_unit !== $return->base_unit) {
                    $this->refuse('state', 'The stock unit of this SKU changed. Cancel and request the return again.');
                }
                $movement = app(InventoryMovementService::class)->recordSupplierReturn($actor, $location, $sku, $batch, StockUnitQuantity::format(StockUnitQuantity::scaled((string) $return->quantity)), (string) $return->public_id);
                $extra['movement_id'] = $movement->id;
            }
            $this->append($actor, $branch, (int) $return->id, $action, $rule['state'], (int) $previous->lock_version + 1, (string) $data['retry_key'], $hash, $extra);

            return (int) $return->id;
        }, 3);
    }

    /**
     * STK-17: one request returns several lots from ONE verified delivery. Every line is an ordinary STK-7 return
     * row, tied to the group; the whole group then moves together.
     *
     * @param  array<string, mixed>  $input
     */
    public function requestGroup(User $actor, array $input): int
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'retry_key' => ['required', 'uuid'], 'goods_receipt_id' => ['required', 'integer', 'min:1'],
            'supplier_reference' => ['nullable', 'string', 'max:100', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::GROUP_MAX_LINES],
            'lines.*.receipt_lot_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.unit' => ['required', 'string', 'max:100'], 'lines.*.quantity' => ['required', 'string', 'max:30'],
            'lines.*.reason' => ['required', 'string', Rule::in(self::REASONS)],
        ], [], ['lines.*.receipt_lot_id' => 'lot', 'lines.*.quantity' => 'quantity', 'lines.*.reason' => 'reason'])->validate();
        $data['supplier_reference'] = isset($data['supplier_reference']) ? trim((string) $data['supplier_reference']) : '';
        $lines = array_values($data['lines']);
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['request_group', [...$data, 'lines' => array_map(fn (array $line): string => json_encode([(int) $line['receipt_lot_id'], (string) $line['unit'], (string) $line['quantity'], (string) $line['reason']], JSON_THROW_ON_ERROR), $lines)]]);

        return DB::transaction(function () use ($actor, $branch, $data, $lines, $hash): int {
            $actor = $this->authority->lockForBranch($actor, $branch, self::REQUEST);
            $lotIds = array_map(fn (array $line): int => (int) $line['receipt_lot_id'], $lines);
            // Receipt first: a receipt correction locks the same row, so "not corrected" below stays true until commit.
            $receiptLocked = DB::table('inventory_goods_receipts')->where('id', (int) $data['goods_receipt_id'])->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            abort_unless($receiptLocked !== null, 404);
            // A replay returns the group it made, whatever has happened to the delivery since.
            $existing = DB::table('inventory_supplier_return_groups')->where('organisation_id', $actor->organisation_id)->where('requester_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $hash)) {
                    $this->refuse('retry_key', 'This retry key was used for a different supplier return.');
                }

                return (int) $existing->id;
            }
            $candidates = $this->returnableLots($actor, $branch)->where('receipt.id', (int) $data['goods_receipt_id'])->whereIn('lot.id', $lotIds)
                ->get(['lot.id', 'receipt.id as goods_receipt_id', 'delivery.supplier_id', 'po.destination_location_id', 'lot.inventory_batch_id'])->keyBy('id');
            foreach ($lines as $i => $line) {
                if (! $candidates->has((int) $line['receipt_lot_id'])) {
                    $this->refuse("lines.$i.receipt_lot_id", 'Every lot must come from the chosen delivery and still be returnable here.');
                }
            }
            // Then lots in id order, then SKUs and batches in id order: the shared inventory lock order.
            $sortedLots = $lotIds;
            sort($sortedLots);
            $lots = DB::table('inventory_receipt_lots')->whereIn('id', $sortedLots)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $batchIds = $lots->pluck('inventory_batch_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $skuIds = DB::table('inventory_batches')->whereIn('id', $batchIds)->pluck('inventory_sku_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $skus = InventorySku::query()->whereIn('id', $skuIds)->where('organisation_id', $actor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $batches = InventoryBatch::query()->whereIn('id', $batchIds)->where('organisation_id', $actor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $first = $candidates->first();
            $location = InventoryLocation::query()->whereKey($first->destination_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();

            $planned = [];
            foreach ($lines as $i => $line) {
                $lot = $lots->get((int) $line['receipt_lot_id']);
                $batch = $batches->get((int) $lot->inventory_batch_id);
                abort_unless($batch !== null, 404);
                $sku = $skus->get((int) $batch->inventory_sku_id);
                abort_unless($sku !== null, 404);
                try {
                    $quantity = StockUnitQuantity::scaled(StockUnitQuantity::base($line['quantity'], $sku->stock_unit, (string) $line['unit']));
                } catch (ValidationException $e) {
                    $this->refuse("lines.$i.quantity", (string) collect($e->errors())->flatten()->first());
                }
                $returnable = StockUnitQuantity::scaled((string) $lot->quantity) - $this->committed((int) $lot->id);
                if ($quantity > $returnable) {
                    $this->refuse("lines.$i.quantity", 'You can return at most '.StockUnitQuantity::format(max(0, $returnable)).' '.$sku->stock_unit.' from batch '.$batch->batch_number.'.');
                }
                $onHand = (string) (DB::table('inventory_stock_balances')->where('organisation_id', $actor->organisation_id)->where('inventory_location_id', $location->id)->where('inventory_sku_id', $sku->id)->where('inventory_batch_id', $batch->id)->value('quantity') ?? '0');
                if ($quantity > StockUnitQuantity::scaled($onHand, true)) {
                    $this->refuse("lines.$i.quantity", 'Only '.StockUnitQuantity::format(StockUnitQuantity::scaled($onHand, true)).' '.$sku->stock_unit.' of batch '.$batch->batch_number.' is on hand here.');
                }
                $planned[] = [$lot, $sku, $batch, $quantity, (string) $line['reason']];
            }

            $groupId = DB::table('inventory_supplier_return_groups')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'group_number' => 'SRG-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(8)), 'supplier_id' => $first->supplier_id,
                'goods_receipt_id' => (int) $data['goods_receipt_id'], 'requester_user_id' => $actor->id,
                'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'created_at' => now()->utc(),
            ]);
            foreach ($planned as $i => [$lot, $sku, $batch, $quantity, $reason]) {
                $id = DB::table('inventory_supplier_returns')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                    'return_number' => 'SRN-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(8)), 'supplier_id' => $first->supplier_id,
                    'goods_receipt_id' => (int) $data['goods_receipt_id'], 'receipt_lot_id' => $lot->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id,
                    'inventory_location_id' => $location->id, 'base_unit' => $sku->stock_unit, 'quantity' => StockUnitQuantity::format($quantity),
                    'reason' => $reason, 'supplier_reference' => $data['supplier_reference'] === '' ? null : $data['supplier_reference'],
                    'requester_user_id' => $actor->id, 'created_at' => now()->utc(), 'group_id' => $groupId,
                ]);
                $this->append($actor, $branch, $id, 'requested', 'awaiting_approval', 1, $this->lineKey((string) $data['retry_key'], $i), $hash, []);
            }
            abort_if($this->audit->record('inventory.supplier_return_group.requested', null, ['group_id' => $groupId, 'lines' => count($planned)], $actor, $branch) === null, 503, 'The supplier return could not be audited. Nothing was saved or posted.');

            return $groupId;
        }, 3);
    }

    /**
     * STK-17: the whole group takes one action together, in one transaction: every line gets its own STK-7 action
     * row, and a dispatch writes one supplier_return movement per line. All or nothing.
     *
     * @param  array<string, mixed>  $input
     */
    public function actGroup(User $actor, int $groupId, string $action, array $input): int
    {
        abort_unless(array_key_exists($action, self::ACTIONS), 404);
        $rule = self::ACTIONS[$action];
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'retry_key' => ['required', 'uuid'],
            'reason_code' => [$action === 'rejected' ? 'required' : 'prohibited', 'string', Rule::in(self::REJECT_REASONS)],
            'reference' => [in_array($action, ['dispatched', 'credit_recorded'], true) ? 'required' : 'prohibited', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'credit_amount_sen' => [$action === 'credit_recorded' ? 'nullable' : 'prohibited', 'regex:/^\d{1,12}$/'],
        ])->validate();
        if (isset($data['reference'])) {
            $data['reference'] = trim((string) $data['reference']);
            if ($data['reference'] === '') {
                $this->refuse('reference', $action === 'dispatched' ? 'Enter the return delivery note or courier reference.' : 'Enter the supplier credit-note reference.');
            }
        }
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['group', $groupId, $action, $data]);

        return DB::transaction(function () use ($actor, $branch, $groupId, $action, $rule, $data, $hash): int {
            $actor = $this->authority->lockForBranch($actor, $branch, $rule['permission']);
            $group = DB::table('inventory_supplier_return_groups')->where('id', $groupId)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            abort_unless($group !== null, 404);
            // Lines before the receipt, as the single-lot path locks its return before the receipt.
            $returns = DB::table('inventory_supplier_returns')->where('group_id', $group->id)->orderBy('id')->lockForUpdate()->get();
            abort_if($returns->isEmpty(), 404);
            if (in_array($action, ['approved', 'dispatched'], true)) {
                // Same receipt row lock as ReceiptCorrectionService, before SKUs and batches.
                DB::table('inventory_goods_receipts')->where('id', $group->goods_receipt_id)->lockForUpdate()->sole();
            }
            // Checked under the group lock, so a concurrent replay of the same key waits and then replays.
            if ($this->retry($actor, $this->lineKey((string) $data['retry_key'], 0), $hash) !== null) {
                return (int) $group->id;
            }
            $latest = DB::table('inventory_supplier_return_actions')->whereIn('return_id', $returns->pluck('id'))
                ->whereRaw('lock_version = (SELECT MAX(x.lock_version) FROM inventory_supplier_return_actions x WHERE x.return_id = inventory_supplier_return_actions.return_id)')
                ->get(['return_id', 'state', 'lock_version'])->keyBy('return_id');
            foreach ($returns as $return) {
                $previous = $latest->get($return->id);
                if ($previous === null || (int) $previous->lock_version !== (int) $data['lock_version']) {
                    $this->refuse('lock_version', 'This return changed. Reload before continuing.');
                }
                if (! in_array($previous->state, $rule['from'], true)) {
                    $this->refuse('state', 'This return is not in a state that allows that action.');
                }
            }
            $requester = (int) $group->requester_user_id === $actor->id;
            if (in_array($action, ['approved', 'rejected'], true) && $requester) {
                $this->refuse('state', 'You requested this return, so a different Finance Officer or Director must approve or reject it.');
            }
            if ($action === 'cancelled' && ! $requester) {
                $this->refuse('state', 'Only the person who requested this return may cancel it.');
            }
            if (in_array($action, ['approved', 'dispatched'], true) && DB::table('inventory_receipt_corrections')->where('goods_receipt_id', $group->goods_receipt_id)->exists()) {
                $this->refuse('state', 'The delivery for this return was reversed by a receipt correction, so this stock is no longer here to return. Cancel or reject this return.');
            }
            $creditAmount = null;
            if ($action === 'credit_recorded') {
                DB::table('inventory_suppliers')->where('id', $group->supplier_id)->lockForUpdate()->sole();
                $duplicate = DB::table('inventory_supplier_return_actions as a')->join('inventory_supplier_returns as r', 'r.id', '=', 'a.return_id')
                    ->where('a.action', 'credit_recorded')->where('r.organisation_id', $actor->organisation_id)->where('r.supplier_id', $group->supplier_id)
                    ->whereRaw('upper(trim(a.reference)) = ?', [Str::upper((string) $data['reference'])])->exists();
                if ($duplicate) {
                    $this->refuse('reference', 'This credit-note reference is already recorded for this supplier.');
                }
                $creditAmount = isset($data['credit_amount_sen']) && $data['credit_amount_sen'] !== '' ? (int) $data['credit_amount_sen'] : null;
            }
            $skus = collect();
            $batches = collect();
            $location = null;
            if ($action === 'dispatched') {
                $skus = InventorySku::query()->whereIn('id', $returns->pluck('inventory_sku_id')->unique()->sort()->values())->where('organisation_id', $actor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $batches = InventoryBatch::query()->whereIn('id', $returns->pluck('inventory_batch_id')->unique()->sort()->values())->where('organisation_id', $actor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $location = InventoryLocation::query()->whereKey($returns->first()->inventory_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
                if (! $location->is_active || $skus->contains(fn (InventorySku $sku): bool => ! $sku->is_active)) {
                    $this->refuse('state', 'A SKU or the stock location of this return is inactive, so stock cannot be dispatched from it. Reactivate it in Stock setup first.');
                }
            }
            foreach ($returns->values() as $i => $return) {
                $extra = ['reason_code' => $data['reason_code'] ?? null, 'reference' => $data['reference'] ?? null];
                if ($action === 'credit_recorded') {
                    // The total sits on the first line only, so a sum over lines never counts it twice.
                    $extra['credit_amount_sen'] = $i === 0 ? $creditAmount : null;
                }
                if ($action === 'dispatched') {
                    $sku = $skus->get($return->inventory_sku_id);
                    $batch = $batches->get($return->inventory_batch_id);
                    abort_unless($sku instanceof InventorySku && $batch instanceof InventoryBatch, 404);
                    if ($sku->stock_unit !== $return->base_unit) {
                        $this->refuse('state', 'The stock unit of '.$sku->sku_code.' changed. Cancel and request the return again.');
                    }
                    $extra['movement_id'] = app(InventoryMovementService::class)->recordSupplierReturn($actor, $location, $sku, $batch, StockUnitQuantity::format(StockUnitQuantity::scaled((string) $return->quantity)), (string) $return->public_id)->id;
                }
                $this->append($actor, $branch, (int) $return->id, $action, $rule['state'], (int) $data['lock_version'] + 1, $this->lineKey((string) $data['retry_key'], $i), $hash, $extra);
            }

            return (int) $group->id;
        }, 3);
    }

    /**
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
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'lots_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'groups_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'delivery' => ['sometimes', 'integer', 'min:1']])->validate();
        $canRequest = $actor->can(self::REQUEST);
        $canApprove = $actor->can(self::APPROVE);
        $needle = filled($data['q'] ?? null) ? '%'.addcslashes((string) $data['q'], '%_\\').'%' : null;

        $latest = DB::table('inventory_supplier_return_actions')->select('return_id')->selectRaw('MAX(lock_version) as version')->groupBy('return_id');
        $returns = DB::table('inventory_supplier_returns as r')->joinSub($latest, 'latest', 'latest.return_id', '=', 'r.id')
            ->join('inventory_supplier_return_actions as a', fn ($j) => $j->on('a.return_id', '=', 'r.id')->on('a.lock_version', '=', 'latest.version'))
            ->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')->join('inventory_batches as b', 'b.id', '=', 'r.inventory_batch_id')
            ->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')->join('inventory_suppliers as sup', 'sup.id', '=', 'r.supplier_id')
            ->join('inventory_receipt_delivery_evidence as d', 'd.goods_receipt_id', '=', 'r.goods_receipt_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id)->whereNull('r.group_id')
            ->when($needle, fn ($q) => $q->where(fn ($w) => $w->where('s.sku_code', 'ilike', $needle)->orWhere('b.batch_number', 'ilike', $needle)->orWhere('sup.name', 'ilike', $needle)->orWhere('r.return_number', 'ilike', $needle)->orWhere('d.delivery_reference', 'ilike', $needle)))
            ->orderByDesc('r.id')
            ->paginate(25, ['r.id', 'r.public_id', 'r.return_number', 'r.quantity', 'r.base_unit', 'r.reason', 'r.supplier_reference', 'r.requester_user_id', 'r.created_at', 'a.state', 'a.lock_version', 's.sku_code', 'b.batch_number', 'b.expiry_date', 'l.name as location', 'sup.name as supplier', 'd.delivery_reference'], 'page', (int) ($data['page'] ?? 1));
        $histories = DB::table('inventory_supplier_return_actions')->whereIn('return_id', $returns->pluck('id'))->orderBy('lock_version')
            ->get(['id', 'return_id', 'action', 'state', 'actor_user_id', 'reason_code', 'reference', 'credit_amount_sen', 'lock_version', 'movement_id', 'created_at'])->groupBy('return_id');
        // TIDY-4: who did each step, in words (BIL-2 staff-visibility rule), not an id.
        $name = app(InventoryStaffNames::class)->resolver($actor, $histories->flatten()->pluck('actor_user_id'));
        $rows = [];
        foreach ($returns->items() as $row) {
            $requester = (int) $row->requester_user_id === $actor->id;
            $rows[] = [...(array) $row, 'history' => $histories->get($row->id, collect())->map(fn (object $step): array => [...(array) $step, 'actor_name' => $name($step->actor_user_id),
                // The stored UTC time in the branch's own time, as staff read it ("12 Nov 2026, 6:00 PM").
                'at_local' => CarbonImmutable::parse($step->created_at)->setTimezone($branch->timezone)->format('j M Y, g:i A')])->values()->all(),
                'canDecide' => $canApprove && $row->state === 'awaiting_approval' && ! $requester,
                'canDispatch' => $canRequest && $row->state === 'approved',
                'canCredit' => $canApprove && $row->state === 'dispatched',
                'canCancel' => $canRequest && $requester && in_array($row->state, ['awaiting_approval', 'approved'], true),
                'requestedByYou' => $requester,
            ];
        }
        $lots = ['rows' => [], 'total' => 0, 'page' => 1, 'lastPage' => 1];
        if ($canRequest) {
            $committed = DB::table('inventory_supplier_returns as cr')->selectRaw('cr.receipt_lot_id, SUM(cr.quantity) as committed')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_supplier_return_actions as ca')->whereColumn('ca.return_id', 'cr.id')->whereIn('ca.state', ['rejected', 'cancelled']))
                ->groupBy('cr.receipt_lot_id');
            $query = $this->returnableLots($actor, $branch)->join('inventory_suppliers as sup', 'sup.id', '=', 'delivery.supplier_id')
                ->join('inventory_batches as b', 'b.id', '=', 'lot.inventory_batch_id')->join('inventory_skus as s', 's.id', '=', 'b.inventory_sku_id')
                ->join('inventory_locations as l', 'l.id', '=', 'po.destination_location_id')
                ->leftJoinSub($committed, 'committed', 'committed.receipt_lot_id', '=', 'lot.id')
                ->leftJoin('inventory_stock_balances as bal', fn ($j) => $j->on('bal.inventory_location_id', '=', 'po.destination_location_id')->on('bal.inventory_batch_id', '=', 'lot.inventory_batch_id')->on('bal.inventory_sku_id', '=', 's.id'))
                ->whereRaw('lot.quantity - COALESCE(committed.committed, 0) > 0')
                ->when($needle, fn ($q) => $q->where(fn ($w) => $w->where('s.sku_code', 'ilike', $needle)->orWhere('b.batch_number', 'ilike', $needle)->orWhere('sup.name', 'ilike', $needle)->orWhere('delivery.delivery_reference', 'ilike', $needle)));
            $paged = $query->orderByDesc('lot.id')->paginate(25, ['lot.id', 'lot.quantity as received_quantity', 'lot.outcome', 's.sku_code', 's.stock_unit', 'b.batch_number', 'b.expiry_date', 'l.name as location', 'sup.name as supplier', 'delivery.delivery_reference', 'delivery.received_at', DB::raw('COALESCE(committed.committed, 0) as committed_quantity'), DB::raw('COALESCE(bal.quantity, 0) as on_hand')], 'lots_page', (int) ($data['lots_page'] ?? 1));
            $lots = ['rows' => array_map(fn (stdClass $row): array => [...(array) $row, 'returnable' => StockUnitQuantity::format(max(0, min(StockUnitQuantity::scaled((string) $row->received_quantity) - StockUnitQuantity::scaled((string) $row->committed_quantity, true), StockUnitQuantity::scaled((string) $row->on_hand, true))))], $paged->items()), 'total' => $paged->total(), 'page' => $paged->currentPage(), 'lastPage' => $paged->lastPage()];
        }

        $groups = $this->groupsPage($actor, $branch, $canRequest, $canApprove, (int) ($data['groups_page'] ?? 1));
        $deliveries = [];
        $deliveryLots = [];
        if ($canRequest) {
            // STK-17: deliveries that still have something to return, newest first, for the multi-lot form.
            $stillCommitted = DB::table('inventory_supplier_returns as cr')->selectRaw('cr.receipt_lot_id, SUM(cr.quantity) as committed')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_supplier_return_actions as ca')->whereColumn('ca.return_id', 'cr.id')->whereIn('ca.state', ['rejected', 'cancelled']))
                ->groupBy('cr.receipt_lot_id');
            $deliveries = array_map(fn (stdClass $row): array => [...(array) $row, 'lots' => (int) $row->lots, 'received_at' => $row->received_at === null ? null : CarbonImmutable::parse((string) $row->received_at, 'UTC')->toIso8601String()], $this->returnableLots($actor, $branch)->join('inventory_suppliers as sup', 'sup.id', '=', 'delivery.supplier_id')
                ->leftJoinSub($stillCommitted, 'committed', 'committed.receipt_lot_id', '=', 'lot.id')->whereRaw('lot.quantity - COALESCE(committed.committed, 0) > 0')
                ->groupBy('receipt.id', 'delivery.delivery_reference', 'delivery.received_at', 'sup.name')
                ->orderByDesc('receipt.id')->limit(50)
                ->get(['receipt.id', 'delivery.delivery_reference', 'delivery.received_at', 'sup.name as supplier', DB::raw('COUNT(lot.id) as lots')])->all());
            if (isset($data['delivery'])) {
                $deliveryLots = array_values(array_filter($this->lotRows($actor, $branch, (int) $data['delivery']), fn (array $row): bool => StockUnitQuantity::scaled((string) $row['returnable'], true) > 0));
            }
        }

        return ['branchId' => $branch->id, 'branchName' => $branch->name, 'q' => $data['q'] ?? '', 'canRequest' => $canRequest, 'canApprove' => $canApprove,
            'groups' => $groups, 'deliveries' => $deliveries, 'delivery' => isset($data['delivery']) ? (int) $data['delivery'] : null, 'deliveryLots' => $deliveryLots, 'maxGroupLines' => self::GROUP_MAX_LINES,
            'reasons' => self::REASONS, 'rejectReasons' => self::REJECT_REASONS,
            'returns' => ['rows' => $rows, 'total' => $returns->total(), 'page' => $returns->currentPage(), 'lastPage' => $returns->lastPage()], 'lots' => $lots];
    }

    /**
     * STK-17: multi-lot returns at this branch, newest first, each with its lines and the actions this person may take.
     *
     * @return array<string, mixed>
     */
    private function groupsPage(User $actor, Branch $branch, bool $canRequest, bool $canApprove, int $page): array
    {
        $paged = DB::table('inventory_supplier_return_groups as g')->join('inventory_suppliers as sup', 'sup.id', '=', 'g.supplier_id')
            ->join('inventory_receipt_delivery_evidence as d', 'd.goods_receipt_id', '=', 'g.goods_receipt_id')
            ->where('g.organisation_id', $actor->organisation_id)->where('g.branch_id', $branch->id)
            ->orderByDesc('g.id')->paginate(10, ['g.id', 'g.group_number', 'g.requester_user_id', 'g.created_at', 'sup.name as supplier', 'd.delivery_reference'], 'groups_page', $page);
        /** @var list<stdClass> $items */
        $items = $paged->items();
        $lines = DB::table('inventory_supplier_returns as r')->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')->join('inventory_batches as b', 'b.id', '=', 'r.inventory_batch_id')
            ->whereIn('r.group_id', array_map(fn (stdClass $g): int => (int) $g->id, $items))->orderBy('r.id')
            ->get(['r.id', 'r.group_id', 'r.return_number', 'r.quantity', 'r.base_unit', 'r.reason', 's.sku_code', 'b.batch_number', 'b.expiry_date'])->groupBy('group_id');
        $history = DB::table('inventory_supplier_return_actions as a')->join('inventory_supplier_returns as r', 'r.id', '=', 'a.return_id')
            ->whereIn('r.group_id', array_map(fn (stdClass $g): int => (int) $g->id, $items))->orderBy('a.return_id')->orderBy('a.lock_version')
            ->get(['r.group_id', 'a.return_id', 'a.action', 'a.state', 'a.reason_code', 'a.reference', 'a.credit_amount_sen', 'a.lock_version', 'a.created_at'])->groupBy('group_id');
        $rows = [];
        foreach ($items as $group) {
            $groupLines = $lines->get($group->id, collect());
            $firstId = $groupLines->first()?->id;
            // The lines move together, so the first line's history is the group's history.
            $steps = $history->get($group->id, collect())->where('return_id', $firstId)->values();
            $latest = $steps->last();
            $state = (string) ($latest->state ?? 'awaiting_approval');
            $requester = (int) $group->requester_user_id === $actor->id;
            $rows[] = [
                'id' => (int) $group->id, 'groupNumber' => $group->group_number, 'supplier' => $group->supplier, 'deliveryReference' => $group->delivery_reference,
                'createdAt' => $group->created_at, 'state' => $state, 'lockVersion' => (int) ($latest->lock_version ?? 1),
                'lines' => $groupLines->map(fn (stdClass $line): array => (array) $line)->values()->all(),
                'history' => $steps->map(fn (stdClass $step): array => ['action' => $step->action, 'state' => $step->state, 'reasonCode' => $step->reason_code, 'reference' => $step->reference, 'creditAmountSen' => $step->credit_amount_sen === null ? null : (int) $step->credit_amount_sen, 'createdAt' => $step->created_at])->all(),
                'canDecide' => $canApprove && $state === 'awaiting_approval' && ! $requester,
                'canDispatch' => $canRequest && $state === 'approved',
                'canCredit' => $canApprove && $state === 'dispatched',
                'canCancel' => $canRequest && $requester && in_array($state, ['awaiting_approval', 'approved'], true),
                'requestedByYou' => $requester,
            ];
        }

        return ['rows' => $rows, 'total' => $paged->total(), 'page' => $paged->currentPage(), 'lastPage' => $paged->lastPage()];
    }

    /**
     * Returnable lots of one delivery at this branch, with what is still returnable (lot less committed, capped by on hand).
     *
     * @return list<array<string, mixed>>
     */
    private function lotRows(User $actor, Branch $branch, int $goodsReceiptId): array
    {
        $committed = DB::table('inventory_supplier_returns as cr')->selectRaw('cr.receipt_lot_id, SUM(cr.quantity) as committed')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_supplier_return_actions as ca')->whereColumn('ca.return_id', 'cr.id')->whereIn('ca.state', ['rejected', 'cancelled']))
            ->groupBy('cr.receipt_lot_id');
        $rows = $this->returnableLots($actor, $branch)->where('receipt.id', $goodsReceiptId)
            ->join('inventory_batches as b', 'b.id', '=', 'lot.inventory_batch_id')->join('inventory_skus as s', 's.id', '=', 'b.inventory_sku_id')
            ->leftJoinSub($committed, 'committed', 'committed.receipt_lot_id', '=', 'lot.id')
            ->leftJoin('inventory_stock_balances as bal', fn ($j) => $j->on('bal.inventory_location_id', '=', 'po.destination_location_id')->on('bal.inventory_batch_id', '=', 'lot.inventory_batch_id')->on('bal.inventory_sku_id', '=', 's.id'))
            ->orderBy('lot.id')->limit(100)
            ->get(['lot.id', 's.sku_code', 's.stock_unit', 'b.batch_number', 'b.expiry_date', 'lot.quantity as received_quantity', DB::raw('COALESCE(committed.committed, 0) as committed_quantity'), DB::raw('COALESCE(bal.quantity, 0) as on_hand')]);

        return array_values(array_map(fn (stdClass $row): array => [...(array) $row, 'returnable' => StockUnitQuantity::format(max(0, min(StockUnitQuantity::scaled((string) $row->received_quantity) - StockUnitQuantity::scaled((string) $row->committed_quantity, true), StockUnitQuantity::scaled((string) $row->on_hand, true))))], $rows->all()));
    }

    /** Verified, uncorrected receipt lots that entered stock at this branch (accepted or quarantined). */
    private function returnableLots(User $actor, Branch $branch): Builder
    {
        return DB::table('inventory_receipt_lots as lot')
            ->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')
            ->join('inventory_goods_receipts as receipt', 'receipt.id', '=', 'line.goods_receipt_id')
            ->join('inventory_receipt_delivery_evidence as delivery', 'delivery.goods_receipt_id', '=', 'receipt.id')
            ->join('inventory_purchase_orders as po', 'po.id', '=', 'receipt.purchase_order_id')
            ->where('receipt.organisation_id', $actor->organisation_id)->where('delivery.branch_id', $branch->id)
            ->whereIn('lot.outcome', ['accepted', 'quarantined'])->whereNotNull('lot.inventory_batch_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_receipt_corrections as c')->whereColumn('c.goods_receipt_id', 'receipt.id'));
    }

    /** Thousandths already committed to returns of this lot that are not rejected or cancelled. */
    private function committed(int $lotId): int
    {
        $sum = DB::table('inventory_supplier_returns as r')->where('r.receipt_lot_id', $lotId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_supplier_return_actions as a')->whereColumn('a.return_id', 'r.id')->whereIn('a.state', ['rejected', 'cancelled']))
            ->selectRaw('COALESCE(SUM(r.quantity), 0) AS total')->value('total');

        return StockUnitQuantity::scaled(is_numeric($sum) ? (string) $sum : '0', true);
    }

    /** @param array<mixed> $payload */
    private function fingerprint(array $payload): string
    {
        foreach ($payload as &$part) {
            if (is_array($part)) {
                ksort($part);
            }
        }
        unset($part);

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function retry(User $actor, string $key, string $hash): ?int
    {
        $retry = DB::table('inventory_supplier_return_actions')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $key)->first();
        if ($retry === null) {
            return null;
        }
        if (! hash_equals((string) $retry->payload_hash, $hash)) {
            $this->refuse('retry_key', 'This retry key was used for a different supplier return action.');
        }

        return (int) $retry->return_id;
    }

    /** @param array<string, mixed> $extra */
    private function append(User $actor, Branch $branch, int $returnId, string $action, string $state, int $version, string $key, string $hash, array $extra): void
    {
        $id = DB::table('inventory_supplier_return_actions')->insertGetId([
            'organisation_id' => $actor->organisation_id, 'return_id' => $returnId, 'actor_user_id' => $actor->id, 'action' => $action, 'state' => $state,
            'lock_version' => $version, 'reason_code' => $extra['reason_code'] ?? null, 'reference' => $extra['reference'] ?? null,
            'credit_amount_sen' => $extra['credit_amount_sen'] ?? null, 'movement_id' => $extra['movement_id'] ?? null,
            'retry_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc(),
        ]);
        abort_if($this->audit->record('inventory.supplier_return.'.$action, null, ['return_id' => $returnId, 'action_id' => $id, 'lock_version' => $version, 'state' => $state], $actor, $branch) === null, 503, 'The supplier return could not be audited. Nothing was saved or posted.');
    }

    /** One retry key per line, derived from the group's key, so each line keeps the STK-7 retry rule. */
    private function lineKey(string $groupKey, int $index): string
    {
        return Uuid::uuid5($groupKey, 'line-'.$index)->toString();
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
