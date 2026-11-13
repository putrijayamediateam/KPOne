<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\SupplierReturnService as Service;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;

class SupplierReturnTest extends VisitTestCase
{
    private const REQUESTERS = ['ca_supervisor'];

    private const APPROVERS = ['director', 'finance_officer'];

    private function fx(): SupplierReturnFixture
    {
        return new SupplierReturnFixture($this->branch);
    }

    private function balance(array $f): string
    {
        return (string) (DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['returnBatchId'])->value('quantity') ?? '0.000');
    }

    private function cmd(int $id, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) DB::table('inventory_supplier_return_actions')->where('return_id', $id)->max('lock_version'), 'retry_key' => (string) Str::uuid(), ...$overrides];
    }

    private function state(int $id): string
    {
        return (string) DB::table('inventory_supplier_return_actions')->where('return_id', $id)->orderByDesc('lock_version')->value('state');
    }

    private function refused(callable $attempt, string $field): void
    {
        try {
            $attempt();
            $this->fail('Expected refusal on '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    /** Request, approve and dispatch, returning the return id. */
    private function dispatched(array $f, string $quantity = '4.000'): int
    {
        $service = app(Service::class);
        $id = $service->request($f['receiver'], $this->fx()->request($f, $quantity));
        app(BranchAccessService::class)->select($f['finance'], $this->branch);
        $service->act($f['finance'], $id, 'approved', $this->cmd($id));
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $service->act($f['receiver'], $id, 'dispatched', $this->cmd($id, ['reference' => 'SYN-COURIER-1']));

        return $id;
    }

    public function test_exact_catalogue_roles_for_request_and_independent_approval(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(self::REQUESTERS, $holders(Service::REQUEST));
        $this->assertSame(self::APPROVERS, $holders(Service::APPROVE));
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->assertSame(in_array($role, self::REQUESTERS, true), Role::findByName($role)->hasPermissionTo(Service::REQUEST), $role);
            $this->assertSame(in_array($role, self::APPROVERS, true), Role::findByName($role)->hasPermissionTo(Service::APPROVE), $role);
            $f = $this->fx()->received('5.000');
            $actor = $this->fx()->actor($role);
            $service = app(Service::class);
            try {
                $id = $service->request($actor, $this->fx()->request($f, '1.000'));
                $this->assertContains($role, self::REQUESTERS);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, self::REQUESTERS);
                app(BranchAccessService::class)->select($f['receiver'], $this->branch);
                $id = $service->request($f['receiver'], $this->fx()->request($f, '1.000'));
            }
            $approver = $this->fx()->actor($role);
            try {
                $service->act($approver, $id, 'approved', $this->cmd($id));
                $this->assertContains($role, self::APPROVERS);
                $this->assertSame('approved', $this->state($id));
            } catch (AuthorizationException) {
                $this->assertNotContains($role, self::APPROVERS);
                $this->assertSame('awaiting_approval', $this->state($id));
            }
        }
    }

    public function test_full_lifecycle_moves_stock_only_at_dispatch_and_records_credit_reference(): void
    {
        $f = $this->fx()->received('10.000');
        $service = app(Service::class);
        $this->assertSame('10.000', $this->balance($f));
        $id = $service->request($f['receiver'], $this->fx()->request($f, '4.250'));
        $return = DB::table('inventory_supplier_returns')->find($id);
        $this->assertSame(['4.250', $f['supplier']->id, $f['receipt']->id, 'damaged', 'SYN-RMA-1'], [$return->quantity, $return->supplier_id, $return->goods_receipt_id, $return->reason, $return->supplier_reference]);
        $this->assertSame('10.000', $this->balance($f));
        app(BranchAccessService::class)->select($f['finance'], $this->branch);
        $service->act($f['finance'], $id, 'approved', $this->cmd($id));
        $this->assertSame('10.000', $this->balance($f));
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $service->act($f['receiver'], $id, 'dispatched', $this->cmd($id, ['reference' => 'SYN-COURIER-1']));
        $this->assertSame('5.750', $this->balance($f));
        $movement = DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_SUPPLIER_RETURN)->sole();
        $this->assertSame(['4.250', $f['location']->id, null, 'inventory_supplier_return', $return->public_id, $f['receiver']->id], [$movement->quantity, $movement->source_location_id, $movement->destination_location_id, $movement->reference_type, $movement->reference_public_id, $movement->actor_user_id]);
        app(BranchAccessService::class)->select($f['director'], $this->branch);
        $service->act($f['director'], $id, 'credit_recorded', $this->cmd($id, ['reference' => 'SYN-CN-001', 'credit_amount_sen' => '531']));
        $this->assertSame('credited', $this->state($id));
        $this->assertSame('5.750', $this->balance($f));
        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'inventory_supplier_return')->count());
        $this->assertSame(['requested', 'approved', 'dispatched', 'credit_recorded'], DB::table('inventory_supplier_return_actions')->where('return_id', $id)->orderBy('lock_version')->pluck('action')->all());
        $this->assertSame(531, (int) DB::table('inventory_supplier_return_actions')->where('return_id', $id)->where('action', 'credit_recorded')->value('credit_amount_sen'));
        foreach (DB::table('audit_logs')->where('event', 'like', 'inventory.supplier_return.%')->get() as $row) {
            $keys = array_keys(json_decode($row->metadata, true));
            sort($keys);
            $this->assertSame(['action_id', 'lock_version', 'return_id', 'state'], $keys);
        }
        $this->assertSame(4, DB::table('audit_logs')->where('event', 'like', 'inventory.supplier_return.%')->count());
        $this->assertContains(StockMovement::TYPE_SUPPLIER_RETURN, InventoryDirectoryService::MOVEMENT_TYPES);
    }

    public function test_requester_can_never_approve_or_reject_their_own_return_in_domain_or_database(): void
    {
        $f = $this->fx()->received('5.000');
        $dual = $this->fx()->actor('ca_supervisor');
        $dual->assignRole('finance_officer');
        $service = app(Service::class);
        $id = $service->request($dual, $this->fx()->request($f, '2.000'));
        foreach (['approved', 'rejected'] as $action) {
            $this->refused(fn () => $service->act($dual, $id, $action, $this->cmd($id, $action === 'rejected' ? ['reason_code' => 'other'] : [])), 'state');
            $this->assertSame('awaiting_approval', $this->state($id));
        }
        try {
            DB::transaction(fn () => DB::table('inventory_supplier_return_actions')->insert(['organisation_id' => $this->organisation->id, 'return_id' => $id, 'actor_user_id' => $dual->id, 'action' => 'approved', 'state' => 'approved', 'lock_version' => 2, 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]));
            $this->fail('Database accepted self-approval');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        app(BranchAccessService::class)->select($f['finance'], $this->branch);
        $service->act($f['finance'], $id, 'approved', $this->cmd($id));
        $this->assertSame('approved', $this->state($id));
    }

    public function test_returnable_quantity_is_capped_by_lot_and_on_hand_and_released_by_reject_or_cancel(): void
    {
        $f = $this->fx()->received('10.000');
        $service = app(Service::class);
        $this->refused(fn () => $service->request($f['receiver'], $this->fx()->request($f, '10.001')), 'quantity');
        $first = $service->request($f['receiver'], $this->fx()->request($f, '6.000'));
        $this->refused(fn () => $service->request($f['receiver'], $this->fx()->request($f, '4.001')), 'quantity');
        $second = $service->request($f['receiver'], $this->fx()->request($f, '4.000'));
        $this->refused(fn () => $service->request($f['receiver'], $this->fx()->request($f, '0.001')), 'quantity');
        app(BranchAccessService::class)->select($f['finance'], $this->branch);
        $service->act($f['finance'], $first, 'rejected', $this->cmd($first, ['reason_code' => 'stock_still_usable']));
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $service->act($f['receiver'], $second, 'cancelled', $this->cmd($second));
        $this->assertSame(['rejected', 'cancelled'], [$this->state($first), $this->state($second)]);
        // Move 7 of the 10 elsewhere: the lot still allows 10, but only 3 are on hand here.
        $other = new InventoryLocation;
        $other->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'code' => 'SYN-'.Str::upper(Str::random(8)), 'name' => 'Synthetic store', 'type' => 'store', 'is_active' => true])->save();
        $batch = DB::table('inventory_batches')->find($f['returnBatchId']);
        app(InventoryMovementService::class)->transfer($f['receiver'], ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $f['location']->public_id, 'destination_location_public_id' => $other->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '7.000']);
        $this->refused(fn () => $service->request($f['receiver'], $this->fx()->request($f, '3.001')), 'quantity');
        $service->request($f['receiver'], $this->fx()->request($f, '3.000'));
        $this->refused(fn () => $service->request($f['receiver'], $this->fx()->request($f, '1.0001')), 'quantity');
        $this->refused(fn () => $service->request($f['receiver'], $this->fx()->request($f, '1.000', ['unit' => 'box'])), 'quantity');
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_SUPPLIER_RETURN)->count());
    }

    public function test_state_machine_versions_and_retry_keys(): void
    {
        $f = $this->fx()->received('10.000');
        $service = app(Service::class);
        $requestPayload = $this->fx()->request($f, '2.000');
        $id = $service->request($f['receiver'], $requestPayload);
        $this->assertSame($id, $service->request($f['receiver'], $requestPayload));
        $this->assertSame(1, DB::table('inventory_supplier_returns')->count());
        $this->refused(fn () => $service->request($f['receiver'], [...$requestPayload, 'quantity' => '3.000']), 'retry_key');
        $this->refused(fn () => $service->act($f['receiver'], $id, 'dispatched', $this->cmd($id, ['reference' => 'SYN-C'])), 'state');
        app(BranchAccessService::class)->select($f['finance'], $this->branch);
        $this->refused(fn () => $service->act($f['finance'], $id, 'credit_recorded', $this->cmd($id, ['reference' => 'SYN-CN'])), 'state');
        $this->refused(fn () => $service->act($f['finance'], $id, 'approved', [...$this->cmd($id), 'lock_version' => 9]), 'lock_version');
        $approve = $this->cmd($id);
        $service->act($f['finance'], $id, 'approved', $approve);
        $service->act($f['finance'], $id, 'approved', $approve);
        $this->assertSame(2, DB::table('inventory_supplier_return_actions')->where('return_id', $id)->count());
        $this->refused(fn () => $service->act($f['finance'], $id, 'rejected', [...$approve, 'reason_code' => 'other']), 'retry_key');
        $other = $this->fx()->actor('ca_supervisor');
        $this->refused(fn () => $service->act($other, $id, 'cancelled', $this->cmd($id)), 'state');
        $this->assertSame('approved', $this->state($id));
        $service->act($other, $id, 'dispatched', $this->cmd($id, ['reference' => 'SYN-COURIER']));
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $this->refused(fn () => $service->act($f['receiver'], $id, 'cancelled', $this->cmd($id)), 'state');
        $this->refused(fn () => $service->act($f['receiver'], $id, 'dispatched', $this->cmd($id, ['reference' => '   '])), 'reference');
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_SUPPLIER_RETURN)->count());
    }

    public function test_duplicate_credit_note_reference_for_the_same_supplier_is_refused(): void
    {
        $f = $this->fx()->received('10.000');
        $service = app(Service::class);
        $a = $this->dispatched($f, '2.000');
        $b = $this->dispatched($f, '3.000');
        app(BranchAccessService::class)->select($f['director'], $this->branch);
        $service->act($f['director'], $a, 'credit_recorded', $this->cmd($a, ['reference' => 'SYN-CN-77']));
        $this->refused(fn () => $service->act($f['director'], $b, 'credit_recorded', $this->cmd($b, ['reference' => ' syn-cn-77 '])), 'reference');
        $this->assertSame('dispatched', $this->state($b));
        $service->act($f['director'], $b, 'credit_recorded', $this->cmd($b, ['reference' => 'SYN-CN-78']));
        $this->assertSame('credited', $this->state($b));
    }

    public function test_fresh_revocation_ended_assignment_inactive_foreign_branch_and_organisation(): void
    {
        $f = $this->fx()->received('5.000');
        $service = app(Service::class);
        $id = $service->request($f['receiver'], $this->fx()->request($f, '1.000'));
        $refused = function (callable $attempt, string $label) use ($id): void {
            try {
                $attempt();
                $this->fail($label);
            } catch (AuthorizationException|HttpException) {
                $this->assertSame('awaiting_approval', $this->state($id), $label);
            }
        };
        $actor = $this->fx()->actor('finance_officer');
        $actor->syncRoles(['marketing']);
        $refused(fn () => $service->act($actor, $id, 'approved', $this->cmd($id)), 'Revoked permission');
        $actor->syncRoles(['finance_officer']);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_from' => now()->subDays(3)->toDateString(), 'valid_until' => now()->subDays(2)->toDateString()]);
        $refused(fn () => $service->act($actor, $id, 'approved', $this->cmd($id)), 'Ended assignment');
        $inactive = $this->fx()->actor('director');
        $inactive->forceFill(['is_active' => false])->save();
        $refused(fn () => $service->act($inactive, $id, 'approved', $this->cmd($id)), 'Inactive');
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $foreign = $this->fx()->actor('director', $otherBranch);
        $refused(fn () => $service->act($foreign, $id, 'approved', $this->cmd($id, ['expected_branch_id' => $otherBranch->id])), 'Foreign branch');
        $foreignRequester = $this->fx()->actor('ca_supervisor', $otherBranch);
        try {
            $service->request($foreignRequester, $this->fx()->request($f, '1.000', ['expected_branch_id' => $otherBranch->id]));
            $this->fail('Foreign-branch request');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $org = Organisation::query()->create(['code' => 'SYN-STK7', 'name' => 'Synthetic return organisation', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org->id, 'code' => 'SYN-STK7', 'name' => 'Synthetic return branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $outsider = (new SupplierReturnFixture($branch))->actor('director');
        $refused(fn () => $service->act($outsider, $id, 'approved', $this->cmd($id, ['expected_branch_id' => $branch->id])), 'Foreign organisation');
    }

    public function test_audit_failure_rolls_back_request_and_dispatch(): void
    {
        $f = $this->fx()->received('10.000');
        $balances = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull()));
        try {
            app(Service::class)->request($f['receiver'], $this->fx()->request($f, '2.000'));
            $this->fail('Unaudited request');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        app()->forgetInstance(AuditRecorder::class);
        $this->assertSame(0, DB::table('inventory_supplier_returns')->count());
        $service = app(Service::class);
        $id = $service->request($f['receiver'], $this->fx()->request($f, '2.000'));
        app(BranchAccessService::class)->select($f['finance'], $this->branch);
        $service->act($f['finance'], $id, 'approved', $this->cmd($id));
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull()));
        try {
            app(Service::class)->act($f['receiver'], $id, 'dispatched', $this->cmd($id, ['reference' => 'SYN-COURIER']));
            $this->fail('Unaudited dispatch');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        app()->forgetInstance(AuditRecorder::class);
        $this->assertSame($moves, DB::table('stock_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($balances, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
        $this->assertSame('approved', $this->state($id));
    }

    public function test_postgresql_evidence_is_append_only_and_return_movements_need_dispatch_evidence(): void
    {
        $f = $this->fx()->received('10.000');
        $id = $this->dispatched($f, '2.000');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (['inventory_supplier_returns', 'inventory_supplier_return_actions'] as $table) {
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Mutable evidence: '.$sql);
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0], $sql);
                }
            }
        }
        $return = DB::table('inventory_supplier_returns')->find($id);
        $row = (array) $return;
        unset($row['id']);
        try {
            DB::transaction(fn () => DB::table('inventory_supplier_returns')->insert([...$row, 'public_id' => (string) Str::uuid(), 'return_number' => 'SRN-SYN-OVER', 'quantity' => '8.001']));
            $this->fail('Database accepted a return above the lot');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $batch = DB::table('inventory_batches')->find($f['returnBatchId']);
        try {
            DB::transaction(function () use ($f, $batch, $return): void {
                DB::table('stock_movements')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $batch->id, 'source_location_id' => $f['location']->id, 'quantity' => '1.000', 'movement_type' => 'supplier_return', 'reference_type' => 'inventory_supplier_return', 'reference_public_id' => $return->public_id, 'actor_user_id' => $f['receiver']->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            });
            $this->fail('Unevidenced supplier return movement accepted');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'supplier_return')->count());
    }

    public function test_a_return_on_a_reversed_receipt_can_never_be_approved_or_dispatched(): void
    {
        $service = app(Service::class);
        $access = app(BranchAccessService::class);
        foreach (['awaiting_approval', 'approved'] as $stage) {
            $f = $this->fx()->received('10.000');
            $id = $service->request($f['receiver'], $this->fx()->request($f, '10.000'));
            if ($stage === 'approved') {
                $access->select($f['finance'], $this->branch);
                $service->act($f['finance'], $id, 'approved', $this->cmd($id));
            }
            $this->fx()->reverse($f);
            $this->assertSame('0.000', $this->balance($f));
            $moves = DB::table('stock_movements')->count();
            $access->select($f['director'], $this->branch);
            if ($stage === 'awaiting_approval') {
                $this->refused(fn () => $service->act($f['director'], $id, 'approved', $this->cmd($id)), 'state');
                $service->act($f['director'], $id, 'rejected', $this->cmd($id, ['reason_code' => 'other']));
                $this->assertSame('rejected', $this->state($id));
            } else {
                $access->select($f['receiver'], $this->branch);
                $this->refused(fn () => $service->act($f['receiver'], $id, 'dispatched', $this->cmd($id, ['reference' => 'SYN-COURIER'])), 'state');
                $service->act($f['receiver'], $id, 'cancelled', $this->cmd($id));
                $this->assertSame('cancelled', $this->state($id));
            }
            $this->assertSame($moves, DB::table('stock_movements')->count());
            $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'supplier_return')->count());
        }
        // PostgreSQL refuses the approval even without the domain service.
        $f = $this->fx()->received('4.000');
        $id = $service->request($f['receiver'], $this->fx()->request($f, '1.000'));
        $this->fx()->reverse($f);
        try {
            DB::transaction(fn () => DB::table('inventory_supplier_return_actions')->insert(['organisation_id' => $this->organisation->id, 'return_id' => $id, 'actor_user_id' => $f['finance']->id, 'action' => 'approved', 'state' => 'approved', 'lock_version' => 2, 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]));
            $this->fail('PostgreSQL accepted approval of a reversed receipt');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_an_inactive_sku_gives_a_clear_refusal_at_dispatch(): void
    {
        $service = app(Service::class);
        $f = $this->fx()->received('5.000');
        $id = $service->request($f['receiver'], $this->fx()->request($f, '2.000'));
        app(BranchAccessService::class)->select($f['finance'], $this->branch);
        $service->act($f['finance'], $id, 'approved', $this->cmd($id));
        DB::table('inventory_skus')->where('id', $f['sku']->id)->update(['is_active' => false]);
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $this->refused(fn () => $service->act($f['receiver'], $id, 'dispatched', $this->cmd($id, ['reference' => 'SYN-COURIER'])), 'state');
        $this->assertSame('5.000', $this->balance($f));
    }

    public function test_a_held_quarantined_lot_can_go_back_to_the_supplier_and_its_hold_stays(): void
    {
        $f = $this->fx()->quarantined('5.000');
        $hold = fn () => DB::table('inventory_batch_hold_states')->where('inventory_batch_id', $f['returnBatchId'])->where('branch_id', $this->branch->id)->value('active_action_id');
        $this->assertNotNull($hold());
        $this->assertSame('5.000', $this->balance($f));
        $id = $this->dispatched($f, '5.000');
        $this->assertSame('0.000', $this->balance($f));
        $this->assertSame('dispatched', $this->state($id));
        $this->assertNotNull($hold(), 'Returning stock never releases a safety hold; release stays a separate, independent action.');
    }

    public function test_page_http_routes_and_movement_history(): void
    {
        $f = $this->fx()->received('10.000');
        $id = $this->dispatched($f, '2.000');
        foreach (['ca' => 200, 'ca_supervisor' => 200, 'finance_officer' => 200, 'director' => 200, 'administrator' => 200, 'technical_admin' => 403, 'resident_doctor' => 403] as $role => $status) {
            $actor = $this->fx()->actor($role);
            $this->actingAs($actor)->get(route('inventory.supplier-returns'))->assertStatus($status);
        }
        $finance = $this->fx()->actor('finance_officer');
        $this->actingAs($finance)->get(route('inventory.supplier-returns'))->assertInertia(fn ($page) => $page->component('Inventory/SupplierReturns')
            ->where('returns.canRequest', false)->where('returns.canApprove', true)->where('returns.returns.rows.0.canCredit', true)->where('returns.lots.total', 0));
        $receiver = $f['receiver'];
        $this->actingAs($receiver)->get(route('inventory.supplier-returns'))->assertInertia(fn ($page) => $page->where('returns.canRequest', true)
            ->where('returns.lots.rows.0.returnable', '8.000')->where('returns.returns.rows.0.canCredit', false));
        $ca = $this->fx()->actor('ca');
        $this->actingAs($ca)->post(route('inventory.supplier-returns.store'), $this->fx()->request($f, '1.000'))->assertForbidden();
        $this->actingAs($finance)->post(route('inventory.supplier-returns.act', ['supplierReturn' => $id, 'action' => 'credit_recorded']), $this->cmd($id, ['reference' => '']))
            ->assertRedirect(route('inventory.supplier-returns'))->assertSessionHasErrors('reference');
        $this->actingAs($finance)->post(route('inventory.supplier-returns.act', ['supplierReturn' => $id, 'action' => 'credit_recorded']), $this->cmd($id, ['reference' => 'SYN-CN-HTTP']))
            ->assertRedirect(route('inventory.supplier-returns'));
        $this->assertSame('credited', $this->state($id));
        $this->actingAs($finance)->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => 'supplier_return']))
            ->assertInertia(fn ($page) => $page->where('inventory.total', 1)->where('inventory.data.0.typeLabel', 'Supplier Return')
                ->where('inventory.data.0.quantityDisplay', '-2')->where('inventory.data.0.reference', 'Supplier return'));
    }

    /** Follow-up to TIDY-4: the history shows its time in the branch's own time, never the stored UTC value. */
    public function test_history_times_are_shown_in_branch_time(): void
    {
        $f = $this->fx()->received('10.000');
        $this->dispatched($f, '2.000');
        $finance = $this->fx()->actor('finance_officer');
        $step = DB::table('inventory_supplier_return_actions')->orderBy('id')->first();
        $expected = CarbonImmutable::parse($step->created_at)->setTimezone($this->branch->timezone)->format('j M Y, g:i A');

        $this->actingAs($finance)->get(route('inventory.supplier-returns'))->assertInertia(fn ($page) => $page
            ->where('returns.returns.rows.0.history.0.at_local', $expected)
            ->where('returns.returns.rows.0.history.0.at_local', fn (string $value): bool => (bool) preg_match('/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} (AM|PM)$/', $value)));
    }

    public function test_unrelated_tables_are_untouched(): void
    {
        $f = $this->fx()->received('10.000');
        $owned = ['inventory_supplier_returns', 'inventory_supplier_return_actions', 'audit_logs', 'stock_movements', 'inventory_stock_balances'];
        $snapshot = function () use ($owned): array {
            $result = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
                if (in_array($table->tablename, $owned, true)) {
                    continue;
                }
                $q = DB::table($table->tablename);
                $result[$table->tablename] = Schema::hasColumn($table->tablename, 'id') ? $q->orderBy('id')->get()->toJson() : $q->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->toJson();
            }

            return $result;
        };
        $before = $snapshot();
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $max = DB::table('stock_movements')->max('id');
        $id = $this->dispatched($f, '3.000');
        app(BranchAccessService::class)->select($f['director'], $this->branch);
        app(Service::class)->act($f['director'], $id, 'credit_recorded', $this->cmd($id, ['reference' => 'SYN-CN-SNAP']));
        $this->assertSame($before, $snapshot());
        $this->assertSame($moves, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
        $this->assertSame(['3.000'], DB::table('stock_movements')->where('id', '>', $max)->pluck('quantity')->all());
    }
}
