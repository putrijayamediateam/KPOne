<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\UnifiedCatalogueSetupService;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UnifiedCatalogueSetupTest extends ClinicalTestCase
{
    public function test_one_medicine_submission_creates_linked_inventory_stock_costs_and_tariffs(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $this->actingAs($actor);
        $location = $this->location($actor);
        $panel = Panel::factory()->create([
            'organisation_id' => $actor->organisation_id,
            'code' => 'SYNTH-UNIFIED-PANEL',
        ]);

        $this->post(route('medicines.store'), [
            'code' => 'UNIFIED-MED',
            'display_name' => 'Synthetic Unified Medicine',
            'generic_name' => 'Synthetic active ingredient',
            'category' => 'Synthetic category',
            'group_name' => 'Synthetic group',
            'strength_text' => '10 mg',
            'dosage_form' => 'tablet',
            'order_unit' => 'tablet',
            'default_dosage_amount' => '1',
            'default_dosage_unit' => 'tablet',
            'default_instruction' => 'After food',
            'route' => 'oral',
            'manufacturer' => 'Synthetic Manufacturer',
            'expected_branch_id' => $this->branch->id,
            'sku' => [
                'sku_code' => 'UNIFIED-MED-SKU',
                'barcode' => 'SYNTH-BARCODE-001',
                'pack_size' => '10',
                'purchase_unit' => 'box',
                'stock_unit' => 'tablet',
                'dispensing_unit' => 'tablet',
                'unit_conversion' => '10',
            ],
            'prices' => [
                'self_pay_sen' => 250,
                'panel_default_sen' => 200,
                'panel_overrides' => [[
                    'panel_id' => $panel->id,
                    'amount_sen' => 175,
                ]],
            ],
            'batch' => [
                'batch_number' => 'SYNTH-BATCH-001',
                'expiry_date' => now()->addYear()->toDateString(),
            ],
            'opening_stock' => [[
                'branch_id' => $this->branch->id,
                'location_public_id' => $location->public_id,
                'quantity' => '20',
                'unit_cost_sen' => 100,
            ]],
        ])->assertRedirect(route('medicines.index'));

        $medicine = MedicineCatalogueItem::query()->where('code', 'UNIFIED-MED')->sole();
        $sku = InventorySku::query()->where('sku_code', 'UNIFIED-MED-SKU')->sole();
        $this->assertSame($medicine->id, DB::table('medicine_catalogue_inventory_skus')
            ->where('medicine_catalogue_item_id', $medicine->id)->value('medicine_catalogue_item_id'));
        $this->assertSame($sku->id, DB::table('medicine_catalogue_inventory_skus')
            ->where('medicine_catalogue_item_id', $medicine->id)->value('inventory_sku_id'));
        $this->assertSame(1, InventoryItem::query()->where('code', 'UNIFIED-MED')->count());
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'opening_balance',
            'quantity' => '20.000',
            'unit_cost_sen' => 100,
        ]);
        $this->assertDatabaseHas('price_books', [
            'scope_key' => 'organisation',
            'price_tier' => 'self_pay',
        ]);
        $this->assertDatabaseHas('price_books', [
            'scope_key' => 'panel:default',
            'price_tier' => 'panel',
        ]);
        $this->assertDatabaseHas('price_books', [
            'scope_key' => 'panel:'.$panel->id,
            'price_tier' => 'panel',
            'panel_id' => $panel->id,
        ]);
        $this->assertSame('Synthetic category', $medicine->category);
    }

    public function test_catalogue_creation_reuses_an_existing_inventory_sku_without_duplicating_it(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $item = app(InventoryReferenceAdministrationService::class)->createItem($actor, [
            'code' => 'REUSE-MED',
            'generic_name' => 'Synthetic reusable ingredient',
            'brand_name' => 'Synthetic reusable medicine',
        ]);
        $sku = app(InventoryReferenceAdministrationService::class)->createSku($actor, $item, [
            'sku_code' => 'REUSE-MED-SKU',
            'pack_size' => '1',
            'purchase_unit' => 'tablet',
            'stock_unit' => 'tablet',
            'dispensing_unit' => 'tablet',
            'unit_conversion' => '1',
        ]);

        $this->actingAs($actor);
        $this->getJson(route('medicines.inventory-skus', ['query' => 'REUSE-MED']))
            ->assertOk()->assertJsonPath('data.0.value', $sku->public_id);
        $this->post(route('medicines.store'), [
            'code' => 'REUSE-MED',
            'display_name' => 'Synthetic reusable medicine',
            'order_unit' => 'tablet',
            'inventory_sku_public_id' => $sku->public_id,
        ])->assertRedirect(route('medicines.index'));

        $this->assertSame(1, InventoryItem::query()->where('code', 'REUSE-MED')->count());
        $this->assertSame(1, InventorySku::query()->where('sku_code', 'REUSE-MED-SKU')->count());
        $this->assertDatabaseHas('medicine_catalogue_inventory_skus', [
            'medicine_catalogue_item_id' => MedicineCatalogueItem::query()->where('code', 'REUSE-MED')->value('id'),
            'inventory_sku_id' => $sku->id,
        ]);
    }

    public function test_price_publication_failure_rolls_back_the_entire_medicine_setup(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        try {
            app(UnifiedCatalogueSetupService::class)->createMedicine($director, [
                'code' => 'ROLLBACK-MED',
                'display_name' => 'Synthetic rollback medicine',
                'order_unit' => 'tablet',
                'prices' => ['self_pay_sen' => 250],
                'expected_branch_id' => $this->branch->id,
            ]);
            $this->fail('A user without price publication authority published a price.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('medicine_catalogue_items', ['code' => 'ROLLBACK-MED']);
            $this->assertDatabaseMissing('inventory_items', ['code' => 'ROLLBACK-MED']);
            $this->assertDatabaseMissing('inventory_skus', ['sku_code' => 'ROLLBACK-MED']);
            $this->assertSame(0, PriceBook::query()->where('scope_key', 'organisation')->count());
        }
    }

    public function test_catalogue_dropdown_options_and_panels_are_persisted_and_authorised(): void
    {
        $director = $this->actor('director');
        $this->actingAs($director);

        $this->postJson(route('catalogue-options.store', 'medicine_category'), [
            'label' => 'Synthetic respiratory category',
        ])->assertCreated()->assertJsonPath('data.value', 'Synthetic respiratory category');
        $this->getJson(route('catalogue-options.index', [
            'type' => 'medicine_category',
            'query' => 'respiratory',
        ]))->assertOk()->assertJsonPath('data.0.label', 'Synthetic respiratory category');

        $this->postJson(route('catalogue-panels.store'), [
            'code' => 'SYNTH-INLINE-PANEL',
            'name' => 'Synthetic Inline Panel',
        ])->assertCreated()->assertJsonPath('data.name', 'Synthetic Inline Panel');
        $this->assertDatabaseHas('panels', [
            'organisation_id' => $director->organisation_id,
            'code' => 'SYNTH-INLINE-PANEL',
            'name' => 'Synthetic Inline Panel',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'panel.created',
            'actor_user_id' => $director->id,
        ]);

        $this->actingAs($director);
        $this->postJson(route('catalogue-setup.suppliers.store'), [
            'code' => 'SYNTH-INLINE-SUPPLIER',
            'name' => 'Synthetic Inline Supplier',
        ])->assertCreated()->assertJsonPath('data.code', 'SYNTH-INLINE-SUPPLIER');
        $this->postJson(route('catalogue-setup.locations.store'), [
            'branch_id' => $this->branch->id,
            'code' => 'SYNTH-INLINE-LOCATION',
            'name' => 'Synthetic Inline Location',
            'type' => 'medical_stock',
        ])->assertCreated()->assertJsonPath('data.name', 'Synthetic Inline Location');
        $this->assertDatabaseHas('inventory_suppliers', [
            'organisation_id' => $director->organisation_id,
            'code' => 'SYNTH-INLINE-SUPPLIER',
        ]);
        $this->assertDatabaseHas('inventory_locations', [
            'organisation_id' => $director->organisation_id,
            'branch_id' => $this->branch->id,
            'code' => 'SYNTH-INLINE-LOCATION',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'inventory.supplier.created',
            'actor_user_id' => $director->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'inventory_location.created',
            'actor_user_id' => $director->id,
        ]);

        $technicalAdmin = $this->actor('technical_admin');
        $this->actingAs($technicalAdmin);
        $this->postJson(route('catalogue-panels.store'), [
            'code' => 'DENIED-PANEL',
            'name' => 'Synthetic Denied Panel',
        ])->assertForbidden();
        $this->postJson(route('catalogue-setup.suppliers.store'), [
            'code' => 'DENIED-SUPPLIER',
            'name' => 'Synthetic Denied Supplier',
        ])->assertForbidden();
        $this->postJson(route('catalogue-setup.locations.store'), [
            'branch_id' => $this->branch->id,
            'code' => 'DENIED-LOCATION',
            'name' => 'Synthetic Denied Location',
            'type' => 'medical_stock',
        ])->assertForbidden();
        $this->postJson(route('catalogue-options.store', 'medicine_category'), [
            'label' => 'Denied option',
        ])->assertForbidden();
        $this->assertDatabaseMissing('panels', ['code' => 'DENIED-PANEL']);
        $this->assertDatabaseMissing('catalogue_options', ['label' => 'Denied option']);
        $this->assertDatabaseMissing('inventory_suppliers', ['code' => 'DENIED-SUPPLIER']);
        $this->assertDatabaseMissing('inventory_locations', ['code' => 'DENIED-LOCATION']);
    }

    private function location(User $actor): InventoryLocation
    {
        return app(InventoryReferenceAdministrationService::class)->createLocation(
            $actor,
            $this->branch,
            null,
            ['code' => 'UNIFIED-LOCATION', 'name' => 'Synthetic Unified Location', 'type' => 'medical_stock'],
        );
    }
}
