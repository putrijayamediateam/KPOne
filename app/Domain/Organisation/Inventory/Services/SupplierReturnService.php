<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'lots_page' => ['sometimes', 'integer', 'min:1', 'max:1000000']])->validate();
        $canRequest = $actor->can(self::REQUEST);
        $canApprove = $actor->can(self::APPROVE);
        $needle = filled($data['q'] ?? null) ? '%'.addcslashes((string) $data['q'], '%_\\').'%' : null;

        $latest = DB::table('inventory_supplier_return_actions')->select('return_id')->selectRaw('MAX(lock_version) as version')->groupBy('return_id');
        $returns = DB::table('inventory_supplier_returns as r')->joinSub($latest, 'latest', 'latest.return_id', '=', 'r.id')
            ->join('inventory_supplier_return_actions as a', fn ($j) => $j->on('a.return_id', '=', 'r.id')->on('a.lock_version', '=', 'latest.version'))
            ->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')->join('inventory_batches as b', 'b.id', '=', 'r.inventory_batch_id')
            ->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')->join('inventory_suppliers as sup', 'sup.id', '=', 'r.supplier_id')
            ->join('inventory_receipt_delivery_evidence as d', 'd.goods_receipt_id', '=', 'r.goods_receipt_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id)
            ->when($needle, fn ($q) => $q->where(fn ($w) => $w->where('s.sku_code', 'ilike', $needle)->orWhere('b.batch_number', 'ilike', $needle)->orWhere('sup.name', 'ilike', $needle)->orWhere('r.return_number', 'ilike', $needle)->orWhere('d.delivery_reference', 'ilike', $needle)))
            ->orderByDesc('r.id')
            ->paginate(25, ['r.id', 'r.public_id', 'r.return_number', 'r.quantity', 'r.base_unit', 'r.reason', 'r.supplier_reference', 'r.requester_user_id', 'r.created_at', 'a.state', 'a.lock_version', 's.sku_code', 'b.batch_number', 'b.expiry_date', 'l.name as location', 'sup.name as supplier', 'd.delivery_reference'], 'page', (int) ($data['page'] ?? 1));
        $histories = DB::table('inventory_supplier_return_actions')->whereIn('return_id', $returns->pluck('id'))->orderBy('lock_version')
            ->get(['id', 'return_id', 'action', 'state', 'actor_user_id', 'reason_code', 'reference', 'credit_amount_sen', 'lock_version', 'movement_id', 'created_at'])->groupBy('return_id');
        $rows = [];
        foreach ($returns->items() as $row) {
            $requester = (int) $row->requester_user_id === $actor->id;
            $rows[] = [...(array) $row, 'history' => $histories->get($row->id, collect())->values()->all(),
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

        return ['branchId' => $branch->id, 'branchName' => $branch->name, 'q' => $data['q'] ?? '', 'canRequest' => $canRequest, 'canApprove' => $canApprove,
            'reasons' => self::REASONS, 'rejectReasons' => self::REJECT_REASONS,
            'returns' => ['rows' => $rows, 'total' => $returns->total(), 'page' => $returns->currentPage(), 'lastPage' => $returns->lastPage()], 'lots' => $lots];
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
            ->value(DB::raw('COALESCE(SUM(r.quantity), 0)'));

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

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
