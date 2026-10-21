<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentMethodClassificationService;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentMethodClassificationTest extends BillingTestCase
{
    public function test_classification_permissions_scope_version_and_values_free_audit(): void
    {
        $method = $this->method();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->actingAs($actor)->patchJson(route('payment-methods.classification', $method), ['classification' => 'cash', 'lock_version' => $method->refresh()->lock_version]);
            if (in_array($role, ['director', 'finance_officer', 'administrator'], true)) {
                $response->assertRedirect();
            } else {
                $response->assertForbidden();
            }
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->actingAs($finance);
        $this->patchJson(route('payment-methods.classification', $method), ['classification' => 'non_cash', 'lock_version' => 0])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->patchJson(route('payment-methods.classification', $method), ['classification' => 'unknown', 'lock_version' => 1])->assertUnprocessable();
        $this->patchJson(route('payment-methods.classification', $method), ['classification' => 'non_cash', 'lock_version' => 1])->assertRedirect();
        $this->assertSame(2, $method->refresh()->lock_version);
        $audit = AuditLog::query()->where('event', 'payment_method.classification_changed')->latest('id')->first();
        $this->assertSame(['method_id' => $method->id, 'changed_fields' => ['classification']], $audit->metadata);
        $this->assertSame('non_cash', $method->reportClassification());
        $foreign = new Organisation;
        $foreign->forceFill(['code' => 'SYNTHETIC_FOREIGN', 'name' => 'Synthetic foreign', 'is_active' => true])->save();
        DB::table('payment_methods')->insert(['organisation_id' => $foreign->id, 'code' => 'FOREIGN', 'name' => 'Synthetic foreign']);
        $this->patchJson(route('payment-methods.classification', DB::table('payment_methods')->where('code', 'FOREIGN')->value('id')), ['classification' => 'cash', 'lock_version' => 0])->assertNotFound();
        $finance->getAllPermissions();
        DB::table('role_has_permissions')->where('permission_id', DB::table('permissions')->where('name', 'payment_methods.manage.organisation')->value('id'))->delete();
        try {
            app(PaymentMethodClassificationService::class)->change($finance, $method, ['classification' => null, 'lock_version' => 2]);
            $this->fail('Revoked permission was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame('non_cash', $method->refresh()->classification);
        }
    }

    public function test_audit_failure_rolls_back_and_classification_changes_no_source_row(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $method = $this->method();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'method' => $method->code, 'amount_sen' => 1000, 'idempotency_key' => (string) Str::uuid()]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $before = $this->evidence();
        foreach (['cash', 'non_cash', null] as $classification) {
            app(PaymentMethodClassificationService::class)->change($finance, $method->refresh(), ['classification' => $classification, 'lock_version' => $method->lock_version]);
            $this->assertSame($before, $this->evidence());
        }
        $this->assertNull($method->refresh()->classification);
        $this->assertSame('non_cash', $method->reportClassification());
        $version = $method->lock_version;
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andReturnNull();
        $this->actingAs($finance)->patchJson(route('payment-methods.classification', $method), ['classification' => 'cash', 'lock_version' => $version])->assertStatus(503);
        $this->assertNull($method->refresh()->classification);
        $this->assertSame($version, $method->lock_version);
        $this->assertSame($before, $this->evidence());
    }

    public function test_backfill_defaults_and_replay_preserve_later_classification(): void
    {
        $method = $this->method();
        $migration = require database_path('migrations/2026_10_21_010000_add_payment_method_classification.php');
        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT payment_methods_classification_check');
        DB::statement('ALTER TABLE payment_methods DROP COLUMN classification, DROP COLUMN lock_version');
        DB::table('payment_methods')->insert(['organisation_id' => $this->organisation->id, 'code' => 'CASH', 'name' => 'Synthetic retained cash']);
        $migration->up();
        $this->assertSame('cash', DB::table('payment_methods')->where('code', 'CASH')->value('classification'));
        $this->assertSame('non_cash', $method->refresh()->classification);
        // Replay must never reset a later decision.
        DB::table('payment_methods')->where('id', $method->id)->update(['classification' => 'cash']);
        $migration->up();
        $migration->down();
        $this->assertSame('cash', $method->refresh()->classification);

    }

    private function method(): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYNTHETIC_CASH', 'name' => 'Synthetic method', 'is_active' => true, 'requires_reference' => false])->save();

        return $method->refresh();
    }

    private function evidence(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if (in_array($table->tablename, ['payment_methods', 'audit_logs', 'sessions', 'cache', 'cache_locks'], true)) {
                continue;
            }
            $columns = DB::getSchemaBuilder()->getColumnListing($table->tablename);
            $query = DB::table($table->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $snapshot[$table->tablename] = $query->get()->toJson();
        }

        return $snapshot;
    }
}
