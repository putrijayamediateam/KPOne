<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryRecallService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/**
 * STK-37 (approved by B.E.R.N.I.S.S for the owner, 2026-11-12): stock moves use the STK-2 usable-batch rule that
 * dispensing uses. A stock request dispatch always needs a usable batch. A direct transfer needs one too, except
 * between two returns locations and within one branch into a store that is neither a dispensary nor returns.
 */
final class TransferEligibilityTest extends VisitTestCase
{
    private const STATES = [
        'expired' => 'the batch expiry must be after the branch-local current date',
        'held' => 'the batch is held at this branch (damaged)',
        'recalled' => 'the batch is under a recall at every branch',
        'inactive_item' => 'the inventory item is inactive',
        'quarantined' => 'the batch status is not available',
    ];

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

    private function location(?Branch $branch, string $type): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $branch?->id,
            'code' => 'TE-'.Str::upper(Str::random(8)), 'name' => 'Synthetic '.$type, 'type' => $type, 'is_active' => true])->save();

        return $location;
    }

    /** @param array<string, mixed> $f */
    private function makeUnusable(array $f, string $state): void
    {
        match ($state) {
            'expired' => DB::table('inventory_batches')->where('id', $f['batch']->id)->update(['expiry_date' => now($this->branch->timezone)->toDateString()]),
            'held' => app(InventoryBatchHoldService::class)->act($this->staff('ca_supervisor'), $f['batch']->public_id, 'place',
                ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'damaged']),
            'recalled' => app(InventoryRecallService::class)->start($this->staff('director'), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
                'sku_public_id' => $f['sku']->public_id, 'batch_public_ids' => [$f['batch']->public_id], 'reason' => 'supplier_recall', 'reference' => 'SYN-RECALL-37']),
            'inactive_item' => DB::table('inventory_items')->where('id', $f['item']->id)->update(['is_active' => false]),
            'quarantined' => DB::table('inventory_batches')->where('id', $f['batch']->id)->update(['status' => 'quarantined']),
        };
    }

    private function transfer(User $actor, array $f, InventoryLocation $from, InventoryLocation $to, string $quantity = '2.000'): void
    {
        app(InventoryMovementService::class)->transfer($actor, ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id,
            'batch_public_id' => $f['batch']->public_id, 'source_location_public_id' => $from->public_id, 'destination_location_public_id' => $to->public_id, 'quantity' => $quantity]);
    }

    private function balanceAt(InventoryLocation $location, array $f): string
    {
        return (string) (DB::table('inventory_stock_balances')->where('inventory_location_id', $location->id)->where('inventory_batch_id', $f['batch']->id)->value('quantity') ?? '0.000');
    }

    /** @return array{int, int, int} transfer movements, transfer dispatch movements and transfer or dispatch audit rows */
    private function counts(): array
    {
        return [
            DB::table('stock_movements')->where('movement_type', 'transfer')->count(),
            DB::table('stock_movements')->where('movement_type', 'transfer_dispatch')->count(),
            DB::table('audit_logs')->whereIn('event', ['inventory.transfer', 'inventory.stock_request.dispatched'])->count(),
        ];
    }

    private function refused(callable $call, string $key, string $expected): void
    {
        try {
            $call();
            $this->fail('Expected a refusal: '.$expected);
        } catch (ValidationException $exception) {
            $this->assertSame($expected, $exception->errors()[$key][0] ?? null);
        }
    }

    public function test_unusable_stock_is_refused_into_a_dispensary_but_may_go_to_a_store_shelf_at_the_same_branch(): void
    {
        $storeTypes = [InventoryLocation::TYPE_BRANCH_STORE, InventoryLocation::TYPE_MEDICAL_STOCK];
        foreach (array_keys(self::STATES) as $i => $state) {
            $reason = self::STATES[$state];
            $f = (new GovernedStockFixture($this->branch))->build('10.000');
            $mover = $this->staff('ca_supervisor');
            $shelf = $this->location($this->branch, InventoryLocation::TYPE_DISPENSARY);
            // Any same-branch location that is neither a dispensary nor returns: a branch store or medical stock.
            $quarantine = $this->location($this->branch, $storeTypes[$i % 2]);
            $this->makeUnusable($f, $state);
            $before = $this->counts();

            $this->refused(fn () => $this->transfer($mover, $f, $f['location'], $shelf), 'location',
                "This batch can't be moved there: {$reason}. Unusable stock can only go to a store at this branch, or be written off.");
            $this->assertSame($before, $this->counts(), $state.': a refused move writes nothing');
            $this->assertSame(['10.000', '0.000'], [$this->balanceAt($f['location'], $f), $this->balanceAt($shelf, $f)], $state);

            // The quarantine shelf path STK-25 relies on: a store at the same branch.
            $this->transfer($mover, $f, $f['location'], $quarantine);
            $this->assertSame(['8.000', '2.000'], [$this->balanceAt($f['location'], $f), $this->balanceAt($quarantine, $f)], $state);
            // It cannot go back onto a dispensary shelf while it is still unusable.
            $this->refused(fn () => $this->transfer($mover, $f, $quarantine, $f['location'], '1.000'), 'location',
                "This batch can't be moved there: {$reason}. Unusable stock can only go to a store at this branch, or be written off.");
        }
    }

    public function test_usable_stock_still_moves_into_a_dispensary(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $shelf = $this->location($this->branch, InventoryLocation::TYPE_DISPENSARY);
        $this->transfer($this->staff('ca_supervisor'), $f, $f['location'], $shelf);
        $this->assertSame('2.000', $this->balanceAt($shelf, $f));
    }

    public function test_unusable_stock_never_moves_to_or_from_an_organisation_store(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $mover = $this->staff('ca_supervisor');
        $this->assertTrue($mover->can('inventory.transfer.organisation'));
        $store = $this->location(null, InventoryLocation::TYPE_BRANCH_STORE);
        $this->makeUnusable($f, 'held');

        // Out of the branch: the branch hold would no longer apply, so it is refused.
        $this->refused(fn () => $this->transfer($mover, $f, $f['location'], $store), 'location',
            "This batch can't be moved there: the batch is held at this branch (damaged). Unusable stock can only go to a store at this branch, or be written off.");

        DB::table('inventory_stock_balances')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $store->id, 'inventory_sku_id' => $f['sku']->id,
            'inventory_batch_id' => $f['batch']->id, 'quantity' => '3.000', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $branchStore = $this->location($this->branch, InventoryLocation::TYPE_BRANCH_STORE);
        // A branch hold belongs to the branch, not to the organisation store, so the held batch may come into it;
        // the hold applies again there.
        $this->transfer($mover, $f, $store, $branchStore, '1.000');
        $this->assertSame('1.000', $this->balanceAt($branchStore, $f));
        // Expired stock in the organisation store cannot come into a branch.
        $this->makeUnusable($f, 'expired');
        $this->refused(fn () => $this->transfer($mover, $f, $store, $branchStore, '1.000'), 'location',
            "This batch can't be moved there: the batch expiry must be after the branch-local current date. Unusable stock can only go to a store at this branch, or be written off.");
    }

    public function test_returned_medicine_still_moves_between_returns_locations_whatever_its_state(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $first = $this->location($this->branch, InventoryLocation::TYPE_DISPENSARY_RETURNS);
        $second = $this->location($this->branch, InventoryLocation::TYPE_DISPENSARY_RETURNS);
        DB::table('inventory_stock_balances')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $first->id, 'inventory_sku_id' => $f['sku']->id,
            'inventory_batch_id' => $f['batch']->id, 'quantity' => '4.000', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->makeUnusable($f, 'expired');
        $this->transfer($this->staff('ca_supervisor'), $f, $first, $second);
        $this->assertSame(['2.000', '2.000'], [$this->balanceAt($first, $f), $this->balanceAt($second, $f)]);
    }

    public function test_unusable_stock_meets_the_returns_rule_not_the_store_advice(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $mover = $this->staff('ca_supervisor');
        $returns = $this->location($this->branch, InventoryLocation::TYPE_DISPENSARY_RETURNS);
        DB::table('inventory_stock_balances')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $returns->id, 'inventory_sku_id' => $f['sku']->id,
            'inventory_batch_id' => $f['batch']->id, 'quantity' => '4.000', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->makeUnusable($f, 'expired');
        $message = 'Returned medicine can only move to another returns location, or be written off.';
        $this->refused(fn () => $this->transfer($mover, $f, $returns, $f['location']), 'location', $message);
        $this->refused(fn () => $this->transfer($mover, $f, $f['location'], $returns), 'location', $message);
    }

    public function test_a_stock_request_from_an_organisation_store_needs_usable_stock_but_no_branch_hold_applies(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $warehouse = $this->location(null, InventoryLocation::TYPE_BRANCH_STORE);
        DB::table('inventory_stock_balances')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $warehouse->id, 'inventory_sku_id' => $f['sku']->id,
            'inventory_batch_id' => $f['batch']->id, 'quantity' => '6.000', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $service = app(StockRequestService::class);
        $supervisor = $this->staff('ca_supervisor');
        $send = function () use ($service, $supervisor, $f, $warehouse): StockRequest {
            $request = $service->create($supervisor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $warehouse->public_id,
                'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '1']]]);
            $request = $service->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);

            return $service->dispatch($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version,
                'dispatch_idempotency_key' => (string) Str::uuid(), 'lines' => [['line_public_id' => $request->lines()->sole()->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1']]]);
        };

        // A hold at this branch belongs to the branch's stock, not to the organisation store's.
        $this->makeUnusable($f, 'held');
        $this->assertSame(StockRequest::STATUS_DISPATCHED, $send()->status);
        // Expiry uses the active branch's date.
        $this->makeUnusable($f, 'expired');
        $this->refused($send, 'lines', $f['sku']->sku_code.": this batch can't be sent: the batch expiry must be after the branch-local current date. Write it off, or move it to a store at this branch.");
    }

    public function test_a_stock_request_never_dispatches_unusable_stock(): void
    {
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        foreach (self::STATES as $state => $reason) {
            foreach (['same branch' => $this->branch, 'other branch' => $other] as $where => $destinationBranch) {
                $f = (new GovernedStockFixture($this->branch))->build('20.000');
                $destination = $this->location($destinationBranch, InventoryLocation::TYPE_DISPENSARY);
                $service = app(StockRequestService::class);
                $supervisor = $this->staff('ca_supervisor');
                // Requested and approved while usable; the batch becomes unusable before dispatch.
                $request = $service->create($this->staff('ca'), ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $f['location']->public_id,
                    'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '2']]]);
                $request = $service->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
                $this->makeUnusable($f, $state);
                $before = $this->counts();

                $this->refused(fn () => $service->dispatch($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version,
                    'dispatch_idempotency_key' => (string) Str::uuid(), 'lines' => [['line_public_id' => $request->lines()->sole()->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '2']]]),
                    'lines', $f['sku']->sku_code.": this batch can't be sent: {$reason}. Write it off, or move it to a store at this branch.");
                $this->assertSame(StockRequest::STATUS_APPROVED, $request->refresh()->status, $state.', '.$where);
                $this->assertSame($before, $this->counts(), $state.', '.$where.': a refused dispatch writes nothing');
                $this->assertSame('20.000', $this->balanceAt($f['location'], $f), $state.', '.$where);
            }
        }
    }
}
