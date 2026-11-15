<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Inventory\Services\InventoryAvailabilityService;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryPickerService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

class BatchHoldTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    private function command(int $version = 0, string $reason = 'recall'): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'reason' => $reason, 'retry_key' => (string) Str::uuid()];
    }

    public function test_exact_permission_holders_and_every_role_place_release_authority(): void
    {
        $this->assertSame(['ca_supervisor', 'director', 'finance_officer'], collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(InventoryBatchHoldService::PLACE, $p, true))->keys()->sort()->values()->all());
        $this->assertSame(['director', 'finance_officer'], collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(InventoryBatchHoldService::RELEASE, $p, true))->keys()->sort()->values()->all());
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $f = $this->fixture();
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $service = app(InventoryBatchHoldService::class);
            try {
                $service->act($actor, $f['batch']->public_id, 'place', $this->command());
                $this->assertContains($role, ['ca_supervisor', 'director', 'finance_officer']);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, ['ca_supervisor', 'director', 'finance_officer']);
            }
            if (! in_array($role, ['ca_supervisor', 'director', 'finance_officer'], true)) {
                $placer = $this->actor('ca_supervisor');
                $this->selectBranch($placer);
                $service->act($placer, $f['batch']->public_id, 'place', $this->command());
            }
            $releaser = $this->actor($role);
            $this->selectBranch($releaser);
            try {
                $service->act($releaser, $f['batch']->public_id, 'release', $this->command(1, 'inspection_passed'));
                $this->assertContains($role, ['director', 'finance_officer']);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, ['director', 'finance_officer']);
            }
        }
    }

    public function test_independent_release_retries_stale_versions_and_fresh_revocation(): void
    {
        $f = $this->fixture();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $service = app(InventoryBatchHoldService::class);
        $command = $this->command();
        $id = $service->act($actor, $f['batch']->public_id, 'place', $command);
        $this->assertSame($id, $service->act($actor, $f['batch']->public_id, 'place', $command));
        foreach ([['reason' => 'damaged'], ['lock_version' => 3]] as $different) {
            try {
                $service->act($actor, $f['batch']->public_id, 'place', [...$command, ...$different]);
                $this->fail('Conflicting retry accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('retry_key', $e->errors());
            }
        }
        try {
            $service->act($actor, $f['batch']->public_id, 'release', $this->command(1, 'inspection_passed'));
            $this->fail('Self release accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $other = $this->actor('finance_officer');
        $this->selectBranch($other);
        try {
            $service->act($other, $f['batch']->public_id, 'release', $this->command(0, 'inspection_passed'));
            $this->fail('Stale version accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lock_version', $e->errors());
        }
        $release = $this->command(1, 'cleared_by_supplier');
        $releaseId = $service->act($other, $f['batch']->public_id, 'release', $release);
        $this->assertSame($releaseId, $service->act($other, $f['batch']->public_id, 'release', $release));
        $this->assertNull(DB::table('inventory_batch_hold_states')->sole()->active_action_id);
        $other->syncRoles(['marketing']);
        $this->expectException(AuthorizationException::class);
        $service->act($other, $f['batch']->public_id, 'release', $release);
    }

    public function test_save_refuses_today_midnight_unavailable_and_held_with_named_english_reason(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(16, 30));
        foreach (['expiry', 'past_expiry', 'quarantined', 'damaged', 'held'] as $reason) {
            $f = $this->fixture();
            $case = $this->open($f);
            if (in_array($reason, ['expiry', 'past_expiry'], true)) {
                $date = now()->setTimezone($this->branch->timezone);
                $f['batch']->forceFill(['expiry_date' => ($reason === 'past_expiry' ? $date->subDay() : $date)->toDateString(), 'received_at' => $date->copy()->subDays(5)->toDateString()])->save();
            } elseif ($reason === 'held') {
                $placer = $this->actor('ca_supervisor');
                $this->selectBranch($placer);
                app(InventoryBatchHoldService::class)->act($placer, $f['batch']->public_id, 'place', $this->command());
            } else {
                $f['batch']->forceFill(['status' => $reason])->save();
            }
            $this->selectBranch($f['ca']);
            $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
            try {
                app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
                $this->fail('Ineligible batch saved');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($f['medicine']->display_name, $e->errors()['allocations'][0]);
                $this->assertStringContainsString(in_array($reason, ['expiry', 'past_expiry'], true) ? 'branch-local' : ($reason === 'held' ? 'held' : 'status'), $e->errors()['allocations'][0]);
            }
            $this->assertSame($before, DB::table('stock_movements')->orderBy('id')->get()->toJson());
        }
    }

    public function test_earlier_and_later_eligible_saves_require_controlled_fefo_evidence_only_for_later(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $later = $f['batch']->replicate();
        $later->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => 'SYN-LATER', 'expiry_date' => now()->addMonths(2)->toDateString()])->save();
        $balance = DB::table('inventory_stock_balances')->where('inventory_batch_id', $f['batch']->id)->sole();
        DB::table('inventory_stock_balances')->insert([...collect((array) $balance)->except('id')->all(), 'inventory_batch_id' => $later->id]);
        $service = app(DispensaryService::class);
        $line = $service->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->assertNull($line->allocations()->sole()->fefo_exception_reason);
        $payload = $this->addPayload([...$f, 'batch' => $later], $case);
        $payload['item_lock_version'] = $line->lock_version;
        unset($payload['medicine_public_id']);
        try {
            $service->editItem($f['ca'], $case->refresh(), $line, $payload);
            $this->fail('Later batch saved without reason');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('FEFO', $e->errors()['allocations'][0]);
        }
        $payload['allocations'][0]['fefo_exception_reason'] = 'other_approved';
        $saved = $service->editItem($f['ca'], $case->refresh(), $line, $payload);
        $this->assertSame('other_approved', $saved->allocations()->sole()->fefo_exception_reason);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'dispensary.fefo_exception')->count());
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        try {
            DB::transaction(fn () => DB::table('dispensary_item_batch_allocations')->where('dispensary_item_id', $saved->id)->update(['fefo_exception_reason' => null]));
            $this->fail('Completed FEFO reason changed');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
        $this->assertSame('other_approved', $saved->allocations()->sole()->fefo_exception_reason);
    }

    public function test_complete_rechecks_held_or_midnight_expiry_after_an_eligible_save(): void
    {
        foreach (['held', 'expiry'] as $reason) {
            $f = $this->fixture();
            $case = $this->open($f);
            $this->confirmAllergy($f, $case);
            app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case));
            if ($reason === 'held') {
                $placer = $this->actor('finance_officer');
                $this->selectBranch($placer);
                app(InventoryBatchHoldService::class)->act($placer, $f['batch']->public_id, 'place', $this->command());
            } else {
                $this->travelTo(CarbonImmutable::parse($f['batch']->expiry_date->toDateString().' 00:00:00', $this->branch->timezone)->utc());
            }
            $this->selectBranch($f['ca']);
            try {
                $this->complete($f, $case);
                $this->fail('Stale eligibility accepted at Complete');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('allocations', $e->errors());
            }
            $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('inventory_batch_id', $f['batch']->id)->sole()->quantity);
            $this->travelBack();
        }
    }

    public function test_hold_release_change_only_declared_evidence_and_values_free_audit_and_are_immutable(): void
    {
        $f = $this->fixture();
        $one = $this->actor('ca_supervisor');
        $two = $this->actor('finance_officer');
        $snapshot = function (): array {
            $rows = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
                if (in_array($table->tablename, ['inventory_batch_hold_actions', 'inventory_batch_hold_states', 'audit_logs'], true)) {
                    continue;
                }
                $columns = array_column(DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', ['public', $table->tablename]), 'column_name');
                $q = DB::table($table->tablename);
                foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                    $q->orderBy($column);
                }
                $rows[$table->tablename] = $q->get()->toJson();
            }

            return $rows;
        };
        $auditBefore = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $auditThrough = DB::table('audit_logs')->max('id') ?? 0;
        $before = $snapshot();
        $this->selectBranch($one);
        app(InventoryBatchHoldService::class)->act($one, $f['batch']->public_id, 'place', $this->command());
        $this->assertSame($before, $snapshot());
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('id', '<=', $auditThrough)->orderBy('id')->get()->toJson());
        $this->selectBranch($two);
        app(InventoryBatchHoldService::class)->act($two, $f['batch']->public_id, 'release', $this->command(1, 'inspection_passed'));
        $this->assertSame($before, $snapshot());
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('id', '<=', $auditThrough)->orderBy('id')->get()->toJson());
        foreach (DB::table('audit_logs')->where('event', 'like', 'inventory.batch_hold.%')->get() as $audit) {
            $this->assertSame(['action_id'], array_keys(json_decode($audit->metadata, true)));
        }
        foreach (['UPDATE inventory_batch_hold_actions SET reason=reason', 'DELETE FROM inventory_batch_hold_actions', 'TRUNCATE inventory_batch_hold_actions CASCADE', 'DELETE FROM inventory_batch_hold_states', 'TRUNCATE inventory_batch_hold_states'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Hold evidence erased');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
            $this->assertDatabaseCount('inventory_batch_hold_actions', 2);
        }
    }

    public function test_picker_and_availability_show_held_but_predicate_refuses_it_and_queries_are_bounded(): void
    {
        $f = $this->fixture();
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        app(InventoryBatchHoldService::class)->act($actor, $f['batch']->public_id, 'place', $this->command());
        $choices = app(InventoryPickerService::class)->choices($actor, $this->branch, 'batches', sku: $f['sku']->public_id);
        $this->assertTrue($choices[0]['held']);
        $this->assertSame('recall', $choices[0]['holdReason']);
        $this->assertTrue(app(InventoryAvailabilityService::class)->forSku($actor, $f['sku']->id, $this->branch->id, true)[0]['held']);
        $balance = (array) DB::table('inventory_stock_balances')->where('inventory_batch_id', $f['batch']->id)->sole();
        // Synthetic review-volume bootstrap only; no runtime balance mutation API.
        for ($i = 1; $i <= 31; $i++) {
            $batch = $f['batch']->replicate();
            $batch->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => 'SYN-PAGED-'.$i])->save();
            DB::table('inventory_stock_balances')->insert([...collect($balance)->except('id')->all(), 'inventory_batch_id' => $batch->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = app(InventoryBatchHoldService::class)->page($actor, []);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(18, count($queries));
        $this->assertCount(25, $page['rows']);
        $this->assertSame(32, $page['total']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:from|join) \"(?:patients|visits|clinical_encounters|invoices|payments)\"/i', $query['query']);
        }
        $this->assertTrue($page['rows'][0]['canPlace'] === false);
    }

    public function test_audit_failure_rolls_back_hold_and_ended_assignment_or_foreign_branch_refuse(): void
    {
        $f = $this->fixture();
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $service = app(InventoryBatchHoldService::class);
        $audit = $this->mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andReturn(null);
        try {
            $service->act($actor, $f['batch']->public_id, 'place', $this->command());
            $this->fail('Unaudited hold accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_batch_hold_actions', 0);
        $this->assertDatabaseCount('inventory_batch_hold_states', 0);
        StaffBranchAssignment::query()->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_from' => now()->subDays(5)->toDateString(), 'valid_until' => now()->subDay()->toDateString()]);
        $this->expectException(AuthorizationException::class);
        $service->act($actor, $f['batch']->public_id, 'place', $this->command());
    }

    public function test_grants_are_replay_safe_missing_roles_logged_and_down_never_revokes(): void
    {
        $migration = require database_path('migrations/2026_11_01_000200_grant_branch_batch_hold_permissions.php');
        $role = Role::findByName('finance_officer', 'web');
        $role->revokePermissionTo(InventoryBatchHoldService::RELEASE);
        $migration->up();
        $migration->up();
        $this->assertTrue($role->fresh()->hasPermissionTo(InventoryBatchHoldService::RELEASE));
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $role->delete();
        Log::shouldReceive('warning')->twice()->with('Batch hold grant skipped missing role', ['role' => 'finance_officer']);
        $migration->up();
    }

    public function test_foreign_branch_and_organisation_cannot_hold_and_missing_or_month_only_expiry_stay_refused(): void
    {
        $f = $this->fixture();
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        foreach ([null, '2026-10'] as $expiry) {
            try {
                app(InventoryReferenceAdministrationService::class)->createBatch($actor, $f['sku'], ['batch_number' => 'SYN-INVALID', 'expiry_date' => $expiry]);
                $this->fail('An expiry date was invented');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('expiry_date', $e->errors());
            }
        }
        $other = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        try {
            app(InventoryBatchHoldService::class)->act($actor, $f['batch']->public_id, 'place', [...$this->command(), 'expected_branch_id' => $other->id]);
            $this->fail('Foreign branch accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_branch_id', $e->errors());
        }
        $foreignOrg = Organisation::query()->create(['code' => 'SYN_HOLD_OTHER', 'name' => 'Synthetic other organisation']);
        $foreignBranch = (new Branch)->forceFill(['organisation_id' => $foreignOrg->id, 'code' => 'SYN_HOLD_BRANCH', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreignBranch->save();
        $foreignActor = $this->actor('director', $foreignBranch);
        $this->selectBranch($foreignActor, $foreignBranch);
        $this->expectException(ModelNotFoundException::class);
        app(InventoryBatchHoldService::class)->act($foreignActor, $f['batch']->public_id, 'place', [...$this->command(), 'expected_branch_id' => $foreignBranch->id]);
    }

    public function test_inactive_sku_or_item_save_is_named_and_validation_redirect_stays_on_case(): void
    {
        foreach (['sku', 'item'] as $reference) {
            $f = $this->fixture();
            $case = $this->open($f);
            if ($reference === 'sku') {
                $f['sku']->forceFill(['is_active' => false])->save();
            } else {
                DB::table('inventory_items')->where('id', $f['sku']->inventory_item_id)->update(['is_active' => false]);
            }
            $this->from('/check-in/irrelevant')->post(route('dispensary.items.add', $case), $this->addPayload($f, $case))->assertRedirect(route('dispensary.show', $case))->assertSessionHasErrors('allocations');
            $message = session('errors')->get('allocations')[0];
            $this->assertStringContainsString($f['medicine']->display_name, $message);
            $this->assertStringContainsString('inactive', $message);
        }
    }

    public function test_release_audit_failure_keeps_the_hold_active_and_old_audits_unchanged(): void
    {
        $f = $this->fixture();
        $placer = $this->actor('ca_supervisor');
        $checker = $this->actor('finance_officer');
        $this->selectBranch($placer);
        $id = app(InventoryBatchHoldService::class)->act($placer, $f['batch']->public_id, 'place', $this->command());
        $before = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $this->selectBranch($checker);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturn(null);
        try {
            app(InventoryBatchHoldService::class)->act($checker, $f['batch']->public_id, 'release', $this->command(1, 'inspection_passed'));
            $this->fail('Unaudited release accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($id, DB::table('inventory_batch_hold_states')->sole()->active_action_id);
        $this->assertDatabaseCount('inventory_batch_hold_actions', 1);
        $this->assertSame($before, DB::table('audit_logs')->orderBy('id')->get()->toJson());
    }
}
