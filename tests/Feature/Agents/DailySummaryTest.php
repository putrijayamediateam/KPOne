<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\DailySummary\AiDailySummarySection;
use App\Domain\Agents\DailySummary\DailyRun;
use App\Domain\Agents\DailySummary\ReportAssistant;
use App\Domain\Agents\Health\SystemHealthOfficer;
use App\Domain\Agents\Health\SystemHealthTool;
use App\Domain\Agents\Inventory\InventoryAnalyst;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Stocktake\StocktakeOfficer;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Agents\Tools\AgentToolRegistry;
use App\Domain\Agents\Tools\OrganisationWideSystemTool;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-8: the 07:00 and 14:00 agent runs, the stored sections and the daily summary page. */
class DailySummaryTest extends QueueTestCase
{
    private string $logs;

    /** @var array<string, mixed> */
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        // 00:30 UTC is 08:30 in Kuala Lumpur: after the 07:00 slot, before 14:00.
        $this->travelTo(CarbonImmutable::parse('2026-11-06T00:30:00Z'));
        $this->seed(KPOneAgentSeeder::class);
        $this->logs = storage_path('framework/testing/ag8-logs-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($this->logs);
        file_put_contents($this->logs.'/laravel.log', '');
        $this->app->instance(SystemHealthTool::class, new SystemHealthTool($this->logs));
        // One low-stock SKU expiring in 90 days, so Hana has a restock and an expiry suggestion at CHERAS.
        $this->f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->level($this->f['location'], '20.000');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logs);
        parent::tearDown();
    }

    private function level(InventoryLocation $location, string $level): void
    {
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->organisation->id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $this->f['sku']->id, 'reorder_level' => $level, 'updated_by_user_id' => $this->f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{stored: list<string>, skipped: list<string>, failed: list<string>} */
    private function dailyRun(string $slot, ?string $branch = null): array
    {
        return app(DailyRun::class)->run($slot, $branch);
    }

    private function activeBranches(): int
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('is_active', true)->count();
    }

    private function section(string $section, string $slot, ?Branch $branch = null, ?string $date = null): ?AiDailySummarySection
    {
        return AiDailySummarySection::query()->where('organisation_id', $this->organisation->id)->where('section', $section)->where('slot', $slot)
            ->where('local_date', $date ?? now('Asia/Kuala_Lumpur')->toDateString())
            ->when($section === 'faris', fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', ($branch ?? $this->branch)->id))->first();
    }

    public function test_both_slots_are_scheduled_hourly_so_each_branch_runs_once_its_own_clock_reaches_the_slot(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'agents:daily-run'))
            ->map(fn ($event): array => [$event->expression, trim(Str::after((string) $event->command, 'agents:daily-run')), $event->withoutOverlapping, $event->onOneServer])->values()->all();

        $this->assertSame([['5 * * * *', '0700', true, true], ['10 * * * *', '1400', true, true]], $events);
    }

    public function test_nothing_runs_before_the_slot_on_the_branch_clock(): void
    {
        // 22:30 UTC is 06:30 the next morning in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2026-11-05T22:30:00Z'));
        $outcome = $this->dailyRun('0700');

        $this->assertSame([[], []], [$outcome['stored'], $outcome['failed']]);
        $this->assertCount($this->activeBranches(), $outcome['skipped']);
        $this->assertSame(0, AiDailySummarySection::query()->count());
        $this->assertSame(0, AiAgentRun::query()->count());
    }

    public function test_each_branch_runs_its_slot_on_its_own_clock_and_stores_its_own_local_date(): void
    {
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $puchong->forceFill(['timezone' => 'Asia/Jakarta'])->save(); // UTC+7, one hour behind Kuala Lumpur

        // 23:30 UTC: 07:30 on 6 Nov in Kuala Lumpur, 06:30 in Jakarta.
        $this->travelTo(CarbonImmutable::parse('2026-11-05T23:30:00Z'));
        $outcome = $this->dailyRun('0700');
        $this->assertContains('PUCHONG: before 0700', $outcome['skipped']);
        $this->assertNotNull($this->section('lina', '0700', null, '2026-11-06'));
        $this->assertNotNull($this->section('faris', '0700', null, '2026-11-06'));
        $this->assertNull($this->section('lina', '0700', $puchong, '2026-11-06'));

        // An hour later Jakarta reaches 07:00 and stores its own sections; Cheras and Faris repeat nothing.
        $this->travelTo(CarbonImmutable::parse('2026-11-06T00:30:00Z'));
        $outcome = $this->dailyRun('0700');
        $this->assertSame(['PUCHONG lina 0700 2026-11-06', 'PUCHONG hana 0700 2026-11-06', 'PUCHONG rizal 0700 2026-11-06'], $outcome['stored']);
        $this->assertSame('2026-11-05', $this->section('lina', '0700', $puchong, '2026-11-06')->payload['date']);

        // At 17:30 UTC it is already 7 Nov in Kuala Lumpur (01:30) but still 6 Nov in Jakarta (00:30): neither runs.
        $this->travelTo(CarbonImmutable::parse('2026-11-06T17:30:00Z'));
        $this->assertSame([], $this->dailyRun('0700')['stored']);
    }

    public function test_the_morning_run_stores_every_section_once_and_a_second_call_repeats_nothing(): void
    {
        $outcome = $this->dailyRun('0700');

        $this->assertSame([], $outcome['failed']);
        $this->assertCount(3 * $this->activeBranches() + 1, $outcome['stored']);
        $this->assertSame(3 * $this->activeBranches() + 1, AiDailySummarySection::query()->count());
        foreach (AiDailySummarySection::query()->get() as $row) {
            $this->assertSame('completed', AiAgentRun::query()->whereKey($row->ai_agent_run_id)->value('status'));
            $this->assertSame(hash('sha256', (string) json_encode($row->payload)), $row->payload_hash);
        }
        $this->assertNull(AiAgentRun::query()->whereNotNull('requested_by_user_id')->first(), 'The daily runs act as system, never as a person.');

        $lina = $this->section('lina', '0700')->payload;
        $this->assertSame(['2026-11-05', 'whole_day', '2026-10-29'], [$lina['date'], $lina['kind'], $lina['comparedWith']]);
        $this->assertSame(['now', 'before'], array_keys($lina['clinic']));
        $this->assertSame(['lowStock' => 1, 'expiring' => 1, 'expired' => 0], $lina['stock']);
        $hana = $this->section('hana', '0700')->payload;
        $this->assertSame(['total' => 1, 'pending' => 1], $hana['counts']['restock']);
        $this->assertSame(['total' => 1, 'pending' => 1], $hana['counts']['expiry']);
        $this->assertSame(1, $this->section('rizal', '0700')->payload['countsDue']);
        $this->assertTrue($this->section('faris', '0700')->payload['allClear']);

        $runs = AiAgentRun::query()->count();
        $proposals = AiProposal::query()->count();
        $again = $this->dailyRun('0700');
        $this->assertSame([[], []], [$again['stored'], $again['failed']]);
        $this->assertSame([$runs, $proposals, 3 * $this->activeBranches() + 1], [AiAgentRun::query()->count(), AiProposal::query()->count(), AiDailySummarySection::query()->count()]);
    }

    public function test_the_afternoon_update_is_today_so_far_without_comparison_and_repeats_no_suggestion(): void
    {
        $this->dailyRun('0700');
        $proposals = AiProposal::query()->pluck('id')->all();

        $this->travelTo(CarbonImmutable::parse('2026-11-06T06:30:00Z'));
        $outcome = $this->dailyRun('1400');

        $this->assertSame([], $outcome['failed']);
        $this->assertCount(2 * $this->activeBranches() + 1, $outcome['stored']);
        $lina = $this->section('lina', '1400')->payload;
        $this->assertSame(['2026-11-06', 'so_far', null, null, null], [$lina['date'], $lina['kind'], $lina['comparedWith'], $lina['clinic']['before'], $lina['money']['before']]);
        $this->assertNull($lina['doctors'][0]['seenBefore'] ?? null);
        $this->assertNull($this->section('rizal', '1400'));
        $this->assertSame($proposals, AiProposal::query()->pluck('id')->all(), 'The 14:00 check proposes nothing already proposed today.');
        $this->assertSame(['total' => 1, 'pending' => 1], $this->section('hana', '1400')->payload['counts']['restock']);
    }

    public function test_a_manual_inventory_review_never_stands_in_for_the_daily_section(): void
    {
        // A manual review of restock only, before the daily run: the daily run still runs every 07:00 check itself.
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--checks' => 'restock'])->assertSuccessful();
        $manual = AiAgentRun::query()->sole();
        $this->assertSame(1, AiProposal::query()->count());

        $this->assertSame([], $this->dailyRun('0700', 'CHERAS')['failed']);

        $section = $this->section('hana', '0700');
        $this->assertNotSame($manual->id, $section->ai_agent_run_id);
        $this->assertSame(['total' => 1, 'pending' => 1], $section->payload['counts']['expiry']);
        // The restock suggestion the manual run made is not proposed again.
        $this->assertSame(['total' => 1, 'pending' => 1], $section->payload['counts']['restock']);
        $this->assertSame(2, AiProposal::query()->count());
    }

    public function test_a_failed_attempt_is_retried_up_to_three_runs_and_a_branch_filter_skips_faris(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $date = now('Asia/Kuala_Lumpur')->toDateString();
        $fail = fn (string $suffix) => $recorder->fail($recorder->start(ReportAssistant::AGENT, ReportAssistant::TASK, $this->branch, idempotencyKey: 'daily:'.ReportAssistant::AGENT.":{$this->branch->id}:{$date}:0700{$suffix}", organisationId: $this->organisation->id), 'daily_run.failed');
        $fail('');

        $outcome = $this->dailyRun('0700', 'CHERAS');
        $this->assertSame([], $outcome['failed']);
        $this->assertSame('daily:'.ReportAssistant::AGENT.":{$this->branch->id}:{$date}:0700:r2", AiAgentRun::query()->whereKey($this->section('lina', '0700')->ai_agent_run_id)->value('idempotency_key'));
        $this->assertNull($this->section('faris', '0700'));

        // Three failed attempts at another branch: the section is not tried again today.
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        foreach (['', ':r2', ':r3'] as $suffix) {
            $recorder->fail($recorder->start(StocktakeOfficer::AGENT, StocktakeOfficer::TASK, $puchong, idempotencyKey: 'daily:'.StocktakeOfficer::AGENT.":{$puchong->id}:{$date}:0700{$suffix}", organisationId: $this->organisation->id), 'daily_run.failed');
        }
        $outcome = $this->dailyRun('0700', 'PUCHONG');
        $this->assertSame(["PUCHONG rizal 0700 {$date}: no attempts left today"], $outcome['failed']);
        $this->assertNull($this->section('rizal', '0700', $puchong));
        $this->assertNotNull($this->section('lina', '0700', $puchong));
    }

    public function test_a_disabled_agent_fails_its_section_only_and_the_command_reports_it(): void
    {
        AiAgent::query()->where('code', SystemHealthOfficer::AGENT)->update(['is_enabled' => false]);

        $this->artisan('agents:daily-run', ['slot' => '0700'])->expectsOutputToContain('not started')->assertFailed();
        $this->assertNull($this->section('faris', '0700'));
        $this->assertNotNull($this->section('lina', '0700'));
        $this->artisan('agents:daily-run', ['slot' => '1200'])->assertFailed();
        $this->expectException(InvalidArgumentException::class);
        $this->dailyRun('1200');
    }

    public function test_stored_sections_never_change_and_come_only_from_a_completed_run_of_their_own_agent(): void
    {
        $this->dailyRun('0700', 'CHERAS');
        $row = $this->section('lina', '0700');
        $this->assertThrows(fn () => $row->forceFill(['slot' => '1400'])->save(), LogicException::class);
        $this->assertThrows(fn () => $row->delete(), LogicException::class);
        foreach (["UPDATE ai_daily_summary_sections SET slot = '1400' WHERE id = {$row->id}", "DELETE FROM ai_daily_summary_sections WHERE id = {$row->id}", 'TRUNCATE ai_daily_summary_sections'] as $sql) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::statement($sql)), QueryException::class);
        }

        $insert = fn (int $runId, string $section) => DB::transaction(fn () => DB::table('ai_daily_summary_sections')->insert([
            'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'local_date' => '2026-11-01', 'slot' => '0700',
            'section' => $section, 'ai_agent_run_id' => $runId, 'payload' => 'x', 'payload_hash' => str_repeat('a', 64),
        ]));
        // Hana's completed run cannot stand behind Lina's section.
        $this->assertThrows(fn () => $insert($this->section('hana', '0700')->ai_agent_run_id, 'lina'), QueryException::class);
        // A run still in progress cannot either.
        $running = app(AgentRunRecorder::class)->start(ReportAssistant::AGENT, ReportAssistant::TASK, $this->branch, organisationId: $this->organisation->id);
        $this->assertThrows(fn () => $insert($running->id, 'lina'), QueryException::class);
        // One section per run: Lina's 07:00 run cannot also stand behind another date's section.
        $this->assertThrows(fn () => $insert($row->ai_agent_run_id, 'lina'), QueryException::class);
        // A completed run of Lina's agent on another task cannot either.
        $recorder = app(AgentRunRecorder::class);
        $otherTask = $recorder->start(ReportAssistant::AGENT, 'other_report', $this->branch, organisationId: $this->organisation->id);
        $recorder->complete($otherTask);
        $this->assertThrows(fn () => $insert($otherTask->id, 'lina'), QueryException::class);
        $sameTask = $recorder->start(ReportAssistant::AGENT, ReportAssistant::TASK, $this->branch, organisationId: $this->organisation->id);
        $recorder->complete($sameTask);
        $insert($sameTask->id, 'lina'); // the control: a completed run of the right agent and task is accepted
        // Faris has no branch; every other section has one.
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('ai_daily_summary_sections')->insert([
            'organisation_id' => $this->organisation->id, 'branch_id' => null, 'local_date' => '2026-11-01', 'slot' => '0700',
            'section' => 'lina', 'ai_agent_run_id' => $row->ai_agent_run_id, 'payload' => 'x', 'payload_hash' => str_repeat('a', 64),
        ])), QueryException::class);
    }

    public function test_the_system_path_with_no_branch_needs_a_branchless_run_and_an_organisation_tool(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $gateway = app(AgentToolGateway::class);
        $branchless = $recorder->start(StocktakeOfficer::AGENT, StocktakeOfficer::TASK, null, organisationId: $this->organisation->id);
        $this->assertThrows(fn () => $gateway->callAsSystem($branchless, 'inventory.stock_counts', null), AuthorizationException::class);
        $branchRun = $recorder->start(ReportAssistant::AGENT, ReportAssistant::TASK, $this->branch, organisationId: $this->organisation->id);
        $this->assertThrows(fn () => $gateway->callAsSystem($branchRun, 'clinic.day_summary', null), AuthorizationException::class);
        $this->assertSame('CHERAS', $gateway->callAsSystem($branchRun, 'clinic.day_summary', $this->branch)['branches'][0]['branch']);
        $this->assertSame('data_read', DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $branchRun->id)->value('kind'));
    }

    public function test_the_page_shows_assigned_branches_and_only_the_sections_each_role_may_see(): void
    {
        $this->dailyRun('0700');
        $this->travelTo(CarbonImmutable::parse('2026-11-06T06:30:00Z'));
        $this->dailyRun('1400');

        $page = fn (string $role, string $query = '') => $this->actingAs($this->actor($role))->get('/agents/daily-summary'.$query)->assertOk()->viewData('page')['props']['summary'];

        $director = $page('director');
        $this->assertSame(['2026-11-06', '2026-11-06', '2026-10-06'], [$director['date'], $director['today'], $director['earliest']]);
        $this->assertSame(['CHERAS'], array_column($director['branches'], 'code'), 'Only the branches the viewer works at today.');
        $this->assertSame('CHERAS', $director['activeBranchCode'], 'The branch the viewer has open opens first.');
        $cheras = $director['branches'][0];
        $this->assertSame(['0700', '1400', '1400', '0700'], [$cheras['linaMorning']['slot'], $cheras['linaAfternoon']['slot'], $cheras['hana']['slot'], $cheras['rizal']['slot']]);
        $this->assertNotNull($cheras['linaMorning']['money']);
        $this->assertNotNull($cheras['linaMorning']['panel']);
        $this->assertSame('1400', $director['faris']['slot']);
        $this->assertTrue($director['canInbox']);

        $supervisor = $page('ca_supervisor')['branches'][0];
        $this->assertNotNull($supervisor['linaMorning']['clinic']);
        $this->assertSame([null, null], [$supervisor['linaMorning']['money'], $supervisor['linaMorning']['panel']]);
        $this->assertNotNull($supervisor['hana']);
        $this->assertNull($page('ca_supervisor')['faris']);

        $administrator = $page('administrator');
        $this->assertNotNull($administrator['branches'][0]['linaMorning']['money']);
        $this->assertNull($administrator['branches'][0]['linaMorning']['panel']);
        $this->assertNotNull($administrator['faris']);
        $this->assertFalse($administrator['canInbox']);

        $finance = $page('finance_officer');
        $this->assertNotNull($finance['branches'][0]['linaMorning']['panel']);
        $this->assertNull($finance['faris']);

        // A date outside the last 31 days, in the future or not a date falls back to today.
        foreach (['?date=2026-10-05', '?date=2026-11-07', '?date=2026-02-30', '?date=nonsense', '?date[]=x'] as $query) {
            $this->assertSame('2026-11-06', $page('director', $query)['date']);
        }
        $yesterday = $page('director', '?date=2026-11-05');
        $this->assertSame(['2026-11-05', null, null], [$yesterday['date'], $yesterday['branches'][0]['linaMorning'], $yesterday['faris']]);

        foreach (['ca', 'resident_doctor', 'technical_admin', 'panel_officer'] as $role) {
            $this->actingAs($this->actor($role))->get('/agents/daily-summary')->assertForbidden();
        }
    }

    public function test_a_section_another_process_is_storing_is_skipped_and_stored_on_the_next_call(): void
    {
        // AG-12: hold Hana's lock for today from a second database connection, as a second daily run would.
        $name = 'ag12_lock_holder';
        config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
        $holder = DB::connection($name);
        $key = DailyRun::lockKey($this->organisation->id, $this->branch, '2026-11-06', 'hana');
        try {
            $this->assertTrue((bool) $holder->selectOne('select pg_try_advisory_lock(hashtextextended(?, 0)) as locked', [$key])->locked);

            $outcome = $this->dailyRun('0700', 'CHERAS');

            $this->assertSame([], $outcome['failed'], 'Waiting for another process is never a failure.');
            $this->assertContains('CHERAS hana 0700 2026-11-06: another run is storing this', $outcome['skipped']);
            $this->assertNull($this->section('hana', '0700'));
            $this->assertNotNull($this->section('lina', '0700'), 'Only the locked section waits.');
            $this->assertSame(0, AiAgentRun::query()->whereHas('agent', fn ($q) => $q->where('code', InventoryAnalyst::AGENT))->count(), 'No run starts while another process holds the section.');
            // The lock covers both slots of one day, so a late 07:00 run and the 14:00 run never race on Hana's keys.
            $this->travelTo(CarbonImmutable::parse('2026-11-06T06:30:00Z'));
            $this->assertContains('CHERAS hana 1400 2026-11-06: another run is storing this', $this->dailyRun('1400', 'CHERAS')['skipped']);
            $holder->select('select pg_advisory_unlock(hashtextextended(?, 0))', [$key]);

            $this->travelTo(CarbonImmutable::parse('2026-11-06T00:30:00Z'));
            $this->assertContains('CHERAS hana 0700 2026-11-06', $this->dailyRun('0700', 'CHERAS')['stored']);
            $this->assertSame(['total' => 1, 'pending' => 1], $this->section('hana', '0700')->payload['counts']['restock']);
            // The run released its lock: another session can take it at once (a session lock is re-entrant, so only
            // a second connection proves the release).
            $this->assertTrue((bool) $holder->selectOne('select pg_try_advisory_lock(hashtextextended(?, 0)) as locked', [$key])->locked);
        } finally {
            $holder->select('select pg_advisory_unlock_all()');
            DB::purge($name);
        }
    }

    public function test_only_a_tool_marked_organisation_wide_is_read_with_no_branch(): void
    {
        $organisationWide = collect(app(AgentToolRegistry::class)->all())->filter(fn ($tool): bool => $tool instanceof OrganisationWideSystemTool)->map->code()->values()->all();
        $this->assertSame(['system.health'], $organisationWide);

        $recorder = app(AgentRunRecorder::class);
        $gateway = app(AgentToolGateway::class);
        // clinic.day_summary is system-readable and its permission is not `.branch`, but it is not marked
        // organisation-wide, so a branchless run cannot read it for every branch at once.
        $branchless = $recorder->start(ReportAssistant::AGENT, ReportAssistant::TASK, null, organisationId: $this->organisation->id);
        $this->assertThrows(fn () => $gateway->callAsSystem($branchless, 'clinic.day_summary', null), AuthorizationException::class, 'This data source is read one branch at a time.');
        $faris = $recorder->start(SystemHealthOfficer::AGENT, SystemHealthOfficer::TASK, null, organisationId: $this->organisation->id);
        $this->assertTrue($gateway->callAsSystem($faris, 'system.health', null)['allClear']);
    }

    public function test_a_doctor_seen_last_week_but_not_on_the_report_day_stays_in_the_comparison(): void
    {
        $ca = $this->actor('ca');
        $absent = $this->doctor();
        $present = $this->doctor();
        $seen = function ($doctor, string $at) use ($ca): void {
            $entry = $this->send($ca, $this->consultationVisit($ca, $doctor));
            DB::table('visits')->where('id', $entry->visit_id)->update(['registered_at' => $at]);
            DB::table('queue_entries')->where('id', $entry->id)->update(['status' => 'serving', 'called_at' => $at, 'called_by_user_id' => $ca->id]);
        };
        $seen($absent, '2026-10-29 02:00:00'); // same weekday last week only
        $seen($present, '2026-10-29 02:30:00');
        $seen($present, '2026-11-05 02:00:00'); // both days

        $this->dailyRun('0700', 'CHERAS');

        $doctors = collect($this->section('lina', '0700')->payload['doctors'])->keyBy('doctorId');
        $this->assertSame([1, 1], [$doctors[$present->id]['seen'], $doctors[$present->id]['seenBefore']]);
        $this->assertSame([0, 0, 1], [$doctors[$absent->id]['seen'], $doctors[$absent->id]['averageConsultationMinutes'], $doctors[$absent->id]['seenBefore']]);
        $this->assertSame([$present->id, $absent->id], array_keys($doctors->all()), 'Doctors absent on the report day come after the others.');

        // The 14:00 update has no comparison, so it adds nobody from last week.
        $this->travelTo(CarbonImmutable::parse('2026-11-06T06:30:00Z'));
        $this->dailyRun('1400', 'CHERAS');
        $this->assertSame([], $this->section('lina', '1400')->payload['doctors']);
    }

    public function test_a_viewer_with_an_ended_assignment_sees_only_the_branch_they_work_at_today(): void
    {
        $this->dailyRun('0700');
        $director = $this->actor('director');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        DB::table('staff_branch_assignments')->insert([
            ...collect((array) DB::table('staff_branch_assignments')->where('staff_profile_id', $director->staffProfile->id)->first())->except(['id', 'branch_id', 'is_primary', 'valid_from', 'valid_until'])->all(),
            'branch_id' => $puchong->id, 'is_primary' => false, 'valid_from' => '2026-10-01', 'valid_until' => '2026-11-04',
        ]);
        $this->assertNotNull($this->section('lina', '0700', $puchong), 'PUCHONG has a stored report.');

        $summary = $this->actingAs($director)->get('/agents/daily-summary')->assertOk()->viewData('page')['props']['summary'];

        $this->assertSame(['CHERAS'], array_column($summary['branches'], 'code'));
    }

    public function test_a_viewer_from_another_organisation_sees_none_of_its_sections(): void
    {
        $this->dailyRun('0700');
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYN-AG12', 'name' => 'Synthetic other organisation', 'is_active' => true])->save();
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN-AG12', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $department = new Department;
        $department->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN-DEPT', 'name' => 'Synthetic Department', 'is_active' => true])->save();
        $outsider = $this->actor('director', $branch);

        $summary = $this->actingAs($outsider)->get('/agents/daily-summary')->assertOk()->viewData('page')['props']['summary'];

        $this->assertSame(['SYN-AG12'], array_column($summary['branches'], 'code'));
        $branchParts = collect($summary['branches'][0])->except(['code', 'name', 'id'])->filter()->all();
        $this->assertSame([], $branchParts, 'No section from the other organisation reaches this viewer.');
        $this->assertNull($summary['faris']);
    }
}
