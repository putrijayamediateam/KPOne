<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService as Reviews;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;

class StockReviewTest extends VisitTestCase
{
    private function stock(string $quantity = '3.000', ?string $expiry = null, string $level = '5.000', ?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        $actor = $this->actor('ca_supervisor', $branch);
        $this->selectBranch($actor, $branch);
        $code = 'SYN-'.Str::upper(Str::random(10));
        $item = new InventoryItem;
        $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'code' => $code, 'generic_name' => 'Synthetic review item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'inventory_item_id' => $item->id, 'sku_code' => $code, 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'is_active' => true])->save();
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'code' => $code, 'name' => 'Synthetic review store', 'type' => 'dispensary', 'is_active' => true])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'inventory_sku_id' => $sku->id, 'batch_number' => $code, 'expiry_date' => $expiry ?? now()->setTimezone($branch->timezone)->addDays(30)->toDateString(), 'received_at' => now()->subYear(), 'status' => 'available'])->save();
        if ($quantity !== '0.000') {
            app(InventoryMovementService::class)->openingBalance($actor, ['expected_branch_id' => $branch->id, 'sku_public_id' => $sku->public_id, 'location_public_id' => $location->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $quantity]);
        }
        $reorder = DB::table('inventory_reorder_levels')->insertGetId(['organisation_id' => $branch->organisation_id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $sku->id, 'reorder_level' => $level, 'updated_by_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);

        return compact('actor', 'item', 'sku', 'location', 'batch', 'reorder');
    }

    private function command(int $version = 0, string $outcome = 'monitoring', ?string $date = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), 'outcome' => $outcome, 'review_by' => $date ?? now()->setTimezone($this->branch->timezone)->addDays(3)->toDateString()];
    }

    private function rows(User $actor, string $kind, array $query = []): array
    {
        return app(Reviews::class)->page($actor, $query)['lists'][$kind]['rows'];
    }

    public function test_catalogue_exact_holders_all_roles_view_and_acknowledge(): void
    {
        $this->assertSame(['ca_supervisor', 'director', 'finance_officer'], collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(Reviews::MANAGE, $p, true))->keys()->sort()->values()->all());
        $f = $this->stock();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $view = in_array($role, ['director', 'ca', 'ca_supervisor', 'finance_officer', 'administrator'], true);
            $this->get(route('inventory.stock-review'))->assertStatus($view ? 200 : 403);
            try {
                $version = (int) DB::table('inventory_stock_reviews')->max('lock_version');
                app(Reviews::class)->acknowledge($actor, 'low_stock', $f['reorder'], $this->command($version));
                $this->assertContains($role, ['director', 'ca_supervisor', 'finance_officer']);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, ['director', 'ca_supervisor', 'finance_officer']);
            }
        }
    }

    public function test_replay_safe_grant_migration_and_no_revocation(): void
    {
        $m = require database_path('migrations/2026_11_03_000200_grant_inventory_stock_review_permission.php');
        $m->up();
        $m->up();
        $m->down();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->assertSame(in_array($role, ['director', 'ca_supervisor', 'finance_officer'], true), Role::findByName($role)->hasPermissionTo(Reviews::MANAGE));
        }
        Role::findByName('finance_officer')->delete();
        Log::spy();
        $m->up();
        Log::shouldHaveReceived('warning')->once()->with('Stock review grant skipped missing role', ['role' => 'finance_officer']);
    }

    public function test_shared_eligibility_zero_level_zero_balance_and_held_quantity(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28T08:00Z'));
        $a = $this->stock('3.000');
        $this->stock('0.000');
        $zero = $this->stock('0.000', level: '0.000');
        $healthy = $this->stock('6.000');
        $this->assertCount(2, $this->rows($a['actor'], 'low_stock'));
        app(InventoryBatchHoldService::class)->act($a['actor'], $a['batch']->public_id, 'place', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'recall']);
        $row = collect($this->rows($a['actor'], 'low_stock'))->firstWhere('item_id', $a['reorder']);
        $this->assertSame('0', rtrim(rtrim($row['quantity'], '0'), '.') ?: '0');
        $this->assertSame('3.000', $row['held']);
        $this->assertNotContains($a['batch']->batch_number, array_column($this->rows($a['actor'], 'expiring'), 'batch'));
        $this->assertNotContains($zero['reorder'], array_column($this->rows($a['actor'], 'low_stock'), 'item_id'));
        $this->assertNotContains($healthy['reorder'], array_column($this->rows($a['actor'], 'low_stock'), 'item_id'));
        foreach (['quarantined', 'damaged'] as $status) {
            $healthy['batch']->forceFill(['status' => $status])->save();
            $this->assertContains($healthy['reorder'], array_column($this->rows($a['actor'], 'low_stock'), 'item_id'));
        }
    }

    public function test_expiry_boundaries_midnight_and_expired_inactive_held_stock_stays_until_gone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28T15:59:00Z'));
        $date = now()->setTimezone($this->branch->timezone)->toImmutable();
        $today = $this->stock(expiry: $date->toDateString());
        $tomorrow = $this->stock(expiry: $date->addDay()->toDateString());
        $ninety = $this->stock(expiry: $date->addDays(90)->toDateString());
        $later = $this->stock(expiry: $date->addDays(91)->toDateString());
        $actor = $today['actor'];
        $this->selectBranch($actor);
        $this->assertEqualsCanonicalizing([$tomorrow['batch']->batch_number, $ninety['batch']->batch_number], array_column($this->rows($actor, 'expiring'), 'batch'));
        $today['item']->forceFill(['is_active' => false])->save();
        $today['sku']->forceFill(['is_active' => false])->save();
        $today['location']->forceFill(['is_active' => false])->save();
        $today['batch']->forceFill(['status' => 'damaged'])->save();
        app(InventoryBatchHoldService::class)->act($actor, $today['batch']->public_id, 'place', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'damaged']);
        $this->assertCount(1, $this->rows($actor, 'expired'));
        $this->travelTo(CarbonImmutable::parse('2026-10-28T16:00:00Z'));
        $this->assertCount(2, $this->rows($actor, 'expired'));
        $id = $this->rows($actor, 'expired')[0]['item_id'];
        // Synthetic future-ledger projection, not a new write-off feature.
        DB::table('inventory_stock_balances')->where('id', $id)->update(['quantity' => '0.000']);
        $this->assertNotContains($id, array_column($this->rows($actor, 'expired'), 'item_id'));
    }

    public function test_acknowledgements_never_hide_conditions_history_is_ten_and_overdue_is_local(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28T15:59Z'));
        $f = $this->stock();
        $actor = $f['actor'];
        $service = app(Reviews::class);
        for ($v = 0; $v < 12; $v++) {
            $service->acknowledge($actor, 'low_stock', $f['reorder'], $this->command($v, 'monitoring', '2026-10-28'));
        }
        $row = $this->rows($actor, 'low_stock')[0];
        $this->assertCount(10, $row['history']);
        $this->assertSame(12, $row['lock_version']);
        $this->assertFalse($row['latest']['overdue']);
        $this->travelTo(CarbonImmutable::parse('2026-10-28T16:00Z'));
        $this->assertTrue($this->rows($actor, 'low_stock')[0]['latest']['overdue']);
        $this->assertDatabaseCount('inventory_stock_reviews', 12);
    }

    public function test_review_dates_controlled_outcomes_stale_keys_retries_and_cleared_conditions(): void
    {
        $f = $this->stock();
        $actor = $f['actor'];
        $service = app(Reviews::class);
        $command = $this->command();
        $id = $service->acknowledge($actor, 'low_stock', $f['reorder'], $command);
        $this->assertSame($id, $service->acknowledge($actor, 'low_stock', $f['reorder'], $command));
        foreach ([['outcome' => 'ordered_externally'], ['lock_version' => 2]] as $change) {
            try {
                $service->acknowledge($actor, 'low_stock', $f['reorder'], [...$command, ...$change]);
                $this->fail('Changed retry');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('retry_key', $e->errors());
            }
        }
        foreach ([$this->command(0), [...$this->command(1), 'review_by' => now()->addDays(15)->toDateString()], [...$this->command(1), 'review_by' => now()->subDay()->toDateString()], [...$this->command(1), 'review_by' => null], [...$this->command(1), 'outcome' => 'send_message']] as $invalid) {
            try {
                $service->acknowledge($actor, 'low_stock', $f['reorder'], $invalid);
                $this->fail('Invalid review accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('inventory_stock_reviews', 1);
            }
        }
        $service->acknowledge($actor, 'low_stock', $f['reorder'], $this->command(1, 'ordered_externally', now()->setTimezone($this->branch->timezone)->addDays(14)->toDateString()));
        $service->acknowledge($actor, 'low_stock', $f['reorder'], [...$this->command(2, 'no_action_needed'), 'review_by' => null]);
        DB::table('inventory_reorder_levels')->where('id', $f['reorder'])->update(['reorder_level' => '1.000']);
        $this->assertCount(0, $this->rows($actor, 'low_stock'));
        $this->assertSame($id, $service->acknowledge($actor, 'low_stock', $f['reorder'], $command));
        try {
            $service->acknowledge($actor, 'low_stock', $f['reorder'], $this->command(3));
            $this->fail('Cleared condition');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_fresh_revocation_ended_assignments_branch_and_organisation_isolation(): void
    {
        $f = $this->stock();
        $actor = $f['actor'];
        $service = app(Reviews::class);
        $actor->load('roles.permissions');
        $actor->syncRoles(['ca']);
        try {
            $service->acknowledge($actor, 'low_stock', $f['reorder'], $this->command());
            $this->fail('Revoked authority');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('inventory_stock_reviews', 0);
        }
        $actor->syncRoles(['ca_supervisor']);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString(), 'valid_from' => now()->subDays(3)->toDateString()]);
        try {
            $service->acknowledge($actor, 'low_stock', $f['reorder'], $this->command());
            $this->fail('Ended assignment');
        } catch (AuthorizationException|HttpException) {
            $this->assertDatabaseCount('inventory_stock_reviews', 0);
        }
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $other = $this->actor('director', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $this->assertCount(0, $this->rows($other, 'low_stock'));
        try {
            $service->acknowledge($other, 'low_stock', $f['reorder'], [...$this->command(), 'expected_branch_id' => $otherBranch->id]);
            $this->fail('Foreign branch');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $org = Organisation::query()->create(['code' => 'SYN-REVIEW', 'name' => 'Synthetic review organisation', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org->id, 'code' => 'SYN-REVIEW', 'name' => 'Synthetic review branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = $this->actor('director', $branch);
        $this->selectBranch($foreign, $branch);
        $this->assertCount(0, $this->rows($foreign, 'low_stock'));
        try {
            $service->acknowledge($foreign, 'low_stock', $f['reorder'], [...$this->command(), 'expected_branch_id' => $branch->id]);
            $this->fail('Foreign organisation');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_paging_search_no_hidden_unresolved_rows_and_constant_batch_queries(): void
    {
        $f = $this->stock();
        $actor = $f['actor'];
        $measure = function () use ($actor): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = app(Reviews::class)->page($actor, []);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return [$page, $queries];
        };
        $measure();
        [$small, $q1] = $measure();
        for ($i = 1; $i < 28; $i++) {
            $this->stock();
        }
        [$page, $q28] = $measure();
        $this->assertCount(count($q1), $q28);
        $this->assertLessThanOrEqual(20, count($q28));
        $this->assertSame(28, $page['lists']['low_stock']['total']);
        $this->assertCount(25, $page['lists']['low_stock']['rows']);
        $second = $this->rows($actor, 'low_stock', ['low_stock_page' => 2]);
        $this->assertCount(3, $second);
        $all = [...array_column($page['lists']['low_stock']['rows'], 'item_id'), ...array_column($second, 'item_id')];
        $this->assertCount(28, array_unique($all));
        $this->assertCount(1, $this->rows($actor, 'low_stock', ['q' => $f['sku']->sku_code]));
        $this->assertCount(0, $this->rows($actor, 'low_stock', ['q' => 'NOT-THERE']));
    }

    public function test_postgresql_evidence_immutable_and_scoped(): void
    {
        $f = $this->stock();
        $id = app(Reviews::class)->acknowledge($f['actor'], 'low_stock', $f['reorder'], $this->command());
        foreach (["UPDATE inventory_stock_reviews SET outcome='no_action_needed'", 'DELETE FROM inventory_stock_reviews', 'TRUNCATE inventory_stock_reviews'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Mutable evidence');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
            $this->assertDatabaseCount('inventory_stock_reviews', 1);
        }
        $row = (array) DB::table('inventory_stock_reviews')->where('id', $id)->sole();
        unset($row['id']);
        $row['branch_id'] = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole()->id;
        $row['retry_key'] = (string) Str::uuid();
        try {
            DB::transaction(fn () => DB::table('inventory_stock_reviews')->insert($row));
            $this->fail('Scope mismatch');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_each_list_accepts_only_current_items_without_hiding_expired_evidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28T08:00Z'));
        $f = $this->stock();
        $expired = $this->stock(expiry: '2026-10-28');
        $actor = $f['actor'];
        $this->selectBranch($actor);
        $service = app(Reviews::class);
        foreach (['expiring', 'expired'] as $kind) {
            $rows = $this->rows($actor, $kind);
            $this->assertCount(1, $rows);
            $id = $service->acknowledge($actor, $kind, $rows[0]['item_id'], [...$this->command(0, 'disposal_requested_external'), 'review_by' => null]);
            $after = $this->rows($actor, $kind);
            $this->assertCount(1, $after);
            $this->assertSame($id, $after[0]['latest']['id']);
            $this->assertSame('3.000', $after[0]['quantity']);
        }
        $this->get(route('inventory.stock-review'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $this->post(route('inventory.stock-review.store', ['kind' => 'low_stock', 'reviewItem' => $f['reorder']]), [...$this->command(), 'outcome' => 'invalid'])
            ->assertRedirect(route('inventory.stock-review'))->assertSessionHasErrors('outcome');
        $this->selectBranch($actor);
        $this->post(route('inventory.stock-review.store', ['kind' => 'low_stock', 'reviewItem' => $f['reorder']]), $this->command())->assertRedirect(route('inventory.stock-review'));
        $this->assertDatabaseCount('inventory_stock_reviews', 3);
    }

    public function test_read_authority_is_fresh_and_drafts_have_no_cross_branch_projection(): void
    {
        $f = $this->stock();
        $actor = $f['actor'];
        $actor->load('roles.permissions');
        $actor->syncRoles(['marketing']);
        try {
            app(Reviews::class)->page($actor, []);
            $this->fail('Stale view authority');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $g = $this->stock('2.000', branch: $other);
        $this->selectBranch($g['actor'], $other);
        $rows = $this->rows($g['actor'], 'low_stock');
        $this->assertSame([$g['reorder']], array_column($rows, 'item_id'));
        $this->selectBranch($this->actor('director'));
        try {
            app(Reviews::class)->acknowledge(auth()->user(), 'low_stock', $f['reorder'], [...$this->command(), 'expected_branch_id' => $other->id]);
            $this->fail('Changed branch accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_branch_id', $e->errors());
        }
    }

    private function unrelated(): array
    {
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
        $result = [];
        foreach ($tables as $table) {
            if (in_array($table->tablename, ['inventory_stock_reviews', 'audit_logs'], true)) {
                continue;
            }
            $query = DB::table($table->tablename);
            if (Schema::hasColumn($table->tablename, 'id')) {
                $query->orderBy('id');
                $result[$table->tablename] = $query->get()->toJson();
            } else {
                $result[$table->tablename] = $query->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->toJson();
            }
        }

        return $result;
    }

    public function test_full_unrelated_rows_unchanged_and_values_free_audit_failure_rolls_back(): void
    {
        $f = $this->stock();
        $actor = $f['actor'];
        $service = app(Reviews::class);
        $command = $this->command();
        $before = $this->unrelated();
        $auditBefore = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $auditMax = DB::table('audit_logs')->max('id');
        $id = $service->acknowledge($actor, 'low_stock', $f['reorder'], $command);
        $this->assertSame($before, $this->unrelated());
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('id', '<=', $auditMax)->orderBy('id')->get()->toJson());
        $audit = json_decode(DB::table('audit_logs')->where('event', 'inventory.stock_review.acknowledged')->sole()->metadata, true);
        $this->assertSame(['review_id' => $id, 'worklist' => 'low_stock', 'outcome' => 'monitoring'], $audit);
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->andReturnNull();
        }));
        try {
            $service->acknowledge($actor, 'low_stock', $f['reorder'], $this->command(1));
            $this->fail('Unaudited review');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_stock_reviews', 1);
        $this->assertSame($before, $this->unrelated());
    }
}
