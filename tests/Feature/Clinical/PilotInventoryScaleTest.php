<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Inventory\Services\InventoryPickerService;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportEvidence;

class PilotInventoryScaleTest extends ClinicalTestCase
{
    use ReportEvidence;

    public function test_five_thousand_reference_rows_are_bounded_searchable_scoped_and_read_only(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        for ($offset = 0; $offset < 5000; $offset += 250) {
            $items = $skus = $locations = [];
            for ($index = $offset; $index < $offset + 250; $index++) {
                $id = 100000 + $index;
                $code = 'SCALE-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
                $items[] = ['id' => $id, 'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'code' => $code, 'generic_name' => 'Synthetic '.$code];
                $skus[] = ['id' => $id, 'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_item_id' => $id, 'sku_code' => $code, 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000'];
                $locations[] = ['id' => $id, 'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'code' => $code, 'name' => 'Synthetic '.$code, 'type' => 'dispensary'];
            }
            DB::table('inventory_items')->insert($items);
            DB::table('inventory_skus')->insert($skus);
            DB::table('inventory_locations')->insert($locations);
        }
        $foreign = Organisation::query()->create(['code' => 'SCALE_FOREIGN', 'name' => 'Synthetic foreign']);
        $foreignItem = DB::table('inventory_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $foreign->id, 'code' => 'SCALE-FOREIGN', 'generic_name' => 'SCALE-FOREIGN']);
        DB::table('inventory_skus')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $foreign->id, 'inventory_item_id' => $foreignItem, 'sku_code' => 'SCALE-FOREIGN', 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000']);
        DB::table('inventory_locations')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $foreign->id, 'code' => 'SCALE-FOREIGN', 'name' => 'SCALE-FOREIGN', 'type' => 'dispensary']);
        $before = $this->sourceEvidence();
        foreach (['items', 'skus', 'locations'] as $kind) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = app(InventoryPickerService::class)->search($actor, ['kind' => $kind, 'expected_branch_id' => $this->branch->id]);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertCount(25, $result['data']);
            $this->assertLessThanOrEqual(12, count($queries));
            $this->assertLessThan(15000, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
            $this->assertTrue(collect($queries)->contains(fn ($query): bool => str_contains($query['query'], 'limit 25')));
            $this->getJson(route('inventory.picker', ['kind' => $kind, 'expected_branch_id' => $this->branch->id, 'q' => '004999']))->assertOk()->assertJsonCount(1, 'data');
            $this->getJson(route('inventory.picker', ['kind' => $kind, 'expected_branch_id' => $this->branch->id, 'q' => 'SCALE-FOREIGN']))->assertOk()->assertJsonCount(0, 'data');
            $this->getJson(route('inventory.picker', ['kind' => $kind, 'expected_branch_id' => $this->branch->id, 'limit' => 100]))->assertOk()->assertJsonCount(100, 'data');
            $this->getJson(route('inventory.picker', ['kind' => $kind, 'expected_branch_id' => $this->branch->id, 'limit' => 101]))->assertUnprocessable();
        }
        $document = $this->get(route('inventory.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('referenceData.items', 25)->has('referenceData.skus', 25)->has('referenceData.locations', 25)
            ->has('operations.skus', 25)->has('operations.locations', 25)->has('inventory.locations', 25));
        $version = $document->viewData('page')['version'];
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get(route('inventory.index'))->assertOk();
        $this->assertSame($version, $response->json('version'));
        $this->assertLessThan(100000, strlen($response->getContent()));
        $this->assertSame($before, $this->sourceEvidence());
    }

    public function test_picker_reuses_exact_existing_permission_and_refuses_stale_branch_and_invalid_filters(): void
    {
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->getJson(route('inventory.picker', ['kind' => 'items', 'expected_branch_id' => $this->branch->id]));
            in_array('inventory.view.branch', $permissions, true) ? $response->assertOk() : $response->assertForbidden();
        }
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        foreach ([['expected_branch_id' => 999999], ['limit' => 0], ['limit' => 'abc'], ['context' => 'foreign'], ['kind' => 'users'], ['q' => str_repeat('x', 101)], ['sku' => 'invalid']] as $invalid) {
            $this->getJson(route('inventory.picker', $invalid + ['kind' => 'items', 'expected_branch_id' => $this->branch->id]))->assertUnprocessable();
        }
        $actor->roles()->firstOrFail()->revokePermissionTo('inventory.view.branch');
        $this->getJson(route('inventory.picker', ['kind' => 'items', 'expected_branch_id' => $this->branch->id]))->assertForbidden();
    }

    public function test_picker_order_is_explicit_byte_order_and_search_metacharacters_are_literal(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        foreach (['雪', 'a', 'Z', 'É', '%'] as $index => $name) {
            DB::table('inventory_items')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'code' => 'ORDER-'.$index, 'generic_name' => $name]);
        }
        $result = app(InventoryPickerService::class)->search($actor, ['kind' => 'items', 'expected_branch_id' => $this->branch->id, 'q' => 'ORDER-']);
        $this->assertSame(['%', 'Z', 'a', 'É', '雪'], array_column($result['data'], 'genericName'));
        $result = app(InventoryPickerService::class)->search($actor, ['kind' => 'items', 'expected_branch_id' => $this->branch->id, 'q' => '%']);
        $this->assertSame(['%'], array_column($result['data'], 'genericName'));
    }
}
