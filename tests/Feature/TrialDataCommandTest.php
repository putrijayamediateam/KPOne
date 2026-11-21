<?php

namespace Tests\Feature;

use App\Domain\Identity\Services\DirectorBootstrapService;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\TrialData\PhaseOneFoundation;
use App\Domain\Shared\TrialData\PhaseTwoClinicDay;
use App\Domain\Shared\TrialData\PlaceholderFiles;
use App\Domain\Shared\TrialData\TrialDataContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
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
        'business_detail_commands', 'branch_document_footers', 'branch_display_settings', 'audit_logs',
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

    public function test_phase_two_adds_marked_posters_once_at_every_branch(): void
    {
        Storage::fake('local');
        $this->runPhaseOne()->assertExitCode(Command::SUCCESS);

        $this->runPhaseTwo()->assertExitCode(Command::SUCCESS);
        foreach (Branch::query()->get() as $branch) {
            $posters = DB::table('branch_display_settings')->where('branch_id', $branch->id)->value('posters');
            $this->assertCount(PhaseTwoClinicDay::POSTERS_PER_BRANCH, json_decode((string) $posters, true), $branch->code);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'trial_data.phase_completed')->whereRaw("metadata::jsonb->>'phase' = '2'")->count());

        $after = $this->counts();
        $this->runPhaseTwo()->expectsOutputToContain('already complete')->assertExitCode(Command::SUCCESS);
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
