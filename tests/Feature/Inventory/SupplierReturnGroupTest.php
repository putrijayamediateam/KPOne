<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\SupplierReturnService as Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;
use Tests\Support\VerifiedReceivingFixture;

/** STK-17: several lots from one delivery returned together (approved by B.E.R.N.I.S.S for the owner, 2026-11-11). */
final class SupplierReturnGroupTest extends VisitTestCase
{
    /** @return array<string, mixed> One verified delivery received as three accepted lots (4 + 3 + 3). */
    private function delivery(): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $lots = [];
        foreach (['4.000', '3.000', '3.000'] as $quantity) {
            $lots[] = ['quantity' => $quantity, 'batch_number' => 'SYN-MULTI-'.Str::upper(Str::random(6)), 'expiry_date' => '2028-12-31', 'outcome' => 'accepted'];
        }
        $payload = [...$f['payload'], 'lines' => [['line_public_id' => $f['order']->lines()->sole()->public_id, 'physical_quantity' => '10.000', 'lots' => $lots]]];
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $payload);
        $lotRows = DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')
            ->where('line.goods_receipt_id', $receipt->id)->orderBy('lot.id')->get(['lot.*']);

        return [...$f, 'receipt' => $receipt, 'lots' => $lotRows];
    }

    /** @return array<string, mixed> */
    private function request(array $f, array $quantities, array $overrides = []): array
    {
        $lines = [];
        foreach ($quantities as $i => $quantity) {
            $lines[] = ['receipt_lot_id' => $f['lots'][$i]->id, 'unit' => $f['sku']->stock_unit, 'quantity' => $quantity, 'reason' => $i === 0 ? 'damaged' : 'short_expiry'];
        }

        return ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'goods_receipt_id' => $f['receipt']->id, 'supplier_reference' => 'SYN-RMA-9', 'lines' => $lines, ...$overrides];
    }

    private function cmd(int $groupId, array $overrides = []): array
    {
        $version = (int) DB::table('inventory_supplier_return_actions as a')->join('inventory_supplier_returns as r', 'r.id', '=', 'a.return_id')->where('r.group_id', $groupId)->max('a.lock_version');

        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), ...$overrides];
    }

    /** @return list<string> */
    private function states(int $groupId): array
    {
        return DB::table('inventory_supplier_returns as r')->where('r.group_id', $groupId)->orderBy('r.id')
            ->pluck('r.id')->map(fn ($id): string => (string) DB::table('inventory_supplier_return_actions')->where('return_id', $id)->orderByDesc('lock_version')->value('state'))->all();
    }

    private function balance(array $f, int $lotIndex): string
    {
        return (string) (DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['lots'][$lotIndex]->inventory_batch_id)->value('quantity') ?? '0.000');
    }

    private function as(mixed $actor): mixed
    {
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    public function test_one_group_returns_several_lots_and_closes_with_one_credit_note(): void
    {
        $f = $this->delivery();
        $service = app(Service::class);
        $input = $this->request($f, ['2.000', '3.000', '1.500']);
        $groupId = $service->requestGroup($this->as($f['receiver']), $input);
        // A retry with the same key returns the same group; the same key with other lines is refused.
        $this->assertSame($groupId, $service->requestGroup($f['receiver'], $input));
        $this->assertThrows(fn () => $service->requestGroup($f['receiver'], [...$input, 'lines' => array_slice($input['lines'], 0, 2)]), ValidationException::class);
        $this->assertSame(['awaiting_approval', 'awaiting_approval', 'awaiting_approval'], $this->states($groupId));

        // A grouped line cannot be acted on by itself.
        $lineId = (int) DB::table('inventory_supplier_returns')->where('group_id', $groupId)->min('id');
        $this->assertThrows(fn () => $service->act($this->as($f['finance']), $lineId, 'approved', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]), ValidationException::class);

        // The requester never approves their own return, even when also given the approval permission.
        $this->assertThrows(fn () => $service->actGroup($this->as($f['receiver']), $groupId, 'approved', $this->cmd($groupId)), AuthorizationException::class);
        $f['receiver']->givePermissionTo(Service::APPROVE);
        $this->assertThrows(fn () => $service->actGroup($this->as($f['receiver']->fresh()), $groupId, 'approved', $this->cmd($groupId)), ValidationException::class);
        $f['receiver']->revokePermissionTo(Service::APPROVE);
        $approve = $this->cmd($groupId);
        $service->actGroup($this->as($f['finance']), $groupId, 'approved', $approve);
        $this->assertSame($groupId, $service->actGroup($f['finance'], $groupId, 'approved', $approve), 'replay');
        $this->assertSame(['approved', 'approved', 'approved'], $this->states($groupId));
        $this->assertSame(['4.000', '3.000', '3.000'], [$this->balance($f, 0), $this->balance($f, 1), $this->balance($f, 2)]);

        $service->actGroup($this->as($f['receiver']), $groupId, 'dispatched', $this->cmd($groupId, ['reference' => 'SYN-COURIER-7']));
        $this->assertSame(['2.000', '0.000', '1.500'], [$this->balance($f, 0), $this->balance($f, 1), $this->balance($f, 2)]);
        $this->assertSame(3, DB::table('stock_movements')->where('movement_type', 'supplier_return')->count());

        $service->actGroup($this->as($f['director']), $groupId, 'credit_recorded', $this->cmd($groupId, ['reference' => 'SYN-CN-77', 'credit_amount_sen' => '1650']));
        $this->assertSame(['credited', 'credited', 'credited'], $this->states($groupId));
        $credits = DB::table('inventory_supplier_return_actions as a')->join('inventory_supplier_returns as r', 'r.id', '=', 'a.return_id')
            ->where('r.group_id', $groupId)->where('a.action', 'credit_recorded')->orderBy('a.return_id')->get(['a.reference', 'a.credit_amount_sen']);
        // One reference on every line; the total once, on the first line.
        $this->assertSame(['SYN-CN-77', 'SYN-CN-77', 'SYN-CN-77'], $credits->pluck('reference')->all());
        $this->assertSame(1650, (int) $credits->sum('credit_amount_sen'));
        $this->assertSame(1, $credits->whereNotNull('credit_amount_sen')->count());
    }

    public function test_the_group_dispatches_all_or_nothing(): void
    {
        $f = $this->delivery();
        $service = app(Service::class);
        $groupId = $service->requestGroup($this->as($f['receiver']), $this->request($f, ['2.000', '3.000']));
        $service->actGroup($this->as($f['finance']), $groupId, 'approved', $this->cmd($groupId));
        // The second lot's stock leaves by another path, so the group can no longer be dispatched in full.
        DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['lots'][1]->inventory_batch_id)->update(['quantity' => '1.000']);
        try {
            $service->actGroup($this->as($f['receiver']), $groupId, 'dispatched', $this->cmd($groupId, ['reference' => 'SYN-COURIER-8']));
            $this->fail('A dispatch beyond stock on hand must be refused.');
        } catch (ValidationException|QueryException) {
        }
        $this->assertSame(['approved', 'approved'], $this->states($groupId));
        $this->assertSame('4.000', $this->balance($f, 0), 'nothing moved for the first lot either');
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'supplier_return')->count());
    }

    public function test_request_checks_each_line_against_its_lot_and_the_delivery(): void
    {
        $f = $this->delivery();
        $service = app(Service::class);
        $this->as($f['receiver']);
        try {
            $service->requestGroup($f['receiver'], $this->request($f, ['2.000', '3.500']));
            $this->fail('A line beyond its lot must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.1.quantity', $e->errors());
        }
        // A lot from another delivery is refused.
        $other = (new SupplierReturnFixture($this->branch))->received('5.000');
        $input = $this->request($f, ['1.000']);
        $input['lines'][] = ['receipt_lot_id' => $other['lot']->id, 'unit' => $f['sku']->stock_unit, 'quantity' => '1.000', 'reason' => 'damaged'];
        try {
            $service->requestGroup($this->as($f['receiver']), $input);
            $this->fail('A lot from another delivery must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.1.receipt_lot_id', $e->errors());
        }
        // The same lot twice is refused, and nothing was saved.
        $twice = $this->request($f, ['1.000']);
        $twice['lines'][] = $twice['lines'][0];
        $this->assertThrows(fn () => $service->requestGroup($this->as($f['receiver']), $twice), ValidationException::class);
        $this->assertSame(0, DB::table('inventory_supplier_return_groups')->count());
        $this->assertSame(0, DB::table('inventory_supplier_returns')->count());
    }

    public function test_cancel_and_reject_move_the_whole_group_and_free_the_quantity(): void
    {
        $f = $this->delivery();
        $service = app(Service::class);
        $groupId = $service->requestGroup($this->as($f['receiver']), $this->request($f, ['4.000', '3.000']));
        $service->actGroup($this->as($f['finance']), $groupId, 'rejected', $this->cmd($groupId, ['reason_code' => 'not_agreed_by_supplier']));
        $this->assertSame(['rejected', 'rejected'], $this->states($groupId));
        // The rejected quantity is free to request again; only the requester cancels.
        $again = $service->requestGroup($this->as($f['receiver']), $this->request($f, ['4.000', '3.000']));
        $this->assertThrows(fn () => $service->actGroup($this->as($f['finance']), $again, 'cancelled', $this->cmd($again)), AuthorizationException::class);
        $service->actGroup($this->as($f['receiver']), $again, 'cancelled', $this->cmd($again));
        $this->assertSame(['cancelled', 'cancelled'], $this->states($again));
    }

    public function test_postgresql_allows_one_credit_reference_within_a_group_only(): void
    {
        $f = $this->delivery();
        $service = app(Service::class);
        // Single-lot return credited first with SYN-CN-1.
        $single = $service->request($this->as($f['receiver']), ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'receipt_lot_id' => $f['lots'][2]->id, 'unit' => $f['sku']->stock_unit, 'quantity' => '1.000', 'reason' => 'damaged']);
        $one = fn (string $action, array $extra = []) => ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) DB::table('inventory_supplier_return_actions')->where('return_id', $single)->max('lock_version'), 'retry_key' => (string) Str::uuid(), ...$extra];
        $service->act($this->as($f['finance']), $single, 'approved', $one('approved'));
        $service->act($this->as($f['receiver']), $single, 'dispatched', $one('dispatched', ['reference' => 'SYN-COURIER-1']));
        $service->act($this->as($f['finance']), $single, 'credit_recorded', $one('credit_recorded', ['reference' => 'SYN-CN-1']));

        $groupId = $service->requestGroup($this->as($f['receiver']), $this->request($f, ['1.000', '1.000']));
        $service->actGroup($this->as($f['finance']), $groupId, 'approved', $this->cmd($groupId));
        $service->actGroup($this->as($f['receiver']), $groupId, 'dispatched', $this->cmd($groupId, ['reference' => 'SYN-COURIER-2']));
        // The service refuses a reference used outside this group.
        $this->assertThrows(fn () => $service->actGroup($this->as($f['finance']), $groupId, 'credit_recorded', $this->cmd($groupId, ['reference' => 'syn-cn-1'])), ValidationException::class);
        $lines = DB::table('inventory_supplier_returns')->where('group_id', $groupId)->orderBy('id')->pluck('id')->all();
        $insert = fn (int $returnId, string $reference) => DB::table('inventory_supplier_return_actions')->insert([
            'organisation_id' => $this->branch->organisation_id, 'return_id' => $returnId, 'actor_user_id' => $f['finance']->id, 'action' => 'credit_recorded', 'state' => 'credited',
            'lock_version' => 4, 'reference' => $reference, 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()->utc(),
        ]);
        // PostgreSQL refuses it too, straight to the table.
        DB::beginTransaction();
        try {
            $insert($lines[0], 'SYN-CN-1');
            $this->fail('PostgreSQL must refuse a credit reference already used outside this group.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('already recorded', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        // Within one group, the same new reference is accepted on every line by PostgreSQL.
        $insert($lines[0], 'SYN-CN-2');
        $insert($lines[1], 'SYN-CN-2');
        $this->assertSame(2, DB::table('inventory_supplier_return_actions')->where('reference', 'SYN-CN-2')->count());
    }

    public function test_page_shows_groups_and_the_lots_of_a_chosen_delivery_and_http_routes_work(): void
    {
        $f = $this->delivery();
        $this->as($f['receiver']);
        $page = $this->actingAs($f['receiver'])->get(route('inventory.supplier-returns', ['delivery' => $f['receipt']->id]))->assertOk()->viewData('page')['props']['returns'];
        $this->assertContains($f['receipt']->id, array_column($page['deliveries'], 'id'));
        $this->assertCount(3, $page['deliveryLots']);

        $this->actingAs($f['receiver'])->post(route('inventory.supplier-return-groups.store'), $this->request($f, ['1.000', '1.000']))->assertRedirect(route('inventory.supplier-returns'));
        $groupId = (int) DB::table('inventory_supplier_return_groups')->value('id');
        $page = $this->actingAs($this->as($f['finance']))->get(route('inventory.supplier-returns'))->assertOk()->viewData('page')['props']['returns'];
        $group = $page['groups']['rows'][0];
        $this->assertSame([$groupId, 'awaiting_approval', true, 2], [$group['id'], $group['state'], $group['canDecide'], count($group['lines'])]);
        // Grouped lines do not repeat in the single-return list.
        $this->assertSame([], $page['returns']['rows']);
        $this->actingAs($f['finance'])->post(route('inventory.supplier-return-groups.act', [$groupId, 'approved']), $this->cmd($groupId))->assertRedirect(route('inventory.supplier-returns'));
        $this->assertSame(['approved', 'approved'], $this->states($groupId));
    }
}
