<?php

namespace Tests\Feature\Insights;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\ReportCsv;
use App\Domain\Shared\Services\SalesSummaryExportService;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;

class SalesSummaryExportTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    private function url(array $filters = []): string
    {
        return route('insights.sales-summary.download', $filters + ['from' => '2026-10-01', 'to' => '2026-10-01', 'branch' => 'all']);
    }

    private function csv(string $text): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($text, 3));
        rewind($stream);
        $headings = fgetcsv($stream, escape: '');
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_combine($headings, $row);
        }
        fclose($stream);

        return $rows;
    }

    private function evidence(): array
    {
        $result = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if (preg_match('/^(invoices|invoice_|payments|payment_|panel_|coverage_|receivables|stock_movements|inventory_stock_balances|dispensary_|treatment_plan|consultation_|visits|clinical_)/', $table->tablename)) {
                $result[$table->tablename] = DB::table($table->tablename)->orderBy('id')->get()->toJson();
            }
        }

        return $result;
    }

    public function test_default_holders_and_additive_migration_preserve_unrelated_grants(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(SalesSummaryExportService::PERMISSION, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['administrator', 'director', 'finance_officer'], $holders);
        $role = Role::findByName('administrator');
        $role->revokePermissionTo(SalesSummaryExportService::PERMISSION);
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $migration = require database_path('migrations/2026_10_20_000200_grant_sales_summary_export_permission.php');
        $migration->up();
        $once = $role->permissions()->pluck('name')->sort()->values()->all();
        $migration->up();
        $migration->down();
        $this->assertSame($once, $role->permissions()->pluck('name')->sort()->values()->all());
        $this->assertSame([], array_values(array_diff($before, $once)));
        $this->assertContains(SalesSummaryExportService::PERMISSION, $once);
    }

    public function test_every_role_is_checked_and_administrator_only_receives_aggregates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Asia/Kuala_Lumpur'));
        [, , $visit] = $this->finalizedFixture();
        $private = $visit->patient->full_name;
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->get($this->url());
            if (in_array($role, ['director', 'finance_officer', 'administrator'], true)) {
                $response->assertOk();
                $text = $response->streamedContent();
                $this->assertStringNotContainsString($private, $text);
                $this->assertStringNotContainsString($visit->patient->patient_number, $text);
                $this->assertCount(11, $this->csv($text));
            } else {
                $response->assertForbidden();
            }
            if ($role === 'administrator') {
                $this->assertFalse($actor->can('patients.view.organisation'));
                $this->assertFalse($actor->can('billing.print.branch'));
            }
        }
    }

    public function test_screen_and_csv_match_with_date_boundary_adjustments_reversal_and_unadjusted_invoice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 23:55', 'Asia/Kuala_Lumpur'));
        [$doctor, , $visit, $invoice] = $this->finalizedFixture(10000);
        $makeNext = function (): array {
            [$doctor, $ca, $visit, $queue] = $this->servingFixture();
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
            $this->selectBranch($ca);
            $builder = app(BillingBuilderService::class);
            $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
            $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);

            return [$visit, $invoice];
        };
        [$secondVisit, $second] = $makeNext();
        $makeNext(); // No adjustment.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 00:05', 'Asia/Kuala_Lumpur'));
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $add = function ($visit, $invoice, string $direction, int $amount) use ($actor) {
            return app(InvoiceAdjustmentService::class)->add($actor, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => $direction, 'input_type' => 'fixed', 'input_value' => $amount, 'confirm_above_limit' => true, 'reason' => 'SYNTHETIC-PRIVATE-REASON', 'idempotency_key' => (string) Str::uuid()]);
        };
        $add($visit, $invoice, 'deduct', 1000);
        $adjustment = $add($secondVisit, $second, 'add', 2000);
        $reverser = $this->actor('finance_officer');
        $this->selectBranch($reverser);
        app(InvoiceAdjustmentService::class)->reverse($reverser, $secondVisit, $second->refresh(), $adjustment->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $second->lock_version, 'reason' => 'SYNTHETIC-PRIVATE-REVERSAL', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($actor);
        $add($secondVisit, $second, 'add', 500);
        $summary = app(ClinicInsightsReportService::class)->report($actor, 'sales', 'all', '2026-10-01', '2026-10-01', null)['report']['summary'];
        $this->get(route('insights.report', ['section' => 'sales', 'from' => '2026-10-01', 'to' => '2026-10-01']))->assertInertia(fn (Assert $p) => $p->where('data.report.summary', $summary)->where('summaryDownloadUrl', $this->url()));
        $rows = $this->csv($this->get($this->url())->assertOk()->streamedContent());
        $current = collect($rows)->where('Period', 'current')->pluck('Value', 'Metric')->all();
        $this->assertSame('300.00', $current['Gross item sales']);
        $this->assertSame('10.00', $current['Invoice discounts']);
        $this->assertSame('5.00', $current['Invoice surcharges']);
        $this->assertSame('295.00', $current['Net invoice sales']);
        foreach (['New net sales' => 'newSen', 'Returning net sales' => 'returningSen', 'Net sales per patient' => 'perPatientSen'] as $metric => $key) {
            $this->assertSame(ReportCsv::money($summary[$key]), $current[$metric]);
        }
        $this->assertSame((string) $summary['patients'], $current['Patients']);
        $this->assertSame('0.00', collect($this->csv($this->get($this->url(['from' => '2026-10-02', 'to' => '2026-10-02']))->streamedContent()))->where('Period', 'current')->firstWhere('Metric', 'Net invoice sales')['Value']);
        $comparison = $this->csv($this->get($this->url(['from' => '2026-10-02', 'to' => '2026-10-02']))->streamedContent());
        $this->assertSame('295.00', collect($comparison)->where('Period', 'previous')->firstWhere('Metric', 'Net invoice sales')['Value']);
        $filtered = $this->csv($this->get($this->url(['doctor' => $doctor->id]))->streamedContent());
        $this->assertSame('90.00', collect($filtered)->where('Period', 'current')->firstWhere('Metric', 'Net invoice sales')['Value']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', json_encode($rows));
    }

    public function test_fresh_revocation_scope_validation_rate_limit_and_audit_failure(): void
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $this->get($this->url(['branch' => 'PUCHONG']))->assertOk(); // Organisation scope, not assignment-only.
        $this->get($this->url(['branch' => 'FOREIGN']))->assertNotFound();
        $this->get($this->url(['from' => '2026-10-03']))->assertSessionHasErrors('to');
        $this->get($this->url(['from' => '2024-01-01', 'to' => '2026-10-01']))->assertStatus(422);
        $this->assertTrue($actor->can(SalesSummaryExportService::PERMISSION));
        $role = Role::findByName('finance_officer');
        $permission = $role->permissions()->where('name', SalesSummaryExportService::PERMISSION)->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
        $this->get($this->url())->assertForbidden();
        $role->givePermissionTo($permission);
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->once()->andReturnNull());
        $this->get($this->url())->assertStatus(503);
    }

    public function test_foreign_organisation_branches_are_excluded_and_cannot_be_selected(): void
    {
        $foreign = new Organisation;
        $foreign->forceFill(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic other organisation', 'is_active' => true])->save();
        $foreignBranch = new Branch;
        $foreignBranch->forceFill(['organisation_id' => $foreign->id, 'code' => 'FOREIGN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $this->selectBranch($this->actor('administrator'));
        $this->get($this->url(['branch' => 'FOREIGN']))->assertNotFound();
        $text = $this->get($this->url())->assertOk()->streamedContent();
        $this->assertStringNotContainsString('FOREIGN', $text);
        $this->assertStringNotContainsString('Synthetic other', $text);
        $this->selectBranch($this->actor('administrator', $foreignBranch), $foreignBranch);
        $this->get($this->url(['branch' => 'CHERAS']))->assertNotFound();
    }

    public function test_downloads_are_throttled_across_filter_variations(): void
    {
        $this->selectBranch($this->actor('administrator'));
        for ($i = 0; $i < 10; $i++) {
            $this->get($this->url(['doctor' => null]))->assertOk();
        }
        $this->get($this->url(['branch' => 'CHERAS']))->assertStatus(429);
    }

    public function test_financial_stock_and_populated_claim_rows_are_identical_except_the_export_audit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Asia/Kuala_Lumpur'));
        [$officer, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        app(PanelClaimService::class)->prepare($officer, $allocation, ['expected_branch_id' => $visit->branch_id]);
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '1000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->assertGreaterThan(0, DB::table('panel_claims')->count());
        $this->assertGreaterThan(0, DB::table('stock_movements')->count());
        $this->selectBranch($this->actor('administrator'));
        $before = $this->evidence();
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $this->get($this->url())->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertSame($before, $this->evidence());
        $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
        $this->assertSame($audits->count() + 1, DB::table('audit_logs')->count());
        $this->assertSame(['dataset' => 'sales_summary', 'branch_scope' => 'all', 'from' => '2026-10-01', 'to' => '2026-10-01', 'doctor_filter' => false, 'row_count' => 11], AuditLog::query()->latest('id')->first()->metadata);
    }

    private function approvedPanelFixture(): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $this->selectBranch($ca, $this->branch);
        $allocation = app(ResponsibilityService::class)->propose($ca, $visit, $invoice, 'panel', [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => 3000,
            'reason' => 'Synthetic Panel responsibility for tests',
            'panel_id' => $panel->id,
            'member_reference' => 'SYNTHETIC-TEST-REF',
        ]);
        $panelOfficer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert([
            'organisation_id' => $visit->organisation_id,
            'branch_id' => $visit->branch_id,
            'user_id' => $panelOfficer->id,
            'capability' => 'panel',
            'limit_sen' => 4000,
        ]);
        $this->selectBranch($panelOfficer, $this->branch);
        app(ResponsibilityService::class)->approve($panelOfficer, $visit, $invoice, 'panel', $allocation->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version,
        ]);

        return [$panelOfficer, $visit, $invoice->refresh(), $allocation->refresh()];
    }
}
