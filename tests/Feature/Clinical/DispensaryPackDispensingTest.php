<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\DispensaryPackDisplay;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\PackConversionService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-24 (owner decision 17 of 20-21 Nov 2026): the CA dispenses whole packs ("bottles") through Inventory's checked
 * STK-52 conversion. Stock still moves in the base unit; the line keeps the conversion version and the packs. The
 * synthetic SKU uses 5 units a bottle, so 2 bottles are 10 units (the fixture's whole opening stock).
 */
class DispensaryPackDispensingTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    private User $director;

    /** @param array<string, mixed> $f */
    private function checkedConversion(array $f, int $perPack = 5, string $unit = 'bottle'): string
    {
        $this->director ??= $this->actor('director');
        $this->selectBranch($this->director, $f['visit']->branch);
        $sku = $f['sku']->refresh();
        if (! DB::table('inventory_unit_contracts')->where('inventory_sku_id', $sku->id)->exists()) {
            app(InventoryReadinessService::class)->verify($this->director, 'unit', $sku->public_id, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'base_unit' => 'unit']);
        }
        $latest = (int) DB::table('inventory_pack_conversions')->where('inventory_sku_id', $sku->id)->max('version');
        $service = app(PackConversionService::class);
        $proposed = $service->propose($this->director, $sku->public_id, ['expected_version' => $latest, 'idempotency_key' => (string) Str::uuid(), 'pack_unit' => $unit, 'base_per_pack' => $perPack]);
        $service->check($this->actor('ca_supervisor'), $proposed, ['idempotency_key' => (string) Str::uuid(), 'checked_base_per_pack' => $perPack]);
        $this->selectBranch($f['ca'], $f['visit']->branch);

        return $proposed;
    }

    /** @return array{public_id: string, version: int, id: int} */
    private function current(InventorySku $sku): array
    {
        $row = DB::table('inventory_pack_conversions')->where('inventory_sku_id', $sku->id)->orderByDesc('version')->first(['id', 'public_id', 'version']);

        return ['public_id' => (string) $row->public_id, 'version' => (int) $row->version, 'id' => (int) $row->id];
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function packPayload(array $f, DispensaryCase $case, int $packs, string $quantity, array $override = []): array
    {
        $conversion = $this->current($f['sku']);

        return $this->addPayload($f, $case, $quantity, ['packs' => $packs, 'pack_conversion_public_id' => $conversion['public_id'], 'pack_conversion_version' => $conversion['version'], ...$override]);
    }

    private function refusedWith(callable $write, string $field, string $message): void
    {
        try {
            $write();
            $this->fail('The save was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame([$message], $e->errors()[$field] ?? null);
        }
    }

    public function test_two_bottles_save_the_ml_the_packs_and_the_exact_conversion_version(): void
    {
        $f = $this->fixture();
        $this->checkedConversion($f);
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->packPayload($f, $case, 2, '10.000'));

        $this->assertSame('10.000', (string) $item->quantity_dispensed);
        $this->assertSame(2, $item->packs_dispensed);
        $this->assertSame($this->current($f['sku'])['id'], $item->pack_conversion_id);
        $this->assertSame('10.000', (string) $item->allocations->sole()->quantity, 'stock still moves in the base unit');
        $this->assertSame('2 bottles (10 unit)', DispensaryPackDisplay::text($item));

        $detail = app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh());
        $line = null;
        foreach ($detail['items'] as $row) {
            if ($row['publicId'] === $item->public_id) {
                $line = $row;
            }
        }
        $this->assertIsArray($line);
        $this->assertSame(2, $line['packsDispensed']);
        $this->assertSame('2 bottles (10 unit)', $line['packText']);
        $this->assertSame(['publicId' => $this->current($f['sku'])['public_id'], 'version' => 1, 'packUnit' => 'bottle', 'basePerPack' => 5, 'baseUnit' => 'unit'], $line['packConversion']);
    }

    public function test_the_label_prints_the_bottles_and_an_ml_only_label_is_unchanged(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $plain = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '2.000'));
        $this->confirmAllergy($f, $case);
        $before = app(DrugLabelService::class)->preview($f['ca'], $case->refresh(), $plain->public_id)['items'][0]['lines'];

        // A checked conversion alone changes nothing on a line saved in the base unit.
        $this->checkedConversion($f);
        $after = app(DrugLabelService::class)->preview($f['ca'], $case->refresh(), $plain->public_id)['items'][0]['lines'];
        $this->assertSame($before, $after);
        $this->assertNotContains('Quantity:', array_map(fn (string $l): string => substr($l, 0, 9), $after));

        $packed = app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $plain->refresh(), [...$this->packPayload($f, $case, 1, '5.000'), 'item_lock_version' => $plain->lock_version]);
        $lines = app(DrugLabelService::class)->preview($f['ca'], $case->refresh(), $packed->public_id)['items'][0]['lines'];
        $this->assertSame(1, preg_match('/QTY: 1 BTL$/', $lines[array_key_first(array_filter($lines, fn (string $l): bool => str_contains($l, 'QTY:')))]));
        $this->assertContains('Quantity:   1 BOTTLE (5 UNIT)', $lines);
    }

    public function test_the_label_helpers_pluralise_and_shorten_bottles(): void
    {
        $this->assertSame('2 BTL', DrugLabelLayout::packShortQuantity(2, 'bottle'));
        $this->assertSame('3 BOX', DrugLabelLayout::packShortQuantity(3, 'box'));
        $this->assertSame('1 bottle (60 ml)', DrugLabelLayout::packQuantity(1, 'bottle', '60.000', 'ml'));
        $this->assertSame('2 bottles (120 ml)', DrugLabelLayout::packQuantity(2, 'bottle', '120.000', 'ml'));
        $this->assertSame('2 boxes', DrugLabelLayout::packCount(2, 'box'));
        // A long pack unit goes over the QTY budget and blocks the label, never cut.
        $render = (new DrugLabelLayout)->render(['clinic' => 'C', 'patient' => 'P', 'name' => 'N', 'dosage' => '1', 'frequency' => '1', 'quantity' => DrugLabelLayout::packShortQuantity(2, 'sachet')], '01/01/2027', null, false);
        $this->assertContains('Quantity: 8 characters; label limit 6.', $render['errors']);
    }

    public function test_packs_that_do_not_match_the_ml_or_a_changed_bottle_size_are_refused(): void
    {
        $f = $this->fixture();
        $this->checkedConversion($f);
        $case = $this->open($f);
        $service = app(DispensaryService::class);

        $this->refusedWith(fn () => $service->addItem($f['ca'], $case->refresh(), $this->packPayload($f, $case, 1, '10.000')), 'packs', 'The bottles do not match the quantity. Reload the page.');
        $this->refusedWith(fn () => $service->addItem($f['ca'], $case->refresh(), $this->packPayload($f, $case, 2, '10.000', ['pack_conversion_version' => 2])), 'packs', 'The bottle size for this medicine changed. Reload the page.');
        $this->refusedWith(fn () => $service->addItem($f['ca'], $case->refresh(), $this->packPayload($f, $case, 2, '10.000', ['pack_conversion_public_id' => (string) Str::uuid()])), 'packs', 'The bottle size for this medicine changed. Reload the page.');
        $this->assertSame(0, DispensaryItem::query()->whereIn('dispensary_handoff_id', $case->handoffs()->pluck('id'))->count(), 'a refused save keeps nothing');

        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->post(route('dispensary.items.add', $case->refresh()), $this->packPayload($f, $case, 100, '500.000'))->assertSessionHasErrors('packs');
    }

    public function test_a_line_saved_in_bottles_needs_a_new_save_after_the_bottle_size_changes(): void
    {
        $f = $this->fixture();
        $this->checkedConversion($f);
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->packPayload($f, $case, 2, '10.000'));
        $this->confirmAllergy($f, $case);
        $this->checkedConversion($f, 10);

        $this->refusedWith(fn () => $this->complete($f, $case), 'allocations', 'The bottle size for this medicine changed. Save the line again.');

        // Saving the line again in the base unit clears the packs, and Complete goes through.
        app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), [...$this->addPayload($f, $case, '10.000'), 'item_lock_version' => $item->lock_version]);
        $this->assertNull($item->refresh()->packs_dispensed);
        $this->assertNull($item->pack_conversion_id);
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $this->complete($f, $case)->status);
    }

    public function test_postgresql_refuses_packs_that_do_not_match_and_a_pick_from_another_sku(): void
    {
        $f = $this->fixture();
        $this->checkedConversion($f);
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->packPayload($f, $case, 2, '10.000'));

        $refused = function (callable $write): void {
            try {
                // The test runs inside one outer transaction, so the deferred check is made to fire now.
                DB::transaction(function () use ($write): void {
                    $write();
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('PostgreSQL accepted the write.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $refused(fn () => DB::table('dispensary_items')->where('id', $item->id)->update(['packs_dispensed' => 1]));
        $refused(fn () => DB::table('dispensary_items')->where('id', $item->id)->update(['packs_dispensed' => null]));
        $refused(fn () => DB::table('dispensary_items')->where('id', $item->id)->update(['packs_dispensed' => 100, 'quantity_dispensed' => '500.000']));

        // An allocation from another SKU is refused when the transaction commits (deferred trigger).
        $otherItem = new InventoryItem;
        $otherItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'code' => 'SYN-OTHER-'.Str::upper(Str::random(5)), 'generic_name' => 'Synthetic other item', 'is_active' => true])->save();
        $other = new InventorySku;
        $other->forceFill([...$f['sku']->only(['organisation_id', 'pack_size', 'purchase_unit', 'stock_unit', 'dispensing_unit', 'unit_conversion', 'storage_type', 'cold_chain_required', 'do_not_freeze', 'protect_from_light', 'batch_tracking_required', 'expiry_tracking_required', 'is_active']), 'public_id' => (string) Str::uuid(), 'inventory_item_id' => $otherItem->id, 'sku_code' => 'SYN-OTHER-'.Str::upper(Str::random(5))])->save();
        $batch = new InventoryBatch;
        $batch->forceFill([...$f['batch']->only(['organisation_id', 'expiry_date', 'received_at', 'status']), 'public_id' => (string) Str::uuid(), 'inventory_sku_id' => $other->id, 'batch_number' => 'SYN-OTHER-B'])->save();
        $refused(fn () => DB::table('dispensary_item_batch_allocations')->where('dispensary_item_id', $item->id)->update(['inventory_sku_id' => $other->id, 'inventory_batch_id' => $batch->id]));

        $this->assertSame(2, $item->refresh()->packs_dispensed);
    }
}
