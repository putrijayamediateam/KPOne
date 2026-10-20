<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\ReportCsv;
use App\Domain\Shared\Services\TransactionReportService;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\InvoiceBillToService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\InvoiceNoteService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReportEvidence;

class TransactionReportTest extends BillingTestCase
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
            $allowed = in_array($role, ['director', 'finance_officer'], true);
            $this->assertSame($allowed, in_array('reports.transactions.view.branch', $permissions, true));
            $this->assertSame($allowed, in_array('reports.transactions.export.branch', $permissions, true));
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->postJson(route('reports.transactions.run'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
            $response = $this->postJson(route('reports.transactions.download'), $this->filters());
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        foreach ([['expected_branch_id' => 999999], ['from' => 'bad'], ['from' => '2026-01-01', 'to' => '2026-02-01'], ['methods' => ['999999']], ['visit_types' => ['foreign']], ['branch' => 'all'], ['treatment' => 'PRIVATE']] as $invalid) {
            $this->postJson(route('reports.transactions.run'), $this->filters($invalid))->assertUnprocessable();
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->first();
        $this->postJson(route('reports.transactions.download'), $this->filters(['expected_branch_id' => $other->id]))->assertUnprocessable();
        $role = Role::findByName('finance_officer');
        $grant = $role->permissions()->where('name', 'reports.transactions.view.branch')->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $grant->id)->delete();
        $this->postJson(route('reports.transactions.run'), $this->filters())->assertForbidden();
        $migration = require database_path('migrations/2026_10_21_050000_grant_transaction_report_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->postJson(route('reports.transactions.run'), $this->filters())->assertOk();
        $export = $role->permissions()->where('name', TransactionReportService::EXPORT)->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $export->id)->delete();
        $this->postJson(route('reports.transactions.run'), $this->filters())->assertOk();
        $this->postJson(route('reports.transactions.download'), $this->filters())->assertForbidden();
        $migration->up();
        $otherOrg = Organisation::query()->create(['code' => 'TX_OTHER', 'name' => 'Synthetic Other', 'is_active' => true]);
        $foreign = (new Branch)->forceFill(['organisation_id' => $otherOrg->id, 'code' => 'TX_FOREIGN', 'name' => 'Synthetic foreign', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreign->save();
        $this->postJson(route('reports.transactions.run'), $this->filters(['expected_branch_id' => $foreign->id]))->assertUnprocessable();
        session(['active_branch_id' => $foreign->id]);
        $this->postJson(route('reports.transactions.download'), $this->filters(['expected_branch_id' => $foreign->id]))->assertUnprocessable();
        $this->selectBranch($finance);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $finance->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->postJson(route('reports.transactions.run'), $this->filters())->assertForbidden();
    }

    public function test_audit_failure_cap_csv_safety_and_shared_throttle(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('reports.transactions.index'))->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', 'like', 'reports.transactions.%')->count());
        $this->mock(AuditRecorder::class)->shouldReceive('record')->twice()->andReturnNull();
        $this->postJson(route('reports.transactions.run'), $this->filters())->assertStatus(503);
        $this->postJson(route('reports.transactions.download'), $this->filters())->assertStatus(503);
        $this->app->forgetInstance(AuditRecorder::class);
        $large = new class extends TransactionReportService
        {
            protected function query(User $actor, Branch $branch, array $filters): Builder
            {
                return Payment::query()->fromRaw('(select generate_series(1,5001) as id) as payments');
            }
        };
        $this->app->instance(TransactionReportService::class, $large);
        $this->postJson(route('reports.transactions.download'), $this->filters())->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->app->forgetInstance(TransactionReportService::class);
        $this->branch->forceFill(['name' => " \t=FORMULA(), \"Sintetik\" 雪"])->save();
        $csv = $this->postJson(route('reports.transactions.download'), $this->filters())->assertOk()->streamedContent();
        [$metadata] = $this->decodeReportCsv($csv);
        $this->assertSame("' \t=FORMULA(), \"Sintetik\" 雪", $metadata['Branch']);
        $this->assertSame('90071992547409.93', ReportCsv::money(9007199254740993));
        // Three download attempts above consumed capacity (failed audit, cap refusal, success).
        for ($i = 0; $i < 7; $i++) {
            $this->postJson(route('reports.transactions.download'), $this->filters())->assertOk();
        }
        $this->postJson(route('reports.transactions.download'), $this->filters())->assertStatus(429);
        $this->postJson(route('patient-billing-report.download'), $this->filters())->assertStatus(429);
    }

    public function test_receipt_cohort_matches_aggregate_with_private_sources_unchanged_and_local_new_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'Asia/Kuala_Lumpur'));
        $f = $this->fixture();
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $cash = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYN-CASH', 'name' => 'Synthetic Cash', 'classification' => 'cash', 'is_active' => true]);
        $cash->save();
        $card = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYN-CARD', 'name' => " \t=FORMULA(), \"Kad\" 雪", 'classification' => null, 'is_active' => true]);
        $card->save();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:01:00', 'Asia/Kuala_Lumpur'));
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        app(InvoiceAdjustmentService::class)->add($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => 'add', 'input_type' => 'fixed', 'input_value' => 500, 'confirm_above_limit' => true, 'reason' => 'PRIVATE ADJUSTMENT', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($ca);
        $pay = app(PaymentService::class);
        $first = $pay->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 2500, 'method' => $cash->code, 'idempotency_key' => (string) Str::uuid()]);
        $pay->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'method' => $card->code, 'idempotency_key' => (string) Str::uuid()]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $first, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $first->lock_version, 'recording_error_only' => true, 'reason' => 'PRIVATE REVERSAL']);
        $card->forceFill(['is_active' => false])->save();
        app(InvoiceNoteService::class)->write($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'operation' => 'added', 'body' => 'PRIVATE NOTE']);
        app(InvoiceBillToService::class)->write($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'operation' => 'set', 'recipient' => ['party_type' => 'company', 'display_name' => 'PRIVATE BILL TO']]);
        $officer = $this->actor('panel_officer');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 3000]);
        $svc = app(ResponsibilityService::class);
        $proposal = $svc->propose($finance, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'panel_id' => $panel->id, 'reason' => 'PRIVATE PANEL']);
        $this->selectBranch($officer);
        $svc->approve($officer, $visit, $invoice->refresh(), 'panel', $proposal->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
        app(PanelClaimService::class)->prepare($officer, $proposal->refresh(), ['expected_branch_id' => $this->branch->id]);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $patient = $visit->patient;
        $patient->forceFill(['full_name' => " \t=PATIENT(), \"Sintetik\" 雪"])->save();
        $hidden = $this->patient($ca);
        $hidden->forceFill(['full_name' => 'PRIVATE UNALLOCATED IDENTITY'])->save();
        $this->legacyReceipt($ca, $hidden, $card, 123);
        $this->legacyReceipt($ca, $hidden, $card, 999, Branch::where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->first()->id);
        $this->selectBranch($finance);
        $before = $this->sourceEvidence();
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $filters = $this->filters();
        $response = $this->postJson(route('reports.transactions.run'), $filters)->assertOk();
        $result = $response->json('result');
        $this->assertSame(3, $result['count']);
        $this->assertSame(['66.23', '25.00', '41.23', '0.00', '41.23'], array_values($result['totals']));
        $this->assertSame(['25.00', '25.00', '0.00', 'Reversed (recording reversal)'], array_slice($result['rows'][0], 8));
        $this->assertSame($patient->full_name, $result['rows'][0][3]);
        $this->assertSame('2027-01-01 00:01:00', $result['rows'][0][1]);
        $this->assertSame('Not classified (Non-cash)', $result['rows'][1][7]);
        $this->assertSame(['—', 'Not shown (no invoice allocation)', '—', '—'], array_slice($result['rows'][2], 2, 4));
        $this->assertSame($before, $this->sourceEvidence());
        $download = $this->postJson(route('reports.transactions.download'), $filters)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $csv = $download->streamedContent();
        [$metadata, $columns, $rows] = $this->decodeReportCsv($csv);
        $this->assertSame($result['totals']['Net received (MYR)'], $metadata['Total Net received (MYR)']);
        $this->assertSame('SYN-CARD', $rows[1][6]);
        foreach ($rows as $i => $row) {
            array_splice($row, 6, 1);
            $this->assertSame(array_map(ReportCsv::safeCell(...), $result['rows'][$i]), $row);
        }
        $this->assertSame($before, $this->sourceEvidence());
        $this->assertSame($audits->count() + 2, DB::table('audit_logs')->count());
        $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
        $metadataAudit = AuditLog::query()->latest('id')->first()->metadata;
        $this->assertSame(['dataset', 'branch_id', 'from', 'to', 'methods', 'visit_types', 'page', 'row_count'], array_keys($metadataAudit));
        $this->assertSame(3, $metadataAudit['row_count']);
        foreach (['PRIVATE', 'identity_number', 'phone', 'diagnosis', 'Bill to'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $csv);
        }
        $this->assertStringNotContainsString('PATIENT', json_encode($metadataAudit));
        $aggregate = app(ClinicInsightsReportService::class)->branchReceiptRows($finance, $this->branch, '2027-01-01', '2027-01-01');
        $this->assertSame(4123, array_sum(array_column($aggregate, 'netSen')));
        $this->assertSame($before, $this->sourceEvidence());
        $this->postJson(route('reports.transactions.run'), $this->filters(['from' => '2026-12-31', 'to' => '2026-12-31']))->assertOk()->assertJsonPath('result.count', 0);
        $this->postJson(route('reports.transactions.run'), $this->filters(['methods' => [(string) $cash->id]]))->assertOk()->assertJsonPath('result.count', 1)->assertJsonPath('result.totals.Net received (MYR)', '0.00');
        $this->postJson(route('reports.transactions.run'), $this->filters(['visit_types' => ['consultation']]))->assertOk()->assertJsonPath('result.count', 2);
        $this->assertSame($before, $this->sourceEvidence());
        $card->forceFill(['classification' => 'cash'])->save();
        $afterReferenceChange = $this->sourceEvidence();
        $this->postJson(route('reports.transactions.run'), $this->filters())->assertOk()->assertJsonPath('result.totals.Cash net received (MYR)', '41.23')->assertJsonPath('result.totals.Non-cash net received (MYR)', '0.00')->assertJsonPath('result.totals.Net received (MYR)', '41.23');
        $this->assertSame($afterReferenceChange, $this->sourceEvidence());
    }

    private function legacyReceipt($actor, $patient, PaymentMethod $method, int $amount, ?int $branch = null): void
    {
        (new Payment)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch ?? $this->branch->id, 'patient_id' => $patient->id, 'payment_method_id' => $method->id, 'receipt_number' => 'SYN-'.Str::random(12), 'amount_sen' => $amount, 'currency' => 'MYR', 'method_snapshot' => $method->name, 'status' => 'posted', 'received_at' => now()->utc(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'recorded_by_user_id' => $actor->id, 'lock_version' => 1])->save();
    }

    public function test_full_result_totals_and_stable_twenty_row_pagination_and_voided_receipt_evidence(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $method = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYN-CASH', 'name' => 'Synthetic cash', 'classification' => 'cash', 'is_active' => true]);
        $method->save();
        $payments = [];
        for ($i = 0; $i < 21; $i++) {
            $payments[] = app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1, 'method' => $method->code, 'idempotency_key' => (string) Str::uuid()]);
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $before = $this->sourceEvidence();
        $one = $this->postJson(route('reports.transactions.run'), $this->filters())->assertOk()->json('result');
        $two = $this->postJson(route('reports.transactions.run'), $this->filters(['page' => 2]))->assertOk()->json('result');
        $this->assertCount(20, $one['rows']);
        $this->assertCount(1, $two['rows']);
        $this->assertSame('21', $two['rows'][0][0]);
        $this->assertSame('0.21', $one['totals']['Net received (MYR)']);
        $this->assertSame($one['totals'], $two['totals']);
        $csv = $this->postJson(route('reports.transactions.download'), $this->filters())->assertOk()->streamedContent();
        $this->assertCount(21, $this->decodeReportCsv($csv)[2]);
        $this->assertSame($before, $this->sourceEvidence());
        $this->postJson(route('reports.transactions.run'), $this->filters(['page' => 3]))->assertUnprocessable();
        foreach ($payments as $payment) {
            app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $payment, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $payment->lock_version, 'recording_error_only' => true, 'reason' => 'PRIVATE REVERSAL']);
        }
        app(InvoiceCorrectionService::class)->void($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'reason' => 'PRIVATE VOID']);
        $beforeVoidReport = $this->sourceEvidence();
        $this->postJson(route('reports.transactions.run'), $this->filters())->assertOk()->assertJsonPath('result.count', 21)->assertJsonPath('result.totals.Reversed receipts (MYR)', '0.21')->assertJsonPath('result.totals.Net received (MYR)', '0.00');
        $this->assertSame($beforeVoidReport, $this->sourceEvidence());
    }
}
