<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryCodeLookupService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;

/** DSP-18: GS1 codes on the read-only scan lookup. A plain barcode keeps the STK-9 answer exactly. */
final class Gs1ScanLookupTest extends VisitTestCase
{
    private const GS = "\x1D";

    private function staff(string $role): User
    {
        return (new SupplierReturnFixture($this->branch))->actor($role);
    }

    private function sku(User $director, string $code, ?string $barcode): InventorySku
    {
        $service = app(InventoryReferenceAdministrationService::class);
        $item = $service->createItem($director, ['code' => 'SYN-GS1-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic GS1 medicine', 'brand_name' => null, 'strength' => '500 mg', 'dosage_form' => 'tablet', 'route' => 'oral', 'manufacturer' => null, 'mal_number' => null]);

        return $service->createSku($director, $item, ['sku_code' => $code, 'barcode' => $barcode, 'pack_size' => '1', 'purchase_unit' => 'tablet', 'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1', 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true]);
    }

    public function test_a_gs1_code_finds_the_sku_by_its_gtin_and_returns_the_batch_and_expiry(): void
    {
        $director = $this->staff('director');
        $sku = $this->sku($director, 'SYN-GS1-PCM', '9556123000010');
        $service = app(InventoryCodeLookupService::class);
        $ca = $this->staff('ca');

        $codes = [
            '(01)09556123000010(17)271031(10)AB12',
            ']d2010955612300001017271031'.'10AB12'.self::GS,
            '010955612300001010AB12'.self::GS.'17271031',
        ];
        foreach ($codes as $code) {
            $result = $service->find($ca, ['code' => $code]);
            $this->assertSame('found', $result['status'], $code);
            $this->assertSame($sku->public_id, $result['sku']['public_id']);
            $this->assertSame(['gtin' => '09556123000010', 'batch' => 'AB12', 'batch_certain' => true, 'expiry' => '2027-10-31'], $result['gs1']);
        }

        // A stored GTIN-14 matches too, and an unknown GTIN still says what was read.
        $this->sku($director, 'SYN-GS1-14', '00012345678905');
        $this->assertSame('SYN-GS1-14', $service->find($ca, ['code' => '(01)00012345678905(10)Z9'])['sku']['sku_code']);
        $unknown = $service->find($ca, ['code' => '(01)00000012345670(10)Q1']);
        $this->assertSame('not_found', $unknown['status']);
        $this->assertSame('Q1', $unknown['gs1']['batch']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', '%scan%')->count());
    }

    public function test_a_plain_barcode_keeps_the_stk9_answer_without_a_gs1_key(): void
    {
        $director = $this->staff('director');
        $this->sku($director, 'SYN-GS1-EAN', '9556123000010');
        $service = app(InventoryCodeLookupService::class);
        $ca = $this->staff('ca');

        $result = $service->find($ca, ['code' => '9556123000010']);
        $this->assertSame(['status', 'code', 'sku'], array_keys($result));
        $this->assertSame(['status' => 'not_found', 'code' => '0000000000000'], $service->find($ca, ['code' => '0000000000000']));
    }

    public function test_only_the_gs_control_character_is_allowed_in_a_code(): void
    {
        $director = $this->staff('director');
        $this->sku($director, 'SYN-GS1-CTL', '9556123000010');
        $service = app(InventoryCodeLookupService::class);
        $ca = $this->staff('ca');

        foreach (["0109556123000010\t10AB12", "01095561230000\u{200B}10", "(01)09556123000010\x1E"] as $code) {
            try {
                $service->find($ca, ['code' => $code]);
                $this->fail('Expected a refusal for a control character');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('code', $e->errors());
            }
        }

        $this->get('/inventory/scan?code='.rawurlencode('0109556123000010'.'10AB12'.self::GS.'17271031'))->assertRedirect();
        $this->actingAs($ca)->getJson('/inventory/scan?code='.rawurlencode('0109556123000010'.'10AB12'.self::GS.'17271031'))
            ->assertOk()->assertJsonPath('status', 'found')->assertJsonPath('gs1.batch', 'AB12')->assertJsonPath('gs1.expiry', '2027-10-31');
    }
}
