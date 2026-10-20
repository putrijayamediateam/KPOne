<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ReportCsv;
use App\Domain\Shared\Services\StockMovementReportService;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReportEvidence;

class StockMovementReportTest extends BillingTestCase
{
    use OtcDispensaryFixtures, ReportEvidence;

    private function filters(array $overrides = []): array
    {
        $date = now()->setTimezone($this->branch->timezone)->toDateString();

        return $overrides + ['expected_branch_id' => $this->branch->id, 'from' => $date, 'to' => $date];
    }

    public function test_exact_holders_scope_validation_fresh_revocation_and_replay(): void
    {
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            $allowed = in_array($role, ['director', 'finance_officer', 'administrator'], true);
            $this->assertSame($allowed, in_array('reports.stock_movement.view.branch', $permissions, true));
            $this->assertSame($allowed, in_array('reports.stock_movement.export.branch', $permissions, true));
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->postJson(route('reports.stock-movement.run'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
            $response = $this->postJson(route('reports.stock-movement.download'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        foreach ([['expected_branch_id' => 999999], ['from' => '2026-01-01', 'to' => '2026-02-01'], ['methods' => ['999999']], ['visit_types' => ['foreign']], ['branch' => 'all'], ['treatment' => 'PRIVATE']] as $invalid) {
            $this->postJson(route('reports.stock-movement.run'), $this->filters($invalid))->assertUnprocessable();
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->first();
        $this->postJson(route('reports.stock-movement.download'), $this->filters(['expected_branch_id' => $other->id]))->assertUnprocessable();
        $role = Role::findByName('finance_officer');
        $grant = $role->permissions()->where('name', 'reports.stock_movement.view.branch')->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $grant->id)->delete();
        $this->postJson(route('reports.stock-movement.run'), $this->filters())->assertForbidden();
        $migration = require database_path('migrations/2026_10_21_040000_grant_stock_movement_report_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->postJson(route('reports.stock-movement.run'), $this->filters())->assertOk();
        DB::table('staff_branch_assignments')->where('staff_profile_id', $finance->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->postJson(route('reports.stock-movement.run'), $this->filters())->assertForbidden();
    }

    public function test_audit_failure_cap_csv_safety_and_shared_throttle(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('reports.stock-movement.index'))->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', 'like', 'reports.stock_movement.%')->count());
        $this->mock(AuditRecorder::class)->shouldReceive('record')->twice()->andReturnNull();
        $this->postJson(route('reports.stock-movement.run'), $this->filters())->assertStatus(503);
        $this->postJson(route('reports.stock-movement.download'), $this->filters())->assertStatus(503);
        $this->app->forgetInstance(AuditRecorder::class);
        $large = new class extends StockMovementReportService
        {
            protected function project(User $actor, Branch $branch, array $filters): array
            {
                return ['columns' => ['Value'], 'rows' => array_fill(0, 5001, ['0.00']), 'totals' => []];
            }
        };
        $this->app->instance(StockMovementReportService::class, $large);
        $this->postJson(route('reports.stock-movement.download'), $this->filters())->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->app->forgetInstance(StockMovementReportService::class);
        $this->branch->forceFill(['name' => " \t=FORMULA(), \"Sintetik\" 雪"])->save();
        $csv = $this->postJson(route('reports.stock-movement.download'), $this->filters())->assertOk()->streamedContent();
        [$metadata] = $this->decodeReportCsv($csv);
        $this->assertSame("' \t=FORMULA(), \"Sintetik\" 雪", $metadata['Branch']);
        $this->assertSame('90071992547409.93', ReportCsv::money(9007199254740993));
        // Three download attempts above consumed capacity (failed audit, cap refusal, success).
        for ($i = 0; $i < 7; $i++) {
            $this->postJson(route('reports.stock-movement.download'), $this->filters())->assertOk();
        }
        $this->postJson(route('reports.stock-movement.download'), $this->filters())->assertStatus(429);
        $this->postJson(route('patient-billing-report.download'), $this->filters())->assertStatus(429);
    }

    public function test_exact_quantities_reconcile_to_balances_and_remain_historical_with_no_source_mutations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'Asia/Kuala_Lumpur'));
        $f = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:01:00', 'Asia/Kuala_Lumpur'));
        [, $ca,$visit,$invoice] = $this->finalizedFixture(10000);
        $method = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYNTHETIC', 'name' => 'Synthetic method', 'is_active' => true]);
        $method->save();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => $method->code, 'idempotency_key' => (string) Str::uuid()]);
        $finance = $this->actor('finance_officer');
        $officer = $this->actor('panel_officer');
        $this->selectBranch($finance);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 3000]);
        $svc = app(ResponsibilityService::class);
        $proposal = $svc->propose($finance, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'panel_id' => $panel->id, 'reason' => 'PRIVATE PANEL']);
        $this->selectBranch($officer);
        $svc->approve($officer, $visit, $invoice->refresh(), 'panel', $proposal->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
        app(PanelClaimService::class)->prepare($officer, $proposal->refresh(), ['expected_branch_id' => $this->branch->id]);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $moves = app(InventoryMovementService::class);
        DB::transaction(fn () => $moves->recordPurchaseReceipt($supervisor, $f['location'], $f['sku'], $f['batch'], '5.125', (string) Str::uuid(), 1234));
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->selectBranch($f['ca']);
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        DB::transaction(function () use ($moves, $supervisor, $f): void {
            $moves->recordAdjustment($supervisor, $f['location'], $f['sku'], $f['batch'], 'out', '1.250', (string) Str::uuid());
            $moves->recordAdjustment($supervisor, $f['location'], $f['sku'], $f['batch'], 'in', '0.375', (string) Str::uuid());
            $moves->recordStocktakeVariance($supervisor, $f['location'], $f['sku'], $f['batch'], '0.500', (string) Str::uuid());
            $moves->recordStocktakeVariance($supervisor, $f['location'], $f['sku'], $f['batch'], '-0.250', (string) Str::uuid());
            $moves->recordTransferDispatch($supervisor, $f['location'], $f['sku'], $f['batch'], '1.000', (string) Str::uuid());
            $moves->recordTransferReceipt($supervisor, $f['location'], $f['sku'], $f['batch'], '0.250', (string) Str::uuid());
        });
        $extra = $f['batch']->replicate();
        $extra->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => 'SYNTHETIC-EXTRA'])->save();
        $this->selectBranch($supervisor);
        $moves->openingBalance($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $extra->public_id, 'quantity' => '0.500']);
        $store = $f['location']->replicate();
        $store->forceFill(['public_id' => (string) Str::uuid(), 'code' => 'SYN-STORE', 'type' => InventoryLocation::TYPE_BRANCH_STORE])->save();
        $moves->transfer($supervisor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $f['location']->public_id, 'destination_location_public_id' => $store->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '2.000']);
        $other = $f['location']->replicate();
        $other->forceFill(['public_id' => (string) Str::uuid(), 'code' => 'OTHER-BRANCH', 'branch_id' => Branch::where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->first()->id])->save();
        DB::transaction(fn () => $moves->recordTransferReceipt($supervisor, $other, $f['sku'], $f['batch'], '1.000', (string) Str::uuid()));
        $balance = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')->where('l.branch_id', $this->branch->id)->where('b.inventory_sku_id', $f['sku']->id)->sum('b.quantity');
        $this->assertSame('12.250', $balance);
        $f['location']->forceFill(['is_active' => false])->save();
        // A future receipt changes the live balance but cannot change the earlier report.
        $this->travelTo(CarbonImmutable::parse('2027-01-02 00:00:00', 'Asia/Kuala_Lumpur'));
        DB::transaction(fn () => $moves->recordPurchaseReceipt($supervisor, $store, $f['sku'], $f['batch'], '1.000', (string) Str::uuid()));
        $this->selectBranch($finance);
        $filters = $this->filters(['from' => '2027-01-01', 'to' => '2027-01-01']);
        $before = $this->sourceEvidence();
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $result = $this->postJson(route('reports.stock-movement.run'), $filters)->assertOk()->json('result');
        $this->assertSame(['Synthetic stock item', $f['sku']->sku_code, 'unit', '10.000', '5.125', '2.000', '-0.125', '-0.750', '12.250', 'Yes'], $result['rows'][0]);
        $this->assertSame($balance, $result['rows'][0][8]);
        $this->assertSame($before, $this->sourceEvidence());
        $csv = $this->postJson(route('reports.stock-movement.download'), $filters)->assertOk()->streamedContent();
        [$metadata,$columns,$rows] = $this->decodeReportCsv($csv);
        $this->assertSame($result['rows'], $rows);
        $this->assertSame($result['columns'], $columns);
        $this->assertSame($before, $this->sourceEvidence());
        foreach (['PRIVATE', $visit->patient->full_name, $invoice->invoice_number, $f['batch']->batch_number, 'unit_cost_sen', 'supplier_id'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $csv);
        }
        $this->assertSame($audits->count() + 2, DB::table('audit_logs')->count());
        $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
        $this->postJson(route('reports.stock-movement.run'), $this->filters(['from' => '2026-12-31', 'to' => '2026-12-31']))->assertOk()->assertJsonPath('result.rows.0.3', '0.000')->assertJsonPath('result.rows.0.6', '10.000')->assertJsonPath('result.rows.0.8', '10.000');
    }
}
