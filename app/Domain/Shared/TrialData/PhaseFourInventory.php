<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\ControlledRegisterService;
use App\Domain\Organisation\Inventory\Services\CycleCountService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\InventoryRecallService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\PurchaseRequestService;
use App\Domain\Organisation\Inventory\Services\ReceivingReviewService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Inventory\Services\SupplierInvoiceService;
use App\Domain\Organisation\Inventory\Services\SupplierReturnService;
use App\Domain\Organisation\Inventory\Services\SupplierTermsService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA phase 4: the inventory workflows at every branch, through the existing services as the fictional staff,
 * with the independence each one requires (requester, approver, receiver, checker and so on are different people).
 * Each block runs in one transaction and is skipped when its own result already exists, so a rerun adds nothing.
 */
final class PhaseFourInventory
{
    use ClinicFlow;

    public function __construct(private readonly TrialDataContext $context) {}

    /** @return array<string, int> what was created, by kind */
    public function run(): array
    {
        $this->created = [];
        $this->supplierTerms();
        foreach ($this->context->branches as $code => $branch) {
            $this->block(fn () => $this->purchaseMatched($code, $branch));
            $this->block(fn () => $this->purchaseWithException($code, $branch));
            $this->block(fn () => $this->supplierReturn($code, $branch));
            $this->block(fn () => $this->holdPlacedAndReleased($code, $branch));
            $this->block(fn () => $this->governedCountAndWriteOffs($code, $branch));
            $this->block(fn () => $this->reorderAndReviews($code, $branch));
            $this->block(fn () => $this->treatmentUsage($code, $branch));
            $this->block(fn () => $this->controlledCheck($code, $branch));
            $this->block(fn () => $this->medicineReturn($code, $branch));
            $this->block(fn () => $this->cycleCount($code, $branch));
        }
        $this->block(fn () => $this->transferFromPuchong());
        $this->block(fn () => $this->recall());

        return $this->created;
    }

    private function block(\Closure $step): void
    {
        DB::transaction(fn () => $step());
    }

    // --- purchasing ------------------------------------------------------------------------------------------------

    /**
     * A purchase request (CA) converted to an order (CA Supervisor), approved by Finance, received in part with actual
     * costs, the rest short-closed by the Director, and the supplier invoice matched exactly.
     */
    private function purchaseMatched(string $code, Branch $branch): void
    {
        $store = $this->location($code, 'store');
        $supplier = $this->supplier(0);
        if (PurchaseOrder::query()->where('destination_location_id', $store->id)->where('supplier_id', $supplier->id)->exists()) {
            return;
        }
        $base = ['expected_branch_id' => $branch->id];
        $requests = app(PurchaseRequestService::class);
        $requestId = $requests->send($this->ca($code, $branch), $base + [
            'idempotency_key' => (string) Str::uuid(),
            'destination_location_public_id' => $store->public_id,
            'preferred_supplier_public_id' => $supplier->public_id,
            'lines' => [
                ['sku_public_id' => $this->sku('TRIAL-PARA500')->public_id, 'quantity' => '100', 'note' => null],
                ['sku_public_id' => $this->sku('TRIAL-CETI10')->public_id, 'quantity' => '100', 'note' => null],
            ],
        ]);
        $request = DB::table('inventory_purchase_requests')->where('public_id', $requestId)->first();
        $lines = DB::table('inventory_purchase_request_lines')->where('purchase_request_id', $request->id)->orderBy('id')->get();
        $orderId = $requests->convert($this->as($code, 'supervisor1', $branch), $requestId, $base + [
            'lock_version' => (int) $request->lock_version,
            'supplier_public_id' => $supplier->public_id,
            'lines' => $lines->map(fn ($line): array => ['line_id' => $line->id, 'quantity' => '100'])->all(),
        ]);
        $this->count('purchase_requests');

        $order = $this->approveOrder($code, $branch, PurchaseOrder::query()->where('public_id', $orderId)->firstOrFail());
        $poLines = $order->lines()->orderBy('id')->get();
        app(ProcurementService::class)->receive($this->as($code, 'supervisor1', $branch), $order->refresh(), $base + [
            'lock_version' => $order->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'delivery_reference' => 'TRIAL-DN-'.$code.'-A',
            'lines' => [
                $this->receiptLine($poLines[0]->public_id, '100', [[100, $this->batchNumber($code, 'PARA'), 2000, 'accepted', null]]),
                $this->receiptLine($poLines[1]->public_id, '60', [[60, $this->batchNumber($code, 'CETI'), 1800, 'accepted', null]]),
            ],
        ]);
        $this->count('receipts');
        $order = app(ProcurementService::class)->shortClose($this->context->asDirector($branch), $order->refresh(), $base + [
            'lock_version' => $order->refresh()->lock_version,
            'reason' => 'supplier_cannot_supply',
            'note' => 'Fictional trial: the rest is out of stock at the supplier',
        ]);
        app(SupplierInvoiceService::class)->record($this->as($code, 'supervisor2', $branch), $base + [
            'idempotency_key' => (string) Str::uuid(),
            'purchase_order_public_id' => $order->public_id,
            'invoice_number' => 'TRIAL-INV-'.$code.'-A',
            'invoice_date' => $this->today($branch)->toDateString(),
            'stated_total' => '38.00',
            'lines' => [
                ['line_public_id' => $poLines[0]->public_id, 'quantity' => '100', 'total' => '20.00'],
                ['line_public_id' => $poLines[1]->public_id, 'quantity' => '60', 'total' => '18.00'],
            ],
        ]);
        $this->count('supplier_invoices');
    }

    /**
     * An order received with five damaged boxes quarantined: the receiver asks for a review, another supervisor approves
     * it, and the receiver posts it (the quarantined batch is held automatically). The supplier's invoice differs from
     * the receipt, so it waits for Finance.
     */
    private function purchaseWithException(string $code, Branch $branch): void
    {
        $store = $this->location($code, 'store');
        $supplier = $this->supplier(1);
        if (PurchaseOrder::query()->where('destination_location_id', $store->id)->where('supplier_id', $supplier->id)->exists()) {
            return;
        }
        $base = ['expected_branch_id' => $branch->id];
        $receiver = $this->as($code, 'supervisor1', $branch);
        $order = app(ProcurementService::class)->create($receiver, $base + [
            'supplier_public_id' => $supplier->public_id,
            'destination_location_public_id' => $store->public_id,
            'lines' => [
                ['sku_public_id' => $this->sku('TRIAL-AMOX250')->public_id, 'quantity' => '50', 'unit_cost_sen' => 60],
                ['sku_public_id' => $this->sku('TRIAL-LORA10')->public_id, 'quantity' => '40', 'unit_cost_sen' => 40],
            ],
        ]);
        $order = app(ProcurementService::class)->submit($this->as($code, 'supervisor1', $branch), $order, $base + ['lock_version' => $order->lock_version]);
        $order = $this->approveOrder($code, $branch, $order);
        $poLines = $order->lines()->orderBy('id')->get();

        $payload = $base + [
            'lock_version' => $order->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'delivery_reference' => 'TRIAL-DN-'.$code.'-B',
            'lines' => [
                $this->receiptLine($poLines[0]->public_id, '50', [
                    [45, $this->batchNumber($code, 'AMOX'), 2700, 'accepted', null],
                    [5, $this->batchNumber($code, 'AMOXQ'), 300, 'quarantined', 'damaged'],
                ]),
                $this->receiptLine($poLines[1]->public_id, '40', [[40, $this->batchNumber($code, 'LORA'), 1600, 'accepted', null]]),
            ],
        ];
        $reviews = app(ReceivingReviewService::class);
        $reviewId = $reviews->request($this->as($code, 'supervisor1', $branch), $order->refresh(), $payload + ['retry_key' => (string) Str::uuid()]);
        $review = DB::table('inventory_receiving_reviews')->where('id', $reviewId)->first();
        $reviews->approve($this->as($code, 'supervisor2', $branch), (string) $review->public_id, $base + ['lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
        app(ProcurementService::class)->receive($this->as($code, 'supervisor1', $branch), $order->refresh(), $payload + [
            'review_public_id' => $review->public_id,
            'review_lock_version' => 2,
        ]);
        $this->count('receipts');
        $this->count('delivery_exceptions');

        app(SupplierInvoiceService::class)->record($this->as($code, 'supervisor2', $branch), $base + [
            'idempotency_key' => (string) Str::uuid(),
            'purchase_order_public_id' => $order->public_id,
            'invoice_number' => 'TRIAL-INV-'.$code.'-B',
            'invoice_date' => $this->today($branch)->toDateString(),
            'stated_total' => '47.50',
            'lines' => [
                ['line_public_id' => $poLines[0]->public_id, 'quantity' => '50', 'total' => '31.50'],
                ['line_public_id' => $poLines[1]->public_id, 'quantity' => '40', 'total' => '16.00'],
            ],
        ]);
        $this->count('supplier_invoices');
    }

    /** The five quarantined boxes go back to the supplier: requested, approved by Finance, dispatched, credit noted. */
    private function supplierReturn(string $code, Branch $branch): void
    {
        if (DB::table('inventory_supplier_returns')->where('branch_id', $branch->id)->exists()) {
            return;
        }
        $lot = DB::table('inventory_receipt_lots')->where('batch_number', $this->batchNumber($code, 'AMOXQ'))->first();
        if ($lot === null) {
            return;
        }
        $base = ['expected_branch_id' => $branch->id];
        $returns = app(SupplierReturnService::class);
        $id = $returns->request($this->as($code, 'supervisor1', $branch), $base + [
            'retry_key' => (string) Str::uuid(),
            'receipt_lot_id' => $lot->id,
            'unit' => $this->sku('TRIAL-AMOX250')->stock_unit,
            'quantity' => '5',
            'reason' => 'damaged',
            'supplier_reference' => 'TRIAL-RMA-'.$code,
        ]);
        foreach ([['approved', 'finance', []], ['dispatched', 'supervisor1', ['reference' => 'TRIAL-COURIER-'.$code]], ['credit_recorded', 'finance', ['reference' => 'TRIAL-CN-'.$code, 'credit_amount_sen' => '300']]] as [$action, $who, $extra]) {
            $actor = $who === 'finance' ? $this->context->as('finance', $branch) : $this->as($code, $who, $branch);
            $returns->act($actor, $id, $action, $base + $extra + [
                'lock_version' => (int) DB::table('inventory_supplier_return_actions')->where('return_id', $id)->max('lock_version'),
                'retry_key' => (string) Str::uuid(),
            ]);
        }
        $this->count('supplier_returns');
    }

    /** A precautionary hold on the received loratadine batch, placed by a CA Supervisor and released by Finance. */
    private function holdPlacedAndReleased(string $code, Branch $branch): void
    {
        $batch = InventoryBatch::query()->where('organisation_id', $branch->organisation_id)->where('batch_number', $this->batchNumber($code, 'LORA'))->first();
        if ($batch === null || DB::table('inventory_batch_hold_states')->where('inventory_batch_id', $batch->id)->where('branch_id', $branch->id)->exists()) {
            return;
        }
        $holds = app(InventoryBatchHoldService::class);
        $base = ['expected_branch_id' => $branch->id];
        $holds->act($this->as($code, 'supervisor1', $branch), $batch->public_id, 'place', $base + ['lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'suspected_quality_issue']);
        $holds->act($this->context->as('finance', $branch), $batch->public_id, 'release', $base + ['lock_version' => 1, 'retry_key' => (string) Str::uuid(), 'reason' => 'inspection_passed']);
        $this->count('batch_holds');
    }

    // --- counts, write-offs and reviews ----------------------------------------------------------------------------

    /**
     * A governed count of the store's paracetamol (counted and recounted by the two CA Supervisors, approved by
     * Finance), a damage write-off, and an expiry write-off of a fictional batch that expired yesterday.
     */
    private function governedCountAndWriteOffs(string $code, Branch $branch): void
    {
        if (DB::table('inventory_governed_cases')->where('branch_id', $branch->id)->exists()) {
            return;
        }
        $store = $this->location($code, 'store');
        $paracetamol = $this->balance($store, $this->batchNumber($code, 'PARA'));
        if ($paracetamol === null) {
            return;
        }
        $governed = app(GovernedStockService::class);
        $base = ['expected_branch_id' => $branch->id];
        $unit = $this->sku('TRIAL-PARA500')->stock_unit;
        $case = $governed->propose($this->as($code, 'supervisor1', $branch), 'count', $base + [
            'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $paracetamol->id,
            'balance_version' => (int) $paracetamol->lock_version, 'unit' => $unit,
        ]);
        $governed->act($this->as($code, 'supervisor1', $branch), $case, 'counted', $base + ['lock_version' => $this->caseVersion($case), 'retry_key' => (string) Str::uuid(), 'quantity' => '98', 'unit' => $unit]);
        $governed->act($this->as($code, 'supervisor2', $branch), $case, 'recounted', $base + ['lock_version' => $this->caseVersion($case), 'retry_key' => (string) Str::uuid(), 'quantity' => '98', 'unit' => $unit]);
        $governed->act($this->context->as('finance', $branch), $case, 'approved', $base + ['lock_version' => $this->caseVersion($case), 'retry_key' => (string) Str::uuid()]);
        $this->count('governed_counts');

        $cetirizine = $this->balance($store, $this->batchNumber($code, 'CETI'));
        if ($cetirizine !== null) {
            $this->writeOff($code, $branch, $cetirizine, 'TRIAL-CETI10', '5', 'damaged');
        }

        // A fictional batch that expired yesterday, put into the store with an opening count (signed off by the second
        // supervisor), so the expired list and the expiry write-off have something real to show.
        $sku = $this->sku('TRIAL-CHLOR4');
        $expired = InventoryBatch::query()->where('organisation_id', $branch->organisation_id)->where('inventory_sku_id', $sku->id)->where('batch_number', 'TRIAL-EXP-'.$code)->first()
            ?? app(InventoryReferenceAdministrationService::class)->createBatch($this->as($code, 'supervisor1', $branch), $sku, [
                'batch_number' => 'TRIAL-EXP-'.$code,
                'expiry_date' => $this->today($branch)->subDay()->toDateString(),
                'received_at' => null,
                'status' => InventoryBatch::STATUS_AVAILABLE,
            ]);
        $opening = app(InventoryMovementService::class)->openingBalance($this->as($code, 'supervisor1', $branch), $base + [
            'location_public_id' => $store->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $expired->public_id, 'quantity' => '20',
        ]);
        app(InventoryReadinessService::class)->verify($this->as($code, 'supervisor2', $branch), 'opening', $opening->public_id, $base + ['lock_version' => 0, 'retry_key' => (string) Str::uuid()]);
        $balance = $this->balance($store, 'TRIAL-EXP-'.$code);
        if ($balance !== null) {
            $this->acknowledge($code, $branch, 'expired', (int) $balance->id, 'disposal_requested_external', false);
            $this->writeOff($code, $branch, $this->balance($store, 'TRIAL-EXP-'.$code) ?? $balance, 'TRIAL-CHLOR4', '20', 'expired');
        }
    }

    /** @param object{id: int, lock_version: int} $balance */
    private function writeOff(string $code, Branch $branch, object $balance, string $medicineCode, string $quantity, string $reason): void
    {
        $governed = app(GovernedStockService::class);
        $case = $governed->propose($this->as($code, 'supervisor1', $branch), 'writeoff', [
            'expected_branch_id' => $branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $balance->id,
            'balance_version' => (int) $balance->lock_version, 'unit' => $this->sku($medicineCode)->stock_unit, 'quantity' => $quantity,
            'reason' => $reason, 'disposal_reference' => 'TRIAL-DISPOSAL-'.$code.'-'.strtoupper($reason),
        ]);
        $governed->act($this->context->as('finance', $branch), $case, 'approved', ['expected_branch_id' => $branch->id, 'lock_version' => $this->caseVersion($case), 'retry_key' => (string) Str::uuid()]);
        $this->count('write_offs');
    }

    /** A reorder level above the dispensary's paracetamol, and the low-stock and expiring lists acknowledged. */
    private function reorderAndReviews(string $code, Branch $branch): void
    {
        $dispensary = $this->location($code, 'dispensary');
        $sku = $this->sku('TRIAL-PARA500');
        if (DB::table('inventory_reorder_levels')->where('inventory_location_id', $dispensary->id)->where('inventory_sku_id', $sku->id)->exists()) {
            return;
        }
        $level = app(InventoryControlService::class)->setReorderLevel($this->as($code, 'supervisor1', $branch), [
            'expected_branch_id' => $branch->id, 'location_public_id' => $dispensary->public_id, 'sku_public_id' => $sku->public_id,
            'reorder_level' => '350', 'max_level' => '600', 'expected_lock_version' => null, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->count('reorder_levels');
        $this->acknowledge($code, $branch, 'low_stock', (int) $level->id, 'ordered_externally', true);

        // The first phase 1 batch expires in about two months, so it is on the expiring list.
        $expiring = $this->balance($dispensary, 'TRIAL-B001');
        if ($expiring !== null) {
            $this->acknowledge($code, $branch, 'expiring', (int) $expiring->id, 'monitoring', true);
        }
    }

    private function acknowledge(string $code, Branch $branch, string $kind, int $item, string $outcome, bool $reviewBy): void
    {
        $version = (int) DB::table('inventory_stock_reviews')->where('branch_id', $branch->id)->where('worklist', $kind)->where('item_id', $item)->max('lock_version');
        app(InventoryStockReviewService::class)->acknowledge($this->as($code, 'supervisor2', $branch), $kind, $item, [
            'expected_branch_id' => $branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), 'outcome' => $outcome,
            'review_by' => $reviewBy ? $this->today($branch)->addDays(7)->toDateString() : null,
        ]);
        $this->count('stock_reviews');
    }

    /** Diclofenac gel used on a wound in the treatment room, and an oral rehydration sachet spilled (CA). */
    private function treatmentUsage(string $code, Branch $branch): void
    {
        if (DB::table('inventory_treatment_usages')->where('branch_id', $branch->id)->exists()) {
            return;
        }
        $dispensary = $this->location($code, 'dispensary');
        $room = BranchRoom::query()->where('branch_id', $branch->id)->where('kind', BranchRoom::KIND_TREATMENT)->first();
        foreach ([['TRIAL-DICLO-GEL', 'usage', 'wound_care'], ['TRIAL-ORS', 'waste', 'spilled_or_broken']] as [$medicine, $kind, $reason]) {
            $sku = $this->sku($medicine);
            app(TreatmentUsageService::class)->record($this->ca($code, $branch), [
                'expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'location_id' => $dispensary->id,
                'batch_id' => $this->phaseOneBatch($sku)->id, 'unit' => $sku->stock_unit, 'quantity' => '1', 'kind' => $kind,
                'reason' => $reason, 'room_id' => $room?->id,
            ]);
            $this->count('treatment_usage');
        }
    }

    /** The controlled-medicine register checked by the second CA Supervisor (who recorded no movement of it). */
    private function controlledCheck(string $code, Branch $branch): void
    {
        $sku = $this->sku('TRIAL-COD15');
        if (DB::table('inventory_controlled_register_checks')->where('branch_id', $branch->id)->where('inventory_sku_id', $sku->id)->exists()) {
            return;
        }
        $checker = $this->as($code, 'supervisor2', $branch);
        $register = app(ControlledRegisterService::class);
        $view = $register->register($checker, $branch, $sku->public_id, [], false);
        $onHand = DB::table('inventory_stock_balances')->join('inventory_locations', 'inventory_locations.id', '=', 'inventory_stock_balances.inventory_location_id')
            ->where('inventory_locations.branch_id', $branch->id)->where('inventory_stock_balances.inventory_sku_id', $sku->id)->sum('inventory_stock_balances.quantity');
        $register->check($this->as($code, 'supervisor2', $branch), $sku->public_id, [
            'expected_branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(),
            'seen_movement_id' => $view['newestMovementId'], 'seen_check_sequence' => $view['newestCheckSequence'],
            'counted' => number_format((float) $onHand, 3, '.', ''),
        ]);
        $this->count('controlled_checks');
    }

    /** Two unused capsules brought back from phase 2's completed card-and-refund visit, quarantined by a supervisor. */
    private function medicineReturn(string $code, Branch $branch): void
    {
        if (DB::table('dispensary_returns')->where('branch_id', $branch->id)->exists()) {
            return;
        }
        $index = (int) array_search($code, array_keys(TrialDataContext::BRANCHES), true);
        $patient = Patient::query()->where('organisation_id', $branch->organisation_id)->where('full_name', sprintf('Trial Patient %02d', $index * PhaseOneFoundation::PATIENTS_PER_BRANCH + 2))->first();
        $visit = $patient === null ? null : Visit::query()->where('patient_id', $patient->id)->where('status', Visit::STATUS_COMPLETED)->first();
        $case = $visit === null ? null : DispensaryCase::query()->where('visit_id', $visit->id)->where('status', DispensaryCase::STATUS_COMPLETED)->first();
        if ($case === null) {
            return; // phase 2 has not run here
        }
        $returnsLocation = InventoryLocation::query()->where('organisation_id', $branch->organisation_id)->where('code', 'TRIAL-'.$code.'-RETURNS')->first()
            ?? app(InventoryReferenceAdministrationService::class)->createLocation($this->as($code, 'supervisor1', $branch), $branch, null, [
                'code' => 'TRIAL-'.$code.'-RETURNS',
                'name' => ucwords(str_replace('_', ' ', strtolower($code))).' Patient Returns (Trial)',
                'type' => InventoryLocation::TYPE_DISPENSARY_RETURNS,
            ]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_COMPLETED)->sole()->items()->firstOrFail();
        $returns = app(DispensaryReturnService::class);
        $return = $returns->record($this->ca($code, $branch), $case, $item->public_id, [
            'expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 0,
            'quantity' => '2.000', 'reason' => 'not_used', 'note' => null, 'condition' => 'sealed_in_date',
        ]);
        $returns->check($this->as($code, 'supervisor2', $branch), $case->refresh(), $return->public_id, [
            'expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'outcome' => 'quarantine',
            'location_public_id' => $returnsLocation->public_id,
            'batch_public_id' => $this->phaseOneBatch($this->sku(MedicineCatalogueItem::query()->findOrFail($item->medicine_catalogue_item_id)->code))->public_id,
        ]);
        $this->count('medicine_returns');
    }

    /** A cycle count started on the store's loratadine; the count cases wait for the counters. */
    private function cycleCount(string $code, Branch $branch): void
    {
        $store = $this->location($code, 'store');
        $sku = $this->sku('TRIAL-LORA10');
        if (DB::table('inventory_governed_cases')->where('branch_id', $branch->id)->where('inventory_sku_id', $sku->id)->where('kind', 'count')->exists()) {
            return;
        }
        if ($this->balance($store, $this->batchNumber($code, 'LORA')) === null) {
            return;
        }
        $opened = app(CycleCountService::class)->start($this->as($code, 'supervisor1', $branch), [
            'expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'sku_public_id' => $sku->public_id, 'location_public_id' => $store->public_id,
        ]);
        $this->count('cycle_count_cases', $opened);
    }

    // --- organisation-wide ----------------------------------------------------------------------------------------

    /** The first supplier's price list and lead time for two medicines, entered by Finance. */
    private function supplierTerms(): void
    {
        $main = $this->context->mainBranch();
        $supplier = $this->supplier(0);
        foreach (['TRIAL-PARA500', 'TRIAL-CETI10'] as $index => $medicine) {
            $sku = $this->sku($medicine);
            if (DB::table('inventory_supplier_sku_terms')->where('inventory_supplier_id', $supplier->id)->where('inventory_sku_id', $sku->id)->exists()) {
                continue;
            }
            app(SupplierTermsService::class)->create($this->context->as('finance', $main), [
                'expected_branch_id' => $main->id, 'idempotency_key' => (string) Str::uuid(), 'supplier_public_id' => $supplier->public_id,
                'sku_public_id' => $sku->public_id, 'pack_description' => 'Box of 100', 'pack_quantity' => '100',
                'pack_price_sen' => $index === 0 ? 2000 : 3000, 'lead_time_days' => 7, 'minimum_order_packs' => 1, 'is_preferred' => true,
            ]);
            $this->count('supplier_terms');
        }
    }

    /** PUCHONG sends omeprazole to the CHERAS store; two boxes arrive short and are resolved as arriving later. */
    private function transferFromPuchong(): void
    {
        $puchong = $this->context->branches->get('PUCHONG');
        $cheras = $this->context->mainBranch();
        if ($puchong === null) {
            return;
        }
        $source = $this->location('PUCHONG', 'dispensary');
        $destination = $this->location('CHERAS', 'store');
        if (StockRequest::query()->where('source_location_id', $source->id)->where('destination_location_id', $destination->id)->exists()) {
            return;
        }
        $sku = $this->sku('TRIAL-OMEP20');
        $requests = app(StockRequestService::class);
        $request = $requests->create($this->ca('PUCHONG', $puchong), [
            'expected_branch_id' => $puchong->id, 'source_location_public_id' => $source->public_id, 'destination_location_public_id' => $destination->public_id,
            'lines' => [['sku_public_id' => $sku->public_id, 'quantity' => '20']], 'retry_key' => (string) Str::uuid(),
        ]);
        $request = $requests->approve($this->as('PUCHONG', 'supervisor1', $puchong), $request, ['expected_branch_id' => $puchong->id, 'lock_version' => $request->lock_version]);
        $line = $request->lines()->firstOrFail();
        $request = $requests->dispatch($this->as('PUCHONG', 'supervisor1', $puchong), $request->refresh(), [
            'expected_branch_id' => $puchong->id, 'lock_version' => $request->refresh()->lock_version, 'dispatch_idempotency_key' => (string) Str::uuid(),
            'lines' => [['line_public_id' => $line->public_id, 'batch_public_id' => $this->phaseOneBatch($sku)->public_id, 'quantity' => '20']],
        ]);
        $request = $requests->receive($this->ca('CHERAS', $cheras), $request->refresh(), [
            'expected_branch_id' => $cheras->id, 'lock_version' => $request->refresh()->lock_version, 'receive_idempotency_key' => (string) Str::uuid(),
            'lines' => [['line_public_id' => $line->public_id, 'accepted_quantity' => '18', 'short_quantity' => '2', 'damaged_quantity' => '0', 'extra_quantity' => '0']],
        ]);
        $difference = DB::table('inventory_transfer_differences')->where('stock_request_id', $request->id)->firstOrFail();
        $requests->resolveDifference($this->as('CHERAS', 'supervisor2', $cheras), $request->refresh(), [
            'expected_branch_id' => $cheras->id, 'lock_version' => $request->refresh()->lock_version, 'retry_key' => (string) Str::uuid(),
            'difference_public_id' => $difference->public_id, 'outcome' => 'arrived_later', 'reference' => 'TRIAL-TRANSFER-LOG-1',
        ]);
        $this->count('transfers');
    }

    /** A supplier recall of the CHERAS loratadine receipt batch, started by the Director (it stays active). */
    private function recall(): void
    {
        $cheras = $this->context->mainBranch();
        $batch = InventoryBatch::query()->where('organisation_id', $cheras->organisation_id)->where('batch_number', $this->batchNumber('CHERAS', 'LORA'))->first();
        if ($batch === null || DB::table('inventory_recalls')->where('organisation_id', $cheras->organisation_id)->exists()) {
            return;
        }
        app(InventoryRecallService::class)->start($this->context->asDirector($cheras), [
            'expected_branch_id' => $cheras->id, 'idempotency_key' => (string) Str::uuid(), 'sku_public_id' => $this->sku('TRIAL-LORA10')->public_id,
            'batch_public_ids' => [$batch->public_id], 'reason' => 'supplier_recall', 'reference' => 'TRIAL-RECALL-NOTICE-1', 'note' => null,
        ]);
        $this->count('recalls');
    }

    // --- helpers ---------------------------------------------------------------------------------------------------

    private function approveOrder(string $code, Branch $branch, PurchaseOrder $order): PurchaseOrder
    {
        $base = ['expected_branch_id' => $branch->id];
        $procurement = app(ProcurementService::class);
        if ($order->status === 'draft') {
            $order = $procurement->submit($this->as($code, 'supervisor1', $branch), $order, $base + ['lock_version' => $order->lock_version]);
        }

        // STK-50: only the Director approves a purchase order.
        return $procurement->approve($this->context->asDirector($branch), $order->refresh(), $base + ['lock_version' => $order->refresh()->lock_version]);
    }

    /**
     * @param  list<array{int, string, int, string, ?string}>  $lots  quantity, batch number, actual total cost (sen), outcome, reason
     * @return array<string, mixed>
     */
    private function receiptLine(string $linePublicId, string $physical, array $lots): array
    {
        return [
            'line_public_id' => $linePublicId,
            'physical_quantity' => $physical,
            'lots' => array_map(fn (array $lot): array => [
                'quantity' => (string) $lot[0], 'batch_number' => $lot[1], 'expiry_date' => now()->addMonthsNoOverflow(20)->endOfMonth()->toDateString(),
                'outcome' => $lot[3], 'reason' => $lot[4], 'cost_total_sen' => $lot[2],
            ], $lots),
            'short_delivery' => false,
        ];
    }

    private function batchNumber(string $code, string $suffix): string
    {
        return 'TRIAL-RCV-'.$code.'-'.$suffix;
    }

    private function as(string $code, string $role, Branch $branch): User
    {
        return $this->context->as(TrialDataContext::key($code, $role), $branch);
    }

    private function location(string $code, string $kind): InventoryLocation
    {
        $locationCode = $kind === 'store' ? PhaseOneFoundation::storeCode($code) : PhaseOneFoundation::dispensaryCode($code);

        return InventoryLocation::query()->where('organisation_id', $this->context->organisation->id)->where('code', $locationCode)->firstOrFail();
    }

    private function supplier(int $index): InventorySupplier
    {
        return InventorySupplier::query()->where('organisation_id', $this->context->organisation->id)->where('name', PhaseOneFoundation::SUPPLIERS[$index])->firstOrFail();
    }

    private function sku(string $medicineCode): InventorySku
    {
        $medicine = MedicineCatalogueItem::query()->where('organisation_id', $this->context->organisation->id)->where('code', $medicineCode)->firstOrFail();
        $mapping = MedicineCatalogueInventorySku::query()->where('organisation_id', $this->context->organisation->id)
            ->where('medicine_catalogue_item_id', $medicine->id)->where('is_active', true)->firstOrFail();

        return InventorySku::query()->whereKey($mapping->inventory_sku_id)->firstOrFail();
    }

    private function phaseOneBatch(InventorySku $sku): InventoryBatch
    {
        return InventoryBatch::query()->where('organisation_id', $this->context->organisation->id)->where('inventory_sku_id', $sku->id)
            ->where('batch_number', 'like', 'TRIAL-B%')->firstOrFail();
    }

    /** @return object{id: int, lock_version: int}|null */
    private function balance(InventoryLocation $location, string $batchNumber): ?object
    {
        $row = DB::table('inventory_stock_balances')
            ->join('inventory_batches', 'inventory_batches.id', '=', 'inventory_stock_balances.inventory_batch_id')
            ->where('inventory_stock_balances.inventory_location_id', $location->id)
            ->where('inventory_batches.batch_number', $batchNumber)
            ->where('inventory_stock_balances.quantity', '>', 0)
            ->select('inventory_stock_balances.id', 'inventory_stock_balances.lock_version')
            ->first();

        return $row === null ? null : (object) ['id' => (int) $row->id, 'lock_version' => (int) $row->lock_version];
    }

    private function caseVersion(int $case): int
    {
        return (int) DB::table('inventory_governed_actions')->where('case_id', $case)->max('lock_version');
    }
}
