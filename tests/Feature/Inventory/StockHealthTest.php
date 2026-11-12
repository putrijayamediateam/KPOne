<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-33 (approved by B.E.R.N.I.S.S for the owner, 2026-11-12): read-only stock health, quantities only. */
final class StockHealthTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
    }

    private function staff(string $role, ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $actor = (new GovernedStockFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function page(User $actor, array $query = []): array
    {
        return $this->actingAs($actor)->get(route('inventory.stock-health', $query))->assertOk()->viewData('page')['props']['health'];
    }

    /** @param array<string, mixed> $f */
    private function approvedCase(string $kind, array $data): int
    {
        $governed = app(GovernedStockService::class);
        $id = $governed->propose($this->staff('ca_supervisor'), $kind, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), ...$data]);
        $governed->act($this->staff('finance_officer'), $id, 'approved', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);

        return $id;
    }

    private function balance(InventoryLocation $location, int $batchId): \stdClass
    {
        return DB::table('inventory_stock_balances')->where('inventory_location_id', $location->id)->where('inventory_batch_id', $batchId)->sole();
    }

    public function test_days_on_hand_turnover_and_expiry_loss_follow_the_ledger(): void
    {
        $now = now();
        // 200 days ago: 100 of batch A, and 6 of batch B that expired 100 days ago.
        $this->travelTo($now->copy()->subDays(200));
        $f = (new GovernedStockFixture($this->branch))->build('100.000');
        // Batch A is still in date today (the fixture sets 90 days after it was built).
        DB::table('inventory_batches')->where('id', $f['batch']->id)->update(['expiry_date' => $now->copy()->setTimezone($this->branch->timezone)->addDays(365)->toDateString()]);
        $old = new InventoryBatch;
        $old->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-OLD-'.Str::upper(Str::random(6)),
            'expiry_date' => $now->copy()->setTimezone($this->branch->timezone)->subDays(100)->toDateString(), 'received_at' => now()->subDay(), 'status' => 'available'])->save();
        app(InventoryMovementService::class)->openingBalance($f['proposer'], ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id,
            'location_public_id' => $f['location']->public_id, 'batch_public_id' => $old->public_id, 'quantity' => '6.000']);
        $this->travelTo($now);

        // In the last 90 days: 9 used in a treatment room, the 6 expired written off, and 1 damaged written off and
        // then reversed (so it is left out of the losses and comes back into stock).
        app(TreatmentUsageService::class)->record($this->staff('ca'), ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'location_id' => $f['location']->id,
            'batch_id' => $f['batch']->id, 'unit' => 'unit', 'quantity' => '9.000', 'kind' => 'usage', 'reason' => 'wound_care']);
        $oldBalance = $this->balance($f['location'], $old->id);
        $this->approvedCase('writeoff', ['balance_id' => $oldBalance->id, 'balance_version' => $oldBalance->lock_version, 'unit' => 'unit', 'quantity' => '6.000', 'reason' => 'expired', 'disposal_reference' => 'SYN-DISPOSAL-1']);
        $balance = $this->balance($f['location'], $f['batch']->id);
        $damaged = $this->approvedCase('writeoff', ['balance_id' => $balance->id, 'balance_version' => $balance->lock_version, 'unit' => 'unit', 'quantity' => '1.000', 'reason' => 'damaged', 'disposal_reference' => 'SYN-DISPOSAL-2']);
        $balance = $this->balance($f['location'], $f['batch']->id);
        $this->approvedCase('reversal', ['balance_id' => $balance->id, 'balance_version' => $balance->lock_version, 'unit' => 'unit', 'reverses_case_id' => $damaged]);

        $health = $this->page($this->staff('ca'));
        $this->assertSame(1, $health['total']);
        $row = $health['rows'][0];
        // Opening 106 (100 + 6), closing 91: average 98.5. Used 9. Usable 91 at 9 per 90 days is 910 days.
        $this->assertSame([
            'onHand' => '91.000', 'usable' => '91.000', 'used' => '9.000', 'averageOnHand' => '98.500', 'daysOnHand' => 910, 'noUse' => false,
            'turnover' => '0.09', 'expired' => '6.000', 'expiryLossPercent' => '40.0',
        ], array_intersect_key($row, array_flip(['onHand', 'usable', 'used', 'averageOnHand', 'daysOnHand', 'noUse', 'turnover', 'expired', 'expiryLossPercent'])));
        $this->assertSame(['slow' => 1, 'noUse' => 0, 'expiryLoss' => 1], $health['summary']);
        $this->assertSame(90, $health['filters']['days']);
        // No cost ever leaves the server.
        $this->assertStringNotContainsString('cost', strtolower(json_encode($health, JSON_THROW_ON_ERROR)));
    }

    public function test_the_period_returns_locations_other_branches_and_unused_stock(): void
    {
        $now = now();
        $this->travelTo($now->copy()->subDays(60));
        $f = (new GovernedStockFixture($this->branch))->build('50.000');
        app(TreatmentUsageService::class)->record($this->staff('ca'), ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'location_id' => $f['location']->id,
            'batch_id' => $f['batch']->id, 'unit' => 'unit', 'quantity' => '5.000', 'kind' => 'usage', 'reason' => 'wound_care']);
        $this->travelTo($now);
        // Stock in a patient returns location and at another branch never shows here.
        $returns = new InventoryLocation;
        $returns->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id,
            'code' => 'SH-RET-'.Str::upper(Str::random(6)), 'name' => 'Synthetic returns', 'type' => InventoryLocation::TYPE_DISPENSARY_RETURNS, 'is_active' => true])->save();
        DB::table('inventory_stock_balances')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $returns->id, 'inventory_sku_id' => $f['sku']->id,
            'inventory_batch_id' => $f['batch']->id, 'quantity' => '3.000', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        (new GovernedStockFixture($other))->build('7.000');

        $ca = $this->staff('ca');
        // 90 days: the use 60 days ago counts.
        $row = $this->page($ca)['rows'];
        $this->assertCount(1, $row);
        $this->assertSame(['45.000', '5.000', 810, false], [$row[0]['onHand'], $row[0]['used'], $row[0]['daysOnHand'], $row[0]['noUse']]);
        // 30 days: nothing was used, so the stock shows as unused, first, with no days on hand.
        $health = $this->page($ca, ['days' => 30]);
        $this->assertSame([null, true, '0.000', null], [$health['rows'][0]['daysOnHand'], $health['rows'][0]['noUse'], $health['rows'][0]['used'], $health['rows'][0]['expiryLossPercent']]);
        $this->assertSame(['slow' => 0, 'noUse' => 1, 'expiryLoss' => 0], $health['summary']);
        // A period outside the list is refused; search narrows the list.
        $this->actingAs($ca)->get(route('inventory.stock-health', ['days' => 45]))->assertSessionHasErrors('days');
        $this->assertSame(0, $this->page($ca, ['q' => 'NOTHING-MATCHES'])['total']);
    }

    public function test_only_inventory_viewers_at_the_active_branch_open_it(): void
    {
        $this->actingAs($this->staff('resident_doctor'))->get(route('inventory.stock-health'))->assertForbidden();
        $this->assertSame(0, $this->page($this->staff('finance_officer'))['total']);
        $this->actingAs($this->staff('ca'))->get(route('inventory.stock-health'))->assertHeader('Cache-Control')
            ->assertInertia(fn ($page) => $page->component('Inventory/StockHealth'));
    }
}
