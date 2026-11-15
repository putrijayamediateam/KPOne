<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingDirectoryService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\PaymentMethodAdministrationService;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class PaymentMethodAdministrationServiceTest extends BillingTestCase
{
    public function test_permission_is_limited_to_director_and_finance_officer(): void
    {
        $roles = PermissionCatalogue::roles();
        $this->assertContains(PaymentMethodAdministrationService::PERMISSION, PermissionCatalogue::all());
        foreach (['director', 'finance_officer'] as $role) {
            $this->assertContains(PaymentMethodAdministrationService::PERMISSION, $roles[$role]);
        }
        foreach (['ca_supervisor', 'ca', 'resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            $this->assertNotContains(PaymentMethodAdministrationService::PERMISSION, $roles[$role]);
            try {
                $this->service()->create($this->actor($role), ['code' => 'DENY-'.$role, 'name' => 'Denied']);
                $this->fail("{$role} managed Payment Method references.");
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('payment_methods', ['code' => 'DENY-'.strtoupper($role)]);
            }
        }
    }

    public function test_creation_validation_publication_lifecycle_and_audit_are_governed(): void
    {
        $finance = $this->actor('finance_officer');
        $before = $this->sideEffectCounts();
        $method = $this->service()->create($finance, [
            'code' => ' cash ',
            'name' => ' Synthetic   Cash ',
            'description' => 'Synthetic local cash payment',
            'requires_reference' => false,
            'sort_order' => 10,
        ]);

        $this->assertSame('CASH', $method->code);
        $this->assertSame('Synthetic Cash', $method->name);
        $this->assertSame(10, $method->sort_order);
        $this->assertFalse($method->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment_method.created', 'subject_id' => $method->id, 'actor_user_id' => $finance->id]);
        $audit = AuditLog::query()->where('event', 'payment_method.created')->where('subject_id', $method->id)->sole();
        $this->assertSame($finance->organisation_id, $audit->organisation_id);
        $this->assertSame('CASH', $audit->metadata['reference_code']);
        $this->assertSame(['code', 'name', 'description', 'requires_reference', 'sort_order', 'is_active'], $audit->metadata['changed_fields']);

        $method = $this->service()->publish($finance, $method);
        $this->assertTrue($method->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment_method.published', 'subject_id' => $method->id]);
        $method = $this->service()->update($finance, $method, ['name' => 'Cash', 'sort_order' => 20]);
        $this->assertSame('Cash', $method->name);
        $this->assertSame(20, $method->sort_order);
        $method = $this->service()->deactivate($finance, $method);
        $this->assertFalse($method->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment_method.deactivated', 'subject_id' => $method->id]);

        try {
            $this->service()->create($finance, ['code' => ' cash ', 'name' => 'Duplicate']);
            $this->fail('A case-insensitive retained duplicate was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        try {
            $this->service()->create($finance, ['code' => 'CARD']);
            $this->fail('A method without a name was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
        try {
            $method->forceFill(['code' => 'OTHER'])->save();
            $this->fail('Payment Method code was mutable.');
        } catch (LogicException) {
            $this->assertSame('CASH', $method->refresh()->code);
        }
        try {
            $method->delete();
            $this->fail('Payment Method hard deletion was allowed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('payment_methods', ['id' => $method->id]);
        }
        $this->assertSame($before, $this->sideEffectCounts());
    }

    public function test_tenant_isolation_blocks_foreign_management(): void
    {
        $finance = $this->actor('finance_officer');
        $method = $this->service()->create($finance, ['code' => 'CASH', 'name' => 'Cash']);
        $foreignFinance = $this->actor('finance_officer', $this->foreignBranch());

        $this->expectException(ModelNotFoundException::class);
        $this->service()->publish($foreignFinance, $method);
    }

    public function test_only_published_active_tenant_methods_are_projected_and_historical_snapshot_survives_deactivation(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(3500);
        $finance = $this->actor('finance_officer');
        $method = $this->service()->create($finance, [
            'code' => 'CASH',
            'name' => 'Cash',
            'sort_order' => 10,
        ]);
        $directory = app(BillingDirectoryService::class);
        $this->assertSame([], $directory->detail($ca, $visit)['methods']);

        $this->service()->publish($finance, $method);
        $this->assertSame([[
            'code' => 'CASH',
            'name' => 'Cash',
            'requiresReference' => false,
        ]], $directory->detail($ca, $visit)['methods']);

        $foreignFinance = $this->actor('finance_officer', $this->foreignBranch());
        $foreign = $this->service()->create($foreignFinance, ['code' => 'QR', 'name' => 'Foreign QR', 'sort_order' => 1]);
        $this->service()->publish($foreignFinance, $foreign);
        $this->assertCount(1, $directory->detail($ca, $visit)['methods']);

        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => 3500,
            'method' => 'CASH',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertSame('Cash', $payment->method_snapshot);
        $this->service()->deactivate($finance, $method);
        $this->assertSame([], $directory->detail($ca, $visit)['methods']);
        $this->assertSame('Cash', $payment->refresh()->method_snapshot);
    }

    public function test_inactive_foreign_and_unknown_methods_are_rejected_without_financial_mutation(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(3500);
        $finance = $this->actor('finance_officer');
        $inactive = $this->service()->create($finance, ['code' => 'INACTIVE', 'name' => 'Inactive']);
        $foreignFinance = $this->actor('finance_officer', $this->foreignBranch());
        $foreign = $this->service()->create($foreignFinance, ['code' => 'FOREIGN', 'name' => 'Foreign']);
        $this->service()->publish($foreignFinance, $foreign);

        foreach ([$inactive->code, $foreign->code, 'UNKNOWN'] as $code) {
            $before = $this->financialMutationSnapshot($invoice);
            try {
                app(PaymentService::class)->add($ca, $visit, $invoice, [
                    'expected_branch_id' => $visit->branch_id,
                    'lock_version' => $invoice->lock_version,
                    'amount_sen' => 3500,
                    'method' => $code,
                    'idempotency_key' => (string) Str::uuid(),
                ]);
                $this->fail("The rejected {$code} Payment Method was accepted.");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    ['Select an active governed method and its required reference.'],
                    $exception->errors()['method'] ?? [],
                );
                $this->assertSame($before, $this->financialMutationSnapshot($invoice));
            }
        }
    }

    public function test_payment_method_migration_rolls_back_and_reapplies_without_losing_legacy_identity(): void
    {
        $method = new PaymentMethod;
        $method->forceFill([
            'organisation_id' => $this->organisation->id,
            'code' => 'LEGACY',
            'name' => 'Legacy synthetic method',
            'requires_reference' => true,
            'is_active' => true,
        ])->save();
        $migration = require database_path('migrations/2026_09_10_000100_govern_payment_methods.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('payment_methods', 'description'));
        $this->assertFalse(Schema::hasColumn('payment_methods', 'sort_order'));
        $this->assertFalse(Schema::hasIndex('payment_methods', 'payment_methods_operational_order_idx'));
        $this->assertDatabaseHas('payment_methods', [
            'id' => $method->id,
            'organisation_id' => $this->organisation->id,
            'code' => 'LEGACY',
            'name' => 'Legacy synthetic method',
            'requires_reference' => true,
            'is_active' => true,
        ]);

        $migration->up();
        $this->assertTrue(Schema::hasColumn('payment_methods', 'description'));
        $this->assertTrue(Schema::hasColumn('payment_methods', 'sort_order'));
        $this->assertTrue(Schema::hasIndex('payment_methods', 'payment_methods_operational_order_idx'));
        $this->assertDatabaseHas('payment_methods', [
            'id' => $method->id,
            'organisation_id' => $this->organisation->id,
            'code' => 'LEGACY',
            'name' => 'Legacy synthetic method',
            'requires_reference' => true,
            'is_active' => true,
            'description' => null,
            'sort_order' => 100,
        ]);
    }

    /** @return array<string, int> */
    private function sideEffectCounts(): array
    {
        return [
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('payments')->count(),
            'stock_balances' => DB::table('inventory_stock_balances')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'dispensary_cases' => DB::table('dispensary_cases')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function financialMutationSnapshot(Invoice $invoice): array
    {
        $invoice->refresh();

        return [
            'payments' => DB::table('payments')->where('organisation_id', $invoice->organisation_id)->count(),
            'allocations' => DB::table('payment_allocations')->where('organisation_id', $invoice->organisation_id)->count(),
            'receipt_counter' => DB::table('billing_document_counters')->where('organisation_id', $invoice->organisation_id)->where('document_type', 'receipt')->orderBy('id')->get()->toJson(),
            'payment_audits' => DB::table('audit_logs')->where('organisation_id', $invoice->organisation_id)->where('event', 'billing.payment_recorded')->count(),
            'invoice_status' => $invoice->status,
            'invoice_lock_version' => $invoice->lock_version,
            'ledger' => app(FinancialLedger::class)->state($invoice),
        ];
    }

    private function service(): PaymentMethodAdministrationService
    {
        return app(PaymentMethodAdministrationService::class);
    }

    private function foreignBranch(): Branch
    {
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'FOREIGN-'.strtoupper(fake()->unique()->lexify('??????')),
            'name' => 'Synthetic Foreign Organisation',
            'is_active' => true,
        ])->save();
        $branch = new Branch;
        $branch->forceFill([
            'organisation_id' => $organisation->id,
            'code' => 'FOREIGN',
            'name' => 'Synthetic Foreign Branch',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();

        return $branch;
    }
}
