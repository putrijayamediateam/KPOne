<?php

namespace Tests\Feature\Patient;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Patient\Services\RegistrationSettingsService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GuardianFixture;

class UnknownDobConfirmationTest extends PatientTestCase
{
    private function input(array $extra = []): array
    {
        return $this->patientAttributes(['date_of_birth' => null, 'adult_confirmed' => true, 'adult_confirmation_key' => (string) Str::uuid(), 'expected_branch_id' => Branch::query()->where('organisation_id', $this->organisation->id)->value('id'), ...$extra]);
    }

    private function assigned(string $role = 'ca'): User
    {
        $actor = $this->actor($role);
        GuardianFixture::assign($actor);
        session([BranchAccessService::SESSION_KEY => Branch::query()->where('organisation_id', $actor->organisation_id)->value('id')]);

        return $actor;
    }

    public function test_blank_create_requires_explicit_confirmation_and_writes_only_id_audit(): void
    {
        $actor = $this->assigned();
        $service = app(PatientAdministrationService::class);
        foreach ([false, null, 'no'] as $tick) {
            try {
                $service->create($actor, $this->input(['adult_confirmed' => $tick]));
                $this->fail('Missing adult acknowledgement accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('adult_confirmed', $e->errors());
            }
        }
        $this->assertDatabaseCount('patients', 0);
        $patient = $service->create($actor, $this->input());
        $row = DB::table('patient_adult_confirmations')->sole();
        $this->assertSame($patient->id, $row->patient_id);
        $this->assertSame($actor->id, $row->confirmed_by_user_id);
        $this->assertSame('staff_create', $row->source);
        $this->assertSame(['confirmation_id' => $row->id], json_decode(DB::table('audit_logs')->where('event', 'patient.adult_confirmed')->value('metadata'), true));
        $this->assertStringNotContainsString('adult_confirm', json_encode(app(PatientDirectoryService::class)->detail($actor, $patient), JSON_THROW_ON_ERROR));
        $service->create($actor, $this->patientAttributes());
        $this->assertDatabaseCount('patient_adult_confirmations', 1);
    }

    public function test_create_and_clear_retries_are_idempotent_and_conflicts_stale_are_refused(): void
    {
        $actor = $this->assigned();
        $service = app(PatientAdministrationService::class);
        $input = $this->input();
        $p = $service->create($actor, $input);
        $this->assertSame($p->id, $service->create($actor, $input)->id);
        try {
            $service->create($actor, [...$input, 'full_name' => 'Different synthetic name']);
            $this->fail('Conflicting key accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('adult_confirmation_key', $e->errors());
        }
        $known = $service->create($actor, $this->patientAttributes());
        $clear = $this->input(['lock_version' => 1]);
        $cleared = $service->update($known, $clear, $actor);
        $this->assertNull($cleared->date_of_birth);
        $this->assertSame(2, $service->update($cleared, $clear, $actor)->lock_version);
        $this->assertDatabaseCount('patient_adult_confirmations', 2);
        try {
            $service->update($cleared, $this->patientAttributes(['lock_version' => 1]), $actor);
            $this->fail('Stale version accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lock_version', $e->errors());
        }
    }

    public function test_clearing_requires_confirmation_but_legacy_unrelated_edits_do_not(): void
    {
        $actor = $this->assigned();
        $service = app(PatientAdministrationService::class);
        $p = $service->create($actor, $this->patientAttributes());
        try {
            $service->update($p, $this->patientAttributes(['date_of_birth' => null, 'lock_version' => 1, 'expected_branch_id' => $this->input()['expected_branch_id']]), $actor);
            $this->fail('Cleared DOB without acknowledgement');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('adult_confirmed', $e->errors());
        }
        try {
            $service->update($p, $this->input(['lock_version' => 1, 'adult_confirmation_key' => 'not-a-uuid']), $actor);
            $this->fail('Malformed domain retry key accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('adult_confirmation_key', $e->errors());
        }
        $legacyActor = $this->actor('ca');
        $legacy = $this->legacyPatient($legacyActor);
        $saved = $service->update($legacy, $this->patientAttributes(['date_of_birth' => null, 'mobile_phone' => null, 'lock_version' => 1]), $legacyActor);
        $this->assertSame(2, $saved->lock_version);
        $this->assertDatabaseCount('patient_adult_confirmations', 0);
    }

    public function test_entered_minor_dob_still_requires_guardian_and_evidence_is_not_age_authority(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27));
        $actor = $this->assigned();
        $service = app(PatientAdministrationService::class);
        $p = $service->create($actor, $this->input());
        try {
            $service->update($p, $this->patientAttributes(['date_of_birth' => '2015-03-03', 'lock_version' => 1]), $actor);
            $this->fail('Confirmation bypassed known minor guardian rule');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('guardian', $e->errors());
        }
        $saved = $service->update($p, $this->patientAttributes(['date_of_birth' => '2015-03-03', 'lock_version' => 1, 'guardian' => GuardianFixture::contact()]), $actor);
        $this->assertSame('2015-03-03', $saved->date_of_birth->toDateString());
        $this->assertDatabaseCount('patient_adult_confirmations', 1);
    }

    public function test_required_dob_settings_cannot_be_bypassed_by_confirmation(): void
    {
        $actor = $this->assigned('director');
        app(RegistrationSettingsService::class)->save($actor, ['expected_branch_id' => $this->input()['expected_branch_id'], 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'require_date_of_birth' => true, 'require_nationality' => false]);
        $this->expectException(ValidationException::class);
        app(PatientAdministrationService::class)->create($actor, $this->input());
    }

    public function test_all_roles_and_fresh_revocation_branch_checks(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->assigned($role);
            try {
                app(PatientAdministrationService::class)->create($actor, $this->input());
                $this->assertContains($role, ['director', 'ca', 'ca_supervisor']);
            } catch (AuthorizationException $e) {
                $this->assertNotContains($role, ['director', 'ca', 'ca_supervisor']);
            }
        }
        $actor = $this->assigned();
        $p = app(PatientAdministrationService::class)->create($actor, $input = $this->input());
        $actor->syncRoles(['marketing']);
        try {
            app(PatientAdministrationService::class)->create($actor, $input);
            $this->fail('Revoked actor replay accepted');
        } catch (AuthorizationException $e) {
            $this->assertNotNull($p->id);
        }
        $actor = $this->assigned();
        try {
            app(PatientAdministrationService::class)->create($actor, $this->input(['expected_branch_id' => 999999]));
            $this->fail('Foreign branch accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_branch_id', $e->errors());
        }
    }

    public function test_evidence_is_append_only_and_audit_failure_rolls_back(): void
    {
        $actor = $this->assigned();
        app(PatientAdministrationService::class)->create($actor, $this->input());
        foreach (['UPDATE patient_adult_confirmations SET source=source', 'DELETE FROM patient_adult_confirmations', 'TRUNCATE patient_adult_confirmations'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Evidence mutated');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
            $this->assertDatabaseCount('patient_adult_confirmations', 1);
        }
        $before = DB::table('patients')->orderBy('id')->get()->toJson();
        $audit = $this->mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andReturn(null);
        try {
            app(PatientAdministrationService::class)->create($actor, $this->input());
            $this->fail('Audit failure accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($before, DB::table('patients')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('patient_adult_confirmations', 1);
    }
}
