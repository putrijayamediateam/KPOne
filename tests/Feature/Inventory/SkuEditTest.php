<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\SkuEditService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;

/** STK-14: the general SKU edit screen (owner "a until g" approval, 2026-11-11). */
final class SkuEditTest extends VisitTestCase
{
    /** @return array{0: InventorySku, 1: InventoryItem, 2: callable(string): User} */
    private function freshSku(): array
    {
        $actors = new SupplierReturnFixture($this->branch);
        $director = $actors->actor('director');
        $references = app(InventoryReferenceAdministrationService::class);
        $item = $references->createItem($director, ['code' => 'SYN-EDIT-ITEM', 'generic_name' => 'Synthetic edit item']);
        $sku = $references->createSku($director, $item, ['sku_code' => 'SYN-EDIT-SKU', 'pack_size' => '1', 'purchase_unit' => 'tablet',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1']);

        return [$sku->refresh(), $item->refresh(), fn (string $role) => $actors->actor($role)];
    }

    /** @return array<string, mixed> */
    private function input(InventorySku $sku, InventoryItem $item, array $overrides = []): array
    {
        return ['generic_name' => $item->generic_name, 'brand_name' => null, 'sku_code' => $sku->sku_code, 'barcode' => $sku->barcode,
            'pack_size' => (string) $sku->pack_size, 'purchase_unit' => $sku->purchase_unit, 'stock_unit' => $sku->stock_unit,
            'dispensing_unit' => $sku->dispensing_unit, 'unit_conversion' => (string) $sku->unit_conversion, 'storage_type' => $sku->storage_type,
            'minimum_temperature' => null, 'maximum_temperature' => null, 'cold_chain_required' => false, 'do_not_freeze' => false,
            'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true,
            'sku_active' => true, 'item_active' => true, 'sku_lock_version' => $sku->lock_version, 'item_lock_version' => $item->lock_version, ...$overrides];
    }

    public function test_a_save_changes_names_barcode_and_storage_with_versions(): void
    {
        [$sku, $item, $actor] = $this->freshSku();
        $supervisor = $actor('ca_supervisor');
        $input = $this->input($sku, $item, ['generic_name' => 'Synthetic renamed item', 'barcode' => '9555000000017', 'storage_type' => 'refrigerated',
            'minimum_temperature' => '2', 'maximum_temperature' => '8', 'cold_chain_required' => true]);
        $this->actingAs($supervisor)->put(route('inventory.skus.update', $sku->public_id), $input)->assertRedirect(route('inventory.skus'))->assertSessionHasNoErrors();
        $sku->refresh();
        $this->assertSame(['Synthetic renamed item', '9555000000017', 'refrigerated', true, 2, 2],
            [$item->refresh()->generic_name, $sku->barcode, $sku->storage_type, $sku->cold_chain_required, $sku->lock_version, $item->lock_version]);

        // The same save again (a retry) is already saved and passes; a different edit from the old version is refused.
        $this->actingAs($supervisor)->put(route('inventory.skus.update', $sku->public_id), $input)->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->put(route('inventory.skus.update', $sku->public_id), [...$input, 'barcode' => '9555000000024'])
            ->assertSessionHasErrors(['expected_lock_version' => 'This SKU changed. Reload and try again.']);
        $this->assertSame('9555000000017', $sku->refresh()->barcode);
    }

    public function test_units_lock_once_evidence_exists_and_only_reference_managers_save(): void
    {
        [$sku, $item, $actor] = $this->freshSku();
        DB::table('inventory_batches')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $sku->organisation_id,
            'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-EDIT-B1', 'expiry_date' => '2028-12-31', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        try {
            app(SkuEditService::class)->save($actor('director'), $sku->public_id, $this->input($sku, $item, ['stock_unit' => 'box']));
            $this->fail('A unit changed after a batch existed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->errors());
        }
        $this->assertSame('tablet', $sku->refresh()->stock_unit);

        $this->actingAs($actor('ca'))->put(route('inventory.skus.update', $sku->public_id), $this->input($sku, $item))->assertForbidden();
        $page = $this->actingAs($actor('ca'))->get(route('inventory.skus', ['q' => 'SYN-EDIT']))->assertOk()->viewData('page')['props']['skus'];
        $this->assertSame([false, true], [$page['canManage'], $page['rows'][0]['unitsLocked']]);
    }

    public function test_an_item_and_its_sku_are_deactivated_and_reactivated_in_one_save(): void
    {
        [$sku, $item, $actor] = $this->freshSku();
        $service = app(SkuEditService::class);
        $administrator = $actor('administrator');
        $service->save($administrator, $sku->public_id, $this->input($sku, $item, ['sku_active' => false, 'item_active' => false]));
        $this->assertSame([false, false], [$sku->refresh()->is_active, $item->refresh()->is_active]);
        $service->save($administrator, $sku->public_id, $this->input($sku, $item, ['sku_active' => true, 'item_active' => true]));
        $this->assertSame([true, true], [$sku->refresh()->is_active, $item->refresh()->is_active]);
    }

    public function test_a_stale_screen_cannot_undo_someone_elses_status_change(): void
    {
        [$sku, $item, $actor] = $this->freshSku();
        $service = app(SkuEditService::class);
        $stale = $this->input($sku, $item);
        // B deactivates the SKU; A then saves the old, unchanged form with "SKU active" still ticked.
        $service->save($actor('director'), $sku->public_id, $this->input($sku, $item, ['sku_active' => false]));
        $this->assertThrows(fn () => $service->save($actor('ca_supervisor'), $sku->public_id, $stale), ValidationException::class);
        $this->assertFalse($sku->refresh()->is_active);

        // Likewise an old screen with "Item active" unticked cannot deactivate an item someone has since renamed.
        $service->save($actor('director'), $sku->public_id, $this->input($sku->refresh(), $item->refresh(), ['sku_active' => false, 'generic_name' => 'Synthetic renamed']));
        $old = $this->input($sku->refresh(), $item, ['sku_active' => false, 'item_active' => false, 'generic_name' => 'Synthetic renamed']);
        $this->assertThrows(fn () => $service->save($actor('ca_supervisor'), $sku->public_id, $old), ValidationException::class);
        $this->assertTrue($item->refresh()->is_active);
    }

    public function test_postgresql_raises_the_version_on_every_update_and_refuses_a_skipped_one(): void
    {
        [$sku] = $this->freshSku();
        DB::table('inventory_skus')->where('id', $sku->id)->update(['barcode' => '9555000000031']);
        $this->assertSame(2, (int) DB::table('inventory_skus')->where('id', $sku->id)->value('lock_version'));
        try {
            DB::transaction(fn () => DB::table('inventory_skus')->where('id', $sku->id)->update(['lock_version' => 9]));
            $this->fail('PostgreSQL accepted a skipped version');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Invalid inventory reference version', $e->getMessage());
        }
    }

    public function test_the_migration_replays_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_07_001000_add_lock_version_to_inventory_items_and_skus.php');
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('inventory_skus', 'lock_version'));
        $migration->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('inventory_items', 'lock_version'));
    }
}
