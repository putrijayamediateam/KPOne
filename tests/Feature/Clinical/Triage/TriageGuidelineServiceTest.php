<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Triage\TriageGuidelineService;
use App\Domain\Clinical\Triage\TriageGuidelineVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** TRI-1a: the triage guideline (TRI-1-DESIGN 4.2 and 7, owner decisions 19 Nov 2026). */
class TriageGuidelineServiceTest extends TriageTestCase
{
    public function test_one_manager_enters_and_the_other_approves_in_either_direction(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $first = $this->enterGuideline($hr);
        $this->assertSame(['draft', 1, 2, 1, 4], [$first->status, $first->version_number, $first->red_flag_count, $first->yellow_sign_count, $first->threshold_count]);
        $this->assertSame(['rf_01', 'rf_02', 'ys_01'], $first->signs->pluck('code')->sort()->values()->all());
        $this->assertNull(app(TriageGuidelineService::class)->inForce($this->organisation->id), 'a draft is never in force');

        $first = $this->approveGuideline($director, $first);
        $this->assertSame([TriageGuidelineVersion::STATUS_APPROVED, $director->id], [$first->status, $first->approved_by_user_id]);
        $this->assertSame($first->id, app(TriageGuidelineService::class)->inForce($this->organisation->id)?->id);

        $second = $this->approveGuideline($hr, $this->enterGuideline($director, ['red_flags' => ['Synthetic only red flag']]));
        $this->assertSame(2, $second->version_number);
        $this->assertSame(TriageGuidelineVersion::STATUS_REPLACED, $first->refresh()->status);
        $this->assertSame($second->approved_at?->toIso8601String(), $first->replaced_at?->toIso8601String());
        $this->assertSame(1, TriageGuidelineVersion::query()->where('status', 'approved')->count());
        $this->assertSame(['entered', 'approved', 'replaced'], DB::table('triage_guideline_events')->where('guideline_version_id', $first->id)->orderBy('record_version')->pluck('event_type')->all());
    }

    public function test_whoever_entered_a_version_can_never_approve_it(): void
    {
        $director = $this->actor('director');
        $draft = $this->enterGuideline($director);
        $this->refused(fn () => $this->approveGuideline($director, $draft), 'someone else must check and approve');
        $this->assertSame('draft', $draft->refresh()->status);
        if (DB::getDriverName() === 'pgsql') {
            $this->assertDatabaseRefuses("UPDATE triage_guideline_versions SET status = 'approved', approved_by_user_id = entered_by_user_id, approved_at = now(), lock_version = lock_version + 1", ['23514']);
        }
    }

    public function test_approval_needs_the_signed_copy_tick_and_the_current_version(): void
    {
        $draft = $this->enterGuideline($this->actor('hr_manager'));
        $director = $this->actor('director');
        $this->refused(fn () => app(TriageGuidelineService::class)->approve($director, $draft, ['lock_version' => 1, 'matches_signed_copy' => false, 'idempotency_key' => (string) Str::uuid()]), 'matches the signed paper copy');
        $this->refused(fn () => app(TriageGuidelineService::class)->approve($director, $draft, ['lock_version' => 9, 'matches_signed_copy' => true, 'idempotency_key' => (string) Str::uuid()]), 'has changed');
    }

    public function test_one_draft_at_a_time_and_discard(): void
    {
        $hr = $this->actor('hr_manager');
        $draft = $this->enterGuideline($hr);
        $this->refused(fn () => $this->enterGuideline($this->actor('director')), 'A draft guideline is waiting');
        $discarded = app(TriageGuidelineService::class)->discard($hr, $draft, ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('discarded', $discarded->status);
        $this->assertSame(2, $this->enterGuideline($hr)->version_number);
        $this->refused(fn () => $this->approveGuideline($this->actor('director'), $discarded), 'has changed');
    }

    public function test_retries_return_the_stored_result_and_a_changed_payload_is_refused(): void
    {
        $hr = $this->actor('hr_manager');
        $input = $this->guidelineInput();
        $service = app(TriageGuidelineService::class);
        $draft = $service->enter($hr, $input);
        $this->assertSame($draft->id, $service->enter($hr, $input)->id);
        $this->refused(fn () => $service->enter($hr, [...$input, 'child_age_limit_years' => 13]), 'retry key has already been used');

        $director = $this->actor('director');
        $approval = ['lock_version' => 1, 'matches_signed_copy' => true, 'idempotency_key' => (string) Str::uuid()];
        $service->approve($director, $draft, $approval);
        $this->assertSame('approved', $service->approve($director, $draft, $approval)->status, 'a retried approval replays');
        $this->assertSame(1, AuditLog::query()->where('event', 'triage.guideline.approved')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'triage.guideline.entered')->count());
    }

    public function test_entry_validation(): void
    {
        $hr = $this->actor('hr_manager');
        $this->refused(fn () => $this->enterGuideline($hr, ['red_flags' => []]), 'at least one red flag');
        $this->refused(fn () => $this->enterGuideline($hr, ['doctor_signed_on' => now('UTC')->addDays(3)->toDateString()]), 'cannot be in the future');
        $this->refused(fn () => $this->enterGuideline($hr, ['thresholds' => [
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_low', 'limit_value' => '130', 'zone' => 'red'],
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '120', 'zone' => 'red'],
        ]]), 'must be below');
        $this->refused(fn () => $this->enterGuideline($hr, ['thresholds' => [
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '120', 'zone' => 'red'],
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '130', 'zone' => 'yellow'],
        ]]), 'nearer normal'); // TRI-1b owner rule 2 (two limits), approved 2026-11-20
        $this->assertSame(0, TriageGuidelineVersion::query()->count());
    }

    public function test_every_role_without_the_guideline_permission_is_refused(): void
    {
        foreach (array_diff(array_keys(PermissionCatalogue::roles()), ['director', 'hr_manager']) as $role) {
            try {
                $this->enterGuideline($this->actor($role));
                $this->fail("$role must be refused");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $inactive = $this->actor('director');
        $inactive->forceFill(['is_active' => false])->save();
        $this->expectException(AuthorizationException::class);
        $this->enterGuideline($inactive);
    }

    public function test_audit_holds_counts_and_ids_never_the_wording(): void
    {
        $version = $this->approvedGuideline();
        $json = json_encode(AuditLog::query()->where('event', 'like', 'triage.guideline.%')->get()->pluck('metadata')->all(), JSON_THROW_ON_ERROR);
        foreach (['Synthetic red flag', 'Synthetic yellow', self::DOCTOR_NAME, '39.50'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
        $this->assertSame(['version_number' => 1, 'entered_by_user_id' => $version->entered_by_user_id, 'replaced_version_id' => null],
            AuditLog::query()->where('event', 'triage.guideline.approved')->sole()->metadata);
    }

    public function test_postgresql_refuses_direct_writes_that_skip_the_rules(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL rules.');
        }
        $version = $this->approvedGuideline();
        DB::unprepared('SET CONSTRAINTS ALL IMMEDIATE'); // run the fixture's deferred checks now, inside the test transaction
        $hr = $version->entered_by_user_id;
        $director = $version->approved_by_user_id;
        $org = $this->organisation->id;
        $this->assertDatabaseRefuses("INSERT INTO triage_guideline_versions (organisation_id, version_number, status, child_age_limit_years, approving_doctor_name, doctor_signed_on, red_flag_count, yellow_sign_count, threshold_count, entered_by_user_id, entered_at, approved_by_user_id, approved_at, idempotency_key, payload_hash, lock_version, created_at, updated_at)
            VALUES ($org, 2, 'approved', 12, 'Dr Synthetic', current_date, 1, 0, 0, $hr, now(), $director, now(), gen_random_uuid(), repeat('a', 64), 1, now(), now())", ['23514']);
        $this->assertDatabaseRefuses("UPDATE triage_guideline_versions SET status = 'replaced', replaced_at = now(), lock_version = lock_version + 1 WHERE id = {$version->id}", ['23514'], true);
        $this->assertDatabaseRefuses("UPDATE triage_guideline_versions SET child_age_limit_years = 5, lock_version = lock_version + 1 WHERE id = {$version->id}", ['23514']);
        $this->assertDatabaseRefuses("INSERT INTO triage_guideline_signs (guideline_version_id, kind, code, label, position, created_at) VALUES ({$version->id}, 'red_flag', 'rf_09', 'Synthetic late flag', 9, now())", ['23514']);
        foreach (['DELETE FROM triage_guideline_versions', 'TRUNCATE triage_guideline_signs', 'UPDATE triage_guideline_thresholds SET zone = \'yellow\'', 'DELETE FROM triage_guideline_events'] as $sql) {
            $this->assertDatabaseRefuses($sql, ['P0001', '23503']);
        }
    }

    /** @param list<string> $states */
    private function assertDatabaseRefuses(string $sql, array $states, bool $deferred = false): void
    {
        DB::beginTransaction();
        try {
            DB::unprepared($sql);
            if ($deferred) {
                DB::unprepared('SET CONSTRAINTS ALL IMMEDIATE');
            }
            $this->fail("must be refused: $sql");
        } catch (QueryException $e) {
            $this->assertContains((string) ($e->errorInfo[0] ?? $e->getCode()), $states, $sql.' '.$e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
