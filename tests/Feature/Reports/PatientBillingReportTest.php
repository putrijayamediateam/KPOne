<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\TransactionalActorAuthority;
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
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PatientBillingReportService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;

class PatientBillingReportTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    private function filters(array $overrides = []): array
    {
        return $overrides + ['expected_branch_id' => $this->branch->id, 'from' => now()->setTimezone($this->branch->timezone)->toDateString(), 'to' => now()->setTimezone($this->branch->timezone)->toDateString()];
    }

    private function reportRequest(array $filters = [])
    {
        return $this->postJson(route('patient-billing-report.run'), $this->filters($filters));
    }

    private function download(array $filters = [])
    {
        return $this->postJson(route('patient-billing-report.download'), $this->filters($filters));
    }

    private function csv(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString("\r\n", $content);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($content, 3));
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

        return [$metadata, $rows];
    }

    public function test_exact_permissions_routes_and_navigation_for_every_role_and_additive_migration(): void
    {
        foreach ([PatientBillingReportService::VIEW, PatientBillingReportService::EXPORT] as $permission) {
            $this->assertSame(['director', 'finance_officer'], collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array($permission, $p, true))->keys()->sort()->values()->all());
        }
        $role = Role::findByName('finance_officer');
        $role->revokePermissionTo(PatientBillingReportService::EXPORT);
        $prior = $role->permissions()->pluck('name')->all();
        $migration = require database_path('migrations/2026_10_21_000100_grant_patient_billing_report_permissions.php');
        $migration->up();
        $once = $role->permissions()->pluck('name')->sort()->values()->all();
        $migration->up();
        $migration->down();
        $this->assertSame($once, $role->permissions()->pluck('name')->sort()->values()->all());
        $this->assertSame([], array_values(array_diff($prior, $once)));
        foreach (array_keys(PermissionCatalogue::roles()) as $name) {
            $actor = $this->actor($name);
            $this->selectBranch($actor);
            if (in_array($name, ['director', 'finance_officer'], true)) {
                $this->get(route('patient-billing-report.index'))->assertOk()->assertInertia(fn ($p) => $p->where('result', null));
                $this->reportRequest()->assertOk()->assertJsonPath('result.count', 0);
                $this->download()->assertOk();
                $this->assertStringContainsString('Billing Detail', $this->get(route('clinic-management.index'))->getContent());
            } else {
                $this->get(route('patient-billing-report.index'))->assertForbidden();
                $this->reportRequest()->assertForbidden();
                $this->download()->assertForbidden();
            }
            if ($name === 'administrator') {
                $this->assertTrue($actor->can('reports.sales.export.organisation'));
                $this->assertFalse($actor->can('billing.print.branch'));
            }
        }
    }

    public function test_fresh_revocation_inactive_actor_and_expired_assignment_are_refused(): void
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $this->reportRequest()->assertOk();
        $role = Role::findByName('finance_officer');
        $grant = $role->permissions()->where('name', PatientBillingReportService::VIEW)->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $grant->id)->delete();
        $this->reportRequest()->assertForbidden();
        $this->download()->assertForbidden();
        $role->givePermissionTo($grant);
        $actor->forceFill(['is_active' => false])->save();
        try {
            app(PatientBillingReportService::class)->execute($actor, $this->filters(), run: true);
            $this->fail('Inactive actor accepted.');
        } catch (AuthorizationException $e) {
            $this->assertSame('You may not perform this operation.', $e->getMessage());
        }
        $actor->forceFill(['is_active' => true])->save();
        DB::table('staff_branch_assignments')->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->reportRequest()->assertForbidden();
    }

    public function test_foreign_branch_organisation_and_unapproved_filters_never_release_content(): void
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        foreach ([['expected_branch_id' => Branch::where('code', 'PUCHONG')->sole()->id], ['from' => 'bad'], ['from' => '2026-01-01', 'to' => '2026-02-01'], ['time_from' => '23:00', 'time_to' => '01:00'], ['doctors' => ['999999']], ['methods' => ['999999']], ['visit_types' => ['clinical']], ['treatments' => ['PRIVATE']], ['branch' => 'all']] as $invalid) {
            $this->reportRequest($invalid)->assertUnprocessable();
            $this->download($invalid)->assertUnprocessable();
        }
        $other = Organisation::query()->create(['code' => 'RPT2_OTHER', 'name' => 'Synthetic Other', 'is_active' => true]);
        $foreign = (new Branch)->forceFill(['organisation_id' => $other->id, 'code' => 'RPT2_FOREIGN', 'name' => 'Synthetic foreign', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreign->save();
        $this->reportRequest(['expected_branch_id' => $foreign->id])->assertUnprocessable();
        session(['active_branch_id' => $foreign->id]);
        $this->download(['expected_branch_id' => $foreign->id])->assertUnprocessable();
    }

    private function method(string $code, string $name): PaymentMethod
    {
        $method = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => $code, 'name' => $name]);
        $method->save();

        return $method;
    }

    private function pay($ca, $visit, $invoice, string $method, int $sen)
    {
        $this->selectBranch($ca);

        return app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => (string) $sen, 'method' => $method, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_one_invoice_multiple_methods_reversal_adjustment_totals_and_live_regeneration(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'Asia/Kuala_Lumpur'));
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $cash = $this->method('cash', 'Tunai, "ujian"');
        $card = $this->method('card', " \t=FORMULA()");
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        app(InvoiceAdjustmentService::class)->add($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => 'add', 'input_type' => 'fixed', 'input_value' => 500, 'confirm_above_limit' => true, 'reason' => 'PRIVATE ADJUSTMENT', 'idempotency_key' => (string) Str::uuid()]);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:01', 'Asia/Kuala_Lumpur'));
        $reversed = $this->pay($ca, $visit, $invoice, 'cash', 1000);
        $this->pay($ca, $visit, $invoice, 'cash', 1234);
        $this->pay($ca, $visit, $invoice, 'card', 2000);
        $card->forceFill(['is_active' => false])->save();
        $this->selectBranch($finance);
        app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $reversed, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $reversed->lock_version, 'recording_error_only' => true, 'reason' => 'PRIVATE REVERSAL']);
        $filters = ['from' => '2026-12-31', 'to' => '2026-12-31'];
        $response = $this->reportRequest($filters)->assertOk()->assertJsonPath('result.count', 1)->assertJsonPath('result.rows.0.netSen', 10500)->assertJsonPath('result.rows.0.receivedSen', 3234)->assertJsonPath('result.rows.0.dueSen', 7266);
        $this->assertSame($visit->patient->patient_number, $response->json('result.rows.0.patientNumber'));
        [$metadata, $rows] = $this->csv($this->download($filters)->assertOk()->streamedContent());
        $this->assertCount(1, $rows);
        $this->assertSame('12.34', $rows[0]['Net receipt cash · '.$cash->name.' (MYR)']);
        $this->assertSame('20.00', $rows[0]['Net receipt card · '.$card->name.' (MYR)']);
        $this->assertSame('2026-12-31 23:59:59', $rows[0]['Local invoice finalisation date/time']);
        $this->assertSame('105.00', $metadata['Total net invoice (MYR)']);
        $this->assertSame('Asia/Kuala_Lumpur', $metadata['Branch timezone']);
        $this->assertStringNotContainsString('PRIVATE', json_encode([$metadata, $rows]));
        // New records between screen and download are allowed; both identify generation time.
        $this->travelTo(CarbonImmutable::parse('2027-01-01 12:00', 'Asia/Kuala_Lumpur'));
        $this->pay($ca, $visit, $invoice, 'cash', 500);
        $this->selectBranch($finance);
        [$newMetadata, $newRows] = $this->csv($this->download($filters)->assertOk()->streamedContent());
        $this->assertNotSame($metadata['Generated at UTC'], $newMetadata['Generated at UTC']);
        $this->assertSame('105.00', $newRows[0]['Net invoice (MYR)']);
        $this->assertSame('37.34', $newRows[0]['Net patient receipts (MYR)']);
        $this->assertSame('105.00', $newMetadata['Total net invoice (MYR)']);
        $data = app(ClinicInsightsReportService::class)->report($finance, 'sales', 'CHERAS', '2026-12-31', '2026-12-31', null);
        $this->assertSame(ReportCsv::money($data['report']['dailyTrend'][0]['salesSen']), $newMetadata['Total net invoice (MYR)']);
        $this->reportRequest($filters + ['doctors' => [(string) $doctor->id], 'methods' => [(string) $card->id, (string) $cash->id], 'visit_types' => ['consultation'], 'time_from' => '23:59', 'time_to' => '23:59'])->assertOk()->assertJsonPath('result.count', 1)->assertJsonPath('result.totals.receivedSen', 3734);
        $this->reportRequest($filters + ['visit_types' => ['otc']])->assertOk()->assertJsonPath('result.count', 0);
        $this->reportRequest(['from' => '2027-01-01', 'to' => '2027-01-01'])->assertOk()->assertJsonPath('result.count', 0);
    }

    public function test_failed_audit_refuses_screen_and_csv_and_no_automatic_patient_rows(): void
    {
        $this->finalizedFixture();
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->twice()->andReturnNull());
        $this->get(route('patient-billing-report.index'))->assertOk()->assertInertia(fn ($p) => $p->where('result', null));
        $this->reportRequest()->assertStatus(503)->assertDontSee('Synthetic Visit Patient');
        $this->download()->assertStatus(503)->assertDontSee('Synthetic Visit Patient');
    }

    public function test_view_only_grant_does_not_allow_export_and_download_limit_is_shared(): void
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $role = Role::findByName('finance_officer');
        $role->revokePermissionTo(PatientBillingReportService::EXPORT);
        $this->reportRequest()->assertOk()->assertJsonPath('canExport', false);
        $this->download()->assertForbidden();
        $role->givePermissionTo(PatientBillingReportService::EXPORT);
        $this->selectBranch($this->actor('finance_officer'));
        for ($i = 0; $i < 10; $i++) {
            if ($i % 2) {
                $this->get(route('insights.daily-sales.download'))->assertOk();
            } else {
                $this->download()->assertOk();
            }
        }
        $this->download()->assertStatus(429);
        $this->get(route('insights.daily-receipts.download'))->assertStatus(429);
    }

    public function test_over_cap_is_refused_before_reading_identities_or_audit(): void
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $mock = $this->getMockBuilder(PatientBillingReportService::class)->setConstructorArgs([app(BranchAccessService::class), app(TransactionalActorAuthority::class), app(FinancialLedger::class), app(AuditRecorder::class)])->onlyMethods(['query'])->getMock();
        $mock->method('query')->willReturn(DB::query()->fromRaw('generate_series(1,5001) AS capped'));
        $this->app->instance(PatientBillingReportService::class, $mock);
        $this->mock(AuditRecorder::class, fn ($m) => $m->shouldNotReceive('record'));
        $this->download()->assertUnprocessable()->assertJsonValidationErrors('export');
    }

    public function test_full_financial_clinical_stock_and_populated_claim_evidence_is_unchanged(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $this->method('cash', 'Tunai');
        $this->pay($ca, $visit, $invoice, 'cash', 1234);
        $this->selectBranch($ca);
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $allocation = app(ResponsibilityService::class)->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'reason' => 'PRIVATE RESPONSIBILITY', 'panel_id' => $panel->id, 'member_reference' => 'PRIVATE MEMBER']);
        $officer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 4000]);
        $this->selectBranch($officer);
        app(ResponsibilityService::class)->approve($officer, $visit, $invoice->refresh(), 'panel', $allocation->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $allocation->lock_version]);
        app(PanelClaimService::class)->prepare($officer, $allocation->refresh(), ['expected_branch_id' => $visit->branch_id]);
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->assertGreaterThan(0, DB::table('panel_claims')->count());
        $this->assertGreaterThan(0, DB::table('stock_movements')->count());
        $this->selectBranch($this->actor('finance_officer'));
        foreach (['screen', 'csv'] as $action) {
            $before = $this->evidence();
            $audits = DB::table('audit_logs')->orderBy('id')->get();
            $response = $action === 'csv' ? $this->download() : $this->reportRequest();
            $response->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $content = $action === 'csv' ? $response->streamedContent() : $response->getContent();
            foreach (['PRIVATE', $visit->patient->mobile_phone, 'Synthetic OTC medicine'] as $excluded) {
                $this->assertStringNotContainsString($excluded, $content);
            }
            $this->assertSame($before, $this->evidence());
            $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
            $this->assertSame($audits->count() + 1, DB::table('audit_logs')->count());
            $metadata = AuditLog::query()->latest('id')->first()->metadata;
            $this->assertSame(['dataset', 'branch_id', 'from', 'to', 'time_from', 'time_to', 'doctors', 'methods', 'visit_types', 'page', 'row_count'], array_keys($metadata));
            $this->assertStringNotContainsString($visit->patient->full_name, json_encode($metadata));
            if ($action === 'screen') {
                $response->assertJsonPath('result.totals.netSen', 10000)->assertJsonPath('result.totals.receivedSen', 1234)->assertJsonPath('result.totals.panelSen', 3000)->assertJsonPath('result.totals.dueSen', 5766);
            }
        }
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

    public function test_stable_paging_full_totals_patient_csv_escaping_and_branch_isolation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 16:00:00', 'UTC'));
        [, , $visit] = $this->finalizedFixture(1234);
        $patient = $visit->patient;
        $patient->forceFill(['full_name' => " \t=UJIAN(\"A,B\")"])->save();
        for ($i = 0; $i < 20; $i++) {
            $this->nextInvoice(true, $patient);
        }
        $this->nextInvoice(false); // Draft must not join the cohort.
        $cheras = $this->branch;
        $this->branch = Branch::where('code', 'PUCHONG')->sole();
        $this->nextInvoice();
        $this->branch = $cheras;
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $first = $this->reportRequest(['page' => 1])->assertOk()->assertJsonPath('result.count', 21)->assertJsonPath('result.pages', 2)->assertJsonCount(20, 'result.rows');
        $second = $this->reportRequest(['page' => 2])->assertOk()->assertJsonCount(1, 'result.rows')->assertJsonPath('result.rows.0.number', 21);
        $this->assertSame($first->json('result.totals'), $second->json('result.totals'));
        $names = array_column([...$first->json('result.rows'), ...$second->json('result.rows')], 'invoiceNumber');
        $this->assertCount(21, array_unique($names));
        [$metadata, $rows] = $this->csv($this->download()->assertOk()->streamedContent());
        $this->assertSame($names, array_column($rows, 'Invoice number'));
        $this->assertSame("'".$patient->full_name, $rows[0]['Patient name (current)']);
        $this->assertSame('259.14', $metadata['Total net invoice (MYR)']);
        $aggregate = app(DailyReportExportService::class)->download($finance, 'daily_sales', 'CHERAS', '2027-01-01', '2027-01-01', null);
        $this->assertStringContainsString('259.14', $aggregate['csv']);
        $this->assertStringContainsString(',1'."\r\n", $aggregate['csv']); // One distinct patient, not 21 invoices.
        $this->reportRequest(['page' => 3])->assertUnprocessable();
    }

    public function test_discount_independent_adjustment_reversal_and_void_cohorts_use_existing_rules(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $director = $this->actor('director');
        $finance = $this->actor('finance_officer');
        $this->selectBranch($director);
        $adjustment = app(InvoiceAdjustmentService::class)->add($director, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => 'deduct', 'input_type' => 'fixed', 'input_value' => 1234, 'confirm_above_limit' => true, 'reason' => 'PRIVATE DISCOUNT', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($finance);
        $this->reportRequest()->assertOk()->assertJsonPath('result.totals.netSen', 8766);
        app(InvoiceAdjustmentService::class)->reverse($finance, $visit, $invoice->refresh(), $adjustment->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'reason' => 'PRIVATE ADJUSTMENT REVERSAL', 'idempotency_key' => (string) Str::uuid()]);
        $this->reportRequest()->assertOk()->assertJsonPath('result.totals.netSen', 10000);
        app(InvoiceCorrectionService::class)->void($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'reason' => 'PRIVATE VOID']);
        $this->reportRequest()->assertOk()->assertJsonPath('result.count', 0)->assertJsonPath('result.totals.netSen', 0);
        [$metadata, $rows] = $this->csv($this->download()->assertOk()->streamedContent());
        $this->assertSame([], $rows);
        $this->assertSame('0.00', $metadata['Total net invoice (MYR)']);
    }

    public function test_otc_is_included_without_inventing_a_checkout_doctor(): void
    {
        $this->finalizedFixture();
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $this->selectBranch($f['ca']);
        $charge = (new ChargeDefinition)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'type' => 'medicine', 'code' => 'SYN-OTC-REPORT', 'display_name' => 'PRIVATE medicine', 'source_key' => 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'unit' => 'unit']);
        $charge->save();
        (new PriceEntry)->forceFill(['organisation_id' => $this->organisation->id, 'price_book_id' => PriceBook::query()->firstOrFail()->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => 123, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($f['ca'], $f['visit'], ['expected_branch_id' => $this->branch->id, 'lock_version' => null]);
        $builder->finalize($f['ca'], $f['visit'], $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version]);
        $this->selectBranch($this->actor('finance_officer'));
        $response = $this->reportRequest(['visit_types' => ['otc']])->assertOk()->assertJsonPath('result.count', 1)->assertJsonPath('result.totals.netSen', 246);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $doctorId = $response->json('options.doctors.0.value');
        $this->reportRequest(['visit_types' => ['otc'], 'doctors' => [$doctorId]])->assertOk()->assertJsonPath('result.count', 0);
    }

    public function test_pending_panel_is_not_money_and_approved_pay_later_remains_separate_from_due_now(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $service = app(ResponsibilityService::class);
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $service->propose($finance, $visit, $invoice, 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 2000, 'reason' => 'PRIVATE pending', 'panel_id' => $panel->id]);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $finance->id, 'capability' => 'deferment', 'limit_sen' => 3000]);
        $proposal = $service->propose($finance, $visit, $invoice->refresh(), 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'due_date' => now()->addDay()->toDateString(), 'reason' => 'PRIVATE receivable']);
        $this->reportRequest()->assertOk()->assertJsonPath('result.totals.panelSen', 0)->assertJsonPath('result.totals.deferredSen', 0)->assertJsonPath('result.totals.dueSen', 10000);
        $service->approve($finance, $visit, $invoice->refresh(), 'deferment', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
        $this->reportRequest()->assertOk()->assertJsonPath('result.totals.deferredSen', 3000)->assertJsonPath('result.totals.dueSen', 7000);
        $this->method('cash', 'Tunai');
        $this->pay($ca, $visit, $invoice, 'cash', 1000);
        $this->selectBranch($finance);
        $this->reportRequest()->assertOk()->assertJsonPath('result.totals.receivedSen', 1000)->assertJsonPath('result.totals.deferredSen', 3000)->assertJsonPath('result.totals.dueSen', 6000);
        // Existing payment priority clears due-now before reducing the approved receivable.
        $this->pay($ca, $visit, $invoice, 'cash', 7000);
        $this->selectBranch($finance);
        $this->reportRequest()->assertOk()->assertJsonPath('result.totals.receivedSen', 8000)->assertJsonPath('result.totals.deferredSen', 2000)->assertJsonPath('result.totals.dueSen', 0);
        [$metadata, $rows] = $this->csv($this->download()->assertOk()->streamedContent());
        $this->assertSame('20.00', $rows[0]['Approved pay-later outstanding (MYR)']);
        $this->assertSame('0.00', $metadata['Total due now (MYR)']);
        $this->assertStringNotContainsString('PRIVATE', json_encode([$metadata, $rows]));
    }

    private function evidence(): array
    {
        $result = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if (preg_match('/^(invoices|invoice_|payments|payment_|panel_|coverage_|patients$|patient_(allergy|problem|identifiers|receivables)|receivable_|stock_movements|inventory_|dispensary_|treatment_plan|consultation_|visits|clinical_)/', $table->tablename)) {
                $result[$table->tablename] = DB::table($table->tablename)->orderBy('id')->get()->toJson();
            }
        }

        return $result;
    }
}
