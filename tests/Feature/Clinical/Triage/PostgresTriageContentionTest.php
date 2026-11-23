<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Triage\TriageGuidelineVersion;
use App\Domain\Clinical\Triage\VisitTriageAssessment;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;

/**
 * TRI-1a races (TRI-1-DESIGN section 8), real two-process PostgreSQL: both workers are proved to wait behind a row the
 * parent holds (pg_blocking_pids) before it is released, so each run is a real race, never a sequence.
 */
class PostgresTriageContentionTest extends TriageTestCase
{
    /** Committed synthetic fixtures must be visible to independent PostgreSQL workers. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        // The following normal fixture test starts a fresh schema; triage evidence is never deleted.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_cas_triaging_one_visit_record_exactly_one_row(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $other = $this->actor('ca');
        $out = $this->race('visits', $visit->id, [
            ['triage', $ca, $visit, $this->triageInput($visit)],
            ['triage', $other, $visit, $this->triageInput($visit)],
        ]);
        $this->assertSame(1, substr_count(implode('', $out), 'TRIAGE_OK'));
        $this->assertSame(1, substr_count(implode('', $out), 'TRIAGE_REFUSED'));
        $this->assertSame(1, VisitTriageAssessment::query()->where('visit_id', $visit->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'visit.triage.recorded')->count());
    }

    public function test_triage_against_the_doctor_starting_the_consultation_never_adds_a_row_after_it(): void
    {
        $this->approvedGuideline();
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $out = $this->race('visits', $visit->id, [
            ['triage', $ca, $visit, $this->triageInput($visit, ['chosen_zone' => 'yellow'])],
            ['start', $doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version]],
        ]);
        $this->assertStringContainsString('START_OK', $out[1], 'a Serving-time triage never bumps the visit, so the doctor is not made stale');
        $this->assertSame(1, ClinicalEncounter::query()->where('visit_id', $visit->id)->count());
        preg_match('/SEEN (\d+)/', $out[1], $seen);
        $row = VisitTriageAssessment::query()->where('visit_id', $visit->id)->first();
        if (str_contains($out[0], 'TRIAGE_OK')) {
            $this->assertNotNull($row);
            $this->assertSame($row->id, (int) $seen[1], 'the encounter start saw exactly the one committed triage row');
        } else {
            $this->assertNull($row);
            $this->assertSame(0, (int) $seen[1]);
        }
        $this->assertSame('normal', $visit->refresh()->priority);
    }

    /** TRI-1c: with the real copy at encounter start, the vitals carry exactly the committed triage row, or none. */
    public function test_triage_against_encounter_start_copies_exactly_the_committed_triage_or_nothing(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $first = $this->triage($ca, $visit);
        $out = $this->race('visits', $visit->id, [
            ['triage', $ca, $visit, $this->triageInput($visit, ['temperature_celsius' => '37.40'])],
            ['startcopy', $doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version]],
        ]);
        $this->assertStringContainsString('STARTCOPY_OK', $out[1]);
        $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->sole();
        $vitals = DB::table('encounter_vital_observations')->where('clinical_encounter_id', $encounter->id)->get();
        $this->assertCount(1, $vitals, 'exactly one copy');
        preg_match('/SOURCE (\d+)/', $out[1], $source);
        $latest = VisitTriageAssessment::query()->where('visit_id', $visit->id)->orderByDesc('sequence')->first();
        $this->assertSame($latest->id, (int) $source[1], 'the copy names the latest committed triage row');
        $this->assertSame((int) $vitals[0]->source_triage_assessment_id, (int) $source[1]);
        if (str_contains($out[0], 'TRIAGE_OK')) {
            $this->assertNotSame($first->id, $latest->id);
            $this->assertSame('37.40', (string) $vitals[0]->temperature_celsius, 'the retake reached the encounter');
        } else {
            $this->assertStringContainsString('TRIAGE_REFUSED', $out[0]);
            $this->assertSame($first->id, $latest->id, 'no triage row after the encounter');
            $this->assertSame('36.80', (string) $vitals[0]->temperature_celsius);
        }
    }

    public function test_triage_raising_priority_against_call_in_has_exactly_one_winner(): void
    {
        $this->approvedGuideline();
        [$ca, $visit, $queue] = $this->waitingFixture();
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $out = $this->race('visits', $visit->id, [
            ['triage', $ca, $visit, $this->triageInput($visit, ['chosen_zone' => 'yellow'])],
            ['call', $doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]],
        ]);
        $triaged = str_contains($out[0], 'TRIAGE_OK');
        $called = str_contains($out[1], 'CALL_OK');
        $this->assertNotSame($triaged, $called);
        $this->assertSame($called ? QueueEntry::STATUS_SERVING : QueueEntry::STATUS_WAITING, $queue->refresh()->status);
        $this->assertSame($triaged ? 'urgent' : 'normal', $visit->refresh()->priority);
        $this->assertSame($triaged ? 1 : 0, VisitTriageAssessment::query()->where('visit_id', $visit->id)->count());
    }

    public function test_triage_raising_priority_against_call_next_has_exactly_one_winner(): void
    {
        $this->approvedGuideline();
        [$ca, $visit, $queue] = $this->waitingFixture();
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $out = $this->race('visits', $visit->id, [
            ['triage', $ca, $visit, $this->triageInput($visit, ['chosen_zone' => 'red'])],
            ['callnext', $doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'doctor_user_id' => $doctor->id, 'visit_number' => $visit->visit_number, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]],
        ]);
        $triaged = str_contains($out[0], 'TRIAGE_OK');
        $called = str_contains($out[1], 'CALLNEXT_OK');
        $this->assertNotSame($triaged, $called);
        $this->assertSame($called ? QueueEntry::STATUS_SERVING : QueueEntry::STATUS_WAITING, $queue->refresh()->status);
        $this->assertSame($triaged ? 'urgent' : 'normal', $visit->refresh()->priority);
        $this->assertSame($triaged ? 1 : 0, VisitTriageAssessment::query()->where('visit_id', $visit->id)->count());
    }

    public function test_two_directors_approving_one_version_give_one_approval(): void
    {
        $draft = $this->enterGuideline($this->actor('hr_manager'));
        $input = fn () => ['version_id' => $draft->id, 'lock_version' => 1, 'matches_signed_copy' => true, 'idempotency_key' => (string) Str::uuid()];
        $out = $this->race('organisations', $this->organisation->id, [
            ['approve', $this->actor('director'), null, $input()],
            ['approve', $this->actor('director'), null, $input()],
        ]);
        $this->assertSame(1, substr_count(implode('', $out), 'APPROVE_OK'));
        $this->assertSame(1, substr_count(implode('', $out), 'APPROVE_REFUSED'));
        $this->assertSame(1, TriageGuidelineVersion::query()->where('status', 'approved')->count());
        $this->assertSame(1, DB::table('triage_guideline_events')->where('event_type', 'approved')->count());
    }

    public function test_triage_against_a_guideline_approval_cites_the_right_version_or_is_refused_cleanly(): void
    {
        $old = $this->approvedGuideline();
        $draft = $this->enterGuideline($this->actor('director'), ['red_flags' => ['Synthetic only red flag']]);
        [$ca, $visit] = $this->waitingFixture();
        $out = $this->race('triage_guideline_versions', $old->id, [
            ['triage', $ca, $visit, $this->triageInput($visit)],
            ['approve', $this->actor('hr_manager'), null, ['version_id' => $draft->id, 'lock_version' => 1, 'matches_signed_copy' => true, 'idempotency_key' => (string) Str::uuid()]],
        ]);
        $this->assertStringContainsString('APPROVE_OK', $out[1]);
        $row = VisitTriageAssessment::query()->where('visit_id', $visit->id)->first();
        if (str_contains($out[0], 'TRIAGE_OK')) {
            $this->assertSame($old->id, $row?->guideline_version_id);
        } else {
            $this->assertStringContainsString('TRIAGE_REFUSED', $out[0]);
            $this->assertNull($row);
        }
    }

    public function test_triage_against_visit_cancel_never_leaves_a_row_written_after_the_cancel(): void
    {
        $this->approvedGuideline();
        [$ca, $visit, $queue] = $this->waitingFixture();
        $out = $this->race('visits', $visit->id, [
            ['triage', $ca, $visit, $this->triageInput($visit)],
            ['cancel', $this->actor('ca'), $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'cancellation_reason' => 'Synthetic cancellation']],
        ]);
        $this->assertStringContainsString('CANCEL_OK', $out[1], 'a green triage changes neither version, so the cancel still applies');
        $this->assertSame(Visit::STATUS_CANCELLED, $visit->refresh()->status);
        $row = VisitTriageAssessment::query()->where('visit_id', $visit->id)->first();
        $this->assertSame(str_contains($out[0], 'TRIAGE_OK'), $row !== null);
        if ($row) {
            $cancelledAt = AuditLog::query()->where('event', 'visit.cancelled')->where('subject_id', $visit->id)->value('id');
            $triagedAt = AuditLog::query()->where('event', 'visit.triage.recorded')->value('id');
            $this->assertLessThan($cancelledAt, $triagedAt, 'the triage row was written before the cancel');
        }
    }

    /**
     * Both workers run one call each, behind a FOR UPDATE lock the parent holds on $table row $id.
     *
     * @param  list<array{0:string, 1:User, 2:Visit|null, 3:array<string,mixed>}>  $calls
     * @return list<string>
     */
    private function race(string $table, int $id, array $calls): array
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName());
        Config::set('database.connections.triage_observer', config('database.connections.'.config('database.default')));
        $observer = DB::connection('triage_observer');
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table($table)->where('id', $id)->lockForUpdate()->first();
            $parent = (int) DB::scalar('select pg_backend_pid()');
            foreach ($calls as [$mode, $actor, $visit, $input]) {
                $stdin = new InputStream;
                $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresTriageWorker.php'), $mode, (string) $actor->id, (string) ($visit->id ?? 0), (string) $this->branch->id, base64_encode(json_encode($input, JSON_THROW_ON_ERROR)), 'triage-'.Str::random(12)], base_path());
                $process->setInput($stdin);
                $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
                $process->start();
                $workers[] = [$process, $stdin];
            }
            foreach ($workers as [$process, $stdin]) {
                $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
                while (! str_contains($process->getOutput(), 'READY ') && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                $this->assertMatchesRegularExpression('/READY (\d+)/', $process->getOutput(), $process->getErrorOutput());
                $stdin->write("GO\n");
                $stdin->close();
            }
            foreach ($workers as [$process]) {
                preg_match('/READY (\d+)/', $process->getOutput(), $pid);
                $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
                do {
                    $blocked = (bool) $observer->scalar('WITH RECURSIVE blockers(pid) AS (SELECT unnest(pg_blocking_pids(?::integer)) UNION SELECT unnest(pg_blocking_pids(blockers.pid)) FROM blockers) SELECT EXISTS(SELECT 1 FROM blockers WHERE pid=?::integer)', [(int) $pid[1], $parent]);
                    if ($blocked) {
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline && $process->isRunning());
                $this->assertTrue($blocked, 'each worker must wait behind the parent: '.$process->getOutput().$process->getErrorOutput());
            }
            DB::commit();
            $outputs = [];
            foreach ($workers as [$process]) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outputs[] = $process->getOutput();
            }

            return $outputs;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as [$process, $stdin]) {
                if (! $stdin->isClosed()) {
                    $stdin->close();
                }
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            DB::disconnect('triage_observer');
        }
    }
}
