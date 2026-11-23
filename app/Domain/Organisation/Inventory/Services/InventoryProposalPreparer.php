<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\PreparedRecord;
use App\Domain\Agents\Proposals\PreparesWork;
use App\Domain\Agents\Proposals\ProposalPreparers;
use App\Domain\Agents\Proposals\WarnsBeforePreparing;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * STK-41 (owner, 18 Nov 2026, "Approve prepares real work"; plan agreed with the agent platform and approved by B.E.R.N.I.S.S for
 * the owner): approving an agent's transfer suggestion prepares a governed stock request, and a restock suggestion a purchase
 * request. The agent platform calls this inside the approval transaction, as the approving person, behind its switch.
 *
 * Every record is made through the normal Inventory service, so the permission, branch, row locks, spare-stock and status checks
 * and the audit are exactly those of the Inventory page. Only ids, codes and quantities from the validated payload are used; no
 * agent text reaches a record. A refusal throws, which rolls the whole approval back and leaves the suggestion pending.
 */
final class InventoryProposalPreparer implements PreparesWork, WarnsBeforePreparing
{
    public const TRANSFER = 'inventory.transfer_suggestion';

    public const RESTOCK = 'inventory.restock_suggestion';

    /** The fixed line note on a purchase request prepared from a restock suggestion (plain words, never agent text). */
    public const RESTOCK_NOTE = 'From a restock suggestion';

    public const ABOVE_LEVEL = 'Stock is now above the reorder level, so there is nothing to order. Reject this suggestion.';

    public const OPEN_ORDER_WARNING = 'An open purchase request or purchase order already includes this item for this location.';

    public function __construct(private StockRequestService $stockRequests, private PurchaseRequestService $purchaseRequests, private InventoryStockReviewService $review) {}

    public function types(): array
    {
        return [self::TRANSFER, self::RESTOCK];
    }

    public function cannotPrepareReason(User $approver, AiProposal $proposal): ?string
    {
        return match ($proposal->type) {
            self::TRANSFER => $approver->can(StockRequestService::CREATE_PERMISSION) ? null
                : 'Your role cannot create stock requests, so approve this as a decision only.',
            self::RESTOCK => $approver->can(PurchaseRequestService::CREATE) ? null
                : 'Your role cannot send purchase requests, so approve this as a decision only.',
            default => 'This suggestion does not prepare work.',
        };
    }

    public function prepare(User $approver, AiProposal $proposal, array $values): PreparedRecord
    {
        return match ($proposal->type) {
            self::TRANSFER => $this->transfer($approver, $proposal, $values),
            self::RESTOCK => $this->restock($approver, $proposal, $values),
            default => throw ValidationException::withMessages(['mode' => 'This suggestion does not prepare work.']),
        };
    }

    /** STK-41b: the inbox shows this before "Approve and create …"; it never blocks (refusals still come from `prepare`). */
    public function warning(User $approver, AiProposal $proposal): ?string
    {
        return $proposal->organisation_id === $approver->organisation_id ? $this->openOrderWarning($proposal) : null;
    }

    /**
     * Read only, for the inbox before Approve: a warning (never a refusal) when an open purchase request or purchase order already
     * holds this item for this location. Null for other types or when nothing is open.
     */
    public function openOrderWarning(AiProposal $proposal): ?string
    {
        if ($proposal->type !== self::RESTOCK) {
            return null;
        }
        $payload = (array) $proposal->payload;
        $sku = InventorySku::query()->where('organisation_id', $proposal->organisation_id)->where('public_id', (string) ($payload['sku_public_id'] ?? ''))->value('id');
        $location = InventoryLocation::query()->where('organisation_id', $proposal->organisation_id)->where('public_id', (string) ($payload['location_public_id'] ?? ''))->value('id');
        if ($sku === null || $location === null) {
            return null;
        }
        $request = DB::table('inventory_purchase_requests as r')->join('inventory_purchase_request_lines as l', 'l.purchase_request_id', '=', 'r.id')
            ->where('r.organisation_id', $proposal->organisation_id)->where('r.status', 'waiting')->where('r.destination_location_id', $location)->where('l.inventory_sku_id', $sku)->exists();
        $order = DB::table('inventory_purchase_orders as o')->join('inventory_purchase_order_lines as l', 'l.purchase_order_id', '=', 'o.id')
            ->where('o.organisation_id', $proposal->organisation_id)->whereIn('o.status', ['draft', 'submitted', 'approved', 'partially_received'])
            ->where('o.destination_location_id', $location)->where('l.inventory_sku_id', $sku)->exists();

        return $request || $order ? self::OPEN_ORDER_WARNING : null;
    }

    /** @param array<string, mixed> $values */
    private function transfer(User $approver, AiProposal $proposal, array $values): PreparedRecord
    {
        $source = $this->location($proposal, $values['source_location_public_id'] ?? null);
        $destination = $this->location($proposal, $values['destination_location_public_id'] ?? null);
        if ($source === null || $destination === null) {
            throw ValidationException::withMessages(['edits' => 'Choose where the stock comes from and where it goes.']);
        }
        // The stock comes from the sending branch named in the suggestion and goes to the receiving one; the stock request service
        // then checks that the source is at the approver's branch and that it can spare the quantity.
        if ($this->branchCode($source) !== (string) ($values['from_branch_code'] ?? '') || $this->branchCode($destination) !== (string) ($values['to_branch_code'] ?? '')) {
            throw ValidationException::withMessages(['edits' => 'The stock must come from the sending branch and go to the receiving branch in this suggestion.']);
        }
        // Only the branch that sends the stock may create a cross-branch stock request (STK-31), so the suggestion is decided there.
        if ($source->branch_id !== $proposal->branch_id) {
            throw ValidationException::withMessages(['mode' => 'This transfer is prepared at the branch that sends the stock. Approve it as a decision only here.']);
        }
        $request = $this->stockRequests->createFromProposal($approver, $proposal->id, [
            'expected_branch_id' => $proposal->branch_id,
            'retry_key' => ProposalPreparers::retryKey($proposal->id),
            'source_location_public_id' => $source->public_id,
            'destination_location_public_id' => $destination->public_id,
            'lines' => [['sku_public_id' => (string) ($values['sku_public_id'] ?? ''), 'quantity' => (string) ($values['quantity'] ?? '')]],
        ]);

        return new PreparedRecord('inventory.stock_request', (string) $request->public_id, (string) $request->request_number);
    }

    /** @param array<string, mixed> $values */
    private function restock(User $approver, AiProposal $proposal, array $values): PreparedRecord
    {
        $branch = Branch::query()->whereKey($proposal->branch_id)->where('organisation_id', $approver->organisation_id)->firstOrFail();
        $sku = InventorySku::query()->where('organisation_id', $approver->organisation_id)->where('public_id', (string) ($values['sku_public_id'] ?? ''))->first();
        $location = $this->location($proposal, $values['location_public_id'] ?? null);
        if ($sku === null || $location === null) {
            throw ValidationException::withMessages(['edits' => 'This item or location is no longer available.']);
        }
        // The live check uses the Stock review's own low-stock rows, the same basis as the suggestion.
        if (! $this->review->lowStockQuery($approver, $branch)->where('s.id', $sku->id)->where('l.id', $location->id)->exists()) {
            throw ValidationException::withMessages(['mode' => self::ABOVE_LEVEL]);
        }
        $publicId = $this->purchaseRequests->sendFromProposal($approver, $proposal->id, [
            'expected_branch_id' => $proposal->branch_id,
            'idempotency_key' => ProposalPreparers::retryKey($proposal->id),
            'destination_location_public_id' => $location->public_id,
            'lines' => [['sku_public_id' => $sku->public_id, 'quantity' => (string) ($values['quantity'] ?? $values['suggested_quantity'] ?? ''), 'note' => self::RESTOCK_NOTE]],
        ]);
        $number = (string) DB::table('inventory_purchase_requests')->where('organisation_id', $approver->organisation_id)->where('public_id', $publicId)->value('request_number');

        return new PreparedRecord('inventory.purchase_request', $publicId, $number);
    }

    private function location(AiProposal $proposal, mixed $publicId): ?InventoryLocation
    {
        return is_string($publicId) && $publicId !== ''
            ? InventoryLocation::query()->where('organisation_id', $proposal->organisation_id)->where('public_id', strtolower($publicId))->where('is_active', true)->first()
            : null;
    }

    private function branchCode(InventoryLocation $location): ?string
    {
        return $location->branch_id === null ? null : (string) Branch::query()->whereKey($location->branch_id)->value('code');
    }
}
