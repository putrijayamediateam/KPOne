<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\PanelSummaryReportService;
use App\Domain\Shared\Services\ReportCsv;
use App\Domain\Visit\Billing\Services\PanelClaimService;
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

class PanelSummaryReportTest extends BillingTestCase
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
            $allowed = in_array($role, ['director', 'finance_officer', 'panel_officer'], true);
            $this->assertSame($allowed, in_array('reports.panel_summary.view.branch', $permissions, true));
            $this->assertSame($allowed, in_array('reports.panel_summary.export.branch', $permissions, true));
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->postJson(route('reports.panel-summary.run'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
            $response = $this->postJson(route('reports.panel-summary.download'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        foreach ([['expected_branch_id' => 999999], ['from' => '2026-01-01', 'to' => '2026-02-01'], ['methods' => ['999999']], ['visit_types' => ['foreign']], ['branch' => 'all'], ['treatment' => 'PRIVATE']] as $invalid) {
            $this->postJson(route('reports.panel-summary.run'), $this->filters($invalid))->assertUnprocessable();
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->first();
        $this->postJson(route('reports.panel-summary.download'), $this->filters(['expected_branch_id' => $other->id]))->assertUnprocessable();
        $role = Role::findByName('finance_officer');
        $grant = $role->permissions()->where('name', 'reports.panel_summary.view.branch')->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $grant->id)->delete();
        $this->postJson(route('reports.panel-summary.run'), $this->filters())->assertForbidden();
        $migration = require database_path('migrations/2026_10_21_030000_grant_panel_summary_report_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->postJson(route('reports.panel-summary.run'), $this->filters())->assertOk();
        DB::table('staff_branch_assignments')->where('staff_profile_id', $finance->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->postJson(route('reports.panel-summary.run'), $this->filters())->assertForbidden();
    }

    public function test_audit_failure_cap_csv_safety_and_shared_throttle(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('reports.panel-summary.index'))->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', 'like', 'reports.panel_summary.%')->count());
        $this->mock(AuditRecorder::class)->shouldReceive('record')->twice()->andReturnNull();
        $this->postJson(route('reports.panel-summary.run'), $this->filters())->assertStatus(503);
        $this->postJson(route('reports.panel-summary.download'), $this->filters())->assertStatus(503);
        $this->app->forgetInstance(AuditRecorder::class);
        $large = new class extends PanelSummaryReportService
        {
            protected function project(User $actor, Branch $branch, array $filters): array
            {
                return ['columns' => ['Value'], 'rows' => array_fill(0, 5001, ['0.00']), 'totals' => []];
            }
        };
        $this->app->instance(PanelSummaryReportService::class, $large);
        $this->postJson(route('reports.panel-summary.download'), $this->filters())->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->app->forgetInstance(PanelSummaryReportService::class);
        $this->branch->forceFill(['name' => " \t=FORMULA(), \"Sintetik\" 雪"])->save();
        $csv = $this->postJson(route('reports.panel-summary.download'), $this->filters())->assertOk()->streamedContent();
        [$metadata] = $this->decodeReportCsv($csv);
        $this->assertSame("' \t=FORMULA(), \"Sintetik\" 雪", $metadata['Branch']);
        $this->assertSame('90071992547409.93', ReportCsv::money(9007199254740993));
        // Three download attempts above consumed capacity (failed audit, cap refusal, success).
        for ($i = 0; $i < 7; $i++) {
            $this->postJson(route('reports.panel-summary.download'), $this->filters())->assertOk();
        }
        $this->postJson(route('reports.panel-summary.download'), $this->filters())->assertStatus(429);
        $this->postJson(route('patient-billing-report.download'), $this->filters())->assertStatus(429);
    }

    public function test_current_claim_measures_and_each_age_boundary_are_aggregate_only_and_leave_all_evidence_unchanged(): void
    {
        $origin = CarbonImmutable::parse('2026-01-01 10:00:00', 'Asia/Kuala_Lumpur');
        $this->travelTo($origin);
        $first = $this->finalizedFixture(10000);
        $officer = $this->actor('panel_officer');
        $finance = $this->actor('finance_officer');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic Panel']);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 10000]);
        $svc = app(ResponsibilityService::class);
        $claims = app(PanelClaimService::class);
        foreach ([10000, 4000, 2000, 5000] as $i => $amount) {
            [$doctor,$ca,$visit,$invoice] = $i === 0 ? $first : $this->anotherReportInvoice();
            $this->selectBranch($finance);
            $proposal = $svc->propose($finance, $visit, $invoice, 'panel', ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'panel_id' => $panel->id, 'member_reference' => 'PRIVATE MEMBER', 'reason' => 'PRIVATE RESPONSIBILITY']);
            $this->selectBranch($officer);
            $svc->approve($officer, $visit, $invoice->refresh(), 'panel', $proposal->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
            if ($i === 1) {
                continue;
            }
            $claim = $claims->prepare($officer, $proposal->refresh(), ['expected_branch_id' => $this->branch->id]);
            if ($i === 2) {
                continue;
            }
            $claim = $claims->submit($officer, $claim, ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version]);
            $claim = $claims->recordDecision($officer, $claim, ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version, 'decision' => $i === 0 ? 'partially_accepted' : 'rejected', 'accepted_amount_sen' => $i === 0 ? 8000 : 0, 'note' => 'PRIVATE DECISION']);
            if ($i === 0) {
                $claims->recordPayment($officer, $claim, ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'received_date' => '2026-01-01', 'amount_sen' => 3000, 'reference' => 'SYNTHETIC']);
            }
        }
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->selectBranch($finance);
        $filters = $this->filters(['from' => '2026-01-01', 'to' => '2026-01-01']);
        $before = $this->sourceEvidence();
        $oldAudits = DB::table('audit_logs')->orderBy('id')->get();
        foreach ([0 => 0, 30 => 0, 31 => 1, 60 => 1, 61 => 2, 90 => 2, 91 => 3] as $age => $bucket) {
            $this->travelTo($origin->addDays($age));
            $result = $this->postJson(route('reports.panel-summary.run'), $filters)->assertOk()->json('result');
            $row = $result['rows'][0];
            $this->assertSame(['Synthetic Panel', '4', '210.00', '2', '150.00', '2', '60.00', '80.00', '50.00', '30.00', '50.00'], array_slice($row, 0, 11));
            $this->assertSame('50.00', $row[11 + $bucket]);
            $this->assertSame('60.00', $row[15 + $bucket]);
            for ($j = 0; $j < 4; $j++) {
                $this->assertSame($j === $bucket ? '50.00' : '0.00', $row[11 + $j]);
                $this->assertSame($j === $bucket ? '60.00' : '0.00', $row[15 + $j]);
            }
            $this->assertSame($before, $this->sourceEvidence());
            $csv = $this->postJson(route('reports.panel-summary.download'), $filters)->assertOk()->streamedContent();
            [$metadata,$columns,$rows] = $this->decodeReportCsv($csv);
            $this->assertSame($result['rows'], $rows);
            $this->assertSame($result['columns'], $columns);
            $this->assertSame($before, $this->sourceEvidence());
            foreach (['PRIVATE', $visit->patient->full_name, $visit->patient->patient_number, $invoice->invoice_number] as $excluded) {
                $this->assertStringNotContainsString($excluded, $csv);
            }
        }
        $this->assertSame($oldAudits->count() + 14, DB::table('audit_logs')->count());
        $this->assertSame($oldAudits->toJson(), DB::table('audit_logs')->where('id', '<=', $oldAudits->last()->id)->orderBy('id')->get()->toJson());
        $this->assertSame(['dataset', 'branch_id', 'from', 'to', 'methods', 'visit_types', 'page', 'row_count'], array_keys(AuditLog::query()->latest('id')->first()->metadata));
        // A resubmission does not reset the first-submission ageing date.
        $this->selectBranch($officer);
        $claim = $claims->submit($officer, $claim->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version]);
        $claims->recordDecision($officer, $claim, ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version, 'decision' => 'accepted', 'accepted_amount_sen' => 5000, 'note' => 'PRIVATE RESUBMISSION']);
        $this->selectBranch($finance);
        $before = $this->sourceEvidence();
        $this->postJson(route('reports.panel-summary.run'), $filters)->assertOk()->assertJsonPath('result.rows.0.14', '100.00')->assertJsonPath('result.rows.0.11', '0.00');
        $this->assertSame($before, $this->sourceEvidence());
    }
}
