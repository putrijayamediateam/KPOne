<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Triage\TriagePermissions as P;
use App\Domain\Clinical\Triage\TriageService;
use App\Domain\Clinical\Triage\VisitTriageAssessment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Support\GuardianFixture;

/** TRI-1a: TriageService (TRI-1-DESIGN sections 3 to 8, owner decisions 19 Nov 2026). */
class TriageServiceTest extends TriageTestCase
{
    public function test_exact_grants_for_every_catalogue_role_and_the_grant_migration_replays_without_revoking(): void
    {
        foreach (PermissionCatalogue::roles() as $name => $permissions) {
            $this->assertSame(in_array($name, ['ca', 'ca_supervisor'], true), in_array(P::RECORD_BRANCH, $permissions, true), "$name record");
            $this->assertSame(in_array($name, ['director', 'hr_manager'], true), in_array(P::GUIDELINE_MANAGE_ORGANISATION, $permissions, true), "$name guideline");
            $actor = $this->actor($name);
            $this->assertSame(in_array($name, ['ca', 'ca_supervisor'], true), $actor->can(P::RECORD_BRANCH), "$name installed record");
            $this->assertSame(in_array($name, ['director', 'hr_manager'], true), $actor->can(P::GUIDELINE_MANAGE_ORGANISATION), "$name installed guideline");
        }
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(P::RECORD_BRANCH));
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(P::GUIDELINE_MANAGE_ORGANISATION));
        $this->assertContains(P::RECORD_BRANCH, PermissionCatalogue::all());
        $this->assertContains(P::GUIDELINE_MANAGE_ORGANISATION, PermissionCatalogue::all());

        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require base_path('database/migrations/2026_11_20_500300_grant_triage_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_ca_and_ca_supervisor_record_readings_only_until_a_guideline_is_approved(): void
    {
        foreach (['ca', 'ca_supervisor'] as $role) {
            [$ca, $visit] = $this->waitingFixture($this->actor($role));
            $row = $this->triage($ca, $visit, ['chosen_zone' => null]);
            $this->assertSame(1, $row->sequence);
            $this->assertNull($row->guideline_version_id);
            $this->assertNull($row->suggested_zone);
            $this->assertNull($row->chosen_zone);
            $this->assertNull($row->age_band);
            $this->assertSame('36.80', $row->temperature_celsius);
            $this->assertSame('normal', $visit->refresh()->priority);
            $this->refused(fn () => $this->triage($ca, $visit, ['chosen_zone' => 'green']), 'records readings only');
            $this->refused(fn () => $this->triage($ca, $visit, ['chosen_zone' => null, 'red_flag_codes' => ['rf_01']]), 'records readings only');
        }
    }

    public function test_every_other_role_is_refused_and_writes_nothing(): void
    {
        [, $visit] = $this->waitingFixture();
        foreach (array_diff(array_keys(PermissionCatalogue::roles()), ['ca', 'ca_supervisor']) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            try {
                app(TriageService::class)->record($actor, $visit, $this->triageInput($visit));
                $this->fail("$role must be refused");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, VisitTriageAssessment::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'visit.triage.recorded')->count());
    }

    public function test_branch_scope_another_branch_visit_is_not_found_and_an_unassigned_ca_is_refused(): void
    {
        [, $visit] = $this->waitingFixture();
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        $elsewhere = $this->actor('ca', $other);
        $this->selectBranch($elsewhere, $other);
        try {
            app(TriageService::class)->record($elsewhere, $visit, [...$this->triageInput($visit), 'expected_branch_id' => $other->id]);
            $this->fail('another branch visit must not be found');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // A CA whose assignment to this branch ended is refused under lock, even with the branch still in session.
        $ended = $this->actor('ca');
        $this->selectBranch($ended);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $ended->staffProfile()->value('id'))
            ->update(['valid_from' => now()->subDays(10)->toDateString(), 'valid_until' => now()->subDays(3)->toDateString()]);
        try {
            app(TriageService::class)->record($ended, $visit, $this->triageInput($visit));
            $this->fail('a CA with no current assignment must be refused');
        } catch (AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, VisitTriageAssessment::query()->count());
    }

    public function test_yellow_and_red_raise_urgent_while_waiting_with_the_existing_audit_and_green_never_lowers(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $row = $this->triage($ca, $visit, ['yellow_sign_codes' => ['ys_01'], 'chosen_zone' => 'yellow']);
        $this->assertSame('yellow', $row->suggested_zone);
        $this->assertSame('urgent', $visit->refresh()->priority);
        $marked = AuditLog::query()->where('event', 'visit.priority.marked_urgent')->sole();
        $this->assertSame('triage', $marked->metadata['source']);
        $this->assertSame($row->id, $marked->metadata['triage_assessment_id']);
        $this->assertSame(['priority'], AuditLog::query()->where('event', 'visit.details.updated')->where('subject_id', $visit->id)->latest('id')->firstOrFail()->metadata['changed_fields']);

        $green = $this->triage($ca, $visit, ['temperature_celsius' => '36.70']);
        $this->assertSame('green', $green->chosen_zone);
        $this->assertSame('urgent', $visit->refresh()->priority, 'green never lowers priority');
        $this->assertSame(2, $green->sequence);

        [$ca2, $redVisit] = $this->waitingFixture();
        $red = $this->triage($ca2, $redVisit, ['red_flag_codes' => ['rf_02'], 'chosen_zone' => 'red']);
        $this->assertSame('red', $red->suggested_zone);
        $this->assertSame('urgent', $redVisit->refresh()->priority);
    }

    public function test_audit_holds_ids_zone_and_codes_only_never_readings(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $row = $this->triage($ca, $visit, ['temperature_celsius' => '39.75', 'chosen_zone' => 'red']);
        $audit = AuditLog::query()->where('event', 'visit.triage.recorded')->sole();
        $this->assertSame($visit->getMorphClass(), $audit->subject_type);
        $this->assertSame($visit->id, $audit->subject_id);
        $this->assertSame([
            'triage_assessment_id' => $row->id, 'sequence' => 1, 'guideline_version_id' => $row->guideline_version_id,
            'suggested_zone' => 'red', 'chosen_zone' => 'red', 'override_reason_code' => null,
            'red_flag_codes' => [], 'yellow_sign_codes' => [], 'priority_raised' => true,
        ], $audit->metadata);
        $json = json_encode(AuditLog::query()->get()->pluck('metadata')->all(), JSON_THROW_ON_ERROR);
        foreach (['39.75', '120', 'systolic', 'temperature', 'age_band', self::DOCTOR_NAME] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "audit must not carry $leak");
        }
    }

    public function test_thresholds_follow_the_age_band(): void
    {
        $this->approvedGuideline();
        [$ca, $child] = $this->waitingFixture(null, ['date_of_birth' => now('Asia/Kuala_Lumpur')->subYears(5)->toDateString(), 'guardian' => GuardianFixture::contact()]);
        $row = $this->triage($ca, $child, ['temperature_celsius' => '38.60', 'chosen_zone' => 'red']);
        $this->assertSame('child', $row->age_band);
        $this->assertSame('red', $row->suggested_zone);

        [$ca, $adult] = $this->waitingFixture();
        $this->refused(fn () => $this->triage($ca, $adult, ['temperature_celsius' => '38.60', 'chosen_zone' => 'red', 'override_reason_code' => 'doctor_reviewed_at_triage']), 'Choose a reason for a higher zone');
        $row = $this->triage($ca, $adult, ['temperature_celsius' => '38.60']);
        $this->assertSame('adult', $row->age_band);
        $this->assertSame('green', $row->suggested_zone);

        [$ca, $edge] = $this->waitingFixture();
        $this->assertSame('red', $this->triage($ca, $edge, ['temperature_celsius' => '39.5', 'chosen_zone' => 'red'])->suggested_zone, 'at the limit counts');
    }

    public function test_unknown_date_of_birth_takes_both_bands_and_an_adult_confirmation_counts_as_adult(): void
    {
        $this->approvedGuideline();
        [$ca, $legacy] = $this->waitingFixture();
        DB::table('patients')->where('id', $legacy->patient_id)->update(['date_of_birth' => null]);
        $this->assertSame(0, DB::table('patient_adult_confirmations')->where('patient_id', $legacy->patient_id)->count());
        $row = $this->triage($ca, $legacy, ['temperature_celsius' => '38.60', 'chosen_zone' => 'red']);
        $this->assertSame(['unknown', 'red'], [$row->age_band, $row->suggested_zone], 'the child limit applies when age is unknown');

        [$ca, $confirmed] = $this->waitingFixture(null, ['date_of_birth' => null, 'adult_confirmed' => true, 'adult_confirmation_key' => (string) \Illuminate\Support\Str::uuid(), 'expected_branch_id' => $this->branch->id]);
        $this->assertSame(1, DB::table('patient_adult_confirmations')->where('patient_id', $confirmed->patient_id)->count());
        $row = $this->triage($ca, $confirmed, ['temperature_celsius' => '38.60']);
        $this->assertSame(['adult', 'green'], [$row->age_band, $row->suggested_zone]);
    }

    public function test_the_age_band_uses_the_branch_local_date_at_kuala_lumpur_midnight(): void
    {
        $this->approvedGuideline();
        // 16:30 UTC is 00:30 the next day in Kuala Lumpur; the patient turns 12 on that local date.
        $this->travelTo(CarbonImmutable::parse('2026-11-25 16:30:00', 'UTC'));
        [$ca, $visit] = $this->waitingFixture(null, ['date_of_birth' => '2014-11-26', 'guardian' => GuardianFixture::contact()]);
        $this->assertSame('adult', $this->triage($ca, $visit)->age_band);
    }

    public function test_lower_zone_needs_a_retake_with_new_readings_and_a_fixed_reason(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $input = ['temperature_celsius' => '40.00'];
        $this->refused(fn () => $this->triage($ca, $visit, [...$input, 'chosen_zone' => 'green', 'override_reason_code' => 'device_error_confirmed']), 'Take the readings again');
        $this->triage($ca, $visit, [...$input, 'chosen_zone' => 'red']);
        $this->refused(fn () => $this->triage($ca, $visit, [...$input, 'chosen_zone' => 'green', 'override_reason_code' => 'device_error_confirmed']), 'Take the readings again');
        $this->refused(fn () => $this->triage($ca, $visit, ['systolic_bp' => null, 'diastolic_bp' => null, 'pulse_bpm' => null, 'temperature_celsius' => null, 'spo2_percent' => null, 'weight_kg' => null, 'height_cm' => null, 'red_flag_codes' => ['rf_01'], 'chosen_zone' => 'green', 'override_reason_code' => 'doctor_reviewed_at_triage']), 'Take the readings again');
        $this->refused(fn () => $this->triage($ca, $visit, ['temperature_celsius' => '39.90', 'chosen_zone' => 'green']), 'Choose a reason to give a lower zone');
        $row = $this->triage($ca, $visit, ['temperature_celsius' => '39.90', 'chosen_zone' => 'yellow', 'override_reason_code' => 'doctor_reviewed_at_triage']);
        $this->assertSame(['red', 'yellow', 'doctor_reviewed_at_triage', 2], [$row->suggested_zone, $row->chosen_zone, $row->override_reason_code, $row->sequence]);
    }

    public function test_higher_zone_with_and_without_reason_and_a_same_zone_reason_is_refused(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->assertSame('yellow', $this->triage($ca, $visit, ['chosen_zone' => 'yellow'])->chosen_zone);
        $this->assertSame('staff_concern', $this->triage($ca, $visit, ['chosen_zone' => 'red', 'override_reason_code' => 'staff_concern'])->override_reason_code);
        $this->refused(fn () => $this->triage($ca, $visit, ['chosen_zone' => 'green', 'override_reason_code' => 'staff_concern']), 'only when you choose another zone');
        $this->refused(fn () => $this->triage($ca, $visit, ['chosen_zone' => null]), 'Confirm the suggested zone');
    }

    public function test_visit_and_queue_state_rules(): void
    {
        $this->approvedGuideline();
        [$doctor, $ca, $serving, $queue] = $this->servingFixture();
        $versionBefore = $serving->refresh()->lock_version;
        $row = $this->triage($ca, $serving, ['chosen_zone' => 'yellow']);
        $this->assertSame('yellow', $row->chosen_zone);
        $this->assertSame('normal', $serving->refresh()->priority, 'no Urgent once called in');
        $this->assertSame($versionBefore, $serving->lock_version, 'the doctor\'s open page stays current');
        $this->startEncounter($doctor, $serving, $queue->refresh());
        $this->refused(fn () => $this->triage($ca, $serving), 'read only once the doctor has started');

        $otc = $this->register($ca, $this->patient($ca), ['visit_type' => 'otc', 'assigned_doctor_user_id' => null]);
        $this->selectBranch($ca);
        $this->refused(fn () => app(TriageService::class)->record($ca, $otc, [...$this->triageInput($serving), 'visit_lock_version' => $otc->lock_version, 'expected_latest_triage_id' => null]), 'only for a registered consultation');

        [$ca, $cancelled, $q] = $this->waitingFixture();
        $this->selectBranch($ca);
        app(VisitAdministrationService::class)->cancel($cancelled, ['expected_branch_id' => $this->branch->id, 'lock_version' => $cancelled->lock_version, 'queue_lock_version' => $q->lock_version, 'cancellation_reason' => 'Synthetic cancellation'], $ca);
        $this->assertSame(Visit::STATUS_CANCELLED, $cancelled->refresh()->status);
        $this->refused(fn () => $this->triage($ca, $cancelled), 'only for a registered consultation');
    }

    public function test_stale_versions_latest_row_guideline_change_and_unknown_codes_are_refused(): void
    {
        $guideline = $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->refused(fn () => $this->triage($ca, $visit, ['visit_lock_version' => 99]), 'changed');
        $this->refused(fn () => $this->triage($ca, $visit, ['queue_lock_version' => 99]), 'changed');
        $this->triage($ca, $visit);
        $this->refused(fn () => $this->triage($ca, $visit, ['expected_latest_triage_id' => null]), 'changed');
        $this->refused(fn () => $this->triage($ca, $visit, ['red_flag_codes' => ['rf_09'], 'chosen_zone' => 'red']), 'not in the triage guideline');

        $old = $guideline->id;
        $this->approveGuideline($this->actor('hr_manager'), $this->enterGuideline($this->actor('director'), ['red_flags' => ['Synthetic only red flag']]));
        $this->refused(fn () => $this->triage($ca, $visit, ['expected_guideline_version_id' => $old]), 'guideline changed');
        $this->refused(fn () => $this->triage($ca, $visit, ['red_flag_codes' => ['rf_02'], 'chosen_zone' => 'red']), 'not in the triage guideline');
    }

    public function test_validation_bounds_are_refused_before_any_database_error(): void
    {
        [$ca, $visit] = $this->waitingFixture();
        foreach ([['systolic_bp' => 32768], ['temperature_celsius' => '1000.00'], ['temperature_celsius' => '37.995'], ['spo2_percent' => '101'], ['systolic_bp' => 120, 'diastolic_bp' => null], ['red_flag_codes' => ['RF 01']], ['red_flag_codes' => ['rf_01', 'rf_01']]] as $bad) {
            try {
                $this->triage($ca, $visit, ['chosen_zone' => null, ...$bad]);
                $this->fail('must be refused: '.json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, VisitTriageAssessment::query()->count());
    }

    public function test_no_reading_is_required(): void
    {
        [$ca, $visit] = $this->waitingFixture();
        $row = $this->triage($ca, $visit, ['systolic_bp' => null, 'diastolic_bp' => null, 'pulse_bpm' => null, 'temperature_celsius' => null, 'spo2_percent' => null, 'weight_kg' => null, 'height_cm' => null, 'chosen_zone' => null]);
        $this->assertNull($row->temperature_celsius);
    }

    public function test_idempotent_retry_returns_the_same_row_and_a_changed_or_moved_key_is_refused(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->selectBranch($ca);
        $input = $this->triageInput($visit);
        $first = app(TriageService::class)->record($ca, $visit, $input);
        $this->assertSame($first->id, app(TriageService::class)->record($ca, $visit, $input)->id);
        $this->assertSame(1, AuditLog::query()->where('event', 'visit.triage.recorded')->count());
        $this->refused(fn () => app(TriageService::class)->record($ca, $visit, [...$input, 'pulse_bpm' => 81]), 'retry key has already been used');
        [, $other] = $this->waitingFixture($ca);
        $this->refused(fn () => app(TriageService::class)->record($ca, $other, [...$this->triageInput($other), 'idempotency_key' => $input['idempotency_key']]), 'retry key has already been used');
    }

    public function test_the_model_and_postgresql_keep_triage_append_only(): void
    {
        [$ca, $visit] = $this->waitingFixture();
        $row = $this->triage($ca, $visit, ['chosen_zone' => null]);
        try {
            $row->forceFill(['pulse_bpm' => 99])->save();
            $this->fail('model update must be refused');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['UPDATE visit_triage_assessments SET pulse_bpm = 99', 'DELETE FROM visit_triage_assessments', 'TRUNCATE visit_triage_assessments'] as $sql) {
            $this->assertDatabaseRefuses($sql, 'P0001');
        }
        $insert = fn (string $columns, string $values): string => "INSERT INTO visit_triage_assessments (organisation_id, branch_id, visit_id, queue_entry_id, sequence, recorded_by_user_id, recorded_at, idempotency_key, payload_hash, created_at{$columns})
             SELECT organisation_id, branch_id, visit_id, queue_entry_id, 2, recorded_by_user_id, now(), gen_random_uuid(), repeat('a', 64), now(){$values} FROM visit_triage_assessments LIMIT 1";
        $this->assertDatabaseRefuses(str_replace(', 2, recorded_by', ', 3, recorded_by', $insert('', '')), '23514', 'numbered in order');
        $this->assertDatabaseRefuses($insert(', override_reason_code', ", 'staff_concern'"), '23514', 'visit_triage_shape');
        $this->assertDatabaseRefuses(str_replace('SELECT organisation_id, branch_id,', 'SELECT organisation_id, branch_id + 1,', $insert('', '')), '23514', 'registered consultation');
        $other = $this->actor('finance_officer');
        $this->assertDatabaseRefuses(str_replace('recorded_by_user_id, now()', $other->id.', now()', $insert('', '')), '23514', 'holder of the triage permission');
        $this->approvedGuideline();
        $this->assertDatabaseRefuses($insert('', ''), '23514', 'version in force');
    }

    private function assertDatabaseRefuses(string $sql, string $state, ?string $message = null): void
    {
        DB::beginTransaction();
        try {
            DB::unprepared($sql);
            $this->fail("must be refused: $sql");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? $e->getCode(), $sql);
            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        } finally {
            DB::rollBack();
        }
    }
}
