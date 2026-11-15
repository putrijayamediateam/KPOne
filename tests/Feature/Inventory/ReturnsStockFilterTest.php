<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ReturnsLocationFixture;
use Tests\Support\SupplierReturnFixture;

/** STK-12: the stock view lists patient-returns stock by itself, and never counts it as available. */
final class ReturnsStockFilterTest extends VisitTestCase
{
    public function test_patient_returns_only_lists_returns_stock_and_available_never_includes_it(): void
    {
        $fixture = new SupplierReturnFixture($this->branch);
        $f = $fixture->received('10.000');
        $supervisor = $fixture->actor('ca_supervisor');
        $returns = ReturnsLocationFixture::create($f['director'], $this->branch, InventoryLocation::TYPE_DISPENSARY_RETURNS);
        $sku = InventorySku::query()->findOrFail($f['sku']->id);
        $batch = InventoryBatch::query()->findOrFail($f['returnBatchId']);
        DB::transaction(fn () => app(InventoryMovementService::class)->recordDispensaryReturn($supervisor, $returns, $sku, $batch, '1.000', (string) Str::uuid()));

        $locations = fn (string $status, string $tab = 'stock') => $this->actingAs($supervisor)->get(route('inventory.index', ['tab' => $tab, 'status' => $status]))
            ->assertOk()->viewData('page')['props']['inventory']['data'];

        foreach (['stock', 'batches'] as $tab) {
            $only = collect($locations('returns', $tab));
            $this->assertNotEmpty($only, $tab);
            $this->assertTrue($only->every(fn ($row) => $row['location'] === $returns->name), $tab);
            $this->assertNotContains($returns->name, collect($locations('active', $tab))->pluck('location')->all(), $tab);
            $this->assertContains($returns->name, collect($locations('inactive', $tab))->pluck('location')->all(), $tab);
        }

        $this->actingAs($supervisor)->get(route('inventory.index', ['status' => 'everything']))->assertSessionHasErrors('status');
    }
}
