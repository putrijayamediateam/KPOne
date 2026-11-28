<?php

namespace Tests\Feature;

use App\Domain\Identity\Services\DirectorBootstrapService;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * TRIAL-DATA end to end, as on the trial server: every phase in order, each step really committed, with PNL-28a's
 * automatic Panel claims on (the default outside tests). The other trial-data tests run inside one rolled-back test
 * transaction, so PostgreSQL's deferred checks (such as completed_visit_evidence, which runs at COMMIT) never fire
 * there. This test uses a freshly migrated, committed database instead, as WebsiteReleaseTest does.
 */
class TrialDataEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'trial.kpjaya.test';

    private const PASSWORD = 'Trial-Shared-Pass-2026!';

    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(KPOneReferenceSeeder::class);
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['app.url' => 'https://'.self::HOST, 'trial-data.allowed' => true, 'panel-claims.auto_create' => true]);
        $cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        // CI-CLOCK-3: the phases close and recount "today", so a first run before local midnight and a rerun after it
        // differ by a day's records. Run at the latest past local noon (BASELINE 6.21: dates in the branch time zone);
        // TrialDataCommandTest covers the minutes around midnight.
        $noon = CarbonImmutable::now($cheras->timezone)->startOfDay()->addHours(12);
        $this->travelTo($noon->isFuture() ? $noon->subDay() : $noon);
        $director = app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Owner Director', 'email' => 'owner.director@example.com', 'staff_number' => 'OWNER-0001',
            'job_title' => 'Director', 'branch_id' => $cheras->id, 'credential_strategy' => 'google_only',
        ]);
        foreach (Branch::query()->where('code', '!=', 'CHERAS')->get() as $branch) {
            StaffBranchAssignmentBootstrapper::create($director->staffProfile, $branch, ['is_primary' => false]);
        }
    }

    public function test_every_phase_commits_in_order_with_automatic_claims_on_and_a_rerun_adds_nothing(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'This test must run with no outer transaction, so deferred checks fire.');

        $this->phase('1')
            ->expectsQuestion('Shared password for the fictional accounts (hidden)', self::PASSWORD)
            ->expectsQuestion('Type the shared password again (hidden)', self::PASSWORD)
            ->assertExitCode(Command::SUCCESS);
        foreach (['2', '3', '4', '5', '6'] as $phase) {
            $this->phase($phase)->doesntExpectOutputToContain('A step failed')->assertExitCode(Command::SUCCESS);
        }

        foreach (Branch::query()->get() as $branch) {
            // Phase 2's ten patients and phase 3's eleven Panel patients each have their visit.
            $this->assertSame(21, DB::table('visits')->where('branch_id', $branch->id)->count(), $branch->code);
            $this->assertSame(16, DB::table('visits')->where('branch_id', $branch->id)->where('status', 'completed')->count(), $branch->code);
        }
        // Every phase 3 claim stage, with no duplicate claim (phase 2's Panel visit adds one prepared claim per branch).
        $this->assertSame(['accepted' => 6, 'paid' => 3, 'partially_accepted' => 3, 'partially_paid' => 3, 'prepared' => 6, 'queried' => 3, 'rejected' => 3, 'submitted' => 9],
            DB::table('panel_claims')->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all());

        $tables = ['visits', 'panel_claims', 'inventory_governed_cases', 'hr_payroll_runs', 'website_releases', 'audit_logs'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);
        foreach (['2', '3', '4', '5', '6'] as $phase) {
            $this->phase($phase)->expectsOutputToContain('already complete')->assertExitCode(Command::SUCCESS);
        }
        $this->assertSame($before, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
    }

    private function phase(string $phase): PendingCommand
    {
        return $this->artisan('kpone:trial-data', ['--phase' => $phase])
            ->expectsQuestion('Type this site\'s host name to confirm (for example trial.example.com)', self::HOST);
    }
}
