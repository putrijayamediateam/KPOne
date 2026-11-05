<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-2 (owner backlog 2026-11-05): the drug label prints the batch number and expiry date only when
 * exactly one batch was allocated to the line; otherwise it prints nothing extra and the preview says
 * why. Printing stays read only. Synthetic data only.
 */
class DrugLabelBatchExpiryTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array<string, mixed> */
    private function stockSnapshot(): array
    {
        return ['movements' => DB::table('stock_movements')->orderBy('id')->get()->toJson(), 'balances' => DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson(), 'allocations' => DB::table('dispensary_item_batch_allocations')->orderBy('id')->get()->toJson()];
    }

    /** @param array<string, mixed> $f */
    private function secondBatch(array $f): InventoryBatch
    {
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-LATER-'.Str::upper(Str::random(4)), 'expiry_date' => now()->setTimezone($f['visit']->branch->timezone)->addMonths(6)->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $f['visit']->branch_id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000']);
        $this->selectBranch($f['ca'], $f['visit']->branch);

        return $batch;
    }

    public function test_one_allocated_batch_prints_its_number_and_expiry_without_touching_stock(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);
        $before = $this->stockSnapshot();

        $preview = app(DrugLabelService::class)->previewWithBatchNotes($f['ca'], $case->refresh());
        $label = $preview['labels']['items'][0];

        $expiry = CarbonImmutable::parse($f['batch']->expiry_date)->format('d/m/Y');
        $this->assertContains('No. Batch: '.$f['batch']->batch_number, $label['lines']);
        $this->assertContains('Tarikh luput: '.$expiry, $label['lines']);
        $this->assertSame([], $preview['batchNotes']);
        $this->assertArrayNotHasKey('batchNote', $label, 'the label payload shape is unchanged');
        $at = fn (string $prefix): int|false => array_search(true, array_map(fn (string $line): bool => str_starts_with($line, $prefix), $label['lines']), true);
        $this->assertTrue($at('Kuantiti: ') < $at('No. Batch: ') && $at('No. Batch: ') < $at('Tarikh luput: ') && $at('Tarikh luput: ') < $at('Dos: '), 'batch and expiry follow the quantity');
        $this->assertSame($before, $this->stockSnapshot(), 'printing changes no stock or allocation');
    }

    public function test_two_batches_print_nothing_extra_and_the_preview_says_why(): void
    {
        $f = $this->fixture();
        $later = $this->secondBatch($f);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allocations' => [
            ['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000'],
            ['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $later->public_id, 'quantity' => '1.000', 'fefo_exception_reason' => 'earlier_batch_insufficient'],
        ]]));
        $this->confirmAllergy($f, $case);

        $preview = app(DrugLabelService::class)->previewWithBatchNotes($f['ca'], $case->refresh());
        $label = $preview['labels']['items'][0];

        $this->assertSame([], array_values(array_filter($label['lines'], fn (string $line): bool => str_starts_with($line, 'No. Batch') || str_starts_with($line, 'Tarikh luput'))));
        $this->assertSame([['name' => 'Synthetic OTC medicine', 'previewUrl' => $label['previewUrl'], 'note' => 'Batch and expiry not printed: more than one batch is allocated to this line.']], $preview['batchNotes']);
        $this->get(route('dispensary.labels', $case))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Labels')
            ->where('labelBatchNotes.0.note', 'Batch and expiry not printed: more than one batch is allocated to this line.')
            ->missing('labels.items.0.batchNote'));
    }

    public function test_the_same_batch_from_two_locations_still_prints(): void
    {
        $f = $this->fixture();
        $store = new InventoryLocation;
        $store->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'branch_id' => $f['visit']->branch_id, 'code' => 'SYN-DISP2-'.Str::upper(Str::random(4)), 'name' => 'Synthetic second dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $f['visit']->branch_id, 'location_public_id' => $store->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '5.000']);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allocations' => [
            ['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000'],
            ['location_public_id' => $store->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000'],
        ]]));
        $this->confirmAllergy($f, $case);

        $preview = app(DrugLabelService::class)->previewWithBatchNotes($f['ca'], $case->refresh());

        $this->assertContains('No. Batch: '.$f['batch']->batch_number, $preview['labels']['items'][0]['lines']);
        $this->assertSame([], $preview['batchNotes']);
    }

    public function test_a_placeholder_expiry_is_never_printed_as_a_date(): void
    {
        $f = $this->fixture();
        DB::table('inventory_batches')->where('id', $f['batch']->id)->update(['expiry_date' => DrugLabelService::PLACEHOLDER_EXPIRY]);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);

        $preview = app(DrugLabelService::class)->previewWithBatchNotes($f['ca'], $case->refresh());

        $this->assertSame([], array_values(array_filter($preview['labels']['items'][0]['lines'], fn (string $line): bool => str_contains($line, '9999') || str_starts_with($line, 'No. Batch'))));
        $this->assertSame('Batch and expiry not printed: this batch has no real expiry date recorded.', $preview['batchNotes'][0]['note']);
    }

    public function test_a_completed_case_reprint_keeps_the_batch_and_expiry(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $case->refresh()->status);

        $label = app(DrugLabelService::class)->preview($f['ca'], $case)['items'][0];

        $this->assertContains('No. Batch: '.$f['batch']->batch_number, $label['lines']);
        $this->assertContains('Tarikh luput: '.CarbonImmutable::parse($f['batch']->expiry_date)->format('d/m/Y'), $label['lines']);
    }

    public function test_an_over_budget_batch_number_blocks_the_label_instead_of_truncating_it(): void
    {
        $f = $this->fixture();
        $f['batch']->forceFill(['batch_number' => str_repeat('B', DrugLabelLayout::FIELD_BUDGETS['batch'] + 1)])->save();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);

        $preview = app(DrugLabelService::class)->preview($f['ca'], $case->refresh());

        $this->assertSame([], $preview['items']);
        $this->assertContains('Batch: 49 characters; label limit 48.', $preview['blocked'][0]['errors']);
    }

    public function test_the_layout_prints_batch_and_expiry_only_when_given(): void
    {
        $layout = new DrugLabelLayout;
        $with = $layout->render(['quantity' => '2 unit', 'batch' => 'SYN-B1', 'expiry' => '31/12/2027', 'dosage' => '1', 'frequency' => '1'], '01/01/2027', false, false);
        $without = $layout->render(['quantity' => '2 unit', 'dosage' => '1', 'frequency' => '1'], '01/01/2027', false, false);

        $this->assertSame(['Kuantiti: 2 unit', 'No. Batch: SYN-B1', 'Tarikh luput: 31/12/2027', 'Dos: 1'], array_slice($with['lines'], 0, 4));
        $this->assertSame(count($without['lines']) + 2, count($with['lines']));
        $this->assertSame([], array_values(array_filter($without['lines'], fn (string $line): bool => str_contains($line, 'Batch') || str_contains($line, 'luput'))));
    }
}
