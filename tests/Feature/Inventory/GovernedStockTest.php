<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Services\GovernedStockService as Service;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

class GovernedStockTest extends VisitTestCase
{
    private function f(string $quantity = '10.000'): array
    {
        return (new GovernedStockFixture($this->branch))->build($quantity);
    }

    private function actorAt(string $role): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function proposal(array $f, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $f['balance']->id, 'balance_version' => $f['balance']->lock_version, 'unit' => 'unit', ...$overrides];
    }

    private function command(int $version, mixed $quantity = null, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), ...($quantity !== null ? ['quantity' => $quantity, 'unit' => 'unit'] : []), ...$overrides];
    }

    private function countCase(array $f, string $first = '8.000', string $second = '8.000'): int
    {
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        $id = app(Service::class)->propose($f['proposer'], 'count', $this->proposal($f));
        app(Service::class)->act($f['proposer'], $id, 'counted', $this->command(1, $first));
        $recounter = $this->actorAt('ca_supervisor');
        app(Service::class)->act($recounter, $id, 'recounted', $this->command(2, $second));

        return $id;
    }

    private function approve(int $id): void
    {
        $actor = $this->actorAt('finance_officer');
        $v = DB::table('inventory_governed_actions')->where('case_id', $id)->max('lock_version');
        app(Service::class)->act($actor, $id, 'approved', $this->command($v));
    }

    public function test_exact_catalogue_roles_for_view_count_recount_propose_and_approval(): void
    {
        $this->assertSame(['ca_supervisor', 'director', 'finance_officer'], collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(Service::APPROVE, $p, true))->keys()->sort()->values()->all());
        foreach (['ca', 'administrator', 'technical_admin', 'resident_doctor', 'panel_officer', 'queue_display', 'marketing', 'hr_manager', 'business_development'] as $deniedRole) {
            $this->assertNotContains(Service::APPROVE, PermissionCatalogue::roles()[$deniedRole]);
            $this->assertFalse(Role::findByName($deniedRole)->hasPermissionTo(Service::APPROVE));
        }
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $f = $this->f();
            $actor = $this->actorAt($role);
            $service = app(Service::class);
            $view = in_array($role, ['director', 'finance_officer', 'ca_supervisor', 'ca', 'administrator'], true);
            $this->actingAs($actor)->get(route('inventory.governed-counts'))->assertStatus($view ? 200 : 403);
            $this->get(route('inventory.governed-writeoffs'))->assertStatus($view ? 200 : 403);
            foreach (['count', 'writeoff'] as $kind) {
                $data = $this->proposal($f, $kind === 'writeoff' ? ['quantity' => '1.000', 'reason' => 'damaged', 'disposal_reference' => 'SYN-LOG-001'] : []);
                try {
                    $service->propose($actor, $kind, $data);
                    $this->assertSame('ca_supervisor', $role);
                } catch (AuthorizationException) {
                    $this->assertNotSame('ca_supervisor', $role);
                }
            }
            app(BranchAccessService::class)->select($f['proposer'], $this->branch);
            $id = $service->propose($f['proposer'], 'count', $this->proposal($f));
            app(BranchAccessService::class)->select($actor, $this->branch);
            try {
                $service->act($actor, $id, 'counted', $this->command(1, '8.000'));
                $this->assertSame('ca_supervisor', $role);
            } catch (AuthorizationException) {
                $this->assertNotSame('ca_supervisor', $role);
                $service->act($f['proposer'], $id, 'counted', $this->command(1, '8.000'));
            }
            $other = $this->actorAt($role);
            try {
                $service->act($other, $id, 'recounted', $this->command(2, '8.000'));
                $this->assertSame('ca_supervisor', $role);
            } catch (AuthorizationException) {
                $this->assertNotSame('ca_supervisor', $role);
                $counter = $this->actorAt('ca_supervisor');
                $service->act($counter, $id, 'recounted', $this->command(2, '8.000'));
            }
            $approver = $this->actorAt($role);
            try {
                $service->act($approver, $id, 'approved', $this->command(3));
                $this->assertContains($role, ['ca_supervisor', 'director', 'finance_officer']);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, ['ca_supervisor', 'director', 'finance_officer']);
            }
        }
    }

    public function test_ca_supervisor_approval_excludes_participants_in_payload_domain_database_and_retries(): void
    {
        $s = app(Service::class);
        $f = $this->f();
        $id = $s->propose($f['proposer'], 'count', $this->proposal($f));
        $counter = $this->actorAt('ca_supervisor');
        $recounter = $this->actorAt('ca_supervisor');
        $s->act($counter, $id, 'counted', $this->command(1, '8.000'));
        $s->act($recounter, $id, 'recounted', $this->command(2, '8.000'));
        $opening = DB::table('stock_movements')->where('inventory_batch_id', $f['batch']->id)->sole();
        $snapshot = fn () => collect(['inventory_governed_cases', 'inventory_governed_actions', 'inventory_stock_balances', 'stock_movements', 'audit_logs'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        foreach ([$f['proposer'], $counter, $recounter] as $actor) {
            app(BranchAccessService::class)->select($actor, $this->branch);
            $this->assertTrue($actor->fresh()->can(Service::APPROVE));
            $this->actingAs($actor)->get(route('inventory.governed-counts'))->assertInertia(fn (AssertableInertia $page) => $page->component('Inventory/GovernedStock')->where('stock.cases.rows.0.canApprove', false));
            $command = $this->command(3);
            $before = $snapshot();
            for ($retry = 0; $retry < 2; $retry++) {
                try {
                    $s->act($actor, $id, 'approved', $command);
                    $this->fail('CA Supervisor participant approved their own variance');
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode());
                }
                $this->assertSame($before, $snapshot());
            }
            try {
                DB::transaction(fn () => DB::table('inventory_governed_actions')->insert(['organisation_id' => $this->branch->organisation_id, 'case_id' => $id, 'actor_user_id' => $actor->id, 'action' => 'approved', 'state' => 'posted', 'quantity' => '2.000', 'movement_id' => $opening->id, 'lock_version' => 4, 'retry_key' => $command['retry_key'], 'payload_hash' => str_repeat('a', 64), 'created_at' => now()->utc()]));
                $this->fail('PostgreSQL accepted participant approval');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
                $this->assertStringContainsString('Independent approver required', $e->getMessage());
            }
            $this->assertSame($before, $snapshot());
        }
        $independent = $this->actorAt('ca_supervisor');
        $this->actingAs($independent)->get(route('inventory.governed-counts'))->assertInertia(fn (AssertableInertia $page) => $page->component('Inventory/GovernedStock')->where('stock.cases.rows.0.canApprove', true));
        $command = $this->command(3);
        $this->assertSame($id, $s->act($independent, $id, 'approved', $command));
        $after = $snapshot();
        $this->assertSame($id, $s->act($independent, $id, 'approved', $command));
        $this->assertSame($after, $snapshot());
        $this->assertSame(1, DB::table('inventory_governed_actions')->where('case_id', $id)->where('action', 'approved')->count());
        $this->assertSame('8.000', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $independent->syncRoles(['ca']);
        $revoked = $snapshot();
        try {
            $s->act($independent, $id, 'approved', $command);
            $this->fail('A retry bypassed fresh CA Supervisor permission revocation');
        } catch (AuthorizationException) {
            $this->assertSame($revoked, $snapshot());
        }
    }

    public function test_zero_closes_without_approval_and_every_nonzero_needs_independent_recount(): void
    {
        $s = app(Service::class);
        $f = $this->f();
        $id = $s->propose($f['proposer'], 'count', $this->proposal($f));
        $max = DB::table('stock_movements')->max('id');
        $s->act($f['proposer'], $id, 'counted', $this->command(1, '10.000'));
        $this->assertSame('closed_zero', DB::table('inventory_governed_actions')->where('case_id', $id)->orderByDesc('lock_version')->first()->state);
        $this->assertSame($max, DB::table('stock_movements')->max('id'));
        $g = $this->f();
        $id = $s->propose($g['proposer'], 'count', $this->proposal($g));
        $s->act($g['proposer'], $id, 'counted', $this->command(1, '9.999'));
        $approver = $this->actorAt('finance_officer');
        try {
            $s->act($approver, $id, 'approved', $this->command(2));
            $this->fail('Skipped recount');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('state', $e->errors());
        }
        try {
            $s->act($g['proposer'], $id, 'recounted', $this->command(2, '9.999'));
            $this->fail('Same counter');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $recount = $this->actorAt('ca_supervisor');
        $s->act($recount, $id, 'recounted', $this->command(2, '10.000'));
        $this->assertSame('closed_zero', DB::table('inventory_governed_actions')->where('case_id', $id)->orderByDesc('lock_version')->first()->state);
    }

    public function test_positive_negative_exact_variances_reconcile_and_no_legacy_rows_change(): void
    {
        foreach (['12.345' => '2.345', '0.000' => '10.000'] as $physical => $delta) {
            $f = $this->f();
            $id = $this->countCase($f, $physical, $physical);
            $this->approve($id);
            $movement = DB::table('stock_movements')->where('reference_type', 'inventory_governed_case')->where('reference_public_id', DB::table('inventory_governed_cases')->where('id', $id)->value('public_id'))->sole();
            $this->assertSame($delta, $movement->quantity);
            $this->assertSame($physical, DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
            $this->assertSame($physical === '0.000' ? 'stocktake_loss' : 'stocktake_gain', $movement->movement_type);
            $this->assertEquals(['base_unit' => 'unit', 'input_unit' => 'unit', 'direction' => 'input_to_base', 'factor' => '1.000', 'precision' => 3], json_decode($movement->unit_snapshot, true));
        }
        $this->assertDatabaseCount('inventory_stocktakes', 0);
        $this->assertDatabaseCount('inventory_adjustments', 0);
    }

    public function test_counter_recouter_proposer_cannot_self_approve_even_with_direct_grant(): void
    {
        $s = app(Service::class);
        $f = $this->f();
        $id = $s->propose($f['proposer'], 'count', $this->proposal($f));
        $counter = $this->actorAt('ca_supervisor');
        $recount = $this->actorAt('ca_supervisor');
        $s->act($counter, $id, 'counted', $this->command(1, '8.000'));
        $s->act($recount, $id, 'recounted', $this->command(2, '7.000'));
        foreach ([$f['proposer'], $counter, $recount] as $actor) {
            $actor->givePermissionTo(Service::APPROVE);
            try {
                $s->act($actor, $id, 'approved', $this->command(3));
                $this->fail('Self approval');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $g = $this->f();
        $id = $s->propose($g['proposer'], 'writeoff', $this->proposal($g, ['quantity' => '1', 'reason' => 'damaged', 'disposal_reference' => 'SYN-DISPOSAL']));
        $g['proposer']->givePermissionTo(Service::APPROVE);
        try {
            $s->act($g['proposer'], $id, 'approved', $this->command(1));
            $this->fail('Proposer self approval');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_held_expired_writeoff_zeroes_projection_without_releasing_hold(): void
    {
        $f = $this->f();
        $f['batch']->forceFill(['expiry_date' => now()->setTimezone($this->branch->timezone)->toDateString()])->save();
        app(InventoryBatchHoldService::class)->act($f['proposer'], $f['batch']->public_id, 'place', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'recall']);
        $before = DB::table('inventory_batch_hold_states')->orderBy('id')->get()->toJson();
        $this->assertSame(1, app(InventoryStockReviewService::class)->page($f['proposer'], [])['lists']['expired']['total']);
        $id = app(Service::class)->propose($f['proposer'], 'writeoff', $this->proposal($f, ['quantity' => '10.000', 'reason' => 'expired', 'disposal_reference' => 'SYN-DISPOSAL-PRIVATE']));
        $this->approve($id);
        $this->assertSame('0.000', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $this->assertSame('expiry_writeoff', DB::table('stock_movements')->where('reference_type', 'inventory_governed_case')->sole()->movement_type);
        $this->assertSame(0, app(InventoryStockReviewService::class)->page($f['proposer'], [])['lists']['expired']['total']);
        $this->assertSame($before, DB::table('inventory_batch_hold_states')->orderBy('id')->get()->toJson());
        $this->assertStringNotContainsString('SYN-DISPOSAL-PRIVATE', DB::table('audit_logs')->get()->toJson());
    }

    public function test_stale_snapshot_versions_new_batch_keys_and_unit_precision_refused(): void
    {
        $f = $this->f();
        $s = app(Service::class);
        $id = $s->propose($f['proposer'], 'count', $this->proposal($f));
        foreach (['1.0001', '1000000000000', '-1', '1e1', 1.5] as $qty) {
            try {
                $s->act($f['proposer'], $id, 'counted', $this->command(1, $qty));
                $this->fail('Bad quantity');
            } catch (ValidationException) {
                $this->assertDatabaseCount('inventory_governed_actions', 1);
            }
        }
        foreach ([['unit' => 'pack'], ['batch_id' => $f['batch']->id], ['lines' => [['quantity' => '3']]], ['lock_version' => 9]] as $change) {
            try {
                $s->act($f['proposer'], $id, 'counted', $this->command(1, '2', $change));
                $this->fail('Unexpected source');
            } catch (ValidationException) {
                $this->assertDatabaseCount('inventory_governed_actions', 1);
            }
        }
        DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->update(['quantity' => '9.000', 'lock_version' => $f['balance']->lock_version + 1]);
        try {
            $s->act($f['proposer'], $id, 'counted', $this->command(1, '8'));
            $this->fail('Stale snapshot');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('snapshot', $e->errors());
        }
        try {
            $s->propose($f['proposer'], 'count', $this->proposal($f));
            $this->fail('Stale source version');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('balance_version', $e->errors());
        }
    }

    public function test_retry_conflicts_never_duplicate_evidence_or_movement(): void
    {
        $f = $this->f();
        $s = app(Service::class);
        $create = $this->proposal($f);
        $id = $s->propose($f['proposer'], 'count', $create);
        $this->assertSame($id, $s->propose($f['proposer'], 'count', $create));
        try {
            $s->propose($f['proposer'], 'count', [...$create, 'balance_version' => $f['balance']->lock_version + 1]);
            $this->fail('Creation conflict');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('retry_key', $e->errors());
        }
        $count = $this->command(1, '8.000');
        $s->act($f['proposer'], $id, 'counted', $count);
        $this->assertSame($id, $s->act($f['proposer'], $id, 'counted', $count));
        try {
            $s->act($f['proposer'], $id, 'counted', [...$count, 'quantity' => '7.000']);
            $this->fail('Count conflict');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('retry_key', $e->errors());
        }
        $recount = $this->actorAt('ca_supervisor');
        $s->act($recount, $id, 'recounted', $this->command(2, '8.000'));
        $a = $this->actorAt('director');
        $command = $this->command(3);
        $s->act($a, $id, 'approved', $command);
        $this->assertSame($id, $s->act($a, $id, 'approved', $command));
        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'inventory_governed_case')->count());
        $this->assertDatabaseCount('inventory_governed_actions', 4);
    }

    public function test_writeoff_limits_and_negative_stock_reversal_refusal(): void
    {
        $f = $this->f();
        $s = app(Service::class);
        foreach ([['quantity' => '10.001'], ['quantity' => '0'], ['reason' => 'free_text'], ['disposal_reference' => ''], ['disposal_reference' => str_repeat('x', 201)], ['disposal_reference' => "SYN\nLOG"], ['reason' => 'expired']] as $change) {
            try {
                $s->propose($f['proposer'], 'writeoff', $this->proposal($f, ['quantity' => '1', 'reason' => 'damaged', 'disposal_reference' => 'SYN', ...$change]));
                $this->fail('Invalid writeoff');
            } catch (ValidationException) {
                $this->assertDatabaseCount('inventory_governed_cases', 0);
            }
        }
        $id = $this->countCase($f, '12.000', '12.000');
        $this->approve($id);
        DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->update(['quantity' => '1.000', 'lock_version' => $f['balance']->lock_version + 2]);
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        $reverse = $s->propose($f['proposer'], 'reversal', $this->proposal($f, ['balance_version' => DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('lock_version'), 'reverses_case_id' => $id]));
        try {
            $this->approve($reverse);
            $this->fail('Negative stock');
        } catch (ValidationException) {
            $this->assertSame('1.000', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        }
    }

    public function test_reversal_appends_inverse_movement_and_original_evidence_stays_exact(): void
    {
        $f = $this->f();
        $s = app(Service::class);
        $id = $this->countCase($f);
        $this->approve($id);
        $original = DB::table('inventory_governed_cases')->where('id', $id)->sole();
        $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $max = DB::table('stock_movements')->max('id');
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        $reverse = $s->propose($f['proposer'], 'reversal', $this->proposal($f, ['balance_version' => DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('lock_version'), 'reverses_case_id' => $id]));
        $this->approve($reverse);
        $this->assertEquals($original, DB::table('inventory_governed_cases')->where('id', $id)->sole());
        $this->assertSame($before, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
        $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        try {
            $s->propose($f['proposer'], 'reversal', $this->proposal($f, ['balance_version' => DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('lock_version'), 'reverses_case_id' => $id]));
            $this->fail('Second reversal');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_governed_cases', 2);
        }
    }

    public function test_fresh_revocation_ended_assignments_foreign_scope_and_route_safety(): void
    {
        $f = $this->f();
        $s = app(Service::class);
        $id = $this->countCase($f);
        $actor = $this->actorAt('finance_officer');
        $actor->load('roles.permissions');
        $actor->syncRoles(['marketing']);
        try {
            $s->act($actor, $id, 'approved', $this->command(3));
            $this->fail('Revoked permission');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'inventory_governed_case')->count());
        }
        $actor->syncRoles(['finance_officer']);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_from' => now()->subDays(3)->toDateString(), 'valid_until' => now()->subDay()->toDateString()]);
        try {
            $s->act($actor, $id, 'approved', $this->command(3));
            $this->fail('Ended assignment');
        } catch (AuthorizationException|HttpException) {
            $this->assertDatabaseCount('inventory_governed_actions', 3);
        }
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $other = (new GovernedStockFixture($otherBranch))->actor('director');
        app(BranchAccessService::class)->select($other, $otherBranch);
        try {
            $s->act($other, $id, 'approved', $this->command(3, null, ['expected_branch_id' => $otherBranch->id]));
            $this->fail('Foreign branch');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $org = Organisation::query()->create(['code' => 'SYN-GOV', 'name' => 'Synthetic governed organisation', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org->id, 'code' => 'SYN-GOV', 'name' => 'Synthetic governed branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = (new GovernedStockFixture($branch))->actor('director');
        app(BranchAccessService::class)->select($foreign, $branch);
        try {
            $s->act($foreign, $id, 'approved', $this->command(3, null, ['expected_branch_id' => $branch->id]));
            $this->fail('Foreign organisation');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->actingAs($f['proposer']);
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        $this->get(route('inventory.governed-counts'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $this->post(route('inventory.governed.act', ['governedCase' => $id, 'action' => 'counted']), $this->command(99, '1'))->assertRedirect(route('inventory.governed-counts'))->assertSessionHasErrors('lock_version');
    }

    public function test_append_only_postgresql_cases_actions_and_unit_changes(): void
    {
        $f = $this->f();
        $s = app(Service::class);
        $id = $s->propose($f['proposer'], 'count', $this->proposal($f));
        foreach (['UPDATE inventory_governed_cases SET reason=reason', 'DELETE FROM inventory_governed_cases', 'TRUNCATE inventory_governed_cases CASCADE', 'UPDATE inventory_governed_actions SET state=state', 'DELETE FROM inventory_governed_actions', 'TRUNCATE inventory_governed_actions'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Mutable evidence');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
            $this->assertDatabaseCount('inventory_governed_cases', 1);
            $this->assertDatabaseCount('inventory_governed_actions', 1);
        }
        try {
            DB::transaction(fn () => DB::statement('TRUNCATE inventory_governed_cases'));
            $this->fail('FK bypass');
        } catch (QueryException $e) {
            $this->assertSame('0A000', $e->errorInfo[0]);
        }
        DB::table('inventory_skus')->where('id', $f['sku']->id)->update(['stock_unit' => 'pack']);
        try {
            $s->act($f['proposer'], $id, 'counted', $this->command(1, '9', ['unit' => 'pack']));
            $this->fail('Changed base unit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('snapshot', $e->errors());
        }
    }

    private function allUnrelated(): array
    {
        $result = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if (in_array($table->tablename, ['inventory_governed_cases', 'inventory_governed_actions', 'audit_logs', 'stock_movements', 'inventory_stock_balances'], true)) {
                continue;
            }
            $q = DB::table($table->tablename);
            $result[$table->tablename] = Schema::hasColumn($table->tablename, 'id') ? $q->orderBy('id')->get()->toJson() : $q->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->toJson();
        }

        return $result;
    }

    public function test_all_unrelated_rows_and_existing_movements_are_identical_audit_is_values_free_and_atomic(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $f = $this->f();
        $g = $this->f();
        $s = app(Service::class);
        $recounter = $this->actorAt('ca_supervisor');
        $approver = $this->actorAt('finance_officer');
        $before = $this->allUnrelated();
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $max = DB::table('stock_movements')->max('id');
        $balances = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $audits = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $auditMax = DB::table('audit_logs')->max('id');
        $id = $s->propose($f['proposer'], 'count', $this->proposal($f));
        $s->act($f['proposer'], $id, 'counted', $this->command(1, '9.875'));
        $s->act($recounter, $id, 'recounted', $this->command(2, '9.875'));
        $this->assertSame($before, $this->allUnrelated());
        $this->assertSame($balances, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, function ($m): void {
            $m->shouldReceive('record')->andReturnNull();
        }));
        try {
            $s->act($approver, $id, 'approved', $this->command(3));
            $this->fail('Unaudited posting');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($moves, DB::table('stock_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($balances, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('inventory_governed_actions', 3);
        app()->forgetInstance(AuditRecorder::class);
        $s->act($approver, $id, 'approved', $this->command(3));
        $this->assertSame($before, $this->allUnrelated());
        $this->assertSame($moves, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
        $this->assertSame($audits, DB::table('audit_logs')->where('id', '<=', $auditMax)->orderBy('id')->get()->toJson());
        $this->assertSame('9.875', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('id', $g['balance']->id)->value('quantity'));
        foreach (DB::table('audit_logs')->where('event', 'like', 'inventory.governed_stock.%')->get() as $row) {
            $metadata = json_decode($row->metadata, true);
            $keys = array_keys($metadata);
            sort($keys);
            $this->assertSame(['action_id', 'case_id', 'lock_version', 'state'], $keys);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_grant_replays_without_revoking_and_stock_reports_include_writeoffs_in_adjusted(): void
    {
        $migration = require database_path('migrations/2026_11_04_000200_grant_inventory_variance_approval.php');
        $migration->up();
        $migration->up();
        $migration->down();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->assertSame(in_array($role, ['ca_supervisor', 'finance_officer', 'director'], true), Role::findByName($role)->hasPermissionTo(Service::APPROVE));
        }
        $f = $this->f();
        $s = app(Service::class);
        $id = $s->propose($f['proposer'], 'writeoff', $this->proposal($f, ['quantity' => '1.125', 'reason' => 'damaged', 'disposal_reference' => 'SYN-LOG']));
        $this->approve($id);
        $this->assertContains('stock_writeoff', InventoryDirectoryService::MOVEMENT_TYPES);
        $this->assertContains('expiry_writeoff', InventoryDirectoryService::MOVEMENT_TYPES);
        $actor = $this->actorAt('director');
        $date = now()->setTimezone($this->branch->timezone)->toDateString();
        $response = $this->actingAs($actor)->postJson(route('reports.stock-movement.run'), ['expected_branch_id' => $this->branch->id, 'from' => $date, 'to' => $date])->assertOk();
        $rows = $response->json('result.rows');
        $this->assertNotEmpty($rows);
        $row = collect($rows)->first(fn ($row) => $row[1] === $f['sku']->sku_code);
        $this->assertSame('8.875', $row[6]);
        $this->assertSame('8.875', $row[8]);
        $this->assertSame('Yes', $row[9]);
        $this->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => 'unknown_type']))->assertSessionHasErrors('movement_type');
    }

    public function test_paged_targets_cases_and_history_have_no_per_row_queries(): void
    {
        $f = $this->f();
        $actor = $f['proposer'];
        $s = app(Service::class);
        $s->propose($actor, 'count', $this->proposal($f));
        $measure = function () use ($s, $actor) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $p = $s->page($actor, 'count', []);
            $q = DB::getQueryLog();
            DB::disableQueryLog();

            return [$p, count($q)];
        };
        $measure();
        [$small,$one] = $measure();
        for ($i = 1; $i < 28; $i++) {
            $g = $this->f();
            $s->propose($g['proposer'], 'count', $this->proposal($g));
        }
        [$page,$many] = $measure();
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(20, $many);
        $this->assertSame(28, $page['cases']['total']);
        $this->assertCount(25, $page['cases']['rows']);
        $this->assertCount(25, $page['targets']['rows']);
        $next = $s->page($actor, 'count', ['page' => 2, 'targets_page' => 2]);
        $this->assertCount(3, $next['cases']['rows']);
        $this->assertCount(3, $next['targets']['rows']);
        $this->assertCount(28, array_unique([...array_column($page['cases']['rows'], 'id'), ...array_column($next['cases']['rows'], 'id')]));
        $search = $s->page($actor, 'count', ['q' => $f['sku']->sku_code]);
        $this->assertCount(1, $search['cases']['rows']);
        $this->assertCount(1, $search['targets']['rows']);
    }

    public function test_reversal_overflow_and_unexpected_movement_types_remain_refused(): void
    {
        $f = $this->f('1.000');
        $s = app(Service::class);
        $id = $s->propose($f['proposer'], 'writeoff', $this->proposal($f, ['quantity' => '1.000', 'reason' => 'damaged', 'disposal_reference' => 'SYN-DISPOSAL']));
        $this->approve($id);
        DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->update(['quantity' => '999999999999.999', 'lock_version' => $f['balance']->lock_version + 2]);
        $reverse = $s->propose($f['proposer'], 'reversal', $this->proposal($f, ['balance_version' => $f['balance']->lock_version + 2, 'reverses_case_id' => $id]));
        try {
            $this->approve($reverse);
            $this->fail('Overflow accepted');
        } catch (ValidationException) {
            $this->assertSame('999999999999.999', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        }
        $movement = (array) DB::table('stock_movements')->where('reference_type', 'inventory_governed_case')->sole();
        unset($movement['id']);
        $movement['public_id'] = (string) Str::uuid();
        $movement['movement_type'] = 'unknown_stock_type';
        try {
            DB::transaction(fn () => DB::table('stock_movements')->insert($movement));
            $this->fail('Unknown movement');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'inventory_governed_case')->count());
    }

    public function test_held_batches_can_be_counted_and_every_evidence_stage_rolls_back_on_audit_failure(): void
    {
        $f = $this->f();
        $s = app(Service::class);
        app(InventoryBatchHoldService::class)->act($f['proposer'], $f['batch']->public_id, 'place', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'recall']);
        $before = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $hold = DB::table('inventory_batch_hold_states')->orderBy('id')->get()->toJson();
        $fail = function (): void {
            $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, function ($m): void {
                $m->shouldReceive('record')->andReturnNull();
            }));
        };
        $fail();
        try {
            $s->propose($f['proposer'], 'count', $this->proposal($f));
            $this->fail('Unaudited snapshot');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }$this->assertDatabaseCount('inventory_governed_cases', 0);
        $this->assertDatabaseCount('inventory_governed_actions', 0);
        app()->forgetInstance(AuditRecorder::class);
        $id = $s->propose($f['proposer'], 'count', $this->proposal($f));
        $fail();
        try {
            $s->act($f['proposer'], $id, 'counted', $this->command(1, '9'));
            $this->fail('Unaudited count');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }$this->assertDatabaseCount('inventory_governed_actions', 1);
        app()->forgetInstance(AuditRecorder::class);
        $s->act($f['proposer'], $id, 'counted', $this->command(1, '9'));
        $recounter = $this->actorAt('ca_supervisor');
        $fail();
        try {
            $s->act($recounter, $id, 'recounted', $this->command(2, '9'));
            $this->fail('Unaudited recount');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }$this->assertDatabaseCount('inventory_governed_actions', 2);
        app()->forgetInstance(AuditRecorder::class);
        $s->act($recounter, $id, 'recounted', $this->command(2, '9'));
        $this->approve($id);
        $this->assertSame('9.000', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $this->assertSame($hold, DB::table('inventory_batch_hold_states')->orderBy('id')->get()->toJson());
        $this->assertSame('10.000', json_decode($before)[0]->quantity);
    }

    public function test_grant_logs_missing_role_and_skips_without_new_holders(): void
    {
        Role::findByName('finance_officer')->delete();
        Log::spy();
        $migration = require database_path('migrations/2026_11_04_000200_grant_inventory_variance_approval.php');
        $migration->up();
        Log::shouldHaveReceived('warning')->once()->with('Variance approval grant skipped missing role', ['role' => 'finance_officer']);
        $this->assertTrue(Role::findByName('ca_supervisor')->hasPermissionTo(Service::APPROVE));
        $this->assertTrue(Role::findByName('director')->hasPermissionTo(Service::APPROVE));
    }
}
