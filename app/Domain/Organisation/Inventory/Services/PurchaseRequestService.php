<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * STK-18 (owner decision 2026-11-12, "agree purchase requests"): a CA or CA Supervisor asks for stock at their branch.
 * A CA Supervisor or Finance Officer who holds the purchase order permission at that branch, and who is NEVER the
 * requester, converts the request into a draft purchase order (they may change quantities; the asked quantity stays
 * on the line) or rejects it with a reason. The requester may withdraw it while it waits. The order then follows the
 * existing submit, approve and receive path. "Never the requester" is enforced here and in PostgreSQL.
 */
final class PurchaseRequestService
{
    public const CREATE = 'inventory.purchase_requests.create.branch';

    public const REJECT_REASONS = ['not_needed', 'stock_elsewhere', 'budget', 'other'];

    private const PAGE_SIZE = 25;

    private const MAX_LINES = 30;

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit, private StaffAccessService $staff, private ProcurementService $procurement) {}

    /** @param array<string, mixed> $input */
    public function send(User $actor, array $input): string
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'],
            'destination_location_public_id' => ['required', 'uuid'], 'preferred_supplier_public_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES], 'lines.*.sku_public_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required'], 'lines.*.note' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ], [
            'lines.required' => 'Add at least one line.', 'lines.min' => 'Add at least one line.', 'lines.max' => 'A request holds at most 30 lines.',
            'lines.*.sku_public_id.distinct' => 'This SKU is already on the request.', 'destination_location_public_id.required' => 'Choose where the stock should go.',
        ])->validate();
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            try {
                $quantity = StockUnitQuantity::scaled((string) $line['quantity']);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => collect($e->errors())->flatten()->first() ?? 'Enter a quantity above zero.']);
            }
            $note = trim((string) ($line['note'] ?? ''));
            $lines[] = ['sku' => strtolower((string) $line['sku_public_id']), 'quantity' => $quantity, 'note' => $note === '' ? null : $note];
        }
        $branch = $this->authority->activeBranch($actor, $data);
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower((string) $data['destination_location_public_id']), strtolower((string) ($data['preferred_supplier_public_id'] ?? '')), $lines], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $branch, $data, $lines, $key, $hash): string {
            $actor = $this->authority->lockForBranch($actor, $branch, self::CREATE);
            $existing = DB::table('inventory_purchase_requests')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['public_id', 'request_hash', 'requested_by_user_id']);
            if ($existing !== null) {
                if ($existing->request_hash !== $hash || (int) $existing->requested_by_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This page was already used for a different request. Reload and try again.']);
                }

                return (string) $existing->public_id;
            }
            $location = InventoryLocation::query()->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower((string) $data['destination_location_public_id']))
                ->where('branch_id', $branch->id)->where('is_active', true)->first();
            if ($location === null || $location->type === InventoryLocation::TYPE_DISPENSARY_RETURNS) {
                throw ValidationException::withMessages(['destination_location_public_id' => 'Choose an active stock location at this branch.']);
            }
            $supplierId = null;
            if (filled($data['preferred_supplier_public_id'] ?? null)) {
                $supplierId = DB::table('inventory_suppliers')->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower((string) $data['preferred_supplier_public_id']))->where('is_active', true)->value('id');
                if ($supplierId === null) {
                    throw ValidationException::withMessages(['preferred_supplier_public_id' => 'Choose an active supplier, or leave it empty.']);
                }
            }
            $skus = DB::table('inventory_skus')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('public_id', array_column($lines, 'sku'))->pluck('id', 'public_id');
            foreach ($lines as $index => $line) {
                if (! $skus->has($line['sku'])) {
                    throw ValidationException::withMessages(["lines.{$index}.sku_public_id" => 'Choose an active SKU.']);
                }
            }
            $publicId = (string) Str::uuid();
            $id = DB::table('inventory_purchase_requests')->insertGetId([
                'public_id' => $publicId, 'request_number' => 'PR-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(6)), 'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id, 'destination_location_id' => $location->id, 'preferred_supplier_id' => $supplierId, 'status' => 'waiting',
                'requested_by_user_id' => $actor->id, 'requested_at' => now()->utc(), 'lock_version' => 1, 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            DB::table('inventory_purchase_request_lines')->insert(array_map(fn (array $line): array => ['organisation_id' => $actor->organisation_id, 'purchase_request_id' => $id,
                'inventory_sku_id' => (int) $skus[$line['sku']], 'requested_quantity' => StockUnitQuantity::format($line['quantity']), 'note' => $line['note']], $lines));
            $this->action($actor, $id, 'sent');
            abort_if($this->audit->record('inventory.purchase_request.sent', null, ['purchase_request_id' => $id, 'lines' => count($lines)], $actor, $branch) === null,
                503, 'The request could not be recorded, so nothing was saved.');

            return $publicId;
        }, 3);
    }

    /**
     * Converts a waiting request into a DRAFT purchase order through the existing ProcurementService, so the order
     * gets the same supplier, location and SKU checks as any other draft. Never by the requester.
     *
     * @param  array<string, mixed>  $input
     */
    public function convert(User $actor, string $publicId, array $input): string
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'supplier_public_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES], 'lines.*.line_id' => ['required', 'integer', 'distinct'], 'lines.*.quantity' => ['required'],
        ], ['supplier_public_id.required' => 'Choose the supplier.'])->validate();
        $quantities = [];
        foreach ($data['lines'] as $index => $line) {
            try {
                $quantities[(int) $line['line_id']] = StockUnitQuantity::scaled((string) $line['quantity'], true);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => collect($e->errors())->flatten()->first() ?? 'Enter a quantity, or 0 to leave the line out.']);
            }
        }
        if (array_sum($quantities) === 0) {
            throw ValidationException::withMessages(['lines' => 'At least one line needs a quantity above zero.']);
        }
        $branch = $this->authority->activeBranch($actor, $data);

        return DB::transaction(function () use ($actor, $branch, $publicId, $data, $quantities): string {
            $actor = $this->authority->lockForBranch($actor, $branch, ProcurementService::CREATE_PERMISSION);
            $request = $this->lockRequest($actor, $branch, $publicId);
            $supplierId = DB::table('inventory_suppliers')->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower((string) $data['supplier_public_id']))->value('id');
            $replay = $this->replayedConversion($actor, $request, (int) $data['lock_version'], $supplierId === null ? null : (int) $supplierId, $quantities);
            if ($replay !== null) {
                // The same reviewer pressed Convert again after a lost response: give back the draft order already made.
                return $replay;
            }
            $this->assertWaiting($request, (int) $data['lock_version']);
            $this->refuseRequester($actor, $request, 'review');
            $lines = DB::table('inventory_purchase_request_lines as l')->join('inventory_skus as s', 's.id', '=', 'l.inventory_sku_id')
                ->where('l.purchase_request_id', $request->id)->orderBy('l.id')->lockForUpdate()->get(['l.id', 's.public_id as sku_public_id']);
            if ($lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all() !== collect(array_keys($quantities))->sort()->values()->all()) {
                throw ValidationException::withMessages(['lines' => 'Give a quantity for every line, using 0 to leave one out.']);
            }
            $location = InventoryLocation::query()->whereKey($request->destination_location_id)->where('is_active', true)->first();
            if ($location === null) {
                throw ValidationException::withMessages(['request' => 'The delivery location is no longer active. Reject this request, and the person can send a new one.']);
            }
            if ($supplierId === null || ! DB::table('inventory_suppliers')->where('id', $supplierId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['supplier_public_id' => 'Choose an active supplier.']);
            }
            $orderLines = [];
            foreach ($lines as $line) {
                if ($quantities[(int) $line->id] > 0) {
                    $orderLines[] = ['sku_public_id' => (string) $line->sku_public_id, 'quantity' => StockUnitQuantity::format($quantities[(int) $line->id])];
                }
            }
            try {
                $order = $this->procurement->create($actor, ['expected_branch_id' => $branch->id, 'supplier_public_id' => strtolower((string) $data['supplier_public_id']),
                    'destination_location_public_id' => $location->public_id, 'lines' => $orderLines]);
            } catch (ValidationException $e) {
                // The order's own line numbers skip lines left out at 0, so its messages are shown for the whole request.
                throw ValidationException::withMessages(['request' => collect($e->errors())->flatten()->first() ?? 'The draft order could not be made.']);
            }
            DB::table('inventory_purchase_requests')->where('id', $request->id)->update(['status' => 'converted', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'purchase_order_id' => $order->id, 'lock_version' => (int) $request->lock_version + 1]);
            foreach ($lines as $line) {
                DB::table('inventory_purchase_request_lines')->where('id', $line->id)->update(['converted_quantity' => StockUnitQuantity::format($quantities[(int) $line->id])]);
            }
            $this->action($actor, (int) $request->id, 'converted');
            abort_if($this->audit->record('inventory.purchase_request.converted', $order, ['purchase_request_id' => (int) $request->id, 'purchase_order_id' => $order->id,
                'lines' => count($orderLines)], $actor, $branch) === null, 503, 'The conversion could not be recorded, so nothing was saved.');

            return (string) $order->public_id;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function reject(User $actor, string $publicId, array $input): void
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', Rule::in(self::REJECT_REASONS)],
        ], ['reason.required' => 'Choose a reason.', 'reason.in' => 'Choose a reason.'])->validate();
        $branch = $this->authority->activeBranch($actor, $data);

        DB::transaction(function () use ($actor, $branch, $publicId, $data): void {
            $actor = $this->authority->lockForBranch($actor, $branch, ProcurementService::CREATE_PERMISSION);
            $request = $this->lockWaiting($actor, $branch, $publicId, (int) $data['lock_version']);
            $this->refuseRequester($actor, $request, 'review');
            DB::table('inventory_purchase_requests')->where('id', $request->id)->update(['status' => 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'reject_reason' => $data['reason'], 'lock_version' => (int) $request->lock_version + 1]);
            $this->action($actor, (int) $request->id, 'rejected', (string) $data['reason']);
            abort_if($this->audit->record('inventory.purchase_request.rejected', null, ['purchase_request_id' => (int) $request->id, 'reason' => $data['reason']], $actor, $branch) === null,
                503, 'The decision could not be recorded, so nothing was changed.');
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function withdraw(User $actor, string $publicId, array $input): void
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1']])->validate();
        $branch = $this->authority->activeBranch($actor, $data);

        DB::transaction(function () use ($actor, $branch, $publicId, $data): void {
            $actor = $this->authority->lockForBranch($actor, $branch, self::CREATE);
            $request = $this->lockWaiting($actor, $branch, $publicId, (int) $data['lock_version']);
            if ((int) $request->requested_by_user_id !== $actor->id) {
                throw new PurchaseRequestRefusal('Only the person who sent this request can withdraw it.');
            }
            DB::table('inventory_purchase_requests')->where('id', $request->id)->update(['status' => 'withdrawn', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'lock_version' => (int) $request->lock_version + 1]);
            $this->action($actor, (int) $request->id, 'withdrawn');
            abort_if($this->audit->record('inventory.purchase_request.withdrawn', null, ['purchase_request_id' => (int) $request->id], $actor, $branch) === null,
                503, 'The withdrawal could not be recorded, so nothing was changed.');
        }, 3);
    }

    /**
     * The page: requests at the active branch by tab, and what this viewer may do. Pre-fills the new-request form
     * from Reorder suggestions when asked (location and SKU public ids with quantities).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, Branch $branch, array $query): array
    {
        $tab = in_array($query['tab'] ?? null, ['waiting', 'converted', 'rejected', 'withdrawn'], true) ? (string) $query['tab'] : 'waiting';
        $can = ['request' => $actor->can(self::CREATE), 'review' => $actor->can(ProcurementService::CREATE_PERMISSION)];
        $base = DB::table('inventory_purchase_requests as r')->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id);
        $counts = (clone $base)->groupBy('r.status')->selectRaw('r.status, count(*) as total')->pluck('total', 'status');
        $page = (clone $base)->join('inventory_locations as l', 'l.id', '=', 'r.destination_location_id')->leftJoin('inventory_suppliers as s', 's.id', '=', 'r.preferred_supplier_id')
            ->leftJoin('inventory_purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')->where('r.status', $tab)
            ->orderByDesc($tab === 'waiting' ? 'r.requested_at' : 'r.decided_at')->orderByDesc('r.id')
            ->paginate(self::PAGE_SIZE, ['r.id', 'r.public_id', 'r.request_number', 'r.status', 'r.lock_version', 'r.requested_by_user_id', 'r.requested_at', 'r.decided_by_user_id', 'r.decided_at',
                'r.reject_reason', 'l.name as location', 's.public_id as supplier_public_id', 's.name as supplier', 'po.order_number', 'po.status as order_status'], 'page', max(1, (int) ($query['page'] ?? 1)));
        /** @var list<stdClass> $requests */
        $requests = $page->items();
        $ids = array_map(fn (stdClass $r): int => (int) $r->id, $requests);
        $lines = DB::table('inventory_purchase_request_lines as l')->join('inventory_skus as s', 's.id', '=', 'l.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->whereIn('l.purchase_request_id', $ids === [] ? [0] : $ids)->orderBy('l.id')
            ->get(['l.purchase_request_id', 'l.id', 's.sku_code', 's.stock_unit', 'i.generic_name', 'l.requested_quantity', 'l.converted_quantity', 'l.note'])->groupBy('purchase_request_id');
        $people = array_values(array_unique(array_filter(array_merge(array_map(fn (stdClass $r): int => (int) $r->requested_by_user_id, $requests), array_map(fn (stdClass $r): int => (int) $r->decided_by_user_id, $requests)))));
        $names = $people === [] ? collect() : $this->staff->visibleUsers($actor)->whereIn('id', $people)->pluck('name', 'id');
        $local = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($branch->timezone)->format('j M Y, g:i A');

        return [
            'expectedBranchId' => $branch->id, 'branchName' => $branch->name, 'tab' => $tab, 'can' => $can,
            'counts' => ['waiting' => (int) ($counts['waiting'] ?? 0), 'converted' => (int) ($counts['converted'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0), 'withdrawn' => (int) ($counts['withdrawn'] ?? 0)],
            'requests' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'rows' => array_map(fn (stdClass $r): array => [
                'publicId' => $r->public_id, 'number' => $r->request_number, 'status' => $r->status, 'lockVersion' => (int) $r->lock_version, 'location' => $r->location,
                'preferredSupplier' => $r->supplier === null ? null : ['publicId' => $r->supplier_public_id, 'name' => $r->supplier],
                'requestedBy' => $names->get((int) $r->requested_by_user_id, 'Staff member'), 'requestedOn' => $local((string) $r->requested_at),
                'requestedByYou' => (int) $r->requested_by_user_id === $actor->id,
                'decidedBy' => $r->decided_by_user_id === null ? null : $names->get((int) $r->decided_by_user_id, 'Staff member'), 'decidedOn' => $local($r->decided_at),
                'rejectReason' => $r->reject_reason, 'order' => $r->order_number === null ? null : ['number' => $r->order_number, 'status' => $r->order_status],
                'lines' => collect($lines->get($r->id, []))->map(fn (stdClass $l): array => ['id' => (int) $l->id, 'sku' => $l->sku_code, 'item' => $l->generic_name, 'stockUnit' => $l->stock_unit,
                    'asked' => self::quantity((string) $l->requested_quantity), 'converted' => $l->converted_quantity === null ? null : self::quantity((string) $l->converted_quantity), 'note' => $l->note])->values()->all(),
            ], $requests)],
            'prefill' => $can['request'] ? $this->prefill($actor, $branch, $query) : null,
            'locations' => $can['request'] ? DB::table('inventory_locations')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('is_active', true)
                ->where('type', '!=', InventoryLocation::TYPE_DISPENSARY_RETURNS)->orderBy('name')->limit(100)->get(['public_id', 'name'])
                ->map(fn (stdClass $l): array => ['publicId' => $l->public_id, 'name' => $l->name])->values()->all() : [],
        ];
    }

    /**
     * Lines handed over from Reorder suggestions ("Request these"): a location at this branch and active SKUs with a
     * quantity. Anything that does not match is left out; the requester still checks and sends the form.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function prefill(User $actor, Branch $branch, array $query): ?array
    {
        $location = is_string($query['location'] ?? null) ? strtolower((string) $query['location']) : null;
        $items = is_array($query['lines'] ?? null) ? array_slice((array) $query['lines'], 0, self::MAX_LINES) : [];
        if ($location === null || $items === []) {
            return null;
        }
        $place = DB::table('inventory_locations')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('public_id', $location)->where('is_active', true)
            ->where('type', '!=', InventoryLocation::TYPE_DISPENSARY_RETURNS)->first(['public_id', 'name']);
        $wanted = [];
        foreach ($items as $item) {
            // Each line is "sku public id:quantity".
            if (is_string($item) && preg_match('/^([0-9a-fA-F-]{36}):(\d{1,12}(\.\d{1,3})?)$/', $item, $m) === 1 && (float) $m[2] > 0) {
                $wanted[strtolower($m[1])] = $m[2];
            }
        }
        $skus = DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->where('s.organisation_id', $actor->organisation_id)->where('s.is_active', true)
            ->whereIn('s.public_id', array_keys($wanted) ?: ['00000000-0000-0000-0000-000000000000'])->get(['s.public_id', 's.sku_code', 's.stock_unit', 'i.generic_name']);
        if ($place === null || $skus->isEmpty()) {
            return null;
        }

        return ['location' => ['publicId' => $place->public_id, 'name' => $place->name], 'lines' => $skus->map(fn (stdClass $s): array => ['skuPublicId' => $s->public_id,
            'label' => $s->sku_code.' · '.$s->generic_name, 'stockUnit' => $s->stock_unit, 'quantity' => self::quantity($wanted[strtolower((string) $s->public_id)])])->values()->all()];
    }

    private function lockWaiting(User $actor, Branch $branch, string $publicId, int $version): stdClass
    {
        $request = $this->lockRequest($actor, $branch, $publicId);
        $this->assertWaiting($request, $version);

        return $request;
    }

    private function lockRequest(User $actor, Branch $branch, string $publicId): stdClass
    {
        $request = DB::table('inventory_purchase_requests')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('public_id', strtolower($publicId))->lockForUpdate()->first();
        abort_unless($request !== null, 404);

        return $request;
    }

    private function assertWaiting(stdClass $request, int $version): void
    {
        if ((int) $request->lock_version !== $version) {
            throw ValidationException::withMessages(['lock_version' => 'This request changed. Reload and review it again.']);
        }
        if ($request->status !== 'waiting') {
            throw ValidationException::withMessages(['status' => 'This request is no longer waiting for review.']);
        }
    }

    /**
     * A repeated Convert by the same reviewer, from the same page, with the same supplier and quantities, returns the
     * order the first press made instead of an error.
     *
     * @param  array<int, int>  $quantities
     */
    private function replayedConversion(User $actor, stdClass $request, int $version, ?int $supplierId, array $quantities): ?string
    {
        if ($request->status !== 'converted' || (int) $request->decided_by_user_id !== $actor->id || (int) $request->lock_version !== $version + 1 || $supplierId === null) {
            return null;
        }
        $order = DB::table('inventory_purchase_orders')->where('id', $request->purchase_order_id)->where('supplier_id', $supplierId)->first(['public_id']);
        $saved = DB::table('inventory_purchase_request_lines')->where('purchase_request_id', $request->id)->pluck('converted_quantity', 'id')
            ->map(fn (mixed $q): int => StockUnitQuantity::scaled((string) $q, true))->all();
        ksort($saved);
        ksort($quantities);

        return $order !== null && $saved === $quantities ? (string) $order->public_id : null;
    }

    /** Never the requester: whoever sent a request cannot convert or reject it (PostgreSQL repeats this). */
    private function refuseRequester(User $actor, stdClass $request, string $what): void
    {
        if ((int) $request->requested_by_user_id === $actor->id) {
            throw new PurchaseRequestRefusal('You sent this request, so another person must '.$what.' it.');
        }
    }

    private function action(User $actor, int $requestId, string $action, ?string $reason = null): void
    {
        DB::table('inventory_purchase_request_actions')->insert(['organisation_id' => $actor->organisation_id, 'purchase_request_id' => $requestId, 'action' => $action,
            'actor_user_id' => $actor->id, 'reason' => $reason, 'created_at' => now()->utc()]);
    }

    private static function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
