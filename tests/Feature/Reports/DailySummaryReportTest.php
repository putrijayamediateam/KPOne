<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\DailySummaryReportService;
use App\Domain\Shared\Services\ReportCsv;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
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

class DailySummaryReportTest extends BillingTestCase
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
            $this->assertSame($allowed, in_array('reports.daily_summary.view.branch', $permissions, true));
            $this->assertSame($allowed, in_array('reports.daily_summary.export.branch', $permissions, true));
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->postJson(route('reports.daily-summary.run'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
            $response = $this->postJson(route('reports.daily-summary.download'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        foreach ([['expected_branch_id' => 999999], ['from' => '2026-01-01', 'to' => '2026-02-01'], ['methods' => ['999999']], ['visit_types' => ['foreign']], ['branch' => 'all'], ['treatment' => 'PRIVATE']] as $invalid) {
            $this->postJson(route('reports.daily-summary.run'), $this->filters($invalid))->assertUnprocessable();
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->first();
        $this->postJson(route('reports.daily-summary.download'), $this->filters(['expected_branch_id' => $other->id]))->assertUnprocessable();
        $role = Role::findByName('finance_officer');
        $grant = $role->permissions()->where('name', 'reports.daily_summary.view.branch')->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $grant->id)->delete();
        $this->postJson(route('reports.daily-summary.run'), $this->filters())->assertForbidden();
        $migration = require database_path('migrations/2026_10_21_020000_grant_daily_summary_report_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->postJson(route('reports.daily-summary.run'), $this->filters())->assertOk();
        DB::table('staff_branch_assignments')->where('staff_profile_id', $finance->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->postJson(route('reports.daily-summary.run'), $this->filters())->assertForbidden();
    }

    public function test_two_date_bases_reversals_classification_filters_and_full_source_invariance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'Asia/Kuala_Lumpur'));
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $cash = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'CASH', 'name' => 'Synthetic cash', 'classification' => 'cash', 'is_active' => true]);
        $cash->save();
        $card = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'CARD', 'name' => 'Synthetic card', 'classification' => null, 'is_active' => true]);
        $card->save();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:01:00', 'Asia/Kuala_Lumpur'));
        $pay = fn ($method, $amount) => app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'method' => $method, 'idempotency_key' => (string) Str::uuid()]);
        $reversed = $pay('CASH', 2500);
        $pay('CARD', 4000);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $reversed, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $reversed->lock_version, 'recording_error_only' => true, 'reason' => 'PRIVATE REVERSAL']);
        $card->forceFill(['is_active' => false])->save();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $service = app(ResponsibilityService::class);
        $officer = $this->actor('panel_officer');
        foreach (['panel' => [$officer, 3000], 'deferment' => [$finance, 2000]] as $capability => [$approver, $amount]) {
            DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $approver->id, 'capability' => $capability, 'limit_sen' => $amount]);
            $this->selectBranch($finance);
            $proposal = $service->propose($finance, $visit, $invoice->refresh(), $capability, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'panel_id' => $panel->id, 'due_date' => '2027-01-05', 'reason' => 'PRIVATE RESPONSIBILITY']);
            $this->selectBranch($approver);
            $service->approve($approver, $visit, $invoice->refresh(), $capability, $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
            if ($capability === 'panel') {
                app(PanelClaimService::class)->prepare($officer, $proposal->refresh(), ['expected_branch_id' => $visit->branch_id]);
            }
        }
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->selectBranch($finance);
        $filters = $this->filters(['from' => '2026-12-31', 'to' => '2027-01-02']);
        $before = $this->sourceEvidence();
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $result = $this->postJson(route('reports.daily-summary.run'), $filters)->assertOk()->json('result');
        $this->assertSame(['2026-12-31', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '30.00', '20.00', '0.00', '0.00', '0.00'], $result['rows'][0]);
        $this->assertSame(['2027-01-01', '25.00', '25.00', '0.00', '40.00', '0.00', '40.00', '0.00', '0.00', '65.00', '25.00', '40.00'], $result['rows'][1]);
        $this->assertSame(array_fill(1, 11, '0.00'), array_combine(range(1, 11), array_slice($result['rows'][2], 1)));
        $this->assertSame($before, $this->sourceEvidence());
        $csv = $this->postJson(route('reports.daily-summary.download'), $filters)->assertOk()->streamedContent();
        [$metadata, $columns, $rows] = $this->decodeReportCsv($csv);
        $this->assertSame($result['rows'], $rows);
        $this->assertSame($result['columns'], $columns);
        $this->assertSame($before, $this->sourceEvidence());
        $this->assertSame($audits->count() + 2, DB::table('audit_logs')->count());
        $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
        $this->assertSame(['dataset', 'branch_id', 'from', 'to', 'methods', 'visit_types', 'page', 'row_count'], array_keys(AuditLog::query()->latest('id')->first()->metadata));
        foreach (['PRIVATE', $visit->patient->full_name, $visit->patient->patient_number, $invoice->invoice_number] as $excluded) {
            $this->assertStringNotContainsString($excluded, $csv);
        }
        $filtered = $this->postJson(route('reports.daily-summary.run'), $filters + ['methods' => [(string) $cash->id]])->assertOk()->json('result.rows');
        $this->assertSame('30.00', $filtered[0][7]);
        $this->assertSame('20.00', $filtered[0][8]);
        $this->assertSame('0.00', $filtered[1][6]);
        $this->postJson(route('reports.daily-summary.run'), $filters + ['visit_types' => ['otc']])->assertOk()->assertJsonPath('result.rows.0.7', '0.00')->assertJsonPath('result.rows.1.11', '0.00');
        $card->forceFill(['classification' => 'cash'])->save();
        $this->postJson(route('reports.daily-summary.run'), $filters)->assertOk()->assertJsonPath('result.rows.1.1', '65.00')->assertJsonPath('result.rows.1.3', '40.00')->assertJsonPath('result.rows.1.6', '0.00')->assertJsonPath('result.rows.1.11', '40.00');
    }

    public function test_audit_failure_cap_csv_safety_and_shared_throttle(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('reports.daily-summary.index'))->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', 'like', 'reports.daily_summary.%')->count());
        $this->mock(AuditRecorder::class)->shouldReceive('record')->twice()->andReturnNull();
        $this->postJson(route('reports.daily-summary.run'), $this->filters())->assertStatus(503);
        $this->postJson(route('reports.daily-summary.download'), $this->filters())->assertStatus(503);
        $this->app->forgetInstance(AuditRecorder::class);
        $large = new class extends DailySummaryReportService
        {
            protected function project(User $actor, Branch $branch, array $filters): array
            {
                return ['columns' => ['Value'], 'rows' => array_fill(0, 5001, ['0.00']), 'totals' => []];
            }
        };
        $this->app->instance(DailySummaryReportService::class, $large);
        $this->postJson(route('reports.daily-summary.download'), $this->filters())->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->app->forgetInstance(DailySummaryReportService::class);
        $this->branch->forceFill(['name' => " \t=FORMULA(), \"Sintetik\" 雪"])->save();
        $csv = $this->postJson(route('reports.daily-summary.download'), $this->filters())->assertOk()->streamedContent();
        [$metadata] = $this->decodeReportCsv($csv);
        $this->assertSame("' \t=FORMULA(), \"Sintetik\" 雪", $metadata['Branch']);
        $this->assertSame('90071992547409.93', ReportCsv::money(9007199254740993));
        // Three download attempts above consumed capacity (failed audit, cap refusal, success).
        for ($i = 0; $i < 7; $i++) {
            $this->postJson(route('reports.daily-summary.download'), $this->filters())->assertOk();
        }
        $this->postJson(route('reports.daily-summary.download'), $this->filters())->assertStatus(429);
        $this->postJson(route('patient-billing-report.download'), $this->filters())->assertStatus(429);
    }
}
