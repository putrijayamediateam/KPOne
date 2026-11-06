<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-12 (B.E.R.N.I.S.S for the owner, 2026-11-06): a scanned SKU finds the catalogue medicine mapped to it for the
 * Add a medicine picker. Find only: nothing is added or saved. Synthetic data only.
 */
class DispensaryScanToAddTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_a_scanned_sku_returns_its_mapped_medicine_shaped_like_a_search_and_saves_nothing(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $tables = ['dispensary_items', 'dispensary_item_batch_allocations', 'stock_movements', 'audit_logs'];
        $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);

        $this->actingAs($f['ca'])->postJson(route('dispensary.medicines.by-sku', $case), ['sku' => $f['sku']->public_id])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.publicId', $f['medicine']->public_id)
            ->assertJsonPath('data.0.sku.publicId', $f['sku']->public_id);
        $byName = app(DispensaryDirectoryService::class)->searchMedicines($f['ca'], $case, $f['medicine']->display_name);
        $bySku = app(DispensaryDirectoryService::class)->medicinesForSku($f['ca'], $case, $f['sku']->public_id);
        $this->assertSame($byName[0], $bySku[0], 'the scan result is the same shape and content as a name search');
        $this->assertSame($before, array_map(fn (string $t): int => DB::table($t)->count(), $tables));
    }

    public function test_an_unmapped_unknown_or_inactive_medicine_finds_nothing(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $url = route('dispensary.medicines.by-sku', $case);
        $this->actingAs($f['ca'])->postJson($url, ['sku' => (string) Str::uuid()])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url, ['sku' => 'not-a-uuid'])->assertUnprocessable();

        DB::table('medicine_catalogue_inventory_skus')->where('inventory_sku_id', $f['sku']->id)->update(['is_active' => false]);
        $this->postJson($url, ['sku' => $f['sku']->public_id])->assertOk()->assertJsonCount(0, 'data');
        DB::table('medicine_catalogue_inventory_skus')->where('inventory_sku_id', $f['sku']->id)->update(['is_active' => true]);
        DB::table('medicine_catalogue_items')->where('id', $f['medicine']->id)->update(['is_active' => false]);
        $this->postJson($url, ['sku' => $f['sku']->public_id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_people_who_may_edit_the_case_at_its_branch_can_look_up(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $url = route('dispensary.medicines.by-sku', $case);
        foreach (['resident_doctor', 'finance_officer', 'administrator'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $f['visit']->branch);
            $this->actingAs($actor)->postJson($url, ['sku' => $f['sku']->public_id])->assertForbidden();
        }
    }
}
