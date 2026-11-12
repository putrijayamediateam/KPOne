<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-31 (approved by B.E.R.N.I.S.S for the owner): stock other branches need, sent from the branch that holds it. */
final class BranchReplenishmentTest extends VisitTestCase
{
    private Branch $other;

    /** @var array<string, mixed> */
    private array $f;

    private InventoryLocation $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        $this->other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        // Our branch holds 20 of a SKU; the other branch has a dispensary with none of it.
        $this->f = (new GovernedStockFixture($this->branch))->build('20.000');
        $this->theirs = $this->location($this->other, 'Synthetic other branch dispensary');
    }

    private function location(Branch $branch, string $name, string $type = 'dispensary'): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
            'code' => 'RP-'.Str::upper(Str::random(8)), 'name' => $name, 'type' => $type, 'is_active' => true])->save();

        return $location;
    }

    private function level(InventoryLocation $location, string $level, ?string $max = null): void
    {
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $location->organisation_id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $this->f['sku']->id,
            'reorder_level' => $level, 'max_level' => $max, 'updated_by_user_id' => $this->f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);
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
        return $this->actingAs($actor)->get(route('inventory.branch-replenishment', $query))->assertOk()->viewData('page')['props']['suggestions'];
    }

    private function request(User $actor, InventoryLocation $source, InventoryLocation $destination, string $quantity): StockRequest
    {
        return app(StockRequestService::class)->create($actor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $source->public_id,
            'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $this->f['sku']->public_id, 'quantity' => $quantity]]]);
    }

    public function test_the_suggestion_is_the_smaller_of_their_need_and_what_we_can_spare(): void
    {
        // They: level 10, maximum 15, nothing on hand → need 15. We: 20 usable, own level 8 → 12 to spare.
        $this->level($this->theirs, '10.000', '15.000');
        $this->level($this->f['location'], '8.000');
        $supervisor = $this->staff('ca_supervisor');

        $rows = $this->page($supervisor)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(['15.000', '20.000', '8.000', '0.000', '12.000', $this->other->name, $this->f['location']->public_id, $this->theirs->public_id],
            [$rows[0]['to']['need'], $rows[0]['from']['usable'], $rows[0]['from']['target'], $rows[0]['from']['sending'], $rows[0]['suggestedQuantity'],
                $rows[0]['to']['branch'], $rows[0]['from']['locationPublicId'], $rows[0]['to']['locationPublicId']]);

        // A request waiting to leave our location comes off what we can spare. Their need counts it as incoming only once
        // it is approved (as STK-16 does), so until then their need stays 15.
        $ca = $this->staff('ca');
        $sent = $this->request($ca, $this->f['location'], $this->theirs, '3');
        $row = $this->page($supervisor)['rows'][0];
        $this->assertSame(['3.000', '15.000', '9.000'], [$row['from']['sending'], $row['to']['need'], $row['suggestedQuantity']]);
        app(StockRequestService::class)->approve($supervisor, $sent, ['expected_branch_id' => $this->branch->id, 'lock_version' => $sent->lock_version]);
        $row = $this->page($supervisor)['rows'][0];
        $this->assertSame(['3.000', '3.000', '12.000', '9.000'], [$row['from']['sending'], $row['to']['incoming'], $row['to']['need'], $row['suggestedQuantity']]);

        // The other branch's own page never suggests sending from them; our branch has no shortage.
        $this->assertSame([], $this->page($this->staff('ca_supervisor', $this->other))['rows']);

        // The search narrows the list, and the page writes nothing.
        $this->assertSame([], $this->page($supervisor, ['q' => 'NO-SUCH'])['rows']);
        $this->assertCount(1, $this->page($supervisor, ['q' => $this->other->name])['rows']);
        $this->assertSame(1, DB::table('inventory_stock_requests')->count());
    }

    public function test_nothing_is_suggested_without_a_shortage_or_a_surplus_and_returns_stock_is_never_used(): void
    {
        $supervisor = $this->staff('ca_supervisor');
        // No level at the other branch: no shortage.
        $this->assertSame([], $this->page($supervisor)['rows']);

        // A shortage, but our own maximum keeps all 20.
        $this->level($this->theirs, '10.000');
        $this->level($this->f['location'], '15.000', '20.000');
        $this->assertSame([], $this->page($supervisor)['rows']);

        // A patient returns location at the other branch is never a destination.
        $returns = $this->location($this->other, 'Synthetic returns', InventoryLocation::TYPE_DISPENSARY_RETURNS);
        DB::table('inventory_reorder_levels')->where('inventory_location_id', $this->f['location']->id)->update(['reorder_level' => '0.000', 'max_level' => null]);
        $this->level($returns, '50.000');
        $rows = collect($this->page($supervisor)['rows']);
        $this->assertSame([$this->theirs->public_id], $rows->pluck('to.locationPublicId')->all());

        // Someone without the stock request permission sees the page but cannot request.
        $this->assertFalse($this->page($this->staff('finance_officer'))['canRequest']);
        $this->assertTrue($this->page($supervisor)['canRequest']);
        $this->actingAs($this->staff('marketing'))->get(route('inventory.branch-replenishment'))->assertForbidden();
    }

    public function test_a_cross_branch_request_is_sent_by_the_holder_and_received_only_by_the_other_branch(): void
    {
        $service = app(StockRequestService::class);
        $ca = $this->staff('ca');
        $request = $this->request($ca, $this->f['location'], $this->theirs, '10');
        $audit = DB::table('audit_logs')->where('event', 'inventory.stock_request.requested')->latest('id')->first();
        $this->assertSame(['destination_branch_id' => $this->other->id, 'cross_branch' => true], array_intersect_key(json_decode((string) $audit->metadata, true), ['destination_branch_id' => 1, 'cross_branch' => 1]));

        // The sender's branch approves and dispatches, under the existing rules.
        $supervisor = $this->staff('ca_supervisor');
        $request = $service->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
        $line = $request->lines()->sole();
        $request = $service->dispatch($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version,
            'dispatch_idempotency_key' => (string) Str::uuid(), 'lines' => [['line_public_id' => $line->public_id, 'batch_public_id' => $this->f['batch']->public_id, 'quantity' => '10']]]);
        $receipt = fn (Branch $branch) => ['expected_branch_id' => $branch->id, 'lock_version' => $request->lock_version, 'receive_idempotency_key' => (string) Str::uuid(),
            'lines' => [['line_public_id' => $line->public_id, 'accepted_quantity' => '9', 'short_quantity' => '1', 'damaged_quantity' => '0', 'extra_quantity' => '0']]];

        // Nobody at the sending branch can receive it.
        foreach (['ca', 'ca_supervisor'] as $role) {
            try {
                $service->receive($this->staff($role), $request, $receipt($this->branch));
                $this->fail('A sender-branch '.$role.' received a cross-branch transfer.');
            } catch (NotFoundHttpException) {
                $this->assertSame(StockRequest::STATUS_DISPATCHED, $request->refresh()->status);
            }
        }

        // The other branch's receiver receives it, with an STK-6 shortage that someone else there resolves.
        $receiver = $this->staff('ca', $this->other);
        $request = $service->receive($receiver, $request, $receipt($this->other));
        $this->assertSame(StockRequest::STATUS_RECEIVED, $request->status);
        $this->assertSame('9.000', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $this->theirs->id)->sum('quantity'));
        $difference = DB::table('inventory_transfer_differences')->where('stock_request_id', $request->id)->sole();
        $this->assertSame('short', $difference->kind);
        $resolver = $this->staff('ca_supervisor', $this->other);
        $service->resolveDifference($resolver, $request, ['expected_branch_id' => $this->other->id, 'lock_version' => $request->refresh()->lock_version, 'retry_key' => (string) Str::uuid(),
            'difference_public_id' => $difference->public_id, 'outcome' => 'lost_in_transit', 'reference' => 'SYN-TRANSFER-LOG-1']);
        $this->assertSame(1, DB::table('inventory_transfer_difference_resolutions')->where('resolved_by_user_id', $resolver->id)->count());
    }

    public function test_the_source_must_be_at_the_requesting_branch_and_returns_locations_are_refused(): void
    {
        $ca = $this->staff('ca');
        // A source at another branch is not ours to send.
        $elsewhere = $this->location($this->other, 'Synthetic other branch store', InventoryLocation::TYPE_BRANCH_STORE);
        $this->expectRefusal(fn () => $this->request($ca, $elsewhere, $this->f['location'], '1'), NotFoundHttpException::class);

        // A patient returns location at the other branch is never a destination.
        $returns = $this->location($this->other, 'Synthetic returns', InventoryLocation::TYPE_DISPENSARY_RETURNS);
        $this->expectRefusal(fn () => $this->request($ca, $this->f['location'], $returns, '1'), ValidationException::class);
        $this->assertSame(0, DB::table('inventory_stock_requests')->count());

        // PostgreSQL refuses the same, whatever the caller.
        $row = fn (InventoryLocation $source, InventoryLocation $destination) => ['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id,
            'requesting_branch_id' => $this->branch->id, 'source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'request_number' => 'SR-SYN-'.Str::upper(Str::random(8)),
            'status' => 'requested', 'lock_version' => 1, 'requested_by_user_id' => $ca->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        $this->expectRefusal(fn () => DB::transaction(fn () => DB::table('inventory_stock_requests')->insert($row($elsewhere, $this->f['location']))), QueryException::class);
        $this->expectRefusal(fn () => DB::transaction(fn () => DB::table('inventory_stock_requests')->insert($row($this->f['location'], $returns))), QueryException::class);
        // The allowed cross-branch shape inserts.
        DB::table('inventory_stock_requests')->insert($row($this->f['location'], $this->theirs));
        $this->assertSame(1, DB::table('inventory_stock_requests')->count());
    }

    public function test_a_refused_request_sent_from_this_page_returns_to_it(): void
    {
        $ca = $this->staff('ca');
        $this->actingAs($ca)->post(route('inventory.stock-requests.store'), ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $this->f['location']->public_id,
            'destination_location_public_id' => $this->theirs->public_id, 'lines' => [['sku_public_id' => $this->f['sku']->public_id, 'quantity' => '0']], 'from' => 'branch-replenishment'])
            ->assertRedirect(route('inventory.branch-replenishment'))->assertSessionHasErrors('lines');
        $this->actingAs($ca)->post(route('inventory.stock-requests.store'), ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $this->f['location']->public_id,
            'destination_location_public_id' => $this->theirs->public_id, 'lines' => [['sku_public_id' => $this->f['sku']->public_id, 'quantity' => '2']], 'from' => 'branch-replenishment'])
            ->assertRedirect(route('inventory.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('inventory_stock_requests')->count());
    }

    /** @param class-string<\Throwable> $class */
    private function expectRefusal(callable $call, string $class): void
    {
        try {
            $call();
            $this->fail('Expected '.$class);
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($class, $exception, $exception->getMessage());
        }
    }
}
