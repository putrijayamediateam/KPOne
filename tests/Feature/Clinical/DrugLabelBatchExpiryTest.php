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
 * DSP-2 (owner backlog 2026-11-05), DSP-10 (owner decisions 2026-11-06): the drug label's bottom row prints
 * "EXP: dd/mm/yyyy" only when exactly one batch with a real expiry was allocated to the line, and "EXP: -" otherwise,
 * with the reason on the preview screen; the batch number is no longer printed. Printing stays read only.
 * DSP-10 changed these assertions with the owner's approval (the old ones pinned the DSP-2 "No. Batch" and
 * "Tarikh luput" rows). Synthetic data only.
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
        $this->assertStringEndsWith('EXP: '.$expiry, (string) end($label['lines']), 'the expiry is on the bottom row');
        $this->assertSame([], array_values(array_filter($label['lines'], fn (string $line): bool => str_contains($line, $f['batch']->batch_number))), 'no batch number');
        $this->assertSame([], $preview['batchNotes']);
        $this->assertArrayNotHasKey('batchNote', $label, 'the label payload carries no batch note');
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

        $this->assertStringEndsWith('EXP: -', (string) end($label['lines']));
        $this->assertSame([['name' => 'Synthetic OTC medicine', 'previewUrl' => $label['previewUrl'], 'note' => 'Expiry printed as "EXP: -": more than one batch is allocated to this line.']], $preview['batchNotes']);
        $this->get(route('dispensary.labels', $case))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Labels')
            ->where('labelBatchNotes.0.note', 'Expiry printed as "EXP: -": more than one batch is allocated to this line.')
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

        $this->assertStringEndsWith('EXP: '.CarbonImmutable::parse($f['batch']->expiry_date)->format('d/m/Y'), (string) end($preview['labels']['items'][0]['lines']));
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

        $this->assertSame([], array_values(array_filter($preview['labels']['items'][0]['lines'], fn (string $line): bool => str_contains($line, '9999'))));
        $this->assertStringEndsWith('EXP: -', (string) end($preview['labels']['items'][0]['lines']));
        $this->assertSame('Expiry printed as "EXP: -": this batch has no real expiry date recorded.', $preview['batchNotes'][0]['note']);
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

        $this->assertStringEndsWith('EXP: '.CarbonImmutable::parse($f['batch']->expiry_date)->format('d/m/Y'), (string) end($label['lines']));
    }

    public function test_a_long_batch_number_no_longer_blocks_the_label_because_it_is_not_printed(): void
    {
        $f = $this->fixture();
        $f['batch']->forceFill(['batch_number' => str_repeat('B', 49)])->save();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);

        $preview = app(DrugLabelService::class)->preview($f['ca'], $case->refresh());

        $this->assertSame([], $preview['blocked']);
        $this->assertSame([], array_values(array_filter($preview['items'][0]['lines'], fn (string $line): bool => str_contains($line, 'BBBB'))));
    }

    public function test_the_layout_prints_the_expiry_only_when_given(): void
    {
        $layout = new DrugLabelLayout;
        $fields = ['clinic' => 'Synthetic Clinic', 'patient' => 'Synthetic Patient', 'name' => 'Synthetic medicine', 'quantity' => '2', 'dosage' => '1', 'frequency' => '1'];
        $with = $layout->render($fields, '01/01/2027', '31/12/2027', false);
        $without = $layout->render($fields, '01/01/2027', null, false);

        $this->assertSame('01/01/2027'.str_repeat(' ', 23).'EXP: 31/12/2027', end($with['lines']));
        $this->assertSame('01/01/2027'.str_repeat(' ', 32).'EXP: -', end($without['lines']));
        $this->assertSame(count($without['lines']), count($with['lines']), 'the expiry uses no extra row');
    }
}
