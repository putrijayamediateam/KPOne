<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * STK-22 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): three-way match of a supplier invoice. A CA Supervisor
 * or Finance Officer records the invoice against one purchase order; each line is compared with what was received
 * (quantity, exact) and with the cost recorded at receipt, or the order's estimate when no actual cost was recorded
 * (price, to the sen; zero tolerance in v1). Every line matching makes the invoice Matched; anything else waits for a
 * Finance Officer or Director who did not record it to accept or reject it. KPOne pays nothing.
 */
final class SupplierInvoiceService
{
    public const RECORD = 'inventory.supplier_invoices.record.branch';

    public const APPROVE = 'inventory.supplier_invoices.approve.branch';

    public const ACCEPT_REASONS = ['price_agreed', 'credit_note_expected', 'rounding', 'other'];

    public const REJECT_REASONS = ['wrong_quantity', 'wrong_price', 'not_our_order', 'other'];

    /** Orders whose received stock can be invoiced. */
    private const INVOICEABLE = [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_FULLY_RECEIVED, PurchaseOrder::STATUS_CLOSED];

    private const PAGE_SIZE = 25;

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit, private StaffAccessService $staff) {}

    /** @param array<string, mixed> $input */
    public function record(User $actor, array $input): string
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'], 'purchase_order_public_id' => ['required', 'uuid'],
            'invoice_number' => ['required', 'string', 'max:60', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'], 'invoice_date' => ['required', 'date_format:Y-m-d'],
            'stated_total' => ['nullable', 'string', 'max:20'], 'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_public_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required'], 'lines.*.total' => ['required', 'string', 'max:20'],
        ], [], ['invoice_number' => 'invoice number', 'invoice_date' => 'invoice date', 'stated_total' => 'invoice total'])->validate();
        $number = trim((string) $data['invoice_number']);
        if ($number === '') {
            throw ValidationException::withMessages(['invoice_number' => 'Enter the invoice number.']);
        }
        $branch = $this->authority->activeBranch($actor, $data);
        $today = now()->setTimezone($branch->timezone)->toDateString();
        if ((string) $data['invoice_date'] > $today) {
            throw ValidationException::withMessages(['invoice_date' => 'The invoice date cannot be in the future.']);
        }
        $entered = [];
        foreach ($data['lines'] as $index => $line) {
            $entered[strtolower((string) $line['line_public_id'])] = [
                'quantity' => StockUnitQuantity::scaled((string) $line['quantity'], true),
                'total' => $this->sen((string) $line['total'], "lines.{$index}.total"),
            ];
        }
        $stated = isset($data['stated_total']) && trim((string) $data['stated_total']) !== '' ? $this->sen((string) $data['stated_total'], 'stated_total') : null;
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower((string) $data['purchase_order_public_id']), $number, $data['invoice_date'], $stated, $entered], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $branch, $data, $number, $entered, $stated, $key, $hash): string {
            $actor = $this->authority->lockForBranch($actor, $branch, self::RECORD);
            $order = PurchaseOrder::query()->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower((string) $data['purchase_order_public_id']))->lockForUpdate()->first();
            abort_unless($order !== null && (int) DB::table('inventory_locations')->where('id', $order->destination_location_id)->value('branch_id') === $branch->id, 404);
            $existing = DB::table('inventory_supplier_invoices')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['public_id', 'request_hash', 'recorded_by_user_id']);
            if ($existing !== null) {
                if ($existing->request_hash !== $hash || (int) $existing->recorded_by_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This page was already used for a different invoice. Reload and try again.']);
                }

                return (string) $existing->public_id;
            }
            if (! in_array($order->status, self::INVOICEABLE, true)) {
                throw ValidationException::withMessages(['purchase_order_public_id' => 'Record an invoice once stock has been received on the order.']);
            }
            $invoiceKey = Str::upper($number);
            $live = DB::table('inventory_supplier_invoices')->where('organisation_id', $actor->organisation_id)->where('status', '<>', 'rejected');
            if ((clone $live)->where('supplier_id', $order->supplier_id)->where('invoice_key', $invoiceKey)->exists()) {
                throw ValidationException::withMessages(['invoice_number' => 'This supplier invoice number is already recorded. Reject the earlier one first to record a corrected copy.']);
            }
            if ((clone $live)->where('purchase_order_id', $order->id)->exists()) {
                throw ValidationException::withMessages(['purchase_order_public_id' => 'This order already has an invoice. Reject it first to record a corrected one.']);
            }
            $lines = DB::table('inventory_purchase_order_lines')->where('purchase_order_id', $order->id)->orderBy('id')->lockForUpdate()->get(['id', 'public_id', 'ordered_quantity', 'received_quantity', 'estimated_unit_cost_sen']);
            if (array_diff(array_keys($entered), $lines->pluck('public_id')->map(fn (mixed $id): string => strtolower((string) $id))->all()) !== [] || count($entered) !== $lines->count()) {
                throw ValidationException::withMessages(['lines' => 'Enter every line of the order, using 0 for a line that is not on this invoice.']);
            }
            $linesTotal = array_sum(array_column($entered, 'total'));
            if ($stated !== null && $stated !== $linesTotal) {
                throw ValidationException::withMessages(['stated_total' => 'The invoice total does not equal the sum of its lines. Check the figures.']);
            }
            $actual = $this->actualReceiptCosts(array_values(array_map(fn (stdClass $line): int => (int) $line->id, $lines->all())));
            $rows = [];
            $matched = true;
            foreach ($lines as $line) {
                $received = StockUnitQuantity::scaled((string) $line->received_quantity, true);
                $input = $entered[strtolower((string) $line->public_id)];
                [$expected, $basis] = $this->expected($received, $actual[(int) $line->id] ?? null, $line->estimated_unit_cost_sen === null ? null : (int) $line->estimated_unit_cost_sen);
                $quantityOk = $input['quantity'] === $received;
                // With nothing to compare against, only a zero line total can match.
                $priceOk = $expected === null ? $input['total'] === 0 : $input['total'] === $expected;
                $result = match (true) {
                    $quantityOk && $priceOk => 'matches',
                    $quantityOk && $expected === null => 'price_unknown',
                    $quantityOk => 'price_differs',
                    $priceOk => 'quantity_differs',
                    default => 'both_differ',
                };
                $matched = $matched && $result === 'matches';
                $rows[] = ['purchase_order_line_id' => $line->id, 'ordered_quantity' => $line->ordered_quantity, 'received_quantity' => StockUnitQuantity::format($received),
                    'invoiced_quantity' => StockUnitQuantity::format($input['quantity']), 'invoiced_total_sen' => $input['total'], 'expected_total_sen' => $expected, 'price_basis' => $basis, 'result' => $result];
            }
            $status = $matched ? 'matched' : 'waiting';
            $publicId = (string) Str::uuid();
            $id = DB::table('inventory_supplier_invoices')->insertGetId([
                'public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'purchase_order_id' => $order->id, 'supplier_id' => $order->supplier_id,
                'invoice_number' => $number, 'invoice_key' => $invoiceKey, 'invoice_date' => $data['invoice_date'], 'lines_total_sen' => $linesTotal, 'stated_total_sen' => $stated,
                'status' => $status, 'recorded_by_user_id' => $actor->id, 'recorded_at' => now()->utc(), 'idempotency_key' => $key, 'request_hash' => $hash, 'lock_version' => 1,
            ]);
            DB::table('inventory_supplier_invoice_lines')->insert(array_map(fn (array $row): array => ['supplier_invoice_id' => $id, ...$row], $rows));
            // Ids and codes only: no amounts, numbers or names in the audit.
            abort_if($this->audit->record('inventory.supplier_invoice.recorded', $order, ['supplier_invoice_id' => $id, 'purchase_order_id' => $order->id, 'status' => $status,
                'lines_differ' => count(array_filter($rows, fn (array $row): bool => $row['result'] !== 'matches'))], $actor, $branch) === null, 503, 'The invoice could not be recorded, so nothing was saved.');

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, string $publicId, string $decision, array $input): void
    {
        abort_unless(in_array($decision, ['accepted', 'rejected'], true), 404);
        $reasons = $decision === 'accepted' ? self::ACCEPT_REASONS : self::REJECT_REASONS;
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in($reasons)], 'note' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ], ['reason.required' => 'Choose a reason.', 'reason.in' => 'Choose a reason.'])->validate();
        $note = trim((string) ($data['note'] ?? ''));
        if ($data['reason'] === 'other' && $note === '') {
            throw ValidationException::withMessages(['note' => 'Add a note to explain why.']);
        }
        $branch = $this->authority->activeBranch($actor, $data);

        DB::transaction(function () use ($actor, $branch, $publicId, $decision, $data, $note): void {
            $actor = $this->authority->lockForBranch($actor, $branch, self::APPROVE);
            $invoice = DB::table('inventory_supplier_invoices')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('public_id', strtolower($publicId))->lockForUpdate()->first();
            abort_unless($invoice !== null, 404);
            if ((int) $invoice->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This invoice changed. Reload and review it again.']);
            }
            if ($invoice->status !== 'waiting') {
                throw ValidationException::withMessages(['status' => 'Only an invoice waiting for approval can be accepted or rejected.']);
            }
            if ((int) $invoice->recorded_by_user_id === $actor->id) {
                throw new AuthorizationException('You recorded this invoice, so a different Finance Officer or Director must decide it.');
            }
            DB::table('inventory_supplier_invoices')->where('id', $invoice->id)->update(['status' => $decision, 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'decision_reason' => $data['reason'], 'decision_note' => $note === '' ? null : $note, 'lock_version' => (int) $invoice->lock_version + 1]);
            $order = PurchaseOrder::query()->whereKey((int) $invoice->purchase_order_id)->firstOrFail();
            abort_if($this->audit->record('inventory.supplier_invoice.'.$decision, $order, ['supplier_invoice_id' => (int) $invoice->id, 'reason' => $data['reason']], $actor, $branch) === null,
                503, 'The decision could not be recorded, so nothing was changed.');
        }, 3);
    }

    /**
     * The page: invoices at the active branch by tab, orders ready to invoice, and who may do what. Money reaches the
     * page only for holders of the cost permission (owner rule: cost is for the Director and Finance Officer).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, Branch $branch, array $query): array
    {
        $tab = in_array($query['tab'] ?? null, ['waiting', 'matched', 'accepted', 'rejected'], true) ? (string) $query['tab'] : 'waiting';
        $can = ['record' => $actor->can(self::RECORD), 'approve' => $actor->can(self::APPROVE)];
        // Anyone who views Inventory reaches the page from its task row, but only recorders and approvers see invoices.
        if (! $can['record'] && ! $can['approve']) {
            return ['expectedBranchId' => $branch->id, 'branchName' => $branch->name, 'tab' => $tab, 'showAmounts' => false, 'counts' => ['waiting' => 0, 'matched' => 0, 'accepted' => 0, 'rejected' => 0],
                'can' => $can, 'invoices' => ['page' => 1, 'lastPage' => 1, 'total' => 0, 'rows' => []], 'orders' => []];
        }
        $cost = $actor->can(SupplierTermsService::COST_PERMISSION);
        $base = DB::table('inventory_supplier_invoices as i')->join('inventory_purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')->join('inventory_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->where('i.organisation_id', $actor->organisation_id)->where('i.branch_id', $branch->id);
        $counts = (clone $base)->groupBy('i.status')->selectRaw('i.status, count(*) as total')->pluck('total', 'status');
        $page = (clone $base)->where('i.status', $tab)->orderByDesc('i.recorded_at')->orderByDesc('i.id')
            ->paginate(self::PAGE_SIZE, ['i.id', 'i.public_id', 'i.invoice_number', 'i.invoice_date', 'i.lines_total_sen', 'i.status', 'i.lock_version', 'i.recorded_by_user_id', 'i.recorded_at',
                'i.decided_by_user_id', 'i.decided_at', 'i.decision_reason', 'i.decision_note', 'po.order_number', 's.name as supplier'], 'page', max(1, (int) ($query['page'] ?? 1)));
        /** @var list<stdClass> $invoices */
        $invoices = $page->items();
        $ids = array_map(fn (stdClass $row): int => (int) $row->id, $invoices);
        $lines = DB::table('inventory_supplier_invoice_lines as l')->join('inventory_purchase_order_lines as pol', 'pol.id', '=', 'l.purchase_order_line_id')
            ->join('inventory_skus as sku', 'sku.id', '=', 'pol.inventory_sku_id')->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')
            ->whereIn('l.supplier_invoice_id', $ids === [] ? [0] : $ids)->orderBy('l.id')
            ->get(['l.supplier_invoice_id', 'item.generic_name as item', 'sku.sku_code as sku', 'sku.stock_unit', 'l.ordered_quantity', 'l.received_quantity', 'l.invoiced_quantity', 'l.invoiced_total_sen', 'l.expected_total_sen', 'l.price_basis', 'l.result'])
            ->groupBy('supplier_invoice_id');
        $people = array_values(array_unique(array_filter(array_merge(array_map(fn (stdClass $r): int => (int) $r->recorded_by_user_id, $invoices), array_map(fn (stdClass $r): int => (int) $r->decided_by_user_id, $invoices)))));
        $names = $people === [] ? collect() : $this->staff->visibleUsers($actor)->whereIn('id', $people)->pluck('name', 'id');
        $local = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($branch->timezone)->format('j M Y');

        return [
            'expectedBranchId' => $branch->id, 'branchName' => $branch->name, 'tab' => $tab, 'showAmounts' => $cost,
            'counts' => ['waiting' => (int) ($counts['waiting'] ?? 0), 'matched' => (int) ($counts['matched'] ?? 0), 'accepted' => (int) ($counts['accepted'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0)],
            'can' => $can,
            'invoices' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'rows' => array_map(fn (stdClass $row): array => [
                'publicId' => $row->public_id, 'number' => $row->invoice_number, 'invoiceDate' => CarbonImmutable::parse((string) $row->invoice_date)->format('j M Y'), 'orderNumber' => $row->order_number,
                'supplier' => $row->supplier, 'status' => $row->status, 'lockVersion' => (int) $row->lock_version,
                'recordedBy' => $names->get((int) $row->recorded_by_user_id, 'Staff member'), 'recordedOn' => $local((string) $row->recorded_at), 'recordedByYou' => (int) $row->recorded_by_user_id === $actor->id,
                'decidedBy' => $row->decided_by_user_id === null ? null : $names->get((int) $row->decided_by_user_id, 'Staff member'), 'decidedOn' => $local($row->decided_at),
                'decisionReason' => $row->decision_reason, 'decisionNote' => $row->decision_note,
                ...($cost ? ['totalSen' => (int) $row->lines_total_sen] : []),
                'lines' => collect($lines->get($row->id, []))->map(fn (stdClass $line): array => [
                    'item' => $line->item, 'sku' => $line->sku, 'stockUnit' => $line->stock_unit, 'result' => $line->result,
                    'orderedQuantity' => (string) $line->ordered_quantity, 'receivedQuantity' => (string) $line->received_quantity, 'invoicedQuantity' => (string) $line->invoiced_quantity,
                    ...($cost ? ['invoicedSen' => (int) $line->invoiced_total_sen, 'expectedSen' => $line->expected_total_sen === null ? null : (int) $line->expected_total_sen, 'priceBasis' => $line->price_basis] : []),
                ])->values()->all(),
            ], $invoices)],
            'orders' => $actor->can(self::RECORD) ? $this->invoiceableOrders($actor, $branch) : [],
        ];
    }

    /**
     * Orders at the branch with received stock and no live invoice, each with its lines for the record form. No price
     * is sent: the recorder types the figures from the paper invoice.
     *
     * @return list<array<string, mixed>>
     */
    private function invoiceableOrders(User $actor, Branch $branch): array
    {
        $orders = DB::table('inventory_purchase_orders as po')->join('inventory_locations as loc', 'loc.id', '=', 'po.destination_location_id')->join('inventory_suppliers as s', 's.id', '=', 'po.supplier_id')
            ->where('po.organisation_id', $actor->organisation_id)->where('loc.branch_id', $branch->id)->whereIn('po.status', self::INVOICEABLE)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_supplier_invoices as i')->whereColumn('i.purchase_order_id', 'po.id')->where('i.status', '<>', 'rejected'))
            ->orderByDesc('po.created_at')->limit(50)->get(['po.id', 'po.public_id', 'po.order_number', 'po.status', 's.name as supplier']);
        $ids = $orders->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $lines = DB::table('inventory_purchase_order_lines as l')->join('inventory_skus as sku', 'sku.id', '=', 'l.inventory_sku_id')->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')
            ->whereIn('l.purchase_order_id', $ids === [] ? [0] : $ids)->orderBy('l.id')
            ->get(['l.purchase_order_id', 'l.public_id', 'item.generic_name as item', 'sku.sku_code as sku', 'sku.stock_unit', 'l.ordered_quantity', 'l.received_quantity'])->groupBy('purchase_order_id');

        return array_values($orders->map(fn (stdClass $order): array => ['publicId' => $order->public_id, 'number' => $order->order_number, 'status' => $order->status, 'supplier' => $order->supplier,
            'lines' => collect($lines->get($order->id, []))->map(fn (stdClass $line): array => ['publicId' => $line->public_id, 'item' => $line->item, 'sku' => $line->sku, 'stockUnit' => $line->stock_unit,
                'orderedQuantity' => (string) $line->ordered_quantity, 'receivedQuantity' => (string) $line->received_quantity])->values()->all()])->all());
    }

    /**
     * What the received stock on each line actually cost, from receipt lots recorded with an actual cost (STK-10B),
     * leaving out reversed receipts and rejected lots. A line appears only when every counted lot has an actual cost.
     *
     * @param  list<int>  $lineIds
     * @return array<int, array{quantity: int, sen: int}>
     */
    private function actualReceiptCosts(array $lineIds): array
    {
        $lots = DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as e', 'e.id', '=', 'lot.line_evidence_id')
            ->whereIn('e.purchase_order_line_id', $lineIds === [] ? [0] : $lineIds)->where('lot.outcome', '<>', 'rejected')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_receipt_corrections as c')->whereColumn('c.goods_receipt_id', 'e.goods_receipt_id'))
            ->get(['e.purchase_order_line_id', 'lot.quantity', 'lot.cost_source', 'lot.cost_total_sen', 'lot.unit_cost_sen']);
        $costs = [];
        $unknown = [];
        foreach ($lots as $lot) {
            $line = (int) $lot->purchase_order_line_id;
            $quantity = StockUnitQuantity::scaled((string) $lot->quantity, true);
            $sen = match (true) {
                $lot->cost_source === 'actual' && $lot->cost_total_sen !== null => (int) $lot->cost_total_sen,
                $lot->cost_source === 'actual' && $lot->unit_cost_sen !== null => ExactMoney::line($quantity, (int) $lot->unit_cost_sen),
                default => null,
            };
            if ($sen === null) {
                $unknown[$line] = true;

                continue;
            }
            $costs[$line] = ['quantity' => ($costs[$line]['quantity'] ?? 0) + $quantity, 'sen' => ($costs[$line]['sen'] ?? 0) + $sen];
        }

        return array_diff_key($costs, $unknown);
    }

    /**
     * The expected line total and where it came from: the receipt's actual cost when it covers everything received,
     * otherwise the order's estimate for the received quantity (half up), otherwise nothing to compare with.
     *
     * @param  array{quantity: int, sen: int}|null  $actual
     * @return array{0: int|null, 1: string}
     */
    private function expected(int $received, ?array $actual, ?int $estimateUnitSen): array
    {
        if ($actual !== null && $actual['quantity'] === $received) {
            return [$actual['sen'], 'receipt'];
        }
        if ($estimateUnitSen !== null) {
            return [ExactMoney::line($received, $estimateUnitSen), 'estimate'];
        }

        return $received === 0 ? [0, 'estimate'] : [null, 'none'];
    }

    /** Ringgit text ("1,234.50" or "1234.5") to whole sen, with no float maths. */
    private function sen(string $value, string $field): int
    {
        $clean = str_replace([',', ' '], '', trim($value));
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $clean, $match) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter an amount in ringgit, for example 125.50.']);
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }
}
