<?php

namespace Tests\Feature\Queue;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitReasonService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;

/**
 * AUTO-1: two CAs register a patient without a doctor at the same moment while two rostered doctors are free. Both
 * workers wait behind the same held doctor row (proved through pg_blocking_pids), then run one after the other: the
 * second sees the first's new waiting patient, so the two visits go to different doctors and nothing deadlocks.
 */
class PostgresAutoAssignRaceTest extends QueueTestCase
{
    /** Committed synthetic fixtures must be visible to independent PostgreSQL workers. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_simultaneous_auto_registrations_go_to_different_free_doctors(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName());
        Config::set('database.connections.auto_observer', config('database.connections.'.config('database.default')));
        $observer = DB::connection('auto_observer');

        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $caOne = $this->actor('ca');
        $caTwo = $this->actor('ca');
        $a = $this->doctor();
        $b = $this->doctor();
        $week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        foreach ([$a, $b] as $doctor) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), [
                'staff_user_id' => $doctor->id, 'date' => $week->toDateString(), 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 0,
                'session_type' => 'morning', 'branch_room_id' => null, 'branch_id' => $this->branch->id,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        DB::table('branch_auto_assign_settings')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'enabled' => true,
            'lock_version' => 1, 'updated_by_user_id' => $director->id, 'created_at' => now(), 'updated_at' => now()]);
        $reason = app(VisitReasonService::class)->create($caOne, 'Synthetic race reason');
        $patients = [$this->patient($caOne), $this->patient($caOne)];
        $frozen = $week->setTimeFromTimeString('10:00')->toIso8601String();

        $first = min($a->id, $b->id);
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $first)->lockForUpdate()->first();
            $parent = (int) DB::scalar('select pg_backend_pid()');
            foreach ([[$caOne, $patients[0]], [$caTwo, $patients[1]]] as [$ca, $patient]) {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresAutoAssignWorker.php'), (string) $ca->id, (string) $this->branch->id,
                    $patient->patient_number, $reason->public_id, (string) Str::uuid(), $frozen, 'autoassign-'.Str::random(12)], base_path());
                $process->setInput($input);
                $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
                $process->start();
                $workers[] = [$process, $input];
            }
            foreach ($workers as [$process, $input]) {
                $readyDeadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
                while (! str_contains($process->getOutput(), 'READY ') && microtime(true) < $readyDeadline) {
                    usleep(10_000);
                }
                $this->assertMatchesRegularExpression('/READY (\d+)/', $process->getOutput());
                $input->write("GO\n");
                $input->close();
            }
            foreach ($workers as [$process]) {
                preg_match('/READY (\d+)/', $process->getOutput(), $pid);
                $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
                do {
                    $process->getOutput();
                    $blocked = (bool) $observer->scalar('WITH RECURSIVE blockers(pid) AS (SELECT unnest(pg_blocking_pids(?::integer)) UNION SELECT unnest(pg_blocking_pids(blockers.pid)) FROM blockers) SELECT EXISTS(SELECT 1 FROM blockers WHERE pid=?::integer)', [(int) $pid[1], $parent]);
                    if ($blocked) {
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($blocked, $process->getOutput().$process->getErrorOutput());
            }
            DB::commit();
            $doctors = [];
            foreach ($workers as [$process]) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertMatchesRegularExpression('/AUTO_OK (\d+)/', $process->getOutput(), $process->getOutput());
                preg_match('/AUTO_OK (\d+)/', $process->getOutput(), $m);
                $doctors[] = (int) $m[1];
            }
            sort($doctors);
            $expected = [$a->id, $b->id];
            sort($expected);
            $this->assertSame($expected, $doctors, 'two free doctors, two patients: one each');
            $this->assertSame(2, Visit::query()->count());
            $this->assertSame(2, DB::table('queue_entries')->where('status', 'waiting')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as [$process]) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }
}
