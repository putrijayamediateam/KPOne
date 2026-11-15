<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\PrintedPhone;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * STK-20 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): an A4 print of one purchase order for the supplier.
 * A live rendering of the saved order, never a frozen copy. Only approved (and later) orders print as an order;
 * anything else carries a "NOT APPROVED, NOT AN ORDER" banner. Prices print only for holders of the cost
 * permission; everyone else's print says "Prices as agreed with the supplier". Each print is audited with ids only.
 */
final class PurchaseOrderPrintService
{
    /** Statuses at which the paper is a real order. */
    public const ORDER_STATUSES = [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_FULLY_RECEIVED, PurchaseOrder::STATUS_CLOSED];

    public function __construct(
        private InventoryAuthorityService $authority,
        private BranchAccessService $branches,
        private BranchLetterheadAssembler $letterheads,
        private AuditRecorder $audit,
        private StaffAccessService $staff,
    ) {}

    /** @return array<string, mixed> */
    public function print(User $actor, string $publicId): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $publicId): array {
            $locked = $this->lockPrinter($actor, $branch);
            $order = DB::table('inventory_purchase_orders as po')->join('inventory_locations as l', 'l.id', '=', 'po.destination_location_id')
                ->join('inventory_suppliers as sup', 'sup.id', '=', 'po.supplier_id')
                ->where('po.organisation_id', $locked->organisation_id)->where('po.public_id', $publicId)
                // Warehouse orders (no branch) print for holders of the warehouse permission, as the Inventory panel lists them.
                ->where(fn ($where) => $where->where('l.branch_id', $branch->id)->when($locked->can(ProcurementService::WAREHOUSE_PERMISSION), fn ($w) => $w->orWhereNull('l.branch_id')))
                ->first(['po.id', 'po.public_id', 'po.order_number', 'po.status', 'po.created_at', 'po.approved_at', 'po.created_by_user_id', 'po.approved_by_user_id', 'po.supplier_id',
                    'l.name as location', 'l.branch_id as location_branch_id', 'sup.name as supplier', 'sup.contact_name', 'sup.business_phone', 'sup.business_email']);
            abort_unless($order !== null, 404);
            $cost = $locked->can(SupplierTermsService::COST_PERMISSION);
            /** @var list<stdClass> $lines */
            $lines = DB::table('inventory_purchase_order_lines as line')->join('inventory_skus as s', 's.id', '=', 'line.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
                ->leftJoin('inventory_supplier_sku_terms as t', fn ($join) => $join->on('t.inventory_sku_id', '=', 's.id')->where('t.inventory_supplier_id', '=', $order->supplier_id)->where('t.is_active', true))
                ->where('line.purchase_order_id', $order->id)->orderBy('line.id')->orderByDesc('t.is_preferred')->orderBy('t.id')
                ->get(['line.id', 's.sku_code', 's.stock_unit', 'i.generic_name as item', 'line.ordered_quantity', 'line.estimated_unit_cost_sen', 't.pack_description', 't.pack_quantity', 't.is_preferred'])
                ->unique('id')->values()->all();
            // Staff names follow the staff-visibility rule (BIL-2), with "Staff member" otherwise.
            $names = $this->staff->visibleUsers($locked)->whereIn('id', array_filter([(int) $order->created_by_user_id, (int) $order->approved_by_user_id]))->pluck('name', 'id');
            $isOrder = in_array($order->status, self::ORDER_STATUSES, true);
            $timezone = $branch->timezone;

            $rows = [];
            $totalSen = 0;
            $priced = true;
            foreach ($lines as $index => $line) {
                $quantity = StockUnitQuantity::scaled((string) $line->ordered_quantity, true);
                $packs = null;
                if ($line->pack_quantity !== null) {
                    $pack = StockUnitQuantity::scaled((string) $line->pack_quantity);
                    $packs = $pack > 0 && $quantity % $pack === 0 ? [intdiv($quantity, $pack), (string) $line->pack_description] : null;
                }
                $lineSen = $line->estimated_unit_cost_sen === null || $quantity === 0 ? null : ExactMoney::line($quantity, (int) $line->estimated_unit_cost_sen);
                if ($lineSen === null) {
                    $priced = false;
                } else {
                    $totalSen += $lineSen;
                }
                $rows[] = [
                    'number' => $index + 1, 'skuCode' => $line->sku_code, 'item' => $line->item, 'stockUnit' => $line->stock_unit,
                    'quantity' => StockUnitQuantity::format($quantity), 'packs' => $packs === null ? null : ['count' => $packs[0], 'description' => $packs[1]],
                    // Cost never leaves the server for someone without the cost permission.
                    ...($cost ? ['unitCostSen' => $line->estimated_unit_cost_sen === null ? null : (int) $line->estimated_unit_cost_sen, 'lineSen' => $lineSen] : []),
                ];
            }
            $letterhead = $this->letterheads->assemble($branch, static fn (string $asset): string => route('inventory.purchase-orders.letterhead-logo', ['asset' => $asset], absolute: false))->toArray();
            foreach (['phone', 'phone_secondary'] as $field) {
                $letterhead['details'][$field] = PrintedPhone::format($letterhead['details'][$field] ?? null);
            }
            abort_if($this->audit->record('inventory.purchase_order.printed', PurchaseOrder::query()->whereKey((int) $order->id)->firstOrFail(), ['purchase_order_id' => (int) $order->id, 'status' => (string) $order->status, 'prices' => $cost], $locked, $branch) === null,
                503, 'The print could not be recorded, so it was not shown.');
            $local = fn (?string $value, string $format): ?string => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format($format);

            return [
                'letterhead' => $letterhead,
                'order' => [
                    'publicId' => $order->public_id, 'number' => $order->order_number, 'status' => $order->status, 'isOrder' => $isOrder,
                    'orderDate' => $local((string) $order->created_at, 'd/m/Y'), 'approvedAt' => $local($order->approved_at, 'd/m/Y'),
                    'preparedBy' => $names->get((int) $order->created_by_user_id, 'Staff member'), 'approvedBy' => $order->approved_by_user_id === null ? null : $names->get((int) $order->approved_by_user_id, 'Staff member'),
                    'supplier' => ['name' => $order->supplier, 'contact' => $order->contact_name, 'phone' => PrintedPhone::format($order->business_phone), 'email' => $order->business_email],
                    'deliverTo' => ['branch' => $order->location_branch_id === null ? 'Organisation warehouse' : $branch->name, 'location' => $order->location],
                    'lines' => $rows, 'showPrices' => $cost, 'totalSen' => $cost && $priced ? $totalSen : null,
                    'printedAt' => CarbonImmutable::now($timezone)->format('d/m/Y, g:i A'),
                ],
            ];
        }, 3);
    }

    /**
     * The logo of this branch's letterhead, for someone who may print a purchase order here.
     *
     * @return array{path: string, mime: string}
     */
    public function logo(User $actor, string $asset): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $asset): array {
            $this->lockPrinter($actor, $branch);
            $letterhead = $this->letterheads->assemble($branch, static fn (string $id): string => $id);
            abort_unless($letterhead->logo !== null && $letterhead->logo['url'] === strtolower($asset), 404);
            $image = DB::table('business_detail_assets')->where('organisation_id', $actor->organisation_id)
                ->where('public_id', strtolower($asset))->whereNull('retired_at')->first(['path', 'mime']);
            abort_unless($image !== null, 404);

            return ['path' => (string) $image->path, 'mime' => (string) $image->mime];
        }, 3);
    }

    /** Holders of the purchase order create or approve permission, assigned to this branch. */
    private function lockPrinter(User $actor, Branch $branch): User
    {
        $fresh = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($fresh !== null, 403);
        $permission = $fresh->can(ProcurementService::APPROVE_PERMISSION) ? ProcurementService::APPROVE_PERMISSION : ProcurementService::CREATE_PERMISSION;
        try {
            return $this->authority->lockForBranch($actor, $branch, $permission);
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
