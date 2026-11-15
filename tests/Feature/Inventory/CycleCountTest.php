<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\CycleCountService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;
use Tests\Support\VerifiedReceivingFixture;

/** STK-23 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): cycle counts, ABC classes and blind counts. */
final class CycleCountTest extends VisitTestCase
{
    private function actorAt(string $role): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function page(User $viewer, array $query = []): array
    {
        return $this->actingAs($viewer)->get(route('inventory.governed-counts', $query))->assertOk()->viewData('page')['props']['stock'];
    }

    private function command(int $version, ?string $quantity = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), ...($quantity !== null ? ['quantity' => $quantity, 'unit' => 'unit'] : [])];
    }

    public function test_a_counter_gets_no_snapshot_or_on_hand_while_a_count_waits(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $service = app(GovernedStockService::class);
        $id = $service->propose($f['proposer'], 'count', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(),
            'balance_id' => $f['balance']->id, 'balance_version' => $f['balance']->lock_version, 'unit' => 'unit']);
        $counter = $this->actorAt('ca_supervisor');
        $approver = $this->actorAt('finance_officer');

        foreach ([$counter, $approver] as $viewer) {
            $stock = $this->page($viewer);
            $row = collect($stock['cases']['rows'])->firstWhere('id', $id);
            // Left out of the payload itself, not hidden on screen.
            $this->assertTrue($row['blind']);
            foreach (['expected_quantity', 'last_quantity', 'quantity'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
            foreach ($row['history'] as $entry) {
                $this->assertObjectNotHasProperty('quantity', (object) $entry);
            }
            foreach ($stock['targets']['rows'] as $target) {
                $this->assertObjectNotHasProperty('quantity', (object) $target);
            }
        }

        // After the first count, the recounter still sees no number.
        $service->act($f['proposer'], $id, 'counted', $this->command(1, '8.000'));
        $row = collect($this->page($counter)['cases']['rows'])->firstWhere('id', $id);
        $this->assertSame('awaiting_recount', $row['state']);
        $this->assertArrayNotHasKey('expected_quantity', $row);
        foreach ($row['history'] as $entry) {
            $this->assertArrayNotHasKey('quantity', (array) $entry);
        }

        // Once both counts are in, the approver sees the snapshot and the counts.
        $service->act($counter, $id, 'recounted', $this->command(2, '8.000'));
        $row = collect($this->page($approver)['cases']['rows'])->firstWhere('id', $id);
        $this->assertSame(['awaiting_approval', false, '10.000'], [$row['state'], $row['blind'], $row['expected_quantity']]);

        // Write-off mode still shows on hand.
        $writeoff = $this->actingAs($counter)->get(route('inventory.governed-writeoffs'))->assertOk()->viewData('page')['props']['stock'];
        $this->assertArrayHasKey('quantity', (array) $writeoff['targets']['rows'][0]);
    }

    public function test_targets_and_cases_name_the_item_without_lifting_the_blind_count(): void
    {
        // V2 page 3 (Daniel): the sheets say "40 tablets of Paracetamol", so each row carries the item's name (read only).
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $id = app(GovernedStockService::class)->propose($f['proposer'], 'count', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(),
            'balance_id' => $f['balance']->id, 'balance_version' => $f['balance']->lock_version, 'unit' => 'unit']);
        $stock = $this->page($this->actorAt('ca_supervisor'));
        $row = collect($stock['cases']['rows'])->firstWhere('id', $id);

        $this->assertSame($f['item']->generic_name, $row['item']);
        $this->assertSame($f['item']->generic_name, ((array) collect($stock['targets']['rows'])->firstWhere('id', $f['balance']->id))['item']);
        // Still blind: the name adds no quantity.
        $this->assertTrue($row['blind']);
        foreach (['expected_quantity', 'last_quantity', 'quantity'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
        $this->assertArrayNotHasKey('quantity', (array) $stock['targets']['rows'][0]);
    }

    public function test_abc_ranking_by_dispensing_movements(): void
    {
        // 100 movements: SKU 1 (70) and SKU 2 (15) make up the first 80% (SKU 2 crosses it), SKU 3 (10) the next 15%.
        $this->assertSame([1 => 'A', 2 => 'A', 3 => 'B', 4 => 'C', 5 => 'C'], CycleCountService::rank([3 => 10, 1 => 70, 2 => 15, 4 => 4, 5 => 1]));
        // Ties are stable by SKU id.
        $this->assertSame([7 => 'A', 9 => 'A', 8 => 'B'], CycleCountService::rank([9 => 5, 7 => 5, 8 => 1]));
        $this->assertSame([], CycleCountService::rank([]));
    }

    public function test_a_controlled_medicine_is_always_class_a_and_never_counted_stock_is_due(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $viewer = $this->actorAt('ca_supervisor');
        $service = app(CycleCountService::class);
        $this->assertNotSame('A', $service->classes($viewer, $this->branch)[$f['sku']->id] ?? 'C');
        DB::table('medicine_catalogue_items')->where('id', $f['medicine']->id)->update(['is_controlled' => true]);
        $this->assertSame('A', $service->classes($viewer, $this->branch)[$f['sku']->id]);

        $row = collect($this->page($viewer)['cycle']['rows'])->firstWhere('skuPublicId', $f['sku']->public_id);
        $this->assertSame(['A', null, null, false], [$row['class'], $row['lastCounted'], $row['overdueDays'], $row['open']]);
        $this->assertSame(['A' => 30, 'B' => 60, 'C' => 90], $this->page($viewer)['cycle']['intervals']);
    }

    public function test_start_count_opens_one_case_per_batch_once_and_a_recent_count_is_not_due(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        // A second batch of the same SKU at the same location.
        $batch = $f['batch']->replicate();
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => $f['batch']->batch_number.'-2'])->save();
        app(InventoryMovementService::class)->openingBalance($f['proposer'], ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id,
            'location_public_id' => $f['location']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '5.000']);
        $counter = $this->actorAt('ca_supervisor');
        $input = ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'sku_public_id' => $f['sku']->public_id, 'location_public_id' => $f['location']->public_id];

        $this->actingAs($counter)->post(route('inventory.governed-counts.cycle'), $input)->assertRedirect(route('inventory.governed-counts'))->assertSessionHas('success', '2 batches are ready to count.');
        $this->assertSame(2, DB::table('inventory_governed_cases')->where('inventory_sku_id', $f['sku']->id)->where('kind', 'count')->count());
        // Pressing again, or retrying the same page, opens nothing more.
        $this->actingAs($counter)->post(route('inventory.governed-counts.cycle'), $input)->assertSessionHas('success', 'A count is already open for every batch here.');
        $this->actingAs($counter)->post(route('inventory.governed-counts.cycle'), [...$input, 'retry_key' => (string) Str::uuid()]);
        $this->assertSame(2, DB::table('inventory_governed_cases')->where('inventory_sku_id', $f['sku']->id)->count());
        $row = collect($this->page($counter)['cycle']['rows'])->firstWhere('skuPublicId', $f['sku']->public_id);
        $this->assertTrue($row['open']);

        // Count both to zero difference: the pair is counted today, so not due again (class C, 90 days).
        $service = app(GovernedStockService::class);
        foreach (DB::table('inventory_governed_cases')->where('inventory_sku_id', $f['sku']->id)->pluck('id') as $id) {
            $expected = (string) DB::table('inventory_governed_cases')->where('id', $id)->value('expected_quantity');
            $service->act($counter, (int) $id, 'counted', $this->command(1, $expected));
        }
        $this->assertNull(collect($this->page($counter)['cycle']['rows'])->firstWhere('skuPublicId', $f['sku']->public_id));

        // Someone without the count permission cannot start one.
        $this->actingAs($this->actorAt('finance_officer'))->post(route('inventory.governed-counts.cycle'), [...$input, 'retry_key' => (string) Str::uuid()])->assertForbidden();
    }

    private function countToZero(User $counter, int $balanceId): void
    {
        $service = app(GovernedStockService::class);
        $balance = DB::table('inventory_stock_balances')->where('id', $balanceId)->sole();
        $id = $service->propose($counter, 'count', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(),
            'balance_id' => $balanceId, 'balance_version' => $balance->lock_version, 'unit' => 'unit']);
        $service->act($counter, $id, 'counted', $this->command(1, (string) $balance->quantity));
    }

    public function test_due_dates_follow_the_class_interval_in_branch_local_days(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $counter = $this->actorAt('ca_supervisor');
        // Counted at 23:30 Kuala Lumpur time on 1 March (15:30 UTC): class C is due on 30 May, 90 local days later.
        $this->travelTo(CarbonImmutable::parse('2027-03-01 23:30', $this->branch->timezone));
        $this->countToZero($counter, (int) $f['balance']->id);
        $row = fn () => collect(app(CycleCountService::class)->due($counter, $this->branch, 1)['rows'])->firstWhere('skuPublicId', $f['sku']->public_id);
        $this->travelTo(CarbonImmutable::parse('2027-05-29 23:59', $this->branch->timezone));
        $this->assertNull($row());
        $this->travelTo(CarbonImmutable::parse('2027-05-30 00:01', $this->branch->timezone));
        $this->assertSame([0, '1 Mar 2027', '30 May 2027'], [$row()['overdueDays'], $row()['lastCounted'], $row()['dueOn']]);
        $this->travelTo(CarbonImmutable::parse('2027-06-02 09:00', $this->branch->timezone));
        $this->assertSame(3, $row()['overdueDays']);
    }

    public function test_one_recent_count_does_not_hide_another_batch_and_a_part_open_location_keeps_start_count(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $batch = $f['batch']->replicate();
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => $f['batch']->batch_number.'-2'])->save();
        app(InventoryMovementService::class)->openingBalance($f['proposer'], ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id,
            'location_public_id' => $f['location']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '5.000']);
        $counter = $this->actorAt('ca_supervisor');
        $this->countToZero($counter, (int) $f['balance']->id);
        $row = fn () => collect(app(CycleCountService::class)->due($counter, $this->branch, 1)['rows'])->firstWhere('skuPublicId', $f['sku']->public_id);
        // The second batch was never counted, so the location is still due, as never counted, and not open.
        $this->assertSame([null, null, false], [$row()['lastCounted'], $row()['overdueDays'], $row()['open']]);
    }

    public function test_inactive_stock_is_left_out_and_refusals_show_under_the_list(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $counter = $this->actorAt('ca_supervisor');
        $input = ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'sku_public_id' => $f['sku']->public_id, 'location_public_id' => $f['location']->public_id];
        DB::table('inventory_skus')->where('id', $f['sku']->id)->update(['is_active' => false]);
        $this->assertNull(collect(app(CycleCountService::class)->due($counter, $this->branch, 1)['rows'])->firstWhere('skuPublicId', $f['sku']->public_id));
        $this->actingAs($counter)->post(route('inventory.governed-counts.cycle'), $input)->assertRedirect(route('inventory.governed-counts'))->assertSessionHasErrors('cycle');
        // A location at another branch is never counted from here.
        DB::table('inventory_skus')->where('id', $f['sku']->id)->update(['is_active' => true]);
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        DB::table('inventory_locations')->where('id', $f['location']->id)->update(['branch_id' => $other->id]);
        $this->actingAs($counter)->post(route('inventory.governed-counts.cycle'), [...$input, 'retry_key' => (string) Str::uuid()])->assertSessionHasErrors('cycle');
        $this->assertSame(0, DB::table('inventory_governed_cases')->count());
    }
}
