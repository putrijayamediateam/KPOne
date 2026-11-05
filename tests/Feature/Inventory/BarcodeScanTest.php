<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryCodeLookupService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;

final class BarcodeScanTest extends VisitTestCase
{
    private function staff(string $role, ?Branch $branch = null): User
    {
        return (new SupplierReturnFixture($branch ?? $this->branch))->actor($role, $branch);
    }

    private function sku(User $director, string $code, ?string $barcode, string $name = 'Synthetic scan medicine'): InventorySku
    {
        $service = app(InventoryReferenceAdministrationService::class);
        $item = $service->createItem($director, ['code' => 'SYN-SCAN-'.Str::upper(Str::random(6)), 'generic_name' => $name, 'brand_name' => null, 'strength' => '500 mg', 'dosage_form' => 'tablet', 'route' => 'oral', 'manufacturer' => null, 'mal_number' => null]);

        return $service->createSku($director, $item, ['sku_code' => $code, 'barcode' => $barcode, 'pack_size' => '1', 'purchase_unit' => 'tablet', 'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1', 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true]);
    }

    private function refused(callable $attempt, string $field): string
    {
        try {
            $attempt();
            $this->fail('Expected refusal on '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return $e->errors()[$field][0];
        }
    }

    /** Legacy duplicates predate the STK-9 rule; insert one with the guard trigger switched off for this test only. */
    private function legacyDuplicate(InventorySku $sku, string $code): void
    {
        DB::statement('ALTER TABLE inventory_skus DISABLE TRIGGER inventory_skus_unique_barcode');
        DB::table('inventory_skus')->where('id', $sku->id)->update(['barcode' => $code]);
        DB::statement('ALTER TABLE inventory_skus ENABLE TRIGGER inventory_skus_unique_barcode');
    }

    public function test_a_scanned_code_finds_its_sku_by_barcode_or_exact_sku_code(): void
    {
        $director = $this->staff('director');
        $sku = $this->sku($director, 'SYN-SCAN-PCM', '9556001234567', 'Synthetic paracetamol');
        $service = app(InventoryCodeLookupService::class);
        $ca = $this->staff('ca');
        $this->refused(fn () => $service->find($ca, ['code' => "9556001234567\t"]), 'code');
        foreach (['9556001234567', ' 9556001234567 ', '  9556001234567'] as $code) {
            $result = $service->find($ca, ['code' => $code]);
            $this->assertSame('found', $result['status'], $code);
            $this->assertSame(['public_id' => $sku->public_id, 'sku_code' => 'SYN-SCAN-PCM', 'item_name' => 'Synthetic paracetamol', 'stock_unit' => 'tablet', 'active' => true], $result['sku']);
        }
        $this->sku($director, 'SYN-SCAN-ABC', 'abc-123');
        $this->assertSame('SYN-SCAN-ABC', $service->find($ca, ['code' => 'ABC-123'])['sku']['sku_code']);
        $this->assertSame('SYN-SCAN-PCM', $service->find($ca, ['code' => 'syn-scan-pcm'])['sku']['sku_code']);
        $this->assertSame(['status' => 'not_found', 'code' => '0000000000000'], $service->find($ca, ['code' => '0000000000000']));
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', '%scan%')->count());
    }

    public function test_one_barcode_belongs_to_one_sku_in_domain_and_database(): void
    {
        $director = $this->staff('director');
        $first = $this->sku($director, 'SYN-SCAN-ONE', '1111111111111');
        $service = app(InventoryReferenceAdministrationService::class);
        $message = $this->refused(fn () => $this->sku($director, 'SYN-SCAN-TWO', ' 1111111111111 '), 'barcode');
        $this->assertStringContainsString('SYN-SCAN-ONE', $message);
        $second = $this->sku($director, 'SYN-SCAN-TWO', '2222222222222');
        $this->refused(fn () => $service->updateSku($director, $second, ['barcode' => '1111111111111']), 'barcode');
        $updated = $service->updateSku($director, $first->refresh(), ['barcode' => '1111111111111', 'pack_size' => '2']);
        $this->assertSame('1111111111111', $updated->barcode);
        $cleared = $service->updateSku($director, $second->refresh(), ['barcode' => null]);
        $this->assertNull($cleared->barcode);
        $this->sku($director, 'SYN-SCAN-THREE', null);
        $this->sku($director, 'SYN-SCAN-FOUR', null);
        try {
            DB::transaction(fn () => DB::table('inventory_skus')->where('id', $second->id)->update(['barcode' => '1111111111111 ']));
            $this->fail('PostgreSQL accepted a shared barcode');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
        }
        $other = Organisation::query()->create(['code' => 'SYN-STK9', 'name' => 'Synthetic scan organisation', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $other->id, 'code' => 'SYN-STK9', 'name' => 'Synthetic scan branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = $this->sku($this->staff('director', $branch), 'SYN-SCAN-ONE', '1111111111111');
        $this->assertSame($other->id, $foreign->organisation_id);
    }

    public function test_a_legacy_shared_code_is_never_resolved_to_one_sku_and_is_listed_for_fixing(): void
    {
        $director = $this->staff('director');
        $this->sku($director, 'SYN-SCAN-A', '3333333333333', 'Synthetic item A');
        $b = $this->sku($director, 'SYN-SCAN-B', null, 'Synthetic item B');
        $this->legacyDuplicate($b, '3333333333333');
        $ca = $this->staff('ca');
        $this->assertSame(['status' => 'shared', 'code' => '3333333333333', 'skus' => ['SYN-SCAN-A', 'SYN-SCAN-B']], app(InventoryCodeLookupService::class)->find($ca, ['code' => '3333333333333']));
        $shared = app(InventoryCodeLookupService::class)->sharedCodes($this->organisation->id);
        $this->assertFalse($shared['more']);
        $this->assertSame([['code' => '3333333333333', 'skus' => [['public_id' => DB::table('inventory_skus')->where('sku_code', 'SYN-SCAN-A')->value('public_id'), 'sku_code' => 'SYN-SCAN-A', 'item_name' => 'Synthetic item A'], ['public_id' => $b->public_id, 'sku_code' => 'SYN-SCAN-B', 'item_name' => 'Synthetic item B']]]], $shared['rows']);
        // Other fields of a SKU holding a legacy duplicate can still be edited.
        $edited = app(InventoryReferenceAdministrationService::class)->updateSku($director, $b->refresh(), ['pack_size' => '3']);
        $this->assertSame('3333333333333', $edited->barcode);
        $this->actingAs($ca)->get(route('inventory.readiness'))->assertInertia(fn ($page) => $page->where('readiness.sharedBarcodes.rows.0.code', '3333333333333')
            ->where('readiness.sharedBarcodes.rows.0.skus.1.sku_code', 'SYN-SCAN-B')->where('readiness.canFixBarcodes', false));
        // The fix: a reference manager gives one SKU its own code through the governed SKU update.
        $this->actingAs($ca)->post(route('inventory.readiness.barcode', ['sku' => $b->public_id]), ['barcode' => '3333333333334'])->assertForbidden();
        $supervisor = $this->staff('ca_supervisor');
        $this->actingAs($supervisor)->get(route('inventory.readiness'))->assertInertia(fn ($page) => $page->where('readiness.canFixBarcodes', true));
        $this->sku($director, 'SYN-SCAN-C', '7777777777777');
        $this->actingAs($supervisor)->post(route('inventory.readiness.barcode', ['sku' => $b->public_id]), ['barcode' => ' 7777777777777 '])
            ->assertRedirect(route('inventory.readiness'))->assertSessionHasErrors('barcode');
        $this->assertSame('3333333333333', $b->refresh()->barcode);
        $auditMax = (int) DB::table('audit_logs')->max('id');
        $this->actingAs($supervisor)->post(route('inventory.readiness.barcode', ['sku' => $b->public_id]), ['barcode' => ''])
            ->assertRedirect(route('inventory.readiness'))->assertSessionHasNoErrors();
        $this->assertNull($b->refresh()->barcode);
        $this->assertSame(1, DB::table('audit_logs')->where('id', '>', $auditMax)->where('event', 'inventory_sku.updated')->count());
        $this->assertSame(['rows' => [], 'more' => false], app(InventoryCodeLookupService::class)->sharedCodes($this->organisation->id));
        $this->assertSame('found', app(InventoryCodeLookupService::class)->find($ca, ['code' => '3333333333333'])['status']);
        $this->actingAs($supervisor)->post(route('inventory.readiness.barcode', ['sku' => (string) Str::uuid()]), ['barcode' => 'x'])->assertNotFound();
    }

    public function test_lookup_route_permissions_branch_scope_and_no_changes(): void
    {
        $director = $this->staff('director');
        $this->sku($director, 'SYN-SCAN-HTTP', '4444444444444');
        foreach (['ca' => 200, 'ca_supervisor' => 200, 'finance_officer' => 200, 'director' => 200, 'administrator' => 200, 'technical_admin' => 403, 'resident_doctor' => 403, 'panel_officer' => 403] as $role => $status) {
            $this->actingAs($this->staff($role))->getJson(route('inventory.scan', ['code' => '4444444444444']))->assertStatus($status);
        }
        $ca = $this->staff('ca');
        $this->actingAs($ca)->getJson(route('inventory.scan', ['code' => '4444444444444']))
            ->assertOk()->assertHeader('Cache-Control')->assertJsonPath('status', 'found')->assertJsonPath('sku.sku_code', 'SYN-SCAN-HTTP');
        $this->actingAs($ca)->getJson(route('inventory.scan'))->assertStatus(422);
        $this->actingAs($ca)->getJson(route('inventory.scan', ['code' => str_repeat('9', 101)]))->assertStatus(422);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $ca->staffProfile->id)->update(['valid_from' => now()->subDays(3)->toDateString(), 'valid_until' => now()->subDays(2)->toDateString()]);
        try {
            app(InventoryCodeLookupService::class)->find($ca->refresh(), ['code' => '4444444444444']);
            $this->fail('Ended assignment looked up a code');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $other = Organisation::query()->create(['code' => 'SYN-STK9B', 'name' => 'Synthetic scan organisation B', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $other->id, 'code' => 'SYN-STK9B', 'name' => 'Synthetic scan branch B', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $this->assertSame('not_found', app(InventoryCodeLookupService::class)->find($this->staff('ca', $branch), ['code' => '4444444444444'])['status']);
    }

    public function test_lookup_changes_no_table(): void
    {
        $director = $this->staff('director');
        $this->sku($director, 'SYN-SCAN-SNAP', '5555555555555');
        $ca = $this->staff('ca');
        $snapshot = function (): array {
            $result = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
                if (in_array($table->tablename, ['sessions', 'cache', 'cache_locks'], true)) {
                    continue;
                }
                $q = DB::table($table->tablename);
                $result[$table->tablename] = Schema::hasColumn($table->tablename, 'id') ? $q->orderBy('id')->get()->toJson() : $q->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->toJson();
            }

            return $result;
        };
        $before = $snapshot();
        app(InventoryCodeLookupService::class)->find($ca, ['code' => '5555555555555']);
        app(InventoryCodeLookupService::class)->find($ca, ['code' => 'nothing-here']);
        $this->assertSame($before, $snapshot());
    }

    public function test_an_inactive_sku_is_reported_inactive_and_a_catalogue_duplicate_shows_under_its_barcode_field(): void
    {
        $director = $this->staff('director');
        $sku = $this->sku($director, 'SYN-SCAN-OFF', '6666666666666');
        DB::table('inventory_skus')->where('id', $sku->id)->update(['is_active' => false]);
        $result = app(InventoryCodeLookupService::class)->find($this->staff('ca'), ['code' => '6666666666666']);
        $this->assertSame(['found', false], [$result['status'], $result['sku']['active']]);
        $supervisor = $this->staff('ca_supervisor');
        $this->actingAs($supervisor)->post(route('medicines.store'), [
            'code' => 'SYN-SCAN-MED', 'display_name' => 'Synthetic scan medicine', 'generic_name' => 'Synthetic scan ingredient', 'category' => 'Synthetic category',
            'order_unit' => 'tablet', 'expected_branch_id' => $this->branch->id,
            'sku' => ['sku_code' => 'SYN-SCAN-MED-SKU', 'barcode' => '6666666666666', 'pack_size' => '1', 'purchase_unit' => 'tablet', 'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1'],
            'prices' => ['self_pay_sen' => 100, 'panel_default_sen' => 120, 'panel_overrides' => []],
        ])->assertSessionHasErrors('sku.barcode');
        $this->assertFalse(DB::table('inventory_skus')->where('sku_code', 'SYN-SCAN-MED-SKU')->exists());
    }
}
