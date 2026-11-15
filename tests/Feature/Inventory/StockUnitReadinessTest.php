<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Clinical\ClinicalTestCase;

class StockUnitReadinessTest extends ClinicalTestCase
{
    /** @return array{InventorySku,StockMovement} */
    private function opening(User $actor): array
    {
        $item = new InventoryItem;
        $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'code' => 'SYN-'.Str::random(10), 'generic_name' => 'Synthetic unit item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'inventory_item_id' => $item->id, 'sku_code' => 'SYN-'.Str::random(10), 'pack_size' => '1.000', 'purchase_unit' => 'pack', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'is_active' => true])->save();
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $this->branch->id, 'code' => 'SYN-'.Str::random(10), 'name' => 'Synthetic location', 'type' => 'branch_store', 'is_active' => true])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-'.Str::random(8), 'expiry_date' => now()->addYear()->toDateString(), 'received_at' => now(), 'status' => 'available'])->save();
        $this->selectBranch($actor);
        $movement = app(InventoryMovementService::class)->openingBalance($actor, ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'location_public_id' => $location->public_id, 'quantity' => '10.125', 'unit' => 'unit']);

        return [$sku, $movement];
    }

    private function data(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), ...$extra];
    }

    private function unit(User $actor, InventorySku $sku): void
    {
        $this->selectBranch($actor);
        app(InventoryReadinessService::class)->verify($actor, 'unit', $sku->public_id, $this->data(['base_unit' => $sku->stock_unit]));
    }

    public function test_exact_quantities_have_no_rounding_overflow_or_non_base_conversion(): void
    {
        foreach (['1', '01.000', '1.0000'] as $value) {
            $this->assertSame('1.000', StockUnitQuantity::base($value, 'tablet', 'tablet'));
        }
        $this->assertSame('999999999999.999', StockUnitQuantity::base('999999999999.999', 'unit'));
        foreach (['0', '-1', '1.0001', '1000000000000', '1e3', 'NaN', 1.1] as $value) {
            try {
                StockUnitQuantity::base($value, 'unit');
                $this->fail('Invalid exact quantity accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quantity', $e->errors());
            }
        }
        $this->expectException(ValidationException::class);
        StockUnitQuantity::base('2', 'tablet', 'pack');
    }

    public function test_non_base_posting_precision_loss_overflow_and_verified_base_changes_are_refused(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $batch = InventoryBatch::findOrFail($opening->inventory_batch_id)->replicate();
        $batch->public_id = (string) Str::uuid();
        $batch->batch_number = 'SYN-NEW-BATCH';
        $batch->save();
        $input = ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'location_public_id' => InventoryLocation::findOrFail($opening->destination_location_id)->public_id, 'quantity' => '1', 'unit' => 'pack'];
        foreach ([$input, [...$input, 'unit' => 'unit', 'quantity' => '1.0001']] as $bad) {
            try {
                app(InventoryMovementService::class)->openingBalance($opener, $bad);
                $this->fail('Invalid posting accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quantity', $e->errors());
            }
        }
        $this->assertDatabaseCount('stock_movements', 1);
        $location = InventoryLocation::findOrFail($opening->destination_location_id);
        try {
            DB::transaction(fn () => app(InventoryMovementService::class)->recordPurchaseReceipt($opener, $location, $sku, InventoryBatch::findOrFail($opening->inventory_batch_id), '999999999999.999', (string) Str::uuid()));
            $this->fail('Balance overflow accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
        $this->assertSame('10.125', DB::table('inventory_stock_balances')->sole()->quantity);
        $this->unit($opener, $sku);
        try {
            DB::transaction(fn () => DB::table('inventory_skus')->where('id', $sku->id)->update(['stock_unit' => 'pack']));
            $this->fail('Verified base unit changed');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
        $this->assertSame('unit', $sku->fresh()->stock_unit);
    }

    public function test_unit_verification_alone_blocks_unit_changes_and_assigned_foreign_branch_cannot_sign(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $copy = $sku->replicate();
        $copy->public_id = (string) Str::uuid();
        $copy->sku_code = 'SYN-UNIT-ONLY';
        $copy->save();
        $this->unit($opener, $copy);
        try {
            app(InventoryReferenceAdministrationService::class)->updateSku($opener, $copy, ['stock_unit' => 'pack']);
            $this->fail('Domain allowed changing a verified unit without other stock evidence');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->errors());
        }
        $this->assertSame('unit', $copy->fresh()->stock_unit);
        $other = Branch::where('organisation_id', $opener->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $checker = $this->actor('finance_officer', $other);
        $this->selectBranch($checker, $other);
        try {
            app(InventoryReadinessService::class)->verify($checker, 'opening', $opening->public_id, $this->data(['expected_branch_id' => $other->id]));
            $this->fail('Assigned foreign branch signed the opening');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_opening_verifications', 0);
    }

    public function test_permission_migration_is_additive_replay_safe_and_logs_missing_roles(): void
    {
        $migration = require database_path('migrations/2026_10_31_000200_grant_inventory_opening_verification.php');
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', InventoryReadinessService::VERIFY))->orderBy('name')->pluck('name')->all();
        $this->assertSame(['ca_supervisor', 'director', 'finance_officer'], $holders);
        Role::where('name', 'finance_officer')->delete();
        Log::spy();
        $migration->up();
        Log::shouldHaveReceived('warning')->with('STK-1 grant skipped missing role', ['role' => 'finance_officer'])->once();
        $this->assertFalse(Role::where('name', 'finance_officer')->exists());
    }

    public function test_exact_grants_all_catalogue_roles_and_independent_checker(): void
    {
        $opener = $this->actor('ca_supervisor');
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            [$sku, $opening] = $this->opening($opener);
            $this->unit($opener, $sku);
            $checker = $this->actor($role);
            $this->selectBranch($checker);
            $allowed = in_array($role, ['ca_supervisor', 'finance_officer', 'director'], true);
            $this->assertSame($allowed, $checker->can(InventoryReadinessService::VERIFY), $role);
            try {
                app(InventoryReadinessService::class)->verify($checker, 'opening', $opening->public_id, $this->data());
                $this->assertTrue($allowed, $role);
            } catch (AuthorizationException $e) {
                $this->assertFalse($allowed, $role);
            }
        }
        [$sku, $opening] = $this->opening($opener);
        $this->unit($opener, $sku);
        $this->expectException(HttpException::class);
        app(InventoryReadinessService::class)->verify($opener, 'opening', $opening->public_id, $this->data());
    }

    public function test_retry_stale_foreign_branch_and_ended_assignment_refuse_without_changes(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $this->unit($opener, $sku);
        $checker = $this->actor('finance_officer');
        $this->selectBranch($checker);
        $service = app(InventoryReadinessService::class);
        $data = $this->data();
        $id = $service->verify($checker, 'opening', $opening->public_id, $data);
        $this->assertSame($id, $service->verify($checker, 'opening', $opening->public_id, $data));
        foreach ([$data + ['base_unit' => 'conflict'], $this->data(['lock_version' => 1])] as $bad) {
            try {
                $service->verify($checker, 'opening', $opening->public_id, $bad);
                $this->fail('Stale/conflicting evidence accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('inventory_opening_verifications', 1);
            }
        }
        $foreign = Branch::query()->where('organisation_id', $checker->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $this->selectBranch($checker, $foreign);
        try {
            $service->verify($checker, 'opening', $opening->public_id, $this->data(['expected_branch_id' => $foreign->id]));
            $this->fail('Foreign branch accepted');
        } catch (AuthorizationException|HttpException) {
            $this->assertDatabaseCount('inventory_opening_verifications', 1);
        }
        $this->selectBranch($checker);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $checker->staffProfile->id)->update(['valid_from' => now()->subDays(5)->toDateString(), 'valid_until' => now()->subDays(1)->toDateString()]);
        $this->expectException(AuthorizationException::class);
        $service->verify($checker, 'opening', $opening->public_id, $data);
    }

    public function test_fresh_revocation_and_foreign_organisation_are_denied(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $this->unit($opener, $sku);
        $checker = $this->actor('finance_officer');
        $checker->load('roles.permissions');
        $this->selectBranch($checker);
        Role::findByName('finance_officer')->revokePermissionTo(InventoryReadinessService::VERIFY);
        try {
            app(InventoryReadinessService::class)->verify($checker, 'opening', $opening->public_id, $this->data());
            $this->fail('Revoked permission accepted');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('inventory_opening_verifications', 0);
        }
        Role::findByName('finance_officer')->givePermissionTo(InventoryReadinessService::VERIFY);
        $org = $this->organisation->replicate();
        $org->code = 'SYN-OTHER';
        $org->save();
        $foreignBranch = $this->branch->replicate();
        $foreignBranch->organisation_id = $org->id;
        $foreignBranch->save();
        $foreign = $this->actor('finance_officer', $foreignBranch);
        $this->selectBranch($foreign, $foreignBranch);
        $this->expectException(ModelNotFoundException::class);
        app(InventoryReadinessService::class)->verify($foreign, 'opening', $opening->public_id, $this->data(['expected_branch_id' => $foreignBranch->id]));
    }

    public function test_readiness_is_paged_bounded_and_reports_ambiguous_mappings_without_repair(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $opener->organisation_id, 'created_by_user_id' => $opener->id, 'updated_by_user_id' => $opener->id]);
        [$second] = $this->opening($opener);
        $mapping = ['organisation_id' => $opener->organisation_id, 'medicine_catalogue_item_id' => $medicine->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $opener->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('medicine_catalogue_inventory_skus')->insert($mapping);
        try {
            DB::transaction(fn () => DB::table('medicine_catalogue_inventory_skus')->insert([...$mapping, 'inventory_sku_id' => $second->id]));
            $this->fail('PostgreSQL allowed an ambiguous active mapping');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
        }
        for ($i = 0; $i < 30; $i++) {
            $copy = $sku->replicate();
            $copy->public_id = (string) Str::uuid();
            $copy->sku_code = 'SYN-PAGED-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $copy->save();
        }
        $this->selectBranch($opener);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = app(InventoryReadinessService::class)->page($opener, []);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(25, $page['rows']);
        $this->assertSame(32, $page['total']);
        $this->assertLessThanOrEqual(22, count($queries));
        $found = app(InventoryReadinessService::class)->page($opener, ['q' => $sku->sku_code]);
        $this->assertFalse($found['rows'][0]['ambiguous']);
        $this->assertTrue($found['rows'][0]['mapped']);
        $this->assertDatabaseCount('medicine_catalogue_inventory_skus', 1);
        $beyond = app(InventoryReadinessService::class)->page($opener, ['q' => 'SYN-PAGED-0029']);
        $this->assertSame('SYN-PAGED-0029', $beyond['rows'][0]['sku_code']);
        $this->assertNotContains('SYN-PAGED-0029', array_column($page['rows'], 'sku_code'));
        $this->expectException(ValidationException::class);
        InventoryReadinessService::assertUnambiguous($opener->organisation_id, $medicine->id, $second->id);
    }

    public function test_signoff_changes_only_new_evidence_and_audit_and_postgresql_protects_it(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $checker = $this->actor('finance_officer');
        $snapshot = function (): array {
            $rows = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $t) {
                $name = $t->tablename;
                if (in_array($name, ['inventory_unit_contracts', 'inventory_opening_verifications', 'audit_logs'], true)) {
                    continue;
                }
                $columns = array_column(DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', ['public', $name]), 'column_name');
                $query = DB::table($name);
                foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                    $query->orderBy($column);
                }
                $rows[$name] = $query->get()->toJson();
            }

            return $rows;
        };
        $before = $snapshot();
        $this->unit($opener, $sku);
        $this->selectBranch($checker);
        app(InventoryReadinessService::class)->verify($checker, 'opening', $opening->public_id, $this->data());
        $this->assertSame($before, $snapshot());
        $audit = DB::table('audit_logs')->where('event', 'like', 'inventory.readiness.%')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('Synthetic', $audit);
        $this->assertStringNotContainsString('10.125', $audit);
        $this->assertSame('unit', $opening->refresh()->unit_snapshot['base_unit']);
        foreach (['inventory_unit_contracts', 'inventory_opening_verifications'] as $table) {
            foreach (["UPDATE $table SET lock_version=lock_version", "DELETE FROM $table", "TRUNCATE $table"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Evidence mutated');
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0]);
                }
            }
        }
    }

    public function test_pending_openings_are_paged_and_read_access_uses_only_existing_inventory_authority(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $location = InventoryLocation::findOrFail($opening->destination_location_id);
        $original = InventoryBatch::findOrFail($opening->inventory_batch_id);
        for ($i = 0; $i < 26; $i++) {
            $batch = $original->replicate();
            $batch->public_id = (string) Str::uuid();
            $batch->batch_number = 'SYN-PAGE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $batch->save();
            app(InventoryMovementService::class)->openingBalance($opener, ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'location_public_id' => $location->public_id, 'quantity' => '1.000']);
        }
        $service = app(InventoryReadinessService::class);
        $first = $service->page($opener, []);
        $second = $service->page($opener, ['opening_page' => 2]);
        $this->assertCount(25, $first['pending']);
        $this->assertCount(2, $second['pending']);
        $this->assertSame(2, $first['openingLastPage']);
        $this->assertSame([], array_intersect(array_column($first['pending'], 'public_id'), array_column($second['pending'], 'public_id')));
        $this->assertSame(27, $first['rows'][0]['opening_count']);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('inventory.readiness'))->assertStatus($actor->can('inventory.view.branch') ? 200 : 403);
        }
    }

    public function test_opening_audit_failure_rolls_back_without_touching_the_count(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku, $opening] = $this->opening($opener);
        $this->unit($opener, $sku);
        $checker = $this->actor('director');
        $this->selectBranch($checker);
        $before = $opening->refresh()->getAttributes();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturn(null);
        try {
            app(InventoryReadinessService::class)->verify($checker, 'opening', $opening->public_id, $this->data());
            $this->fail('Unaudited opening verification accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_opening_verifications', 0);
        $this->assertSame($before, $opening->fresh()->getAttributes());
    }

    public function test_audit_failure_rolls_back_unit_and_opening_evidence(): void
    {
        $opener = $this->actor('ca_supervisor');
        [$sku,$opening] = $this->opening($opener);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturn(null);
        try {
            $this->unit($opener, $sku);
            $this->fail('Unaudited unit saved');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_unit_contracts', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('10.125', DB::table('inventory_stock_balances')->sole()->quantity);
    }
}
