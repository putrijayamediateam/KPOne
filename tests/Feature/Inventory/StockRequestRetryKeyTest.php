<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/**
 * STK-39 (approved by B.E.R.N.I.S.S for the owner): a stock request sent twice with one retry key is made once. The same
 * key with other details, or from another person, is refused; a caller that sends no key behaves as before. Synthetic data.
 */
final class StockRequestRetryKeyTest extends VisitTestCase
{
    /** @var array<string, mixed> */
    private array $stock;

    private InventoryLocation $destination;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = (new GovernedStockFixture($this->branch))->build('20.000');
        $this->destination = new InventoryLocation;
        $this->destination->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'code' => 'DEST-'.Str::upper(Str::random(8)), 'name' => 'Synthetic receiving dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
        $this->requester = $this->staff('ca');
    }

    private function staff(string $role): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function payload(?string $key, string $quantity = '2.000'): array
    {
        return ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $this->stock['location']->public_id, 'destination_location_public_id' => $this->destination->public_id,
            'lines' => [['sku_public_id' => $this->stock['sku']->public_id, 'quantity' => $quantity]], ...($key === null ? [] : ['retry_key' => $key])];
    }

    private function requested(): int
    {
        return DB::table('audit_logs')->where('event', 'inventory.stock_request.requested')->count();
    }

    public function test_the_same_key_and_details_return_the_request_already_made(): void
    {
        $key = (string) Str::uuid();
        $first = app(StockRequestService::class)->create($this->requester, $this->payload($key));
        $again = app(StockRequestService::class)->create($this->requester, $this->payload(strtoupper($key)));

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, StockRequest::query()->count());
        $this->assertSame(1, DB::table('inventory_stock_request_lines')->count());
        $this->assertSame(1, $this->requested());
        $this->assertSame($key, StockRequest::query()->value('retry_key'));
        $this->assertSame(64, strlen((string) StockRequest::query()->value('payload_hash')));
        $this->assertCount(1, $again->lines);
    }

    public function test_the_same_key_with_other_details_or_from_another_person_is_refused(): void
    {
        $key = (string) Str::uuid();
        app(StockRequestService::class)->create($this->requester, $this->payload($key));

        foreach ([[$this->requester, $this->payload($key, '3.000')], [$this->staff('ca'), $this->payload($key)]] as [$who, $payload]) {
            try {
                app(StockRequestService::class)->create($who, $payload);
                $this->fail('A reused key must be refused.');
            } catch (ValidationException $exception) {
                $this->assertSame('This request key was already used for a different stock request. Reload the page and try again.', $exception->errors()['retry_key'][0]);
            }
        }
        $this->assertSame(1, StockRequest::query()->count());
        $this->assertSame(1, $this->requested());
    }

    public function test_no_key_behaves_as_before_and_a_malformed_key_writes_nothing(): void
    {
        app(StockRequestService::class)->create($this->requester, $this->payload(null));
        app(StockRequestService::class)->create($this->requester, $this->payload(null));
        $this->assertSame(2, StockRequest::query()->count());
        $this->assertSame(2, StockRequest::query()->whereNull('retry_key')->whereNull('payload_hash')->count());

        foreach (['not-a-key', 12345, ['x']] as $bad) {
            try {
                app(StockRequestService::class)->create($this->requester, [...$this->payload(null), 'retry_key' => $bad]);
                $this->fail('A malformed key must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('retry_key', $exception->errors());
            }
        }
        $this->assertSame(2, StockRequest::query()->count());
        $this->assertSame(2, $this->requested());
    }

    public function test_the_route_passes_the_key_so_a_double_press_makes_one_request(): void
    {
        $key = (string) Str::uuid();
        for ($press = 0; $press < 2; $press++) {
            $this->actingAs($this->requester)->withSession([BranchAccessService::SESSION_KEY => $this->branch->id])
                ->post('/inventory/stock-requests', $this->payload($key))->assertRedirect(route('inventory.index'))->assertSessionHasNoErrors();
        }
        $this->assertSame(1, StockRequest::query()->count());
        $this->assertSame($key, StockRequest::query()->value('retry_key'));
    }

    public function test_the_database_keeps_one_key_per_organisation_and_the_key_with_its_hash(): void
    {
        $key = (string) Str::uuid();
        $request = app(StockRequestService::class)->create($this->requester, $this->payload($key));
        $copy = collect((array) DB::table('inventory_stock_requests')->where('id', $request->id)->first())->except(['id'])->all();

        $this->assertDatabaseRefuses(fn () => DB::table('inventory_stock_requests')->insert([...$copy, 'public_id' => (string) Str::uuid(), 'request_number' => 'SR-COPY-'.Str::upper(Str::random(6))]));
        if (DB::getDriverName() === 'pgsql') {
            $this->assertDatabaseRefuses(fn () => DB::table('inventory_stock_requests')->where('id', $request->id)->update(['payload_hash' => null]));
        }
        $this->assertSame(1, StockRequest::query()->count());
    }

    private function assertDatabaseRefuses(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('The database must refuse this write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }
}
