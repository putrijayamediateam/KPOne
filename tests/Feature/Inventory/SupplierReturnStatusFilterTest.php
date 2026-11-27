<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\SupplierReturnService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;

final class SupplierReturnStatusFilterTest extends VisitTestCase
{
    private function createReturn(array $f, bool $group): int
    {
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $input = (new SupplierReturnFixture($this->branch))->request($f, '1.000');
        if (! $group) {
            return app(SupplierReturnService::class)->request($f['receiver'], $input);
        }

        return app(SupplierReturnService::class)->requestGroup($f['receiver'], [
            'expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'goods_receipt_id' => $f['receipt']->id,
            'lines' => [['receipt_lot_id' => $f['lot']->id, 'quantity' => '1.000', 'unit' => $f['sku']->stock_unit, 'reason' => 'damaged']],
        ]);
    }

    private function advance(array $f, bool $group, int $id, string $state): void
    {
        $actions = match ($state) {
            'approved' => ['approved'], 'dispatched' => ['approved', 'dispatched'], 'credited' => ['approved', 'dispatched', 'credit_recorded'],
            'rejected' => ['rejected'], 'cancelled' => ['cancelled'], default => [],
        };
        foreach ($actions as $action) {
            $actor = in_array($action, ['dispatched', 'cancelled'], true) ? $f['receiver'] : $f['finance'];
            app(BranchAccessService::class)->select($actor, $this->branch);
            $returnId = $group ? DB::table('inventory_supplier_returns')->where('group_id', $id)->min('id') : $id;
            $input = ['expected_branch_id' => $this->branch->id, 'lock_version' => DB::table('inventory_supplier_return_actions')->where('return_id', $returnId)->max('lock_version'), 'retry_key' => (string) Str::uuid(),
                ...($action === 'rejected' ? ['reason_code' => 'wrong_quantity'] : []),
                ...(in_array($action, ['dispatched', 'credit_recorded'], true) ? ['reference' => 'SYN-STATUS-'.($group ? 'GROUP-' : 'SINGLE-').$id] : [])];
            $method = $group ? 'actGroup' : 'act';
            app(SupplierReturnService::class)->{$method}($actor, $id, $action, $input);
        }
    }

    public function test_latest_state_buckets_apply_to_single_and_group_requests_without_read_mutations(): void
    {
        $f = (new SupplierReturnFixture($this->branch))->received('100.000');
        foreach ([false, true] as $group) {
            foreach (['awaiting_approval', 'approved', 'dispatched', 'credited', 'rejected', 'cancelled'] as $state) {
                $id = $this->createReturn($f, $group);
                $this->advance($f, $group, $id, $state);
            }
        }
        $service = app(SupplierReturnService::class);
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $before = [DB::table('stock_movements')->count(), DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson(), DB::table('inventory_supplier_return_actions')->count()];
        $all = $service->page($f['receiver'], []);
        $open = $service->page($f['receiver'], ['show' => 'open']);
        $done = $service->page($f['receiver'], ['show' => 'done']);
        $this->assertSame('all', $all['show']);
        foreach (['returns', 'groups'] as $key) {
            $this->assertSame(6, $all[$key]['total']);
            $this->assertSame(3, $open[$key]['total']);
            $this->assertSame(3, $done[$key]['total']);
            $this->assertEqualsCanonicalizing(['awaiting_approval', 'approved', 'dispatched'], array_column($open[$key]['rows'], 'state'));
            $this->assertEqualsCanonicalizing(['credited', 'rejected', 'cancelled'], array_column($done[$key]['rows'], 'state'));
            $this->assertCount(3, array_unique(array_column($done[$key]['rows'], 'id')));
        }
        $this->assertSame($all['lots'], $open['lots']);
        $this->assertSame($all['deliveries'], $done['deliveries']);
        $this->assertSame($before, [DB::table('stock_movements')->count(), DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson(), DB::table('inventory_supplier_return_actions')->count()]);
    }

    public function test_preserves_twenty_five_single_and_ten_group_pages_search_scope_and_bounded_queries(): void
    {
        $f = (new SupplierReturnFixture($this->branch))->received('100.000');
        $service = app(SupplierReturnService::class);
        $first = $this->createReturn($f, false);
        $this->createReturn($f, true);
        $queryCount = function () use ($service, $f): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $service->page($f['receiver'], ['show' => 'open']);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $queryCount();
        $small = $queryCount();
        for ($i = 1; $i < 26; $i++) {
            $this->createReturn($f, false);
        }
        for ($i = 1; $i < 11; $i++) {
            $this->createReturn($f, true);
        }
        $this->assertSame($small, $queryCount());
        $page = $service->page($f['receiver'], ['show' => 'open', 'page' => 2, 'groups_page' => 2]);
        $this->assertSame(26, $page['returns']['total']);
        $this->assertSame(11, $page['groups']['total']);
        $this->assertCount(1, $page['returns']['rows']);
        $this->assertCount(1, $page['groups']['rows']);
        $this->assertSame(2, $page['returns']['lastPage']);
        $this->assertSame(2, $page['groups']['lastPage']);
        $number = DB::table('inventory_supplier_returns')->where('id', $first)->value('return_number');
        $searched = $service->page($f['receiver'], ['show' => 'open', 'q' => $number, 'delivery' => $f['receipt']->id]);
        $this->assertSame(1, $searched['returns']['total']);
        $this->assertSame(11, $searched['groups']['total'], 'Existing q remains individual-only.');
        $this->assertSame($f['receipt']->id, $searched['delivery']);
        $this->assertNotEmpty($searched['deliveryLots']);
    }

    public function test_invalid_filters_fail_and_existing_fresh_branch_authority_stays_scoped(): void
    {
        $fx = new SupplierReturnFixture($this->branch);
        $f = $fx->received('10.000');
        $this->createReturn($f, false);
        $service = app(SupplierReturnService::class);
        foreach (['unknown', ['open'], 1] as $invalid) {
            $this->assertThrows(fn () => $service->page($f['receiver'], ['show' => $invalid]), ValidationException::class);
        }
        $this->assertSame('all', $service->page($f['receiver'], ['show' => ''])['show']);
        $this->actingAs($f['receiver'])->getJson('/inventory/supplier-returns?show[]=open')->assertUnprocessable()->assertJsonValidationErrors('show');
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $foreign = $fx->actor('ca_supervisor', $other);
        $this->assertSame(0, $service->page($foreign, ['show' => 'open'])['returns']['total']);
        $f['receiver']->forceFill(['is_active' => false])->save();
        $this->assertThrows(fn () => $service->page($f['receiver'], ['show' => 'open']), HttpException::class);
    }
}
