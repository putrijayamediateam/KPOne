<?php

namespace Tests\Feature\Patient;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Patient\Services\PatientGuardianService;
use App\Domain\Patient\Services\PatientIdentityService;
use App\Domain\Patient\Services\PatientWhatsAppService;
use App\Domain\Patient\Services\RegistrationSettingsService;
use App\Models\User;
use Database\Factories\PatientFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GuardianFixture;

class GuardianContactTest extends PatientTestCase
{
    protected function actor(string $role = 'director', ?Organisation $organisation = null): User
    {
        $actor = parent::actor($role, $organisation);
        $actor->forceFill(['name' => 'Synthetic guardian '.$role, 'email' => 'guardian-'.Str::uuid().'@kpone.test'])->save();

        return $actor;
    }

    private function minorInput(array $extra = []): array
    {
        return $this->patientAttributes(['date_of_birth' => '2015-03-03', 'mobile_phone' => null, 'guardian' => GuardianFixture::contact(), ...$extra]);
    }

    public function test_adults_and_unrelated_minor_edits_have_no_new_branch_requirement_even_with_unchanged_guardian(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        $actor = $this->actor('ca');
        $service = app(PatientAdministrationService::class);
        $adult = $service->create($actor, $this->patientAttributes());
        $this->assertNotNull($adult->id);
        $legacy = PatientFactory::new()->create(['organisation_id' => $actor->organisation_id, 'date_of_birth' => '2015-03-03', 'mobile_phone' => '+12025550121']);
        $saved = $service->update($legacy, $this->patientAttributes(['date_of_birth' => '2015-03-03', 'mobile_phone' => '+12025550121', 'lock_version' => $legacy->lock_version]), $actor);
        $this->assertSame(2, $saved->lock_version);
        $this->assertDatabaseCount('patient_guardian_contacts', 0);
        GuardianFixture::assign($actor);
        $child = $service->create($actor, $this->minorInput());
        $guardian = app(PatientGuardianService::class)->contact($child);
        DB::table('staff_branch_assignments')->delete();
        app()->forgetInstance(BranchAccessService::class);
        $saved = $service->update($child, $this->minorInput(['guardian' => [...$guardian, 'retry_key' => (string) Str::uuid()], 'full_name' => 'Synthetic edited child', 'lock_version' => $child->lock_version]), $actor);
        $this->assertSame('Synthetic edited child', $saved->full_name);
        $this->assertSame(1, app(PatientGuardianService::class)->contact($saved)['lock_version']);
    }

    public function test_known_minor_boundary_requires_guardian_and_exactly_eighteen_does_not(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        $this->assertTrue(PatientGuardianService::minorOn('2008-10-28', '2026-10-27'));
        $this->assertFalse(PatientGuardianService::minorOn('2008-10-27', '2026-10-27'));
        $this->assertFalse(PatientGuardianService::minorOn(null, '2026-10-27'));
        $this->assertTrue(PatientGuardianService::minorOn('2006-03-01', '2024-02-29'));
        $this->assertFalse(PatientGuardianService::minorOn('2006-02-28', '2024-02-29'));
        $actor = $this->actor('ca');
        $adult = app(PatientAdministrationService::class)->create($actor, $this->patientAttributes(['date_of_birth' => '2008-10-27']));
        $this->assertNotNull($adult->id);
        $this->expectException(ValidationException::class);
        app(PatientAdministrationService::class)->create($actor, $this->patientAttributes(['date_of_birth' => '2008-10-28']));
    }

    public function test_guardian_phone_only_is_encrypted_not_substituted_and_creation_retry_is_idempotent(): void
    {
        $actor = $this->actor('ca');
        GuardianFixture::assign($actor);
        $input = $this->minorInput();
        $service = app(PatientAdministrationService::class);
        $patient = $service->create($actor, $input);
        $this->assertNull($patient->mobile_phone);
        $this->assertSame($input['email'], $patient->email);
        $this->assertSame($patient->id, $service->create($actor, $input)->id);
        $this->assertDatabaseCount('patients', 1);
        $contact = app(PatientGuardianService::class)->contact($patient);
        $this->assertSame('Synthetic Guardian', $contact['full_name']);
        $this->assertStringNotContainsString('Synthetic Guardian', DB::table('patient_guardian_contacts')->sole()->encrypted_contact);
        $metadata = json_encode(DB::table('audit_logs')->pluck('metadata')->all());
        foreach (['Synthetic Guardian', '+12025550120', 'guardian@kpone.test'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $metadata);
        }
        $this->assertCount(0, app(PatientIdentityService::class)->possibleDuplicates($actor->organisation_id, ['search_name' => 'different child', 'mobile_phone' => '+12025550120']));
        $this->actingAs($actor);
        $this->assertSame(0, app(PatientDirectoryService::class)->search($actor, ['search_type' => 'phone', 'query' => '+12025550120'])['total']);
        $this->expectException(HttpException::class);
        app(PatientWhatsAppService::class)->destination($actor, 'patient', $patient->patient_number);
    }

    public function test_changed_guardian_is_versioned_and_retries_conflict_without_overwriting_other_children(): void
    {
        $actor = $this->actor('ca');
        GuardianFixture::assign($actor);
        $service = app(PatientAdministrationService::class);
        $child = $service->create($actor, $this->minorInput());
        $sibling = $service->create($actor, $this->minorInput(['full_name' => 'Synthetic sibling']));
        $data = $this->minorInput(['lock_version' => $child->lock_version, 'guardian' => [...GuardianFixture::contact(), 'lock_version' => 1, 'full_name' => 'Synthetic replacement']]);
        $saved = $service->update($child, $data, $actor);
        $this->assertSame($saved->id, $service->update($child, $data, $actor)->id);
        $this->assertSame('Synthetic Guardian', app(PatientGuardianService::class)->contact($sibling)['full_name']);
        $this->assertSame(2, app(PatientGuardianService::class)->contact($saved)['lock_version']);
        $this->expectException(ValidationException::class);
        $service->update($saved, [...$data, 'guardian' => [...$data['guardian'], 'full_name' => 'Conflicting retry']], $actor);
    }

    public function test_stale_guardian_refuses_and_audit_failure_rolls_back_every_patient_source(): void
    {
        $actor = $this->actor('ca');
        GuardianFixture::assign($actor);
        $service = app(PatientAdministrationService::class);
        $child = $service->create($actor, $this->minorInput());
        $before = $child->getAttributes();
        try {
            $service->update($child, $this->minorInput(['lock_version' => 1, 'guardian' => [...GuardianFixture::contact(), 'full_name' => 'Synthetic stale', 'lock_version' => 0]]), $actor);
            $this->fail('Stale guardian accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('guardian.lock_version', $e->errors());
        }
        $this->assertSame($before, $child->fresh()->getAttributes());
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturn(null);
        try {
            $service->create($actor, $this->minorInput(['full_name' => 'Synthetic rollback']));
            $this->fail('Audit failure must refuse');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('patient_guardian_contacts', 1);
    }

    public function test_every_catalogue_role_has_exact_existing_contact_authority_and_foreign_patients_are_denied(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->assertSame(in_array($role, ['director', 'ca', 'ca_supervisor'], true), $actor->can('patients.create.organisation'), $role);
            $this->assertSame(in_array($role, ['director', 'resident_doctor', 'ca', 'ca_supervisor'], true), $actor->can('patients.view.organisation'), $role);
        }
        $actor = $this->actor('ca');
        GuardianFixture::assign($actor);
        $organisation = $this->organisation->replicate();
        $organisation->code = 'SYNTHETIC_OTHER';
        $organisation->name = 'Synthetic other organisation';
        $organisation->save();
        $foreign = PatientFactory::new()->create(['organisation_id' => $organisation->id, 'full_name' => 'Synthetic foreign child']);
        $this->expectException(AuthorizationException::class);
        app(PatientDirectoryService::class)->detail($actor, $foreign);
    }

    public function test_unknown_dob_has_no_guardian_gate_and_changed_branch_or_revoked_actor_cannot_write_guardian(): void
    {
        $director = $this->actor('director');
        GuardianFixture::assign($director);
        $branch = Branch::query()->where('organisation_id', $director->organisation_id)->firstOrFail();
        $settings = app(RegistrationSettingsService::class);
        $settings->save($director, ['expected_branch_id' => $branch->id, 'lock_version' => $settings->policy($director->organisation_id)['lockVersion'], 'idempotency_key' => (string) Str::uuid(), 'require_date_of_birth' => false, 'require_nationality' => false]);
        $ca = $this->actor('ca');
        GuardianFixture::assign($ca);
        $unknown = app(PatientAdministrationService::class)->create($ca, $this->patientAttributes(['date_of_birth' => null, 'adult_confirmed' => true, 'adult_confirmation_key' => (string) Str::uuid(), 'expected_branch_id' => $branch->id]));
        $this->assertNull($unknown->date_of_birth);
        $this->assertDatabaseCount('patient_guardian_contacts', 0);
        try {
            app(PatientAdministrationService::class)->create($ca, $this->minorInput(['expected_branch_id' => 999999]));
            $this->fail('Wrong effective branch accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_branch_id', $e->errors());
        }
        $ca->syncRoles(['marketing']);
        $this->expectException(AuthorizationException::class);
        app(PatientAdministrationService::class)->create($ca, $this->minorInput());
    }

    public function test_guardian_update_preserves_all_unrelated_id_ordered_tables_and_commands_are_append_only(): void
    {
        $actor = $this->actor('ca');
        GuardianFixture::assign($actor);
        $patient = app(PatientAdministrationService::class)->create($actor, $this->minorInput());
        $snapshot = function (): array {
            $result = [];
            foreach (DB::select("select tablename from pg_tables where schemaname='public' order by tablename") as $table) {
                $name = $table->tablename;
                if (in_array($name, ['patients', 'patient_guardian_contacts', 'patient_guardian_commands', 'audit_logs'], true)) {
                    continue;
                }
                $columns = array_column(DB::select('select column_name from information_schema.columns where table_schema=? and table_name=? order by ordinal_position', ['public', $name]), 'column_name');
                $query = DB::table($name);
                foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                    $query->orderBy($column);
                }
                $result[$name] = $query->get()->toJson();
            }

            return $result;
        };
        $before = $snapshot();
        app(PatientAdministrationService::class)->update($patient, $this->minorInput(['lock_version' => 1, 'guardian' => [...GuardianFixture::contact(), 'lock_version' => 1, 'full_name' => 'Synthetic changed guardian']]), $actor);
        $this->assertSame($before, $snapshot());
        foreach (['UPDATE patient_guardian_commands SET payload_hash=payload_hash', 'DELETE FROM patient_guardian_commands', 'TRUNCATE patient_guardian_commands'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Append-only command mutated');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
        }
    }
}
