<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\HandCost;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\HandCostService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\ReceiptCorrectionService;
use App\Domain\Organisation\Inventory\Services\WeightedAverageCostService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;
use Tests\Support\HandCostFixture;

final class HandCostTest extends VisitTestCase
{
    private function opening(): StockMovement
    {
        $f = (new GovernedStockFixture($this->branch))->build();

        return StockMovement::query()->where('inventory_batch_id', $f['batch']->id)->where('movement_type', StockMovement::TYPE_OPENING)->sole();
    }

    /** @return array<string, mixed> */
    private function entry(StockMovement $movement, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'movement_public_id' => $movement->public_id,
            'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'cost_total_sen' => 1234,
            'reason_code' => 'supplier_invoice_found', ...$overrides];
    }

    /** @return array<string, mixed> */
    private function decision(array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => 'accepted', ...$overrides];
    }

    public function test_exact_permission_holders_and_forbidden_roles(): void
    {
        foreach ([HandCostService::ENTER => ['finance_officer', 'store_admin'], HandCostService::CHECK => ['director', 'finance_officer', 'store_admin']] as $permission => $holders) {
            $actual = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
            $this->assertSame($holders, $actual);
            foreach ($holders as $holder) {
                $this->assertTrue(Role::findByName($holder)->hasPermissionTo($permission));
            }
        }
        $movement = $this->opening();
        foreach (['ca', 'ca_supervisor', 'director', 'account_assistant', 'panel_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->assertThrows(fn () => app(HandCostService::class)->enter($actor, $this->entry($movement)), AuthorizationException::class);
        }
        $this->assertSame(0, HandCost::query()->count());
    }

    public function test_enter_check_retries_replacement_and_private_audit_preserve_stock(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $this->selectBranch($finance);
        $input = $this->entry($movement, ['reason_code' => 'other', 'note' => 'Synthetic supplier evidence', 'reference' => 'SYN-HAND-1']);
        $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $valuation = app(WeightedAverageCostService::class)->replay($this->organisation->id);
        $service = app(HandCostService::class);
        $cost = $service->enter($finance, $input);
        $this->assertSame('waiting', $cost->status);
        $this->assertSame($valuation, app(WeightedAverageCostService::class)->replay($this->organisation->id));
        $this->assertSame($cost->id, $service->enter($finance, $input)->id);
        $this->assertNotSame('Synthetic supplier evidence', DB::table('inventory_hand_costs')->value('note'));
        $this->assertSame('Synthetic supplier evidence', $cost->fresh()->note);
        $this->assertThrows(fn () => $service->enter($finance, [...$input, 'cost_total_sen' => 1235]), ValidationException::class);
        $this->assertThrows(fn () => $service->enter($finance, $this->entry($movement)), ValidationException::class);
        $this->assertThrows(fn () => $service->decide($finance, $cost->public_id, $this->decision()), AuthorizationException::class);
        $this->selectBranch($director);
        $this->assertThrows(fn () => $service->decide($director, $cost->public_id, $this->decision(['lock_version' => 2])), ValidationException::class);
        $decision = $this->decision();
        $accepted = $service->decide($director, $cost->public_id, $decision);
        $this->assertSame(['accepted', 2, $director->id], [$accepted->status, $accepted->lock_version, $accepted->decided_by_user_id]);
        $this->assertSame($accepted->id, $service->decide($director, $cost->public_id, $decision)->id);
        $otherChecker = $this->actor('store_admin');
        $this->selectBranch($otherChecker);
        $this->assertThrows(fn () => $service->decide($otherChecker, $cost->public_id, $decision), ValidationException::class);
        $this->selectBranch($director);
        $this->assertThrows(fn () => $service->decide($director, $cost->public_id, [...$decision, 'status' => 'rejected', 'reason' => 'Wrong total']), ValidationException::class);
        $this->selectBranch($finance);
        $replacement = $service->enter($finance, $this->entry($movement, ['cost_total_sen' => 0]));
        $this->assertSame(['accepted', 'waiting'], HandCost::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, $replacement->cost_total_sen);
        $this->assertSame($before, DB::table('stock_movements')->orderBy('id')->get()->toJson());
        $audits = DB::table('audit_logs')->where('event', 'like', 'inventory.hand_cost.%')->get();
        $this->assertCount(3, $audits);
        foreach ($audits as $audit) {
            $this->assertSame(['hand_cost_id', 'reason_code', 'status', 'stock_movement_id'], collect(json_decode($audit->metadata, true))->keys()->sort()->values()->all());
            $this->assertStringNotContainsString('Synthetic supplier evidence', $audit->metadata);
            $this->assertStringNotContainsString('SYN-HAND-1', $audit->metadata);
        }
    }

    public function test_rejection_allows_reentry_and_wrong_checker_is_refused(): void
    {
        $movement = $this->opening();
        $enterer = $this->actor('store_admin');
        $checker = $this->actor('finance_officer');
        $service = app(HandCostService::class);
        $this->selectBranch($enterer);
        $cost = $service->enter($enterer, $this->entry($movement));
        $this->selectBranch($checker);
        $this->assertThrows(fn () => $service->decide($checker, $cost->public_id, $this->decision(['status' => 'rejected', 'reason' => ' '])), ValidationException::class);
        $rejected = $service->decide($checker, $cost->public_id, $this->decision(['status' => 'rejected', 'reason' => 'Invoice total differs']));
        $this->assertSame('rejected', $rejected->status);
        $this->selectBranch($enterer);
        $this->assertSame('waiting', $service->enter($enterer, $this->entry($movement))->status);
    }

    public function test_fresh_permissions_active_branch_and_cost_visibility_are_required(): void
    {
        $movement = $this->opening();
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $this->assertThrows(fn () => app(HandCostService::class)->enter($actor, $this->entry($movement, ['expected_branch_id' => 0])), ValidationException::class);
        $actor->can(HandCostService::ENTER);
        Role::findByName('finance_officer')->revokePermissionTo(HandCostService::ENTER);
        $this->assertThrows(fn () => app(HandCostService::class)->enter($actor, $this->entry($movement)), AuthorizationException::class);
        Role::findByName('finance_officer')->givePermissionTo(HandCostService::ENTER);
        Role::findByName('finance_officer')->revokePermissionTo(HandCostService::VIEW);
        $this->assertThrows(fn () => app(HandCostService::class)->enter($actor, $this->entry($movement)), AuthorizationException::class);
    }

    public function test_actual_receipt_refused_and_reversal_blocks_entry_or_acceptance_but_not_rejection(): void
    {
        $fx = new HandCostFixture($this->branch);
        $actual = $fx->receipt(['cost_total_sen' => 250]);
        $this->selectBranch($actual['finance']);
        $service = app(HandCostService::class);
        $this->assertThrows(fn () => $service->enter($actual['finance'], $this->entry($actual['movement'])), ValidationException::class);
        $f = $fx->receipt();
        $this->selectBranch($f['finance']);
        $cost = $service->enter($f['finance'], $this->entry($f['movement']));
        $correction = $fx->correction($f);
        $this->selectBranch($f['receiver']);
        app(ReceiptCorrectionService::class)->reverse($f['receiver'], $correction['review'], $correction['input']);
        $this->selectBranch($f['director']);
        $this->assertThrows(fn () => $service->decide($f['director'], $cost->public_id, $this->decision()), ValidationException::class);
        $this->assertSame('rejected', $service->decide($f['director'], $cost->public_id, $this->decision(['status' => 'rejected', 'reason' => 'Receipt reversed']))->status);
        $this->selectBranch($f['finance']);
        $this->assertThrows(fn () => $service->enter($f['finance'], $this->entry($f['movement'])), ValidationException::class);
    }

    public function test_pre_stk10b_unit_cost_receipt_is_eligible(): void
    {
        $f = (new HandCostFixture($this->branch))->receipt();
        // Synthetic historical evidence via the unchanged ledger writer's legacy (null source) signature.
        $movement = app(InventoryMovementService::class)->recordPurchaseReceipt($f['receiver'], $f['location'], $f['sku'], $f['batch'], '1.000', $f['receipt']->public_id, 125);
        $this->assertNull($movement->cost_source);
        $this->selectBranch($f['finance']);
        $this->assertSame('waiting', app(HandCostService::class)->enter($f['finance'], $this->entry($movement))->status);
    }

    public function test_postgres_guards_history_scope_version_and_independent_decisions(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $this->selectBranch($finance);
        $cost = app(HandCostService::class)->enter($finance, $this->entry($movement));
        $reject = fn (callable $write) => $this->assertThrows(fn () => DB::transaction($write), QueryException::class);
        $row = DB::table('inventory_hand_costs')->where('id', $cost->id);
        foreach ([['cost_total_sen' => 1], ['branch_id' => Branch::where('id', '<>', $this->branch->id)->firstOrFail()->id], ['lock_version' => 3], ['status' => 'accepted']] as $change) {
            $reject(fn () => (clone $row)->update($change));
        }
        $reject(fn () => (clone $row)->update(['status' => 'accepted', 'lock_version' => 2, 'decided_by_user_id' => $finance->id, 'decided_at' => now(), 'decision_idempotency_key' => (string) Str::uuid(), 'decision_payload_hash' => str_repeat('a', 64)]));
        $reject(fn () => (clone $row)->delete());
        $reject(fn () => DB::statement('TRUNCATE inventory_hand_costs'));
        $clone = (array) $row->first();
        unset($clone['id']);
        $clone['public_id'] = (string) Str::uuid();
        $clone['idempotency_key'] = (string) Str::uuid();
        $reject(fn () => DB::table('inventory_hand_costs')->insert($clone));
        $this->selectBranch($director);
        app(HandCostService::class)->decide($director, $cost->public_id, $this->decision());
        $reject(fn () => (clone $row)->update(['status' => 'rejected', 'decision_reason' => 'Changed mind']));
        $this->assertSame('accepted', $cost->fresh()->status);
    }

    public function test_audit_failure_rolls_back_new_cost(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $input = $this->entry($movement);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnNull();
        $this->assertThrows(fn () => app(HandCostService::class)->enter($finance, $input), HttpException::class);
        $this->assertSame(0, HandCost::query()->count());
    }

    public function test_failed_decision_audit_keeps_the_cost_waiting(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $this->selectBranch($finance);
        $cost = app(HandCostService::class)->enter($finance, $this->entry($movement));
        $this->selectBranch($director);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnNull();
        $this->assertThrows(fn () => app(HandCostService::class)->decide($director, $cost->public_id, $this->decision()), HttpException::class);
        $this->assertSame(['waiting', 1, null], [$cost->fresh()->status, $cost->fresh()->lock_version, $cost->fresh()->decided_by_user_id]);
    }

    public function test_postgres_insert_rejects_ineligible_costs_scope_and_invalid_fields(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $cost = app(HandCostService::class)->enter($finance, $this->entry($movement));
        $base = (array) DB::table('inventory_hand_costs')->where('id', $cost->id)->sole();
        unset($base['id']);
        $available = $this->opening();
        $base = [...$base, 'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'stock_movement_id' => $available->id];
        $actual = (new HandCostFixture($this->branch))->receipt(['cost_total_sen' => 250]);
        $changes = [
            ['cost_total_sen' => -1], ['cost_total_sen' => 1000000000000], ['reason_code' => 'other', 'note' => null],
            ['payload_hash' => 'not-a-hash'], ['branch_id' => null], ['lock_version' => 2],
            ['stock_movement_id' => $actual['movement']->id],
            ['status' => 'accepted', 'lock_version' => 2, 'decided_by_user_id' => $actual['director']->id, 'decided_at' => now(),
                'decision_idempotency_key' => (string) Str::uuid(), 'decision_payload_hash' => str_repeat('a', 64)],
        ];
        foreach ($changes as $change) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('inventory_hand_costs')->insert([...$base, ...$change])), QueryException::class);
        }
        // The unchanged control row succeeds: the refusals above were not duplicate-key accidents.
        DB::table('inventory_hand_costs')->insert($base);
        $this->assertSame(2, HandCost::query()->count());
    }

    public function test_entry_retry_belongs_to_its_actor_and_notes_are_validated_before_writing(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $other = $this->actor('store_admin');
        $this->selectBranch($finance);
        $service = app(HandCostService::class);
        foreach ([['reason_code' => 'other', 'note' => '  '], ['note' => str_repeat('x', 201)], ['cost_total_sen' => '1.001']] as $override) {
            $this->assertThrows(fn () => $service->enter($finance, $this->entry($movement, $override)), ValidationException::class);
        }
        $input = $this->entry($movement);
        $cost = $service->enter($finance, $input);
        $this->selectBranch($other);
        $this->assertThrows(fn () => $service->enter($other, $input), ValidationException::class);
        $this->assertSame($finance->id, $cost->fresh()->entered_by_user_id);
        $this->assertSame(1, HandCost::query()->count());
    }

    public function test_approved_noop_downs_preserve_history_guards_and_permissions(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $cost = app(HandCostService::class)->enter($finance, $this->entry($movement));
        $schema = require database_path('migrations/2026_11_26_110000_create_inventory_hand_costs.php');
        $grants = require database_path('migrations/2026_11_26_110100_grant_inventory_hand_cost_permissions.php');
        $schema->down();
        $grants->down();
        $this->assertSame($cost->id, HandCost::sole()->id);
        $this->assertTrue(Role::findByName('finance_officer')->hasPermissionTo(HandCostService::ENTER));
        $this->assertTrue(Role::findByName('director')->hasPermissionTo(HandCostService::CHECK));
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('inventory_hand_costs')->where('id', $cost->id)->delete()), QueryException::class);
    }
}
