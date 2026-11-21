<?php

namespace Tests\Feature;

use App\Domain\Identity\Services\DirectorBootstrapService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\TrialData\PhaseSixRest;
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
 * TRIAL-DATA phase 6. A separate class because the website release freeze owns a real transaction and refuses to run
 * inside a test savepoint, so this test uses a freshly migrated, committed database (as WebsiteReleaseTest does).
 */
class TrialDataPhaseSixTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'trial.kpjaya.test';

    private const PASSWORD = 'Trial-Shared-Pass-2026!';

    /** @var list<string> */
    private const COUNTED_TABLES = [
        'compliance_licences', 'compliance_fridges', 'compliance_audits', 'compliance_tasks', 'website_content_items',
        'website_releases', 'public_checkin_links', 'patient_merge_requests', 'patients', 'audit_logs',
    ];

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
        config(['app.url' => 'https://'.self::HOST, 'trial-data.allowed' => true]);
        $cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $director = app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Owner Director', 'email' => 'owner.director@example.com', 'staff_number' => 'OWNER-0001',
            'job_title' => 'Director', 'branch_id' => $cheras->id, 'credential_strategy' => 'google_only',
        ]);
        foreach (Branch::query()->where('code', '!=', 'CHERAS')->get() as $branch) {
            StaffBranchAssignmentBootstrapper::create($director->staffProfile, $branch, ['is_primary' => false]);
        }
    }

    public function test_phase_six_fills_compliance_website_triage_qr_links_and_merges_once(): void
    {
        $this->confirmHost('1')
            ->expectsQuestion('Shared password for the fictional accounts (hidden)', self::PASSWORD)
            ->expectsQuestion('Type the shared password again (hidden)', self::PASSWORD)
            ->assertExitCode(Command::SUCCESS);

        $this->confirmHost('6')->assertExitCode(Command::SUCCESS);
        foreach (Branch::query()->get() as $branch) {
            $this->assertSame(1, DB::table('compliance_licences')->where('branch_id', $branch->id)->count(), $branch->code);
            $this->assertSame(2, DB::table('compliance_fridge_readings')->where('branch_id', $branch->id)->count(), $branch->code);
            $this->assertSame(1, DB::table('compliance_audits')->where('branch_id', $branch->id)->count(), $branch->code);
            $this->assertSame(1, DB::table('public_checkin_links')->where('branch_id', $branch->id)->where('is_active', true)->count(), $branch->code);
        }
        $this->assertSame(1, DB::table('compliance_tasks')->whereNotNull('verified_at')->count());
        $this->assertSame(1, DB::table('website_releases')->count());
        $this->assertSame(1, DB::table('website_release_controls')->where('state', 'approved')->count());
        $guideline = DB::table('triage_guideline_versions')->where('status', 'approved')->sole();
        $this->assertSame(PhaseSixRest::GUIDELINE_SIGNER, $guideline->approving_doctor_name);
        $this->assertNotSame($guideline->entered_by_user_id, $guideline->approved_by_user_id);
        $this->assertSame(['approved' => 1, 'proposed' => 1], DB::table('patient_merge_requests')->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')
            ->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all());

        $after = $this->counts();
        $this->confirmHost('6')->expectsOutputToContain('already complete')->assertExitCode(Command::SUCCESS);
        $this->assertSame($after, $this->counts());
    }

    private function confirmHost(string $phase): PendingCommand
    {
        return $this->artisan('kpone:trial-data', ['--phase' => $phase])
            ->expectsQuestion('Type this site\'s host name to confirm (for example trial.example.com)', self::HOST);
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
