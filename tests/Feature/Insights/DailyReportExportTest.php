<?php

namespace Tests\Feature\Insights;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\DailyReportExportService;
use App\Domain\Shared\Services\ReportCsv;
use App\Domain\Shared\Services\SalesSummaryExportService;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentReconciliationService;
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

class DailyReportExportTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    private function url(string $dataset, array $filters = []): string
    {
        return route('insights.'.($dataset === 'daily_sales' ? 'daily-sales' : 'daily-receipts').'.download', $filters + ['from' => '2026-12-31', 'to' => '2027-01-02', 'branch' => 'all']);
    }

    private function csv(string $text): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $this->assertStringContainsString("\r\n", $text);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($text, 3));
        rewind($stream);
        $metadata = [];
        while (($row = fgetcsv($stream, escape: '')) !== false && $row !== [null]) {
            $metadata[$row[0]] = $row[1];
        }
        $headings = fgetcsv($stream, escape: '');
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_combine($headings, $row);
        }
        fclose($stream);
        $this->assertSame('MYR', $metadata['Currency']);
        $this->assertStringContainsString('Asia/Kuala_Lumpur', $metadata['Branch timezones']);

        return [$metadata, $rows];
    }

    public function test_permissions_are_exact_additive_and_every_role_is_enforced_for_both_exports(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(DailyReportExportService::PAYMENTS_PERMISSION, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['administrator', 'director', 'finance_officer'], $holders);
        $role = Role::findByName('administrator');
        $role->revokePermissionTo(DailyReportExportService::PAYMENTS_PERMISSION);
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $migration = require database_path('migrations/2026_10_20_000300_grant_payments_export_permission.php');
        $migration->up();
        $once = $role->permissions()->pluck('name')->sort()->values()->all();
        $migration->up();
        $migration->down();
        $this->assertSame($once, $role->permissions()->pluck('name')->sort()->values()->all());
        $this->assertSame([], array_values(array_diff($before, $once)));
        $this->assertContains(DailyReportExportService::PAYMENTS_PERMISSION, $once);
        foreach (array_keys(PermissionCatalogue::roles()) as $name) {
            $actor = $this->actor($name);
            $this->selectBranch($actor);
            foreach (['daily_sales', 'daily_receipts'] as $dataset) {
                $response = $this->get($this->url($dataset));
                if (in_array($name, $holders, true)) {
                    $response->assertOk();
                    $this->assertCount(3, $this->csv($response->streamedContent())[1]);
                } else {
                    $response->assertForbidden();
                }
            }
            if ($name === 'administrator') {
                foreach (['patients.view.organisation', 'billing.print.branch', 'payments.add.branch'] as $permission) {
                    $this->assertFalse($actor->can($permission));
                }
            }
        }
    }

    public function test_both_exports_refuse_foreign_scope_invalid_filters_fresh_revocation_and_failed_audit(): void
    {
        $other = Organisation::query()->create(['code' => 'RPT_OTHER', 'name' => 'Synthetic Other', 'is_active' => true]);
        $foreign = new Branch;
        $foreign->forceFill(['organisation_id' => $other->id, 'code' => 'FOREIGN', 'name' => 'Synthetic foreign', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        foreach (['daily_sales' => SalesSummaryExportService::PERMISSION, 'daily_receipts' => DailyReportExportService::PAYMENTS_PERMISSION] as $dataset => $permission) {
            $actor = $this->actor('administrator');
            $this->selectBranch($actor);
            $this->get($this->url($dataset, ['branch' => 'PUCHONG']))->assertOk();
            $this->get($this->url($dataset, ['branch' => 'FOREIGN']))->assertNotFound();
            $this->get($this->url($dataset, ['from' => 'bad']))->assertSessionHasErrors('from');
            $this->get($this->url($dataset, ['to' => '2026-01-01']))->assertSessionHasErrors('to');
            $this->get($this->url($dataset, ['from' => '2020-01-01']))->assertStatus(422);
            $this->get($this->url($dataset, ['doctor' => 999999]))->assertNotFound();
            $this->assertTrue($actor->can($permission));
            $role = Role::findByName('administrator');
            $grant = $role->permissions()->where('name', $permission)->sole();
            DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $grant->id)->delete();
            $this->get($this->url($dataset))->assertForbidden();
            $role->givePermissionTo($grant);
            $this->selectBranch($this->actor('administrator', $foreign), $foreign);
            $this->get($this->url($dataset, ['branch' => 'CHERAS']))->assertNotFound();
        }
    }

    public function test_shared_throttle_counts_all_three_rpt_downloads_and_filter_variants(): void
    {
        $this->selectBranch($this->actor('administrator'));
        for ($i = 0; $i < 10; $i++) {
            $url = $i % 3 === 0 ? route('insights.sales-summary.download') : $this->url($i % 3 === 1 ? 'daily_sales' : 'daily_receipts', ['branch' => $i % 2 ? 'CHERAS' : 'all']);
            $this->get($url)->assertOk();
        }
        $this->get($this->url('daily_receipts'))->assertStatus(429);
        $this->get($this->url('daily_sales'))->assertStatus(429);
    }

    public function test_audit_failure_releases_neither_file_and_has_no_financial_write(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $before = $this->evidence();
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->twice()->andReturnNull());
        foreach (['daily_sales', 'daily_receipts'] as $dataset) {
            $this->get($this->url($dataset))->assertStatus(503);
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_payment_export_refuses_over_cap_before_audit_or_streaming(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $this->mock(ClinicInsightsReportService::class, fn ($mock) => $mock->shouldReceive('report')->once()->andReturn(['report' => ['dailyReceipts' => array_fill(0, 5001, ['date' => '2026-12-31', 'methodCode' => 'cash', 'method' => 'Tunai', 'receiptsSen' => 100, 'reversedSen' => 0, 'netSen' => 100])]]));
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldNotReceive('record'));
        $this->get($this->url('daily_receipts'))->assertRedirect(route('insights.report', ['section' => 'payments']))->assertSessionHasErrors('export');
    }

    private function nextInvoice(bool $finalize = true, ?Patient $patient = null): array
    {
        if ($patient === null) {
            [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        } else {
            $doctor = $this->doctor();
            $ca = $this->actor('ca');
            $visit = $this->register($ca, $patient, ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor->id, 'visit_reason' => 'Synthetic repeat daily count', 'confirm_repeat' => true]);
            $queue = $this->send($ca, $visit);
            $this->selectBranch($doctor);
            $queue = app(QueueEntryService::class)->call($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
            $visit->refresh();
            $queue->refresh();
        }
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        if ($finalize) {
            $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
        }

        return [$doctor, $ca, $visit, $invoice];
    }

    public function test_daily_sales_matches_trend_adjustments_independent_reversal_new_year_and_zero_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'Asia/Kuala_Lumpur'));
        [$doctor, , $visit, $invoice] = $this->finalizedFixture(10000);
        [, , $secondVisit, $second] = $this->nextInvoice();
        $this->nextInvoice(false); // Draft excluded.
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00', 'Asia/Kuala_Lumpur'));
        $this->nextInvoice();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $add = function ($v, $i, $direction, $amount) use ($actor) {
            $this->selectBranch($actor);

            return app(InvoiceAdjustmentService::class)->add($actor, $v, $i->refresh(), ['expected_branch_id' => $v->branch_id, 'lock_version' => $i->lock_version, 'threshold_version' => 0, 'direction' => $direction, 'input_type' => 'fixed', 'input_value' => $amount, 'confirm_above_limit' => true, 'reason' => 'PRIVATE-REASON', 'idempotency_key' => (string) Str::uuid()]);
        };
        $add($visit, $invoice, 'deduct', 1000);
        $a = $add($secondVisit, $second, 'add', 2000);
        $reverser = $this->actor('finance_officer');
        $this->selectBranch($reverser);
        app(InvoiceAdjustmentService::class)->reverse($reverser, $secondVisit, $second->refresh(), $a->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $second->lock_version, 'reason' => 'PRIVATE-REVERSAL', 'idempotency_key' => (string) Str::uuid()]);
        $add($secondVisit, $second, 'add', 500);
        $this->selectBranch($actor);
        [$metadata, $rows] = $this->csv($this->get($this->url('daily_sales'))->assertOk()->streamedContent());
        $this->assertSame([['Local date' => '2026-12-31', 'Net invoice sales (MYR)' => '195.00', 'Distinct patients' => '2'], ['Local date' => '2027-01-01', 'Net invoice sales (MYR)' => '100.00', 'Distinct patients' => '1'], ['Local date' => '2027-01-02', 'Net invoice sales (MYR)' => '0.00', 'Distinct patients' => '0']], $rows);
        $data = app(ClinicInsightsReportService::class)->report($actor, 'sales', 'all', '2026-12-31', '2027-01-02', null);
        foreach ($data['report']['dailyTrend'] as $point) {
            $this->assertSame(ReportCsv::money($point['salesSen']), collect($rows)->firstWhere('Local date', $point['date'])['Net invoice sales (MYR)']);
            $this->assertSame((string) $point['patients'], collect($rows)->firstWhere('Local date', $point['date'])['Distinct patients']);
        }
        $this->get(route('insights.report', ['section' => 'sales', 'from' => '2026-12-31', 'to' => '2027-01-02']))->assertInertia(fn (Assert $p) => $p->where('data.report.dailyTrend', $data['report']['dailyTrend'])->where('dailyDownloadUrl', $this->url('daily_sales')));
        $filtered = $this->csv($this->get($this->url('daily_sales', ['doctor' => $doctor->id]))->streamedContent())[1];
        $this->assertSame('90.00', $filtered[0]['Net invoice sales (MYR)']);
        $this->assertStringContainsString('finalisation', $metadata['Basis']);
        $this->assertStringContainsString('daily counts need not sum', $metadata['Patient counting']);
    }

    private function receipt($actor, $patient, $method, int $amount, string $utc, string $status = 'posted', ?int $branch = null): Payment
    {
        $p = new Payment;
        $p->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch ?? $this->branch->id, 'patient_id' => $patient->id, 'payment_method_id' => $method->id, 'receipt_number' => 'SYN-'.Str::random(12), 'amount_sen' => $amount, 'currency' => 'MYR', 'method_snapshot' => $method->name, 'status' => $status, 'received_at' => $utc, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'recorded_by_user_id' => $actor->id, 'lock_version' => 1])->save();

        return $p;
    }

    public function test_receipts_match_screen_and_terminal_with_midnight_archived_methods_and_multiple_branches(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-03', 'Asia/Kuala_Lumpur'));
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $cash = (new PaymentMethod)->forceFill(['organisation_id' => $ca->organisation_id, 'code' => 'cash', 'name' => 'Tunai, "ujian"']);
        $cash->save();
        $card = (new PaymentMethod)->forceFill(['organisation_id' => $ca->organisation_id, 'code' => 'card', 'name' => " \t=FORMULA()"]);
        $card->save();
        $this->receipt($ca, $patient, $cash, 1234, '2026-12-31 15:59:59'); // 31 Dec.
        $this->receipt($ca, $patient, $cash, 500, '2026-12-31 16:00:00'); // New Year local.
        $this->receipt($ca, $patient, $cash, 300, '2026-12-31 15:00:00', 'reversed');
        $this->receipt($ca, $patient, $card, 250, '2026-12-31 16:00:01');
        $this->receipt($ca, $patient, $card, 111, '2026-12-31 15:30:00'); // Same-day other method must not enter a cash close.
        $other = Branch::where('code', 'PUCHONG')->sole();
        $this->receipt($ca, $patient, $cash, 700, '2026-12-31 16:00:01', 'posted', $other->id);
        $this->receipt($ca, $patient, $cash, 9000, '2027-01-02 16:00:00'); // Outside range.
        $cash->forceFill(['is_active' => false])->save();
        $attributes = ['expected_branch_id' => $this->branch->id, 'payment_method_id' => $cash->id, 'business_date' => '2026-12-31', 'idempotency_key' => (string) Str::uuid(), 'terminal_sales_count' => 1, 'terminal_sales_total' => '12.34'];
        $close = app(PaymentReconciliationService::class)->reconcile($ca, $attributes);
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        [$metadata, $rows] = $this->csv($this->get($this->url('daily_receipts'))->assertOk()->streamedContent());
        $cashRows = collect($rows)->where('Payment method (receipt snapshot)', $cash->name)->values();
        $this->assertSame(['15.34', '12.00', '0.00'], $cashRows->pluck('Receipts (MYR)')->all());
        $this->assertSame(['3.00', '0.00', '0.00'], $cashRows->pluck('Reversed (MYR)')->all());
        $this->assertSame(ReportCsv::money($close->kpone_payment_total_sen), $cashRows[0]['Net received (MYR)']);
        $this->assertSame("'".$card->name, $rows[0]['Payment method (receipt snapshot)']);
        $data = app(ClinicInsightsReportService::class)->report($actor, 'payments', 'all', '2026-12-31', '2027-01-02', null);
        $this->assertSame(0, $data['report']['summary']['receivedSen']); // No allocations: explicit difference to old card.
        foreach ($data['report']['dailyReceipts'] as $i => $row) {
            $this->assertSame(ReportCsv::money($row['receiptsSen']), $rows[$i]['Receipts (MYR)']);
            $this->assertSame(ReportCsv::money($row['reversedSen']), $rows[$i]['Reversed (MYR)']);
            $this->assertSame(ReportCsv::money($row['netSen']), $rows[$i]['Net received (MYR)']);
        }
        $selected = $this->csv($this->get($this->url('daily_receipts', ['branch' => 'CHERAS']))->streamedContent())[1];
        $this->assertSame('5.00', collect($selected)->where('Payment method (receipt snapshot)', $cash->name)->values()[1]['Net received (MYR)']);
        $this->assertStringContainsString('received_at', $metadata['Basis']);
        $this->assertStringNotContainsString($patient->full_name, json_encode($rows));
        $this->get(route('insights.report', ['section' => 'payments', 'from' => '2026-12-31', 'to' => '2027-01-02']))->assertInertia(fn (Assert $p) => $p->where('data.report.dailyReceipts', $data['report']['dailyReceipts'])->where('dailyDownloadUrl', $this->url('daily_receipts')));
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
        foreach (['daily_sales', 'daily_receipts'] as $dataset) {
            $before = $this->evidence();
            $audits = DB::table('audit_logs')->orderBy('id')->get();
            $response = $this->get($this->url($dataset, ['from' => '2026-10-01', 'to' => '2026-10-01']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame($before, $this->evidence());
            $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
            $this->assertSame($audits->count() + 1, DB::table('audit_logs')->count());
            $this->assertSame(['dataset' => $dataset, 'branch_scope' => 'all', 'from' => '2026-10-01', 'to' => '2026-10-01', 'row_count' => 1], AuditLog::query()->latest('id')->first()->metadata);
        }
    }

    public function test_actual_payment_reversal_void_and_receipt_date_remain_distinct_from_finalisation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'Asia/Kuala_Lumpur'));
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $method = (new PaymentMethod)->forceFill(['organisation_id' => $ca->organisation_id, 'code' => 'cash', 'name' => 'Tunai']);
        $method->save();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:01', 'Asia/Kuala_Lumpur'));
        $this->selectBranch($ca);
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '1234', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $before = $this->csv($this->get($this->url('daily_receipts'))->streamedContent())[1];
        $this->assertSame(['0.00', '12.34', '0.00'], array_column($before, 'Net received (MYR)'));
        $this->travelTo(CarbonImmutable::parse('2027-01-02 12:00', 'Asia/Kuala_Lumpur'));
        app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $payment, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $payment->lock_version, 'recording_error_only' => true, 'reason' => 'PRIVATE REVERSAL']);
        app(InvoiceCorrectionService::class)->void($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'reason' => 'PRIVATE VOID']);
        $after = $this->csv($this->get($this->url('daily_receipts'))->streamedContent())[1];
        $this->assertSame(['0.00', '12.34', '0.00'], array_column($after, 'Reversed (MYR)'));
        $this->assertSame(['0.00', '0.00', '0.00'], array_column($after, 'Net received (MYR)'));
        $sales = $this->csv($this->get($this->url('daily_sales'))->streamedContent())[1];
        $this->assertSame(['0.00', '0.00', '0.00'], array_column($sales, 'Net invoice sales (MYR)'));
        $this->assertStringNotContainsString('PRIVATE', json_encode($after));
        $filtered = $this->csv($this->get($this->url('daily_receipts', ['doctor' => $doctor->id]))->streamedContent())[1];
        $this->assertSame($after, $filtered); // Voiding must not erase the receipt evidence.
    }

    public function test_daily_sales_combines_branch_local_dates_without_exposing_foreign_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 16:00:00', 'UTC'));
        $this->finalizedFixture(10000);
        $cheras = $this->branch;
        $this->branch = Branch::where('code', 'PUCHONG')->sole();
        $this->nextInvoice();
        $this->branch = $cheras;
        $this->selectBranch($this->actor('administrator'));
        $all = $this->csv($this->get($this->url('daily_sales'))->streamedContent())[1];
        $selected = $this->csv($this->get($this->url('daily_sales', ['branch' => 'CHERAS']))->streamedContent())[1];
        $this->assertSame('200.00', $all[1]['Net invoice sales (MYR)']);
        $this->assertSame('100.00', $selected[1]['Net invoice sales (MYR)']);
    }

    public function test_distinct_patients_are_deduplicated_per_local_date_across_invoices_and_selected_branches(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:55', 'Asia/Kuala_Lumpur'));
        [, , $visit] = $this->finalizedFixture(10000);
        $patient = $visit->patient;
        $this->nextInvoice(true, $patient);
        $this->nextInvoice();
        $cheras = $this->branch;
        $this->branch = Branch::where('code', 'PUCHONG')->sole();
        $this->nextInvoice(true, $patient);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:05', 'Asia/Kuala_Lumpur'));
        $this->nextInvoice(true, $patient);
        $this->branch = $cheras;
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        [$metadata, $rows] = $this->csv($this->get($this->url('daily_sales'))->assertOk()->streamedContent());
        $this->assertSame(['2', '1', '0'], array_column($rows, 'Distinct patients'));
        $this->assertSame(['400.00', '100.00', '0.00'], array_column($rows, 'Net invoice sales (MYR)'));
        $data = app(ClinicInsightsReportService::class)->report($actor, 'sales', 'all', '2026-12-31', '2027-01-02', null);
        $this->assertSame([2, 1], array_column($data['report']['dailyTrend'], 'patients'));
        $this->assertSame(2, $data['report']['summary']['patients']);
        $selected = $this->csv($this->get($this->url('daily_sales', ['branch' => 'PUCHONG']))->streamedContent())[1];
        $this->assertSame(['1', '1', '0'], array_column($selected, 'Distinct patients'));
        $this->assertStringContainsString('Multiple invoices or selected branches', $metadata['Patient counting']);
        $this->assertStringNotContainsString($patient->patient_number, json_encode($rows));
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
