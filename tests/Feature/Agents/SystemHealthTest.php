<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Health\SystemHealthOfficer;
use App\Domain\Agents\Health\SystemHealthTool;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Agents\Tools\AgentToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

/** AG-10: the system.health data source and Faris's check report counts only, never log text. */
class SystemHealthTest extends VisitTestCase
{
    private string $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->logs = storage_path('framework/testing/ag10-logs-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($this->logs);
        $this->app->instance(SystemHealthTool::class, new SystemHealthTool($this->logs));
        $this->travelTo(CarbonImmutable::parse('2026-11-06T06:00:00Z'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logs);
        parent::tearDown();
    }

    private function startRun(string $agent = SystemHealthOfficer::AGENT): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start($agent, SystemHealthOfficer::TASK, null, organisationId: $this->organisation->id);
    }

    /** @param list<string> $lines */
    private function log(string $file, array $lines): void
    {
        file_put_contents($this->logs.'/'.$file, implode("\n", $lines)."\n");
        touch($this->logs.'/'.$file, now()->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function read(array $input = []): array
    {
        return app(AgentToolGateway::class)->call($this->startRun(), 'system.health', $this->actor('director'), null, $input);
    }

    public function test_the_registry_holds_system_health_through_its_tag(): void
    {
        $tool = app(AgentToolRegistry::class)->get('system.health');
        $this->assertInstanceOf(SystemHealthTool::class, $tool);
        $this->assertSame('ai_agents.view.organisation', $tool->requiredPermission());
    }

    public function test_error_level_entries_are_counted_and_log_text_never_leaves(): void
    {
        $this->log('laravel.log', [
            '[2026-11-05 05:00:00] testing.ERROR: SENTINEL OLD before the window',
            '[2026-11-06 01:00:00] testing.ERROR: SENTINEL patient Siti 900101145678 sentinel@kpone.test',
            '#0 /app/vendor/stack/frame.php(12): [2026-11-06 01:00:00] testing.ERROR: SENTINEL inside a trace',
            '[2026-11-06 02:00:00] testing.CRITICAL: SENTINEL database down',
            '[2026-11-06 03:00:00] testing.INFO: SENTINEL just information',
            '[2026-11-06 04:00:00] testing.WARNING: SENTINEL a warning',
        ]);
        $this->log('laravel-2026-11-06.log', ['[2026-11-06T05:30:00.123456+00:00] production.EMERGENCY: SENTINEL daily file']);
        $this->log('laravel-2026-10-01.log', ['[2026-10-01 05:30:00] production.ERROR: SENTINEL stale file']);
        touch($this->logs.'/laravel-2026-10-01.log', CarbonImmutable::parse('2026-10-01T06:00:00Z')->getTimestamp());

        $result = $this->read();

        $this->assertSame(['error' => 1, 'critical' => 1, 'alert' => 0, 'emergency' => 1, 'total' => 3], $result['errorLogEntries']);
        $this->assertSame(['2026-11-05T06:00:00Z', 'found', false, false], [$result['since'], $result['logFiles'], $result['logReadLimited'], $result['allClear']]);
        $this->assertStringNotContainsString('SENTINEL', (string) json_encode($result));
        $this->assertStringNotContainsString('SENTINEL', (string) DB::table('ai_agent_run_steps')->pluck('summary')->implode(' '));
    }

    public function test_a_missing_log_file_reads_as_missing_with_zero_counts(): void
    {
        $result = $this->read();

        $this->assertSame('missing', $result['logFiles']);
        $this->assertSame(0, $result['errorLogEntries']['total']);
        $this->assertSame([0, 0, 0, true], [$result['failedScheduledRuns'], $result['pendingMigrations'], $result['failedQueueJobs'], $result['allClear']]);

        File::deleteDirectory($this->logs);
        $this->assertSame('missing', $this->read()['logFiles'], 'a missing log directory is not an error');
    }

    public function test_a_pending_migration_is_detected(): void
    {
        $this->assertSame(0, $this->read()['pendingMigrations']);

        DB::table('migrations')->where('migration', '2026_11_05_100000_create_ai_agent_registry')->delete();

        $result = $this->read();
        $this->assertSame([1, false], [$result['pendingMigrations'], $result['allClear']]);
    }

    public function test_failed_scheduled_runs_and_failed_queue_jobs_since_the_window_are_counted(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $recorder->fail($recorder->start('inventory_analyst', 'daily_stock_review', null, organisationId: $this->organisation->id), 'data_source.refused');
        $this->travelTo(CarbonImmutable::parse('2026-11-04T06:00:00Z'));
        $recorder->fail($recorder->start('inventory_analyst', 'daily_stock_review', null, organisationId: $this->organisation->id), 'data_source.refused');
        $this->travelTo(CarbonImmutable::parse('2026-11-06T06:00:00Z'));
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'SENTINEL job text', 'failed_at' => now()]);

        $result = $this->read();

        $this->assertSame([1, 1], [$result['failedScheduledRuns'], $result['failedQueueJobs']]);
        $this->assertStringNotContainsString('SENTINEL', (string) json_encode($result));
    }

    public function test_faris_counts_since_his_previous_completed_check_and_records_one_line(): void
    {
        $director = $this->actor('director');
        $officer = app(SystemHealthOfficer::class);
        $recorder = app(AgentRunRecorder::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-06T03:00:00Z'));
        $first = $this->startRun();
        $this->assertSame('All clear.', $officer->check($first, $director)->summary());
        $recorder->complete($first);

        $this->travelTo(CarbonImmutable::parse('2026-11-06T07:00:00Z'));
        $this->log('laravel.log', ['[2026-11-06 02:00:00] testing.ERROR: before the last check', '[2026-11-06 05:00:00] testing.ERROR: after the last check']);
        $run = $this->startRun();
        $check = $officer->check($run, $director);

        $this->assertSame(['2026-11-06T03:00:00Z', 1], [$check->since, $check->errorLogEntries]);
        $this->assertSame('1 error log entry, 0 failed agent runs, 0 pending migrations, 0 failed queue jobs.', $check->summary());
        $steps = DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->orderBy('sequence')->get(['kind', 'tool_code', 'summary']);
        $this->assertSame([['data_read', 'system.health'], ['decision', null]], $steps->map(fn ($s) => [$s->kind, $s->tool_code])->all());
        $this->assertSame('working', $run->refresh()->status, 'the caller finishes its own run');
    }

    public function test_another_agent_or_a_requester_without_agent_access_is_refused(): void
    {
        $refusals = 0;
        foreach ([
            fn () => app(SystemHealthOfficer::class)->check($this->startRun('stocktake_officer'), $this->actor('director')),
            fn () => app(SystemHealthOfficer::class)->check($this->startRun(), $this->actor('ca')),
        ] as $attempt) {
            try {
                $attempt();
            } catch (AuthorizationException) {
                $refusals++;
            }
        }

        $this->assertSame(2, $refusals);
        $this->assertSame(0, DB::table('ai_agent_run_steps')->count());
    }
}
