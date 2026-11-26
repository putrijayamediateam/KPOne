<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Emergency\EmergencyAlert;
use App\Domain\Queue\Emergency\EmergencyAlertService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Feature\Clinical\Triage\TriageTestCase;
use Tests\Support\ContentionTimeouts;

/** Real actor/patient lock contention using the established READY/GO PostgreSQL observer harness.
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresEmergencyAlertContentionTest extends TriageTestCase
{
    private const OBSERVER = 'pgsql_emergency_alert_observer';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL patient merge regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Patient merge concurrency tests require an isolated PostgreSQL test database.');
        }
        Config::set('database.connections.'.self::OBSERVER, config('database.connections.'.config('database.default')));
        DB::purge(self::OBSERVER);
    }

    protected function tearDown(): void
    {
        foreach ($this->inputs as $input) {
            if (! $input->isClosed()) {
                $input->close();
            }
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    private function red(): array
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);

        return [$ca, $doctor, $visit->refresh()];
    }

    private function command(Visit $visit): array
    {
        $alert = EmergencyAlert::query()->where('visit_id', $visit->id)->whereNull('closed_at')->firstOrFail();

        return ['expected_branch_id' => $this->branch->id, 'episode_id' => $alert->id, 'lock_version' => $alert->lock_version, 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_two_doctors_take_one_version_only_once(): void
    {
        [,,$visit] = $this->red();
        $out = $this->race($visit, [$this->worker('take', $this->doctor(), $this->payload($visit)), $this->worker('take', $this->doctor(), $this->payload($visit))]);
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertStringContainsString('STALE', $out);
        $this->assertDatabaseCount('queue_red_zone_claims', 1);
        $this->assertSame(1, DB::table('queue_emergency_alert_events')->where('event_code', 'taken')->count());
    }

    public function test_exact_take_retry_race_appends_one_claim(): void
    {
        [,,$visit] = $this->red();
        $doctor = $this->doctor();
        $input = $this->payload($visit);
        $out = $this->race($visit, [$this->worker('take', $doctor, $input), $this->worker('take', $doctor, $input)]);
        $this->assertSame(2, substr_count($out, 'SUCCESS'), $out);
        $this->assertDatabaseCount('queue_red_zone_claims', 1);
        $this->assertSame(1, DB::table('queue_emergency_alert_events')->where('event_code', 'taken')->count());
    }

    public function test_seen_retry_race_appends_one_acknowledgement(): void
    {
        [,,$visit] = $this->red();
        $doctor = $this->doctor();
        $input = $this->payload($visit);
        $out = $this->race($visit, [$this->worker('seen', $doctor, $input), $this->worker('seen', $doctor, $input)]);
        $this->assertSame(2, substr_count($out, 'SUCCESS'), $out);
        $this->assertDatabaseCount('queue_red_zone_claims', 0);
        $this->assertSame(1, DB::table('queue_emergency_alert_events')->where('event_code', 'seen')->count());
    }

    public function test_lowering_and_take_serialize_without_stale_open_episode(): void
    {
        [$ca,,$visit] = $this->red();
        $out = $this->race($visit, [$this->worker('take', $this->doctor(), $this->payload($visit)), $this->worker('triage', $ca, ['visit_id' => $visit->id, ...$this->triageInput($visit, ['chosen_zone' => 'green'])])]);
        $this->assertStringContainsString('SUCCESS', $out);
        $latest = DB::table('visit_triage_assessments')->where('visit_id', $visit->id)->orderByDesc('sequence')->value('chosen_zone');
        $this->assertSame($latest === 'red' ? 1 : 0, EmergencyAlert::query()->whereNull('closed_at')->count(), $out);
        $this->assertLessThanOrEqual(1, DB::table('queue_red_zone_claims')->count());
    }

    public function test_call_and_take_have_one_winner_and_consistent_episode(): void
    {
        [, $assigned, $visit] = $this->red();
        $call = ['visit_id' => $visit->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version];
        $out = $this->race($visit, [$this->worker('take', $this->doctor(), $this->payload($visit)), $this->worker('call', $assigned, $call)]);
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame($visit->queueEntry->fresh()->status === 'waiting' ? 1 : 0, EmergencyAlert::query()->whereNull('closed_at')->count());
    }

    public function test_activation_and_legacy_take_capture_floor_or_refuse_old_payload(): void
    {
        $legacy = \Mockery::mock(new EmergencyAlertService);
        $legacy->shouldReceive('triageLocked')->once();
        $this->instance(EmergencyAlertService::class, $legacy);
        [, $doctor, $visit] = $this->red();
        $this->forgetMock(EmergencyAlertService::class);
        $out = $this->race($visit, [$this->worker('activate', $doctor, ['visit_id' => $visit->id]), $this->worker('legacy', $doctor, ['visit_id' => $visit->id])]);
        $this->assertStringContainsString('SUCCESS', $out);
        $episode = EmergencyAlert::query()->sole();
        $this->assertSame((int) DB::table('queue_red_zone_claims')->max('id'), $episode->claim_floor_id);
        $this->assertNull($episode->taken_claim_id);
        $this->assertSame('activation', $episode->raised_kind);
    }

    public function test_two_activation_workers_create_one_episode_and_event(): void
    {
        $legacy = \Mockery::mock(new EmergencyAlertService);
        $legacy->shouldReceive('triageLocked')->once();
        $this->instance(EmergencyAlertService::class, $legacy);
        [, $doctor, $visit] = $this->red();
        $this->forgetMock(EmergencyAlertService::class);
        $input = ['visit_id' => $visit->id];
        $out = $this->race($visit, [$this->worker('activate', $doctor, $input), $this->worker('activate', $doctor, $input)]);
        $this->assertSame(2, substr_count($out, 'SUCCESS'), $out);
        $this->assertDatabaseCount('queue_emergency_alerts', 1);
        $this->assertDatabaseCount('queue_emergency_alert_events', 1);
    }

    public function test_cancel_and_take_never_leave_cancelled_episode_open(): void
    {
        [$ca, , $visit] = $this->red();
        $cancel = ['visit_id' => $visit->id, 'lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version, 'cancellation_reason' => 'Synthetic concurrent cancellation'];
        $out = $this->race($visit, [$this->worker('take', $this->doctor(), $this->payload($visit)), $this->worker('cancel', $ca, $cancel)]);
        $this->assertStringContainsString('SUCCESS', $out);
        $this->assertSame($visit->fresh()->status === 'registered' ? 1 : 0, EmergencyAlert::query()->whereNull('closed_at')->count(), $out);
        $this->assertLessThanOrEqual(1, DB::table('queue_red_zone_claims')->count());
    }

    public function test_two_red_retakes_keep_one_episode(): void
    {
        [$ca, , $visit] = $this->red();
        $input = ['visit_id' => $visit->id, ...$this->triageInput($visit, ['chosen_zone' => 'red'])];
        $other = [...$input, 'idempotency_key' => (string) Str::uuid()];
        $out = $this->race($visit, [$this->worker('triage', $ca, $input), $this->worker('triage', $ca, $other)]);
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertDatabaseCount('queue_emergency_alerts', 1);
        $this->assertDatabaseCount('queue_emergency_alert_events', 1);
        $this->assertDatabaseCount('visit_triage_assessments', 2);
    }

    public function test_role_revocation_and_take_serialize_on_actor_and_replay_rechecks_authority(): void
    {
        [, , $visit] = $this->red();
        $doctor = $this->doctor();
        $input = $this->payload($visit);
        $out = $this->race($doctor, [$this->worker('take', $doctor, $input), $this->worker('revoke', $doctor, $input)]);
        $this->assertStringContainsString('SUCCESS', $out);
        $this->assertFalse($doctor->fresh()->hasRole('resident_doctor'));
        $this->assertLessThanOrEqual(1, DB::table('queue_red_zone_claims')->count());
        $this->selectBranch($doctor);
        $this->expectException(HttpException::class);
        app(EmergencyAlertService::class)->take($doctor->fresh(), $visit->fresh(), $input);
    }

    #[DataProvider('activationTransitions')]
    public function test_activation_serializes_with_live_source_transition(string $mode, string $zone): void
    {
        $legacy = \Mockery::mock(new EmergencyAlertService);
        $legacy->shouldReceive('triageLocked')->once();
        $this->instance(EmergencyAlertService::class, $legacy);
        [$ca, $doctor, $visit] = $this->red();
        $this->forgetMock(EmergencyAlertService::class);
        $input = ['visit_id' => $visit->id];
        $transition = match ($mode) {
            'triage' => [...$input, ...$this->triageInput($visit, ['chosen_zone' => $zone])],
            'call' => [...$input, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version],
            'cancel' => [...$input, 'lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version, 'cancellation_reason' => 'Synthetic activation race'],
        };
        $out = $this->race($visit, [$this->worker('activate', $doctor, $input), $this->worker($mode, $mode === 'call' ? $doctor : $ca, $transition)]);
        $this->assertSame(2, substr_count($out, 'SUCCESS'), $out);
        $health = app(EmergencyAlertService::class)->activate($this->branch, false);
        $this->assertSame(0, $health['remaining'], $out);
        $this->assertSame(0, $health['invalidOpen'], $out);
        $this->assertSame($mode === 'triage' && $zone === 'red' ? 1 : 0, EmergencyAlert::query()->whereNull('closed_at')->count());
    }

    public static function activationTransitions(): array
    {
        return [['triage', 'red'], ['triage', 'green'], ['call', 'red'], ['cancel', 'red']];
    }

    private function payload(Visit $visit): array
    {
        return ['visit_id' => $visit->id, ...$this->command($visit)];
    }

    /** Makes the fixture visible to the worker processes; the next test rebuilds the schema. */
    private function commitFixture(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
            RefreshDatabaseState::$migrated = false;
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return Worker
     */
    private function worker(string $mode, User $actor, array $args): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresEmergencyAlertWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-emergency-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the shared visit row the service locks, starts both workers, proves each one is waiting behind this
     * session (directly or behind the other worker), then releases the lock so they run strictly one after the other.
     *
     * @param  list<Worker>  $workers
     */
    private function race(Visit|User $shared, array $workers): string
    {
        $this->commitFixture();
        DB::beginTransaction();
        try {
            DB::select('select id from '.($shared instanceof User ? 'users' : 'visits').' where id = ? for update', [$shared->id]);
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            foreach ($workers as $worker) {
                $worker['process']->start();
            }
            $this->waitReady($workers);
            foreach ($workers as $worker) {
                $worker['input']->write("GO\n");
                $worker['input']->close();
            }
            foreach ($workers as $worker) {
                $this->waitForDatabaseBlock($worker['process'], $parentPid);
            }
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
        foreach ($workers as $worker) {
            $worker['process']->wait();
            $this->assertSame(0, $worker['process']->getExitCode(), $this->processDiagnostics($worker['process']));
        }

        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    /** @param list<Worker> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated() && ! str_contains($worker['process']->getOutput(), 'READY ')) {
                    throw new RuntimeException('Emergency alert worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Emergency alert worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required emergency alert contention was not reached. '.$this->processDiagnostics($process));
            }
            $result = $this->observer()->selectOne(<<<'SQL'
                select exists (
                    with recursive blockers(pid) as (
                        select unnest(pg_blocking_pids(activity.pid))
                        union
                        select unnest(pg_blocking_pids(blockers.pid)) from blockers
                    ) select 1 from blockers where pid = ?
                ) as expected_blocker
                from pg_stat_activity as activity where pid = ?
                SQL, [$expectedBlockerPid, $pid]);
            if ($result && filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Expected emergency alert blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }
}
