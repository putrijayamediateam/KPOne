<?php

namespace Database\Seeders;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\SupplierSettingsService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Str;
use LogicException;

/**
 * DEMO-2 (owner, 16 Nov 2026: "build the inventory walk seeder"): adds what the pilot CA Supervisor guide needs to
 * walk the Inventory screens on top of KPOneDemoSetupSeeder (DEMO-1). Local and testing environments only, every
 * account must end in .test, and every change goes through the domain services as a named demo account, so
 * permissions, branch scope, locks and audit apply. Each step first checks whether its result exists, so a rerun
 * adds nothing. Fictional names; no patient, Panel or cost policy.
 */
class KPOneInventoryWalkSeeder extends KPOneDemoSetupSeeder
{
    public const STORE_CODE = 'DEMO-CHERAS-STORE';

    /** Fictional suppliers (names say so); no phone numbers. */
    public const SUPPLIERS = [
        'Demo Pharma Supplies (fictional)',
        'Demo Medical Trading (fictional)',
    ];

    /** A controlled medicine for the controlled register, with its own SKU and 100 on hand at the dispensary. */
    public const CONTROLLED = ['code' => 'DEMO-COD15', 'display_name' => 'Codeine phosphate 15 mg tablet', 'generic_name' => 'Codeine phosphate',
        'strength_text' => '15 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'self_pay_sen' => 60, 'panel_default_sen' => 50];

    public const CONTROLLED_QUANTITY = '100';

    /** A second, earlier-expiring Paracetamol batch in the store: FEFO, recalls and transfers have something to show. */
    public const SECOND_BATCH = 'DEMO-B101';

    public const SECOND_BATCH_QUANTITY = '300';

    /** One approved Purchase Order from the first supplier into the store, waiting to be received. */
    public const ORDER_LINES = [
        ['code' => 'DEMO-AMOX250', 'quantity' => '500', 'unit_cost_sen' => 35],
        ['code' => 'DEMO-CETI10', 'quantity' => '300', 'unit_cost_sen' => 20],
    ];

    public function run(): void
    {
        $this->assertSafeEnvironment();

        $organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->where('is_active', true)->firstOrFail();
        $branch = Branch::query()->where('organisation_id', $organisation->id)->where('code', self::BRANCH_CODE)->where('is_active', true)->firstOrFail();
        $dispensary = InventoryLocation::query()->where('organisation_id', $organisation->id)->where('code', self::LOCATION_CODE)->first()
            ?? throw new LogicException('Run KPOneDemoSetupSeeder first: the CHERAS demo dispensary is missing.');
        $this->requireUser(self::SECOND_SUPERVISOR_EMAIL);

        // CA Supervisor: the store, the controlled medicine and its stock, and the second Paracetamol batch.
        $supervisor = $this->actAs('demo.ca_supervisor@kpone.test');
        $store = $this->ensureStore($supervisor, $branch);
        $controlledSku = $this->ensureMedicine($supervisor, $branch, self::CONTROLLED);
        $this->ensureUnitVerified($supervisor, $branch, $controlledSku);
        $openings = [
            $this->ensureOpening($supervisor, $branch, $dispensary, $controlledSku, 'DEMO-C001', 20, self::CONTROLLED_QUANTITY),
            $this->ensureOpening($supervisor, $branch, $store, $this->sku($organisation, 'DEMO-PARA500'), self::SECOND_BATCH, 8, self::SECOND_BATCH_QUANTITY),
        ];
        $this->ensureControlled($supervisor, $organisation);

        // A second CA Supervisor signs off both openings (STK-1: never the recorder).
        $verifier = $this->actAs(self::SECOND_SUPERVISOR_EMAIL);
        foreach ($openings as $opening) {
            $this->ensureOpeningSignedOff($verifier, $branch, $opening);
        }

        // Finance Officer: the two suppliers.
        $finance = $this->actAs('demo.finance_officer@kpone.test');
        $suppliers = array_map(fn (string $name): InventorySupplier => $this->ensureSupplier($finance, $branch, $name), self::SUPPLIERS);

        // CA Supervisor raises and submits the order; the Finance Officer approves it (STK-19: never the raiser).
        $supervisor = $this->actAs('demo.ca_supervisor@kpone.test');
        $order = $this->ensureOrder($supervisor, $branch, $organisation, $suppliers[0], $store);
        if ($order->status === PurchaseOrder::STATUS_DRAFT) {
            $order = app(ProcurementService::class)->submit($supervisor, $order, ['expected_branch_id' => $branch->id, 'lock_version' => $order->lock_version]);
        }
        if ($order->status === PurchaseOrder::STATUS_SUBMITTED) {
            $finance = $this->actAs('demo.finance_officer@kpone.test');
            app(ProcurementService::class)->approve($finance, $order, ['expected_branch_id' => $branch->id, 'lock_version' => $order->lock_version]);
        }
        $this->actAs();
    }

    private function ensureStore(User $actor, Branch $branch): InventoryLocation
    {
        return InventoryLocation::query()->where('organisation_id', $actor->organisation_id)->where('code', self::STORE_CODE)->first()
            ?? app(InventoryReferenceAdministrationService::class)->createLocation($actor, $branch, null, [
                'code' => self::STORE_CODE,
                'name' => 'Cheras Store (Demo)',
                'type' => InventoryLocation::TYPE_BRANCH_STORE,
            ]);
    }

    private function sku(Organisation $organisation, string $code): InventorySku
    {
        return InventorySku::query()->where('organisation_id', $organisation->id)->where('sku_code', $code)->first()
            ?? throw new LogicException("Run KPOneDemoSetupSeeder first: SKU {$code} is missing.");
    }

    private function ensureOpening(User $actor, Branch $branch, InventoryLocation $location, InventorySku $sku, string $batchNumber, int $monthsAhead, string $quantity): StockMovement
    {
        $batch = InventoryBatch::query()->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->where('batch_number', $batchNumber)->first()
            ?? app(InventoryReferenceAdministrationService::class)->createBatch($actor, $sku, [
                'batch_number' => $batchNumber,
                'expiry_date' => now()->addMonthsNoOverflow($monthsAhead)->endOfMonth()->toDateString(),
                'received_at' => now()->toDateString(),
                'status' => InventoryBatch::STATUS_AVAILABLE,
            ]);

        return StockMovement::query()->where('organisation_id', $actor->organisation_id)->where('destination_location_id', $location->id)
            ->where('inventory_sku_id', $sku->id)->where('inventory_batch_id', $batch->id)->where('movement_type', StockMovement::TYPE_OPENING)->first()
            ?? app(InventoryMovementService::class)->openingBalance($actor, [
                'expected_branch_id' => $branch->id,
                'location_public_id' => $location->public_id,
                'sku_public_id' => $sku->public_id,
                'batch_public_id' => $batch->public_id,
                'quantity' => $quantity,
            ]);
    }

    private function ensureControlled(User $actor, Organisation $organisation): void
    {
        $medicine = MedicineCatalogueItem::query()->where('organisation_id', $organisation->id)->where('code', self::CONTROLLED['code'])->firstOrFail();
        if (! $medicine->is_controlled) {
            app(MedicineAdministrationService::class)->setControlled($actor, $medicine, true, false);
        }
    }

    private function ensureSupplier(User $actor, Branch $branch, string $name): InventorySupplier
    {
        return InventorySupplier::query()->where('organisation_id', $actor->organisation_id)->where('name', $name)->first()
            ?? app(SupplierSettingsService::class)->create($actor, [
                'expected_branch_id' => $branch->id,
                'idempotency_key' => (string) Str::uuid(),
                'name' => $name,
                'contacts' => [['name' => 'Demo sales desk', 'phone' => null, 'country' => 'MY']],
            ]);
    }

    private function ensureOrder(User $actor, Branch $branch, Organisation $organisation, InventorySupplier $supplier, InventoryLocation $store): PurchaseOrder
    {
        $existing = PurchaseOrder::query()->where('organisation_id', $organisation->id)->where('supplier_id', $supplier->id)
            ->where('destination_location_id', $store->id)->orderBy('id')->first();

        return $existing ?? app(ProcurementService::class)->create($actor, [
            'expected_branch_id' => $branch->id,
            'supplier_public_id' => $supplier->public_id,
            'destination_location_public_id' => $store->public_id,
            'lines' => array_map(fn (array $line): array => [
                'sku_public_id' => $this->sku($organisation, $line['code'])->public_id,
                'quantity' => $line['quantity'],
                'unit_cost_sen' => $line['unit_cost_sen'],
            ], self::ORDER_LINES),
        ]);
    }
}
