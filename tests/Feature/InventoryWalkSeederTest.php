<?php

namespace Tests\Feature;

use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Models\User;
use Database\Seeders\KPOneDemoSetupSeeder;
use Database\Seeders\KPOneInventoryWalkSeeder;
use Database\Seeders\KPOnePresentationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** DEMO-2: the inventory walk seeder for the pilot CA Supervisor guide. */
class InventoryWalkSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const COUNTED_TABLES = [
        'users', 'medicine_catalogue_items', 'inventory_skus', 'inventory_locations', 'inventory_batches', 'stock_movements',
        'inventory_opening_verifications', 'inventory_suppliers', 'inventory_purchase_orders', 'inventory_purchase_order_lines', 'audit_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOnePresentationSeeder::class);
    }

    public function test_it_refuses_production_and_a_database_without_demo_1(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->app->make(KPOneInventoryWalkSeeder::class)->run();
            $this->fail('The walk seeder ran in production.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('KPOneInventoryWalkSeeder is restricted to local and testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $before = $this->counts();
        try {
            $this->seed(KPOneInventoryWalkSeeder::class);
            $this->fail('The walk seeder ran without DEMO-1.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Run KPOneDemoSetupSeeder first', $exception->getMessage());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_it_adds_a_store_suppliers_a_controlled_medicine_a_second_batch_and_an_approved_order(): void
    {
        $this->seed(KPOneDemoSetupSeeder::class);
        $this->seed(KPOneInventoryWalkSeeder::class);

        $store = InventoryLocation::query()->where('code', KPOneInventoryWalkSeeder::STORE_CODE)->sole();
        $this->assertSame([InventoryLocation::TYPE_BRANCH_STORE, 'CHERAS'], [$store->type, DB::table('branches')->where('id', $store->branch_id)->value('code')]);

        $this->assertSame(KPOneInventoryWalkSeeder::SUPPLIERS, DB::table('inventory_suppliers')->whereIn('name', KPOneInventoryWalkSeeder::SUPPLIERS)->orderBy('id')->pluck('name')->all());

        // The controlled medicine is on the controlled register with 100 on hand at the dispensary.
        $this->assertTrue((bool) DB::table('medicine_catalogue_items')->where('code', 'DEMO-COD15')->value('is_controlled'));
        $supervisor = User::query()->where('email', 'demo.ca_supervisor@kpone.test')->sole();
        $rows = $this->actingAs($supervisor)->get(route('inventory.controlled-register'))->assertOk()->viewData('page')['props']['register']['rows'];
        $this->assertSame(['Codeine phosphate 15 mg'], array_column($rows, 'medicine'));

        // Both new openings are signed off by the second supervisor, never the recorder.
        $batchIds = DB::table('inventory_batches')->whereIn('batch_number', ['DEMO-C001', KPOneInventoryWalkSeeder::SECOND_BATCH])->pluck('id');
        $openings = StockMovement::query()->whereIn('inventory_batch_id', $batchIds)->where('movement_type', StockMovement::TYPE_OPENING)->get();
        $this->assertCount(2, $openings);
        $verifier = User::query()->where('email', KPOneDemoSetupSeeder::SECOND_SUPERVISOR_EMAIL)->sole();
        foreach ($openings as $opening) {
            $this->assertSame($supervisor->id, (int) $opening->actor_user_id);
            $this->assertSame($verifier->id, (int) DB::table('inventory_opening_verifications')->where('stock_movement_id', $opening->id)->value('actor_user_id'));
        }

        // Paracetamol now has two batches; the store one expires first (FEFO has something to show).
        $expiries = DB::table('inventory_batches')->join('inventory_skus', 'inventory_skus.id', '=', 'inventory_batches.inventory_sku_id')
            ->where('inventory_skus.sku_code', 'DEMO-PARA500')->orderBy('expiry_date')->pluck('batch_number')->all();
        $this->assertSame([KPOneInventoryWalkSeeder::SECOND_BATCH, 'DEMO-B001'], $expiries);

        // One order into the store, raised by the CA Supervisor and approved by the Finance Officer, waiting to be received.
        $order = PurchaseOrder::query()->where('destination_location_id', $store->id)->sole();
        $finance = User::query()->where('email', 'demo.finance_officer@kpone.test')->sole();
        $this->assertSame([PurchaseOrder::STATUS_APPROVED, $supervisor->id, $finance->id], [$order->status, (int) $order->created_by_user_id, (int) $order->approved_by_user_id]);
        $this->assertSame(2, DB::table('inventory_purchase_order_lines')->where('purchase_order_id', $order->id)->count());
        $this->assertSame(0, DB::table('inventory_goods_receipts')->where('purchase_order_id', $order->id)->count());
    }

    public function test_running_it_twice_duplicates_nothing(): void
    {
        $this->seed(KPOneDemoSetupSeeder::class);
        $this->seed(KPOneInventoryWalkSeeder::class);
        $afterFirst = $this->counts();

        $this->seed(KPOneInventoryWalkSeeder::class);

        $this->assertSame($afterFirst, $this->counts());
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (self::COUNTED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
