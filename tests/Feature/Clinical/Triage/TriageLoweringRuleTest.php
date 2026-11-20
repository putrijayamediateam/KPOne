<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Triage\TriageGuidelineService;
use App\Domain\Clinical\Triage\TriageGuidelineVersion;
use App\Domain\Clinical\Triage\VisitTriageAssessment;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\GuardianFixture;

/**
 * TRI-1b owner answers (20 Nov 2026): (1) a zone is lowered only after the reading that crossed its limit has been
 * retaken and is within limits, and never while a red flag is ticked (a red flag at the visit keeps it red, R1);
 * (2) a reading may have a yellow and a red limit. Enforced in TriageService and in PostgreSQL.
 */
class TriageLoweringRuleTest extends TriageTestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/triage-zone-cases.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function twoLimitGuideline(): TriageGuidelineVersion
    {
        return $this->approvedGuideline(['thresholds' => $this->fixture()['limits']]);
    }

    /** @return array{User, Visit} */
    private function redVisit(): array
    {
        $this->twoLimitGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $row = $this->triage($ca, $visit, ['temperature_celsius' => '40.00', 'chosen_zone' => 'red']);
        $this->assertSame('red', $row->suggested_zone);

        return [$ca, $visit];
    }

    public function test_a_yellow_and_a_red_limit_give_yellow_between_them_and_red_past_the_red_limit(): void
    {
        $this->twoLimitGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->assertSame('yellow', $this->triage($ca, $visit, ['temperature_celsius' => '38.20', 'chosen_zone' => 'yellow'])->suggested_zone);
        [$ca, $visit] = $this->waitingFixture();
        $this->assertSame('red', $this->triage($ca, $visit, ['temperature_celsius' => '39.50', 'chosen_zone' => 'red'])->suggested_zone);
        [$ca, $visit] = $this->waitingFixture();
        $this->assertSame('yellow', $this->triage($ca, $visit, ['spo2_percent' => '93.00', 'chosen_zone' => 'yellow'])->suggested_zone);
    }

    public function test_guideline_entry_accepts_two_limits_and_refuses_bad_pairs(): void
    {
        $hr = $this->actor('hr_manager');
        $pair = fn (string $yellow, string $red, string $direction = 'very_high'): array => ['thresholds' => [
            ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => $direction, 'limit_value' => $yellow, 'zone' => 'yellow'],
            ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => $direction, 'limit_value' => $red, 'zone' => 'red'],
        ]];
        $this->refused(fn () => $this->enterGuideline($hr, $pair('39.50', '39.50')), 'nearer normal');
        $this->refused(fn () => $this->enterGuideline($hr, $pair('40.00', '39.50')), 'nearer normal');
        $this->refused(fn () => $this->enterGuideline($hr, $pair('35.00', '35.50', 'very_low')), 'nearer normal');
        $this->refused(fn () => $this->enterGuideline($hr, ['thresholds' => [
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '120', 'zone' => 'yellow'],
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '125', 'zone' => 'yellow'],
        ]]), 'one yellow and one red limit at most');
        $this->refused(fn () => $this->enterGuideline($hr, ['thresholds' => [
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_low', 'limit_value' => '125', 'zone' => 'yellow'],
            ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '120', 'zone' => 'yellow'],
        ]]), 'must be below');
        $this->assertSame(0, TriageGuidelineVersion::query()->count());

        $version = $this->enterGuideline($hr, $pair('38.00', '39.50'));
        $this->assertSame(2, $version->threshold_count);
        $all = [];
        foreach (['systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_celsius', 'spo2_percent', 'weight_kg', 'height_cm'] as $i => $reading) {
            foreach (['adult', 'child'] as $band) {
                $all[] = ['reading' => $reading, 'age_band' => $band, 'direction' => 'very_low', 'limit_value' => (string) (10 + $i), 'zone' => 'red'];
                $all[] = ['reading' => $reading, 'age_band' => $band, 'direction' => 'very_low', 'limit_value' => (string) (20 + $i), 'zone' => 'yellow'];
                $all[] = ['reading' => $reading, 'age_band' => $band, 'direction' => 'very_high', 'limit_value' => (string) (200 + $i), 'zone' => 'yellow'];
                $all[] = ['reading' => $reading, 'age_band' => $band, 'direction' => 'very_high', 'limit_value' => (string) (300 + $i), 'zone' => 'red'];
            }
        }
        app(TriageGuidelineService::class)->discard($hr, $version->refresh(), ['lock_version' => $version->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(56, $this->enterGuideline($hr, ['thresholds' => $all])->threshold_count);
        $this->refused(fn () => $this->enterGuideline($hr, ['thresholds' => [...$all, $all[0]]]), 'thresholds');
    }

    public function test_lowering_after_the_crossed_reading_is_retaken_within_limits(): void
    {
        [$ca, $visit] = $this->redVisit();
        $this->refused(fn () => $this->triage($ca, $visit, ['temperature_celsius' => '39.60', 'chosen_zone' => 'yellow', 'override_reason_code' => 'doctor_reviewed_at_triage']), 'Temperature must be retaken and within limits');
        $this->refused(fn () => $this->triage($ca, $visit, ['temperature_celsius' => null, 'chosen_zone' => 'green']), 'Temperature must be retaken and within limits');
        $this->refused(fn () => $this->triage($ca, $visit, ['temperature_celsius' => '38.50', 'chosen_zone' => 'green', 'override_reason_code' => 'device_error_confirmed']), 'must be retaken and within limits');
        $yellow = $this->triage($ca, $visit, ['temperature_celsius' => '38.50', 'chosen_zone' => 'yellow']);
        $this->assertSame(['yellow', 'yellow', 2], [$yellow->suggested_zone, $yellow->chosen_zone, $yellow->sequence], 'red to yellow once only the yellow limit is crossed');
        $green = $this->triage($ca, $visit, ['temperature_celsius' => '37.00']);
        $this->assertSame(['green', 3], [$green->chosen_zone, $green->sequence]);
    }

    public function test_every_earlier_crossed_reading_must_be_retaken_not_only_the_latest_rows(): void
    {
        [$ca, $visit] = $this->redVisit();
        $this->triage($ca, $visit, ['temperature_celsius' => null, 'chosen_zone' => 'red', 'override_reason_code' => 'staff_concern']);
        $this->refused(fn () => $this->triage($ca, $visit, ['temperature_celsius' => null]), 'Temperature must be retaken');
        $this->assertSame('green', $this->triage($ca, $visit, ['temperature_celsius' => '36.90'])->chosen_zone);
    }

    public function test_never_while_a_red_flag_is_ticked_and_a_red_flag_keeps_the_visit_red(): void
    {
        $this->twoLimitGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->triage($ca, $visit, ['red_flag_codes' => ['rf_01'], 'chosen_zone' => 'red']);
        $this->refused(fn () => $this->triage($ca, $visit, ['red_flag_codes' => ['rf_01'], 'chosen_zone' => 'yellow', 'override_reason_code' => 'doctor_reviewed_at_triage']), 'while a red flag is ticked');
        $this->refused(fn () => $this->triage($ca, $visit, ['red_flag_codes' => []]), 'stays red');
        $this->assertSame('red', $this->triage($ca, $visit, ['red_flag_codes' => [], 'chosen_zone' => 'red', 'override_reason_code' => 'staff_concern'])->chosen_zone);
        $this->refused(fn () => $this->triage($ca, $visit, ['red_flag_codes' => []]), 'stays red');
    }

    public function test_an_override_below_the_suggestion_only_against_yellow_signs_with_every_reading_within_limits(): void
    {
        $this->twoLimitGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->triage($ca, $visit, ['yellow_sign_codes' => ['ys_01'], 'chosen_zone' => 'yellow']);
        $this->refused(fn () => $this->triage($ca, $visit, ['yellow_sign_codes' => ['ys_01'], 'pulse_bpm' => 125, 'chosen_zone' => 'green', 'override_reason_code' => 'doctor_reviewed_at_triage']), 'Pulse must be within limits');
        $row = $this->triage($ca, $visit, ['yellow_sign_codes' => ['ys_01'], 'chosen_zone' => 'green', 'override_reason_code' => 'doctor_reviewed_at_triage']);
        $this->assertSame(['yellow', 'green'], [$row->suggested_zone, $row->chosen_zone]);
    }

    public function test_audit_carries_no_crossed_readings_and_messages_carry_no_values(): void
    {
        [$ca, $visit] = $this->redVisit();
        try {
            $this->triage($ca, $visit, ['temperature_celsius' => '39.75', 'chosen_zone' => 'green']);
            $this->fail('must be refused');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('39.75', json_encode($e->errors(), JSON_THROW_ON_ERROR));
        }
        $this->triage($ca, $visit, ['temperature_celsius' => '37.00']);
        $json = json_encode(AuditLog::query()->get()->pluck('metadata')->all(), JSON_THROW_ON_ERROR);
        foreach (['crossed', 'retake', 'temperature', '40.00', '37.00'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_postgresql_assesses_the_shared_zone_cases_like_php_and_typescript(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL rule.');
        }
        $fixture = $this->fixture();
        $version = $this->twoLimitGuideline();
        foreach ($fixture['cases'] as $case) {
            $r = $case['readings'];
            $value = fn (string $key) => isset($r[$key]) && $r[$key] !== '' ? (string) $r[$key] : null;
            $row = DB::selectOne('SELECT zone, crossed, red FROM kp_triage_assess(?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $version->id, $case['age_band'], $value('systolic_bp'), $value('diastolic_bp'), $value('pulse_bpm'),
                $value('temperature_celsius'), $value('spo2_percent'), $value('weight_kg'), $value('height_cm'),
            ]);
            $zone = $row->zone;
            if ($case['yellow_signs'] !== [] && $zone === 'green') {
                $zone = 'yellow';
            }
            if ($case['red_flags'] !== []) {
                $zone = 'red';
            }
            $this->assertSame($case['zone'], $zone, $case['name']);
            $crossed = array_keys($case['crossed']);
            sort($crossed);
            $this->assertSame('{'.implode(',', $crossed).'}', $row->crossed, $case['name']);
            $red = array_keys(array_filter($case['crossed'], fn (string $z): bool => $z === 'red'));
            sort($red);
            $this->assertSame('{'.implode(',', $red).'}', $row->red, $case['name']);
        }
    }

    public function test_postgresql_refuses_a_faked_suggestion_age_group_or_lowering(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL rule.');
        }
        [$ca, $visit] = $this->redVisit();
        $first = VisitTriageAssessment::query()->where('visit_id', $visit->id)->sole();
        $insert = fn (array $set): string => "INSERT INTO visit_triage_assessments (organisation_id, branch_id, visit_id, queue_entry_id, sequence, recorded_by_user_id, recorded_at, idempotency_key, payload_hash, created_at, temperature_celsius, red_flag_codes, age_band, guideline_version_id, suggested_zone, chosen_zone, override_reason_code)
             SELECT organisation_id, branch_id, visit_id, queue_entry_id, 2, recorded_by_user_id, now(), gen_random_uuid(), repeat('a', 64), now(), "
            .($set['temp'] ?? 'NULL').', '.($set['flags'] ?? "'{}'").', '.($set['band'] ?? 'age_band').", guideline_version_id, '"
            .$set['suggested']."', '".$set['chosen']."', ".($set['reason'] ?? 'NULL')." FROM visit_triage_assessments WHERE id = {$first->id}";
        $this->assertDatabaseRefuses($insert(['temp' => '40', 'suggested' => 'green', 'chosen' => 'green']), 'follow the readings');
        $this->assertDatabaseRefuses($insert(['temp' => '38.6', 'band' => "'child'", 'suggested' => 'red', 'chosen' => 'red']), 'age group');
        $this->assertDatabaseRefuses($insert(['temp' => '39.6', 'suggested' => 'red', 'chosen' => 'yellow', 'reason' => "'doctor_reviewed_at_triage'"]), 'retaken within limits');
        $this->assertDatabaseRefuses($insert(['suggested' => 'green', 'chosen' => 'green']), 'readings taken again');
        $this->assertDatabaseRefuses($insert(['temp' => '38.5', 'suggested' => 'yellow', 'chosen' => 'green', 'reason' => "'device_error_confirmed'"]), 'retaken within limits');
        $this->assertDatabaseRefuses($insert(['temp' => '37', 'flags' => "'{rf_01}'", 'suggested' => 'red', 'chosen' => 'green', 'reason' => "'device_error_confirmed'"]), 'red flag is ticked');
        DB::unprepared($insert(['temp' => '37', 'suggested' => 'green', 'chosen' => 'green']));
        $this->assertSame(2, VisitTriageAssessment::query()->where('visit_id', $visit->id)->count());
    }

    public function test_postgresql_keeps_a_red_flag_visit_red_and_checks_a_child_age_group(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL rule.');
        }
        $this->twoLimitGuideline();
        [$ca, $child] = $this->waitingFixture(null, ['date_of_birth' => now('Asia/Kuala_Lumpur')->subYears(5)->toDateString(), 'guardian' => GuardianFixture::contact()]);
        $first = $this->triage($ca, $child, ['red_flag_codes' => ['rf_01'], 'chosen_zone' => 'red']);
        $this->assertSame('child', $first->age_band);
        $sql = "INSERT INTO visit_triage_assessments (organisation_id, branch_id, visit_id, queue_entry_id, sequence, recorded_by_user_id, recorded_at, idempotency_key, payload_hash, created_at, temperature_celsius, age_band, guideline_version_id, suggested_zone, chosen_zone)
             SELECT organisation_id, branch_id, visit_id, queue_entry_id, 2, recorded_by_user_id, now(), gen_random_uuid(), repeat('a', 64), now(), 36.5, %s, guideline_version_id, 'green', 'green' FROM visit_triage_assessments WHERE id = {$first->id}";
        $this->assertDatabaseRefuses(sprintf($sql, "'adult'"), 'age group');
        $this->assertDatabaseRefuses(sprintf($sql, "'child'"), 'stays red');
    }

    private function assertDatabaseRefuses(string $sql, string $message): void
    {
        DB::beginTransaction();
        try {
            DB::unprepared($sql);
            $this->fail("must be refused: $sql");
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? $e->getCode(), $sql);
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
