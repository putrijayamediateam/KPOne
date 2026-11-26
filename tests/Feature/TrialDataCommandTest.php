<?php

namespace Tests\Feature;

use App\Domain\Identity\Services\DirectorBootstrapService;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\TrialData\PhaseFiveHr;
use App\Domain\Shared\TrialData\PhaseOneFoundation;
use App\Domain\Shared\TrialData\PhaseThreePanel;
use App\Domain\Shared\TrialData\PhaseTwoClinicDay;
use App\Domain\Shared\TrialData\PlaceholderFiles;
use App\Domain\Shared\TrialData\TrialDataContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class TrialDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'trial.kpjaya.test';

    private const PASSWORD = 'Trial-Shared-Pass-2026!';

    /** @var list<string> */
    private const COUNTED_TABLES = [
        'users', 'staff_branch_assignments', 'medicine_catalogue_items', 'clinical_service_catalogue_items',
        'inventory_items', 'inventory_skus', 'inventory_locations', 'inventory_batches', 'stock_movements',
        'charge_definitions', 'price_books', 'price_entries', 'inventory_unit_contracts', 'inventory_opening_verifications',
        'payment_methods', 'panels', 'branch_rooms', 'diagnosis_presets', 'clinician_professional_profiles', 'patients',
        'inventory_suppliers', 'billing_approval_limits', 'visit_reason_catalogue_items', 'panel_claim_message_templates',
        'business_detail_commands', 'branch_document_footers', 'price_change_proposals', 'branch_display_settings', 'visits',
        'queue_entries', 'otc_queue_entries', 'queue_calls', 'queue_call_holds', 'consultation_holds', 'queue_treatment_steps',
        'visit_triage_assessments', 'dispensary_cases', 'invoices', 'payments', 'payment_refunds', 'medical_certificates',
        'referral_documents', 'attendance_documents', 'payment_reconciliations', 'cash_closings', 'coverage_allocations', 'panel_claims', 'panel_claim_payments',
        'panel_claim_adjustments', 'panel_remittances', 'panel_claim_payment_reversals', 'inventory_purchase_requests',
        'inventory_purchase_orders', 'inventory_goods_receipts', 'inventory_receiving_reviews', 'inventory_supplier_invoices',
        'inventory_supplier_returns', 'inventory_batch_hold_states', 'inventory_governed_cases', 'inventory_reorder_levels',
        'inventory_stock_reviews', 'inventory_treatment_usages', 'inventory_controlled_register_checks', 'dispensary_returns',
        'inventory_stock_requests', 'inventory_recalls', 'inventory_supplier_sku_terms', 'hr_workers', 'hr_bank_details',
        'hr_leave_requests', 'hr_roster_versions', 'hr_clock_sessions', 'hr_attendance_corrections', 'hr_overtime_requests',
        'hr_credential_records', 'hr_sops', 'hr_memos', 'hr_letters', 'hr_appraisals', 'hr_incidents', 'hr_discipline_cases',
        'hr_open_shifts', 'hr_staff_claims', 'hr_job_positions', 'hr_statutory_tables', 'hr_pay_profiles', 'hr_payroll_runs',
        'hr_payslips', 'hr_payroll_exports', 'audit_logs',
    ];

    private Branch $cheras;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://'.self::HOST, 'trial-data.allowed' => true]);
        $this->cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        // The real owner's Director account need not be a .test address.
        $director = app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Owner Director',
            'email' => 'owner.director@example.com',
            'staff_number' => 'OWNER-0001',
            'job_title' => 'Director',
            'branch_id' => $this->cheras->id,
            'credential_strategy' => 'google_only',
        ]);
        // Director coverage of every branch (what kpone:director-branches provides), set up as a test fixture.
        foreach (Branch::query()->where('code', '!=', 'CHERAS')->get() as $branch) {
            StaffBranchAssignmentBootstrapper::create($director->staffProfile, $branch, ['is_primary' => false]);
        }
    }

    public function test_it_refuses_without_the_flag(): void
    {
        config(['trial-data.allowed' => false]);
        $before = $this->counts();

        $this->artisan('kpone:trial-data')
            ->expectsOutputToContain('Trial data is switched off')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $this->counts());
    }

    public function test_it_refuses_the_wrong_host_name(): void
    {
        $before = $this->counts();

        $this->artisan('kpone:trial-data')
            ->expectsQuestion('Type this site\'s host name to confirm (for example trial.example.com)', 'clinic.example.com')
            ->expectsOutputToContain('The host name does not match this site')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $this->counts());
    }

    public function test_it_refuses_when_not_interactive(): void
    {
        $this->artisan('kpone:trial-data', ['--no-interaction' => true])
            ->expectsOutputToContain('interactive terminal')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_refuses_a_non_test_account_other_than_the_director(): void
    {
        User::factory()->create(['email' => 'real.person@example.com']);
        $before = $this->counts();

        $this->artisan('kpone:trial-data')
            ->expectsOutputToContain('1 account(s) other than the Director')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $this->counts());
    }

    public function test_it_refuses_without_exactly_one_director(): void
    {
        User::role('director')->update(['is_active' => false]);

        $this->artisan('kpone:trial-data')
            ->expectsOutputToContain('Exactly one active Director is needed')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_refuses_when_the_director_does_not_cover_every_branch(): void
    {
        $puchong = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
        DB::table('staff_branch_assignments')->where('branch_id', $puchong->id)->delete();
        $before = $this->counts();

        $this->artisan('kpone:trial-data')
            ->expectsOutputToContain('no current assignment at PUCHONG. Run `php artisan kpone:director-branches` first')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $this->counts());
    }

    public function test_it_refuses_mismatched_or_weak_passwords_before_any_write(): void
    {
        $before = $this->counts();

        $this->confirmHost()
            ->expectsQuestion('Shared password for the fictional accounts (hidden)', self::PASSWORD)
            ->expectsQuestion('Type the shared password again (hidden)', 'Something-Else-2026!')
            ->expectsOutputToContain('typed the same twice')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $this->counts());
    }

    public function test_phase_one_creates_the_foundation_once_through_the_services(): void
    {
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);

        $branches = Branch::query()->get()->keyBy('code');
        foreach (TrialDataContext::accounts() as $key => $account) {
            $user = User::query()->where('email', TrialDataContext::email($key))->sole();
            $this->assertTrue($user->hasRole($account['role']), $key);
            $this->assertTrue(Hash::check(self::PASSWORD, (string) $user->password), $key);
            $assignments = $user->staffProfile->branchAssignments()->effectiveAt()->get();
            $this->assertSame($branches[$account['branches'][0]]->id, $assignments->firstWhere('is_primary', true)?->branch_id, $key);
            $this->assertEqualsCanonicalizing(
                array_map(fn (string $code): int => $branches[$code]->id, $account['branches']),
                $assignments->pluck('branch_id')->all(),
                $key,
            );
        }
        $this->assertSame(28, count(TrialDataContext::accounts()));
        $this->assertSame(0, User::query()->where('email', 'like', 'trial.%')->whereRaw("email NOT LIKE '%@kpjaya.test'")->count());

        $this->assertSame(count(PhaseOneFoundation::MEDICINES), DB::table('medicine_catalogue_items')->count());
        $this->assertSame(count(PhaseOneFoundation::SERVICES), DB::table('clinical_service_catalogue_items')->count());
        $this->assertTrue((bool) DB::table('medicine_catalogue_items')->where('code', 'TRIAL-COD15')->value('is_controlled'));
        $this->assertSame(count(PhaseOneFoundation::PAYMENT_METHODS), DB::table('payment_methods')->where('is_active', true)->count());
        $this->assertSame('cash', DB::table('payment_methods')->where('code', 'CASH')->value('classification'));
        $this->assertSame(count(PhaseOneFoundation::PANELS), DB::table('panels')->count());
        // Panel prices came by proposal (Panel Officer) and approval (Finance Officer), never by direct publishing.
        $panelBook = DB::table('price_books')->where('scope_key', 'panel:default')->value('id');
        $this->assertSame(1 + count(PhaseOneFoundation::SERVICES) + count(PhaseOneFoundation::MEDICINES), DB::table('price_entries')->where('price_book_id', $panelBook)->count());
        $this->assertSame(PhaseOneFoundation::PATIENTS_PER_BRANCH * 3, DB::table('patients')->count());
        $this->assertSame(6, DB::table('clinician_professional_profiles')->where('verified', true)->count());

        foreach ($branches as $code => $branch) {
            // Each branch has its own letterhead, footer, rooms and approval limits.
            $this->assertSame(1, DB::table('branch_business_profiles')->where('branch_id', $branch->id)->count(), $code);
            $this->assertSame(PhaseOneFoundation::FOOTER_TEXT, DB::table('branch_document_footers')->where('branch_id', $branch->id)->value('text'), $code);
            $this->assertSame(count(PhaseOneFoundation::ROOMS), DB::table('branch_rooms')->where('branch_id', $branch->id)->count(), $code);
            $this->assertSame(2, DB::table('billing_approval_limits')->where('branch_id', $branch->id)->count(), $code);

            // Every opening at this branch was recorded by its first CA Supervisor and signed off by its second.
            $recorder = User::query()->where('email', TrialDataContext::email(TrialDataContext::key($code, 'supervisor1')))->sole();
            $verifier = User::query()->where('email', TrialDataContext::email(TrialDataContext::key($code, 'supervisor2')))->sole();
            $dispensary = DB::table('inventory_locations')->where('code', PhaseOneFoundation::dispensaryCode($code))->value('id');
            $openings = StockMovement::query()->where('movement_type', StockMovement::TYPE_OPENING)->where('destination_location_id', $dispensary)->get();
            $this->assertCount(count(PhaseOneFoundation::MEDICINES), $openings, $code);
            foreach ($openings as $opening) {
                $this->assertSame($recorder->id, $opening->actor_user_id, $code);
                $this->assertSame($verifier->id, (int) DB::table('inventory_opening_verifications')->where('stock_movement_id', $opening->id)->value('actor_user_id'), $code);
                $this->assertGreaterThan(now()->toDateString(), (string) DB::table('inventory_batches')->where('id', $opening->inventory_batch_id)->value('expiry_date'));
            }
        }

        // One system audit row for the phase: no actor, counts only.
        $row = DB::table('audit_logs')->where('event', 'trial_data.phase_completed')->sole();
        $this->assertNull($row->actor_user_id);
        $metadata = json_decode((string) $row->metadata, true);
        $this->assertSame(1, $metadata['phase']);
        $this->assertSame(28, $metadata['created']['staff']);
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);
        $after = $this->counts();

        // All accounts exist, so no password is asked for.
        $this->confirmHost()
            ->expectsOutputToContain('already complete')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame($after, $this->counts());
    }

    public function test_it_runs_with_the_app_in_production_mode_as_on_the_trial_server(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->runPhaseOne()->assertExitCode(Command::SUCCESS);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(count(TrialDataContext::accounts()), User::query()->where('email', 'like', '%@kpjaya.test')->count());
        $this->assertSame(PhaseOneFoundation::PATIENTS_PER_BRANCH * 3, DB::table('patients')->count());
    }

    public function test_the_password_never_appears_in_output_logs_or_audit(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->runPhaseOne()
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertExitCode(Command::SUCCESS);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::PASSWORD, $line);
        }
        $this->assertSame(0, DB::table('audit_logs')->where('metadata', 'like', '%'.self::PASSWORD.'%')->count());
        $this->assertSame(0, DB::table('users')->where('password', self::PASSWORD)->count());
    }

    public function test_phase_two_needs_phase_one_first(): void
    {
        $this->artisan('kpone:trial-data', ['--phase' => '2'])
            ->expectsQuestion('Type this site\'s host name to confirm (for example trial.example.com)', self::HOST)
            ->expectsOutputToContain('Run --phase=1 first')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_phase_two_runs_one_clinic_day_at_every_branch_once(): void
    {
        Storage::fake('local');
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);

        $this->runPhaseTwo()->assertExitCode(Command::SUCCESS);
        foreach (Branch::query()->get() as $branch) {
            $at = fn (string $table) => DB::table($table)->where('branch_id', $branch->id);
            $code = $branch->code;
            $posters = $at('branch_display_settings')->value('posters');
            $this->assertCount(PhaseTwoClinicDay::POSTERS_PER_BRANCH, json_decode((string) $posters, true), $code);

            // Every scenario patient has one visit: five completed, five still open in different states.
            $this->assertSame(count(PhaseTwoClinicDay::SCENARIOS), $at('visits')->count(), $code);
            $this->assertSame(5, $at('visits')->where('status', 'completed')->count(), $code);
            $this->assertSame(1, $at('visits')->where('visit_type', 'otc')->count(), $code);
            $this->assertSame(1, $at('dispensary_cases')->where('status', 'pending')->count(), $code);
            $this->assertSame(1, $at('dispensary_cases')->where('status', 'returned_to_doctor')->count(), $code);
            $this->assertSame(2, $at('consultation_holds')->whereNull('resumed_at')->count(), $code);
            $this->assertSame(1, $at('queue_call_holds')->count(), $code);
            $this->assertSame(1, $at('queue_entries')->where('status', 'waiting')->count(), $code);
            $this->assertSame(1, $at('queue_entries')->where('status', 'serving')->whereNotIn('visit_id', DB::table('consultation_holds')->whereNull('resumed_at')->select('visit_id'))->count(), $code);
            $this->assertSame(1, $at('queue_treatment_steps')->count(), $code);
            $this->assertSame(2, $at('visit_triage_assessments')->count(), $code);
            $this->assertGreaterThanOrEqual(3, $at('queue_calls')->whereIn('service', ['dispensary', 'treatment'])->count(), $code);

            // Billing: one voided invoice, one approved refund, Panel and pay later, and nothing left to pay.
            $this->assertSame(1, $at('invoices')->where('status', 'voided')->count(), $code);
            $this->assertSame(1, $at('payment_refunds')->count(), $code);
            $this->assertSame(1, DB::table('coverage_allocations')->whereIn('invoice_id', $at('invoices')->select('id'))->where('status', 'approved')->count(), $code);
            $this->assertSame(1, DB::table('patient_receivables')->whereIn('invoice_id', $at('invoices')->select('id'))->where('status', 'approved')->count(), $code);

            // Documents on the first patient's consultation, and the day-end records.
            $this->assertSame(1, $at('medical_certificates')->count(), $code);
            $this->assertSame(1, $at('referral_documents')->count(), $code);
            $this->assertSame(1, $at('attendance_documents')->count(), $code);
            $this->assertSame(1, $at('payment_reconciliations')->count(), $code);
            $this->assertSame(1, $at('cash_closings')->count(), $code);
        }
        $audit = DB::table('audit_logs')->where('event', 'trial_data.phase_completed')->whereRaw("metadata::jsonb->>'phase' = '2'")->sole();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame(30, json_decode((string) $audit->metadata, true)['created']['visits']);

        $after = $this->counts();
        $this->runPhaseTwo()->expectsOutputToContain('already complete')->assertExitCode(Command::SUCCESS);
        $this->assertSame($after, $this->counts());
    }

    public function test_phase_three_reuses_automatic_claims_when_they_are_switched_on(): void
    {
        // The live server creates a prepared claim on every Panel approval (PNL-28a, on by default outside tests).
        config(['panel-claims.auto_create' => true]);
        $this->test_phase_three_takes_panel_claims_through_every_stage_once();
        foreach (Branch::query()->get() as $branch) {
            $this->assertSame(count(PhaseThreePanel::SCENARIOS), DB::table('panel_claims')->where('branch_id', $branch->id)->count(), $branch->code);
        }
    }

    public function test_phase_three_takes_panel_claims_through_every_stage_once(): void
    {
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);

        $this->runPhase('3')->assertExitCode(Command::SUCCESS);
        $expected = ['prepared' => 1, 'submitted' => 3, 'queried' => 1, 'accepted' => 2, 'partially_accepted' => 1, 'rejected' => 1, 'partially_paid' => 1, 'paid' => 1];
        foreach (Branch::query()->get() as $branch) {
            $statuses = DB::table('panel_claims')->where('branch_id', $branch->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
            ksort($statuses);
            ksort($expected);
            $this->assertSame($expected, $statuses, $branch->code);
            $this->assertSame(count(PhaseThreePanel::SCENARIOS), DB::table('visits')->where('branch_id', $branch->id)->where('status', 'completed')->count(), $branch->code);
            $this->assertSame(1, DB::table('panel_claim_adjustments')->where('branch_id', $branch->id)->where('status', 'confirmed')->count(), $branch->code);
            $this->assertSame(1, DB::table('panel_remittances')->where('branch_id', $branch->id)->count(), $branch->code);
        }
        // A bulk claim (two claims sharing one submission on Panel B) and one corrected payment per branch.
        $panelB = DB::table('panels')->where('code', PhaseOneFoundation::PANELS[1]['code'])->value('id');
        $this->assertSame(6, DB::table('panel_claims')->where('panel_id', $panelB)->count());
        $this->assertSame(3, DB::table('panel_claim_payment_reversals')->count());
        $this->assertSame(['approved' => 1, 'proposed' => 1], DB::table('price_change_proposals')->where('agreement_reference', PhaseThreePanel::AGREEMENT_REFERENCE)
            ->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all());

        $after = $this->counts();
        $this->runPhase('3')->expectsOutputToContain('already complete')->assertExitCode(Command::SUCCESS);
        $this->assertSame($after, $this->counts());
    }

    private function runPhase(string $phase): PendingCommand
    {
        return $this->artisan('kpone:trial-data', ['--phase' => $phase])
            ->expectsQuestion('Type this site\'s host name to confirm (for example trial.example.com)', self::HOST);
    }

    public function test_phase_four_runs_the_inventory_workflows_once_at_every_branch(): void
    {
        Storage::fake('local');
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);
        $this->runPhase('2')->assertExitCode(Command::SUCCESS);

        $this->runPhase('4')->assertExitCode(Command::SUCCESS);
        foreach (Branch::query()->get() as $branch) {
            $code = $branch->code;
            $invoices = DB::table('inventory_supplier_invoices')->where('branch_id', $branch->id)->orderBy('id')->pluck('status')->all();
            $this->assertSame(['matched', 'waiting'], $invoices, $code);
            $this->assertSame(1, DB::table('inventory_receiving_reviews')->where('branch_id', $branch->id)->count(), $code);
            $this->assertSame(1, DB::table('inventory_supplier_returns')->where('branch_id', $branch->id)->count(), $code);
            $this->assertSame(['count', 'count', 'writeoff', 'writeoff'], DB::table('inventory_governed_cases')->where('branch_id', $branch->id)->orderBy('kind')->pluck('kind')->all(), $code);
            $this->assertSame(2, DB::table('inventory_treatment_usages')->where('branch_id', $branch->id)->count(), $code);
            $this->assertSame(1, DB::table('inventory_controlled_register_checks')->where('branch_id', $branch->id)->count(), $code);
            $this->assertSame(1, DB::table('dispensary_returns')->where('branch_id', $branch->id)->count(), $code);
            $this->assertSame(2, DB::table('inventory_stock_reviews')->where('branch_id', $branch->id)->whereIn('worklist', ['low_stock', 'expiring'])->count(), $code);
        }
        $this->assertSame(1, DB::table('inventory_stock_requests')->count());
        $this->assertSame(1, DB::table('inventory_transfer_differences')->count());
        $this->assertSame(1, DB::table('inventory_recalls')->count());

        $after = $this->counts();
        $this->runPhase('4')->expectsOutputToContain('already complete')->assertExitCode(Command::SUCCESS);
        $this->assertSame($after, $this->counts());
    }

    /**
     * Phase 5 adds an "earlier today" attendance session with overtime. Just after midnight in Kuala Lumpur
     * (16:00 to 16:59 UTC) that session is only minutes long, so the overtime asked for must fit it; and until 08:30 there the
     * fictional incident, reported at 08:30, must be on an earlier day. The clock is frozen
     * at the latest past occurrence of each UTC time, so it stays close to the database's own clock.
     */
    #[DataProvider('justAfterKualaLumpurMidnight')]
    public function test_phase_five_works_just_after_midnight_in_kuala_lumpur(string $utcTime): void
    {
        $at = CarbonImmutable::now('UTC')->setTimeFromTimeString($utcTime);
        $this->travelTo($at->isFuture() ? $at->subDay() : $at);
        Storage::fake('local');
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);

        $this->runPhase('5')->assertExitCode(Command::SUCCESS);
        $this->assertSame(1, DB::table('hr_overtime_requests')->where('status', 'approved')->count());
        $this->assertLessThanOrEqual(60, (int) DB::table('hr_overtime_requests')->value('minutes_approved'));
    }

    /** @return array<string, array{string}> */
    public static function justAfterKualaLumpurMidnight(): array
    {
        return ['00:04 in Kuala Lumpur' => ['16:04:00'], '00:30 in Kuala Lumpur' => ['16:30:00'], '01:30 in Kuala Lumpur' => ['17:30:00'], '08:00 in Kuala Lumpur' => ['00:00:00']];
    }

    public function test_phase_five_fills_kpone_hr_once_with_fictional_payroll_tables(): void
    {
        // This success case needs an earlier session today; the command deliberately refuses before 00:03.
        $at = CarbonImmutable::now($this->cheras->timezone)->startOfDay()->addHours(12);
        $this->travelTo($at->isFuture() ? $at->subDay() : $at);
        Storage::fake('local');
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);

        $this->runPhase('5')->assertExitCode(Command::SUCCESS);
        $statuses = fn (string $table): array => DB::table($table)->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
        $this->assertSame(['approved' => 1, 'cancelled' => 1, 'pending' => 1, 'rejected' => 1], $statuses('hr_leave_requests'));
        $this->assertSame(['approved' => 3, 'draft' => 3], $statuses('hr_roster_versions'));
        $this->assertSame(['closed' => 1, 'open' => 3], $statuses('hr_clock_sessions'));
        $this->assertSame(['approved' => 1, 'pending' => 1], $statuses('hr_attendance_corrections'));
        $this->assertSame(['confirmed' => 2, 'waiting' => 1], $statuses('hr_bank_details'));
        $this->assertSame(['paid' => 2, 'waiting' => 1], $statuses('hr_staff_claims'));
        $this->assertSame(['accepted' => 1, 'waiting' => 1], $statuses('hr_credential_records'));
        $this->assertSame(['locked' => 1], $statuses('hr_payroll_runs'));
        $this->assertSame(['confirmed' => 19], $statuses('hr_pay_profiles'));
        $this->assertSame(19, DB::table('hr_payslips')->count());
        $this->assertSame(2, DB::table('hr_payroll_exports')->count());

        // Every statutory table is marked fictional and was checked by someone other than its entrant.
        $tables = DB::table('hr_statutory_tables')->get();
        $this->assertCount(4, $tables);
        foreach ($tables as $table) {
            $this->assertStringStartsWith('FICTIONAL', (string) $table->category);
            $this->assertSame(PhaseFiveHr::FICTIONAL, $table->source_note);
            $this->assertSame('checked', $table->status);
            $this->assertNotSame($table->entered_by_user_id, $table->decided_by_user_id);
        }
        // The Director's and the HR Manager's own pay stays outside KPOne; the Director has no staff file.
        $director = User::role('director')->sole();
        $hr = User::query()->where('email', TrialDataContext::email('hr'))->sole();
        $this->assertSame(0, DB::table('hr_pay_profiles')->whereIn('worker_id', DB::table('hr_workers')->whereIn('user_id', [$director->id, $hr->id])->select('id'))->count());
        $this->assertSame(0, DB::table('hr_workers')->where('user_id', $director->id)->count());

        $after = $this->counts();
        $this->runPhase('5')->expectsOutputToContain('already complete')->assertExitCode(Command::SUCCESS);
        $this->assertSame($after, $this->counts());
    }

    public function test_placeholder_files_are_real_png_and_pdf_marked_fictional(): void
    {
        $files = new PlaceholderFiles;
        $image = $files->image('Poster one', 640, 360);
        $pdf = $files->pdf('Trial document', 'Detail line');
        $this->assertSame('image/png', $image->getMimeType());
        $this->assertSame([640, 360], array_slice((array) getimagesize($image->getPathname()), 0, 2));
        $this->assertSame('application/pdf', $pdf->getMimeType());
        $bytes = (string) file_get_contents($pdf->getPathname());
        $this->assertStringContainsString(PlaceholderFiles::MARK, $bytes);
        $this->assertStringEndsWith("%%EOF\n", $bytes);
        $paths = [$image->getPathname(), $pdf->getPathname()];
        $files->cleanUp();
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    private function runPhaseTwo(): PendingCommand
    {
        return $this->artisan('kpone:trial-data', ['--phase' => '2'])
            ->expectsQuestion('Type this site\'s host name to confirm (for example trial.example.com)', self::HOST);
    }

    private function confirmHost(): PendingCommand
    {
        return $this->artisan('kpone:trial-data')
            ->expectsQuestion('Type this site\'s host name to confirm (for example trial.example.com)', self::HOST);
    }

    private function runPhaseOne(): PendingCommand
    {
        return $this->confirmHost()
            ->expectsQuestion('Shared password for the fictional accounts (hidden)', self::PASSWORD)
            ->expectsQuestion('Type the shared password again (hidden)', self::PASSWORD);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (self::COUNTED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
