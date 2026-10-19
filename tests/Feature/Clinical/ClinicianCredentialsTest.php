<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Documents\FixedMedicalCertificateTemplates;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Services\ClinicianCredentialService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Visit\VisitTestCase;

class ClinicianCredentialsTest extends VisitTestCase
{
    private function command(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(),
            'professional_name' => 'Synthetic Registered Doctor', 'mmc_number' => '123456', 'verified' => true, ...$extra];
    }

    public function test_exact_default_grants_and_patient_free_pages_and_hub(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->assertSame($role === 'director', $actor->can(ClinicianCredentialService::PERMISSION), $role);
            $this->get('/clinician-credentials')->assertStatus($role === 'director' ? 200 : 403);
            $settings = in_array($role, ['director', 'administrator', 'finance_officer', 'panel_officer'], true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get('/medical-certificate-templates');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $response->assertStatus($settings ? 200 : 403);
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/from\s+"?(patients|patient_identifiers|visits|clinical_encounters)"?\b/i', $query['query']);
            }
            if ($settings) {
                $response->assertInertia(fn (Assert $p) => $p->component('MedicalCertificateTemplates/Index')->has('revisions', 2)->missing('patients')->missing('visits'));
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
            $groups = app(ClinicManagementNavigation::class)->groups($actor);
            $links = collect($groups)->pluck('links')->flatten(1)->pluck('href')->all();
            $this->assertSame($role === 'director', in_array('/clinician-credentials', $links, true));
            $this->assertSame($settings, in_array('/medical-certificate-templates', $links, true));
        }
        foreach (['medical_certificates.print.own', 'medical_certificates.void.own'] as $name) {
            $this->assertFalse(Permission::query()->where('name', $name)->exists());
        }
        $this->assertTrue(Permission::query()->where('name', 'medical_certificates.issue.own')->exists());
        $this->assertTrue(Schema::hasTable('medical_certificates'));
        $this->assertTrue(Schema::hasTable('mc_number_counters'));
        foreach (['issued_documents'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_verified_credentials_are_versioned_values_free_and_independent_of_account_name(): void
    {
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($director);
        $service = app(ClinicianCredentialService::class);
        $command = $this->command();
        $before = DB::table('users')->where('id', $doctor->id)->first();
        $service->save($director, $doctor->id, $command);
        $profile = ClinicianProfessionalProfile::query()->sole();
        $this->assertSame(1, $profile->lock_version);
        $this->assertTrue($profile->verified);
        $this->assertSame($director->id, $profile->getAttribute('verified_by_user_id'));
        $this->assertEquals($before, DB::table('users')->where('id', $doctor->id)->first());
        $this->assertArrayNotHasKey('professional_name', $profile->toArray());
        $this->assertArrayNotHasKey('mmc_number', $profile->toArray());
        $audit = AuditLog::query()->where('event', 'clinician_credentials.updated')->sole();
        $this->assertEqualsCanonicalizing(['fields', 'record_version'], array_keys($audit->metadata));
        $this->assertStringNotContainsString($command['professional_name'], json_encode($audit->metadata));
        $this->assertStringNotContainsString($command['mmc_number'], json_encode($audit->metadata));
        $doctor->forceFill(['name' => 'Synthetic Changed Account Name'])->save();
        $this->assertSame($command['professional_name'], $profile->refresh()->professional_name);
        $service->save($director, $doctor->id, $this->command(['lock_version' => 1, 'verified' => false]));
        $this->assertFalse($profile->refresh()->verified);
        $this->assertNull($profile->getAttribute('verified_at'));
        $this->assertNull($profile->getAttribute('verified_by_user_id'));
        $this->assertSame(2, $profile->lock_version);
    }

    public function test_retries_conflicts_stale_versions_and_duplicate_mmc(): void
    {
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $second = $this->actor('resident_doctor');
        $this->selectBranch($director);
        $service = app(ClinicianCredentialService::class);
        $a = $this->command();
        $service->save($director, $doctor->id, $a);
        $service->save($director, $doctor->id, $a);
        $this->assertSame(1, DB::table('clinician_credential_commands')->count());
        foreach ([[$doctor, [...$a, 'mmc_number' => '654321'], 'idempotency_key'], [$doctor, $this->command(), 'lock_version'], [$second, $this->command(), 'mmc_number']] as [$subject, $payload, $field]) {
            try {
                $service->save($director, $subject->id, $payload);
                $this->fail('Invalid command accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertSame(1, ClinicianProfessionalProfile::query()->sole()->lock_version);
        $this->assertSame(1, DB::table('clinician_credential_commands')->count());
    }

    public function test_own_read_is_minimized_other_roles_and_stale_branch_or_revoked_permission_cannot_write(): void
    {
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $other = $this->actor('resident_doctor');
        $this->selectBranch($director);
        app(ClinicianCredentialService::class)->save($director, $doctor->id, $this->command());
        $this->selectBranch($doctor);
        $this->get('/clinician-credentials/me')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManage', false)->has('clinicians', 1)->where('clinicians.0.userId', $doctor->id)->where('clinicians.0.professionalName', 'Synthetic Registered Doctor')->missing('patients'));
        $this->get('/clinician-credentials')->assertForbidden();
        $this->post('/clinician-credentials/'.$other->id, $this->command())->assertForbidden();
        $this->selectBranch($director);
        $this->post('/clinician-credentials/'.$doctor->id, $this->command(['expected_branch_id' => 999999]))->assertSessionHasErrors('expected_branch_id');
        $director->getAllPermissions();
        DB::table('role_has_permissions')->where('permission_id', Permission::findByName(ClinicianCredentialService::PERMISSION)->id)->delete();
        try {
            app(ClinicianCredentialService::class)->save($director, $doctor->id, $this->command(['lock_version' => 1]));
            $this->fail('Revoked grant accepted');
        } catch (AuthorizationException) {
            $this->assertSame(1, ClinicianProfessionalProfile::query()->sole()->lock_version);
        }
    }

    public function test_validation_no_implicit_clinical_eligibility_and_direct_permission_is_authoritative(): void
    {
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $this->selectBranch($director);
        foreach ([['professional_name' => '<script>'], ['professional_name' => '   '], ['professional_name' => str_repeat('X', 201)], ['mmc_number' => 'ABC'], ['mmc_number' => "12\n34"], ['idempotency_key' => 'bad']] as $invalid) {
            $this->post('/clinician-credentials/'.$doctor->id, $this->command($invalid))->assertSessionHasErrors(array_key_first($invalid));
        }
        $this->post('/clinician-credentials/'.$ca->id, $this->command())->assertForbidden();
        $director->givePermissionTo('encounters.start.own');
        $this->post('/clinician-credentials/'.$director->id, $this->command())->assertForbidden();
        $this->assertSame(0, ClinicianProfessionalProfile::query()->count());
        $ca->givePermissionTo(ClinicianCredentialService::PERMISSION);
        $this->selectBranch($ca);
        app(ClinicianCredentialService::class)->save($ca, $doctor->id, $this->command());
        $this->assertTrue(ClinicianProfessionalProfile::query()->sole()->verified);
        $this->assertFalse($ca->can('encounters.start.own'));
    }

    public function test_fixed_revisions_are_patient_free_allowlisted_and_database_immutable(): void
    {
        $revisions = app(FixedMedicalCertificateTemplates::class)->revisions();
        $this->assertSame(['bm', 'en'], array_column($revisions, 'language'));
        foreach ($revisions as $r) {
            $this->assertSame(1, $r['revision']);
            $this->assertFalse($r['productionApproved']);
            $this->assertSame('mc-a4-v1', $r['rendererVersion']);
            preg_match_all('/{{([a-z_]+)}}/', $r['wording'], $matches);
            $this->assertSame([], array_diff($matches[1], $r['mergeFields']));
            $this->assertNotContains('diagnosis', $r['mergeFields']);
            $this->assertStringNotContainsString('<', $r['wording']);
        }
        DB::beginTransaction();
        try {
            DB::table('mc_template_revisions')->where('language', 'en')->update(['wording' => 'Changed']);
            $this->fail('Template mutation accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($revisions, app(FixedMedicalCertificateTemplates::class)->revisions());
    }

    public function test_cross_organisation_expired_assignment_and_inactive_subject_fail_closed(): void
    {
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $org = Organisation::query()->create(['code' => 'SYN_DOC02A_OTHER', 'name' => 'Synthetic Other Organisation', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org->id, 'code' => 'SYN_OTHER', 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $outsider = $this->actor('resident_doctor', $branch);
        $this->selectBranch($director);
        $this->post('/clinician-credentials/'.$outsider->id, $this->command())->assertNotFound();
        $doctor->forceFill(['is_active' => false])->save();
        $this->post('/clinician-credentials/'.$doctor->id, $this->command())->assertForbidden();
        $doctor->forceFill(['is_active' => true])->save();
        StaffBranchAssignment::query()->where('staff_profile_id', $director->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->post('/clinician-credentials/'.$doctor->id, $this->command())->assertForbidden();
        $this->assertSame(0, ClinicianProfessionalProfile::query()->count());
    }

    public function test_audit_failure_rolls_back_profile_version_and_retry_command(): void
    {
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($director);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        try {
            app(ClinicianCredentialService::class)->save($director, $doctor->id, $this->command());
            $this->fail('Audit failure accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertSame(0, ClinicianProfessionalProfile::query()->count());
        $this->assertSame(0, DB::table('clinician_credential_commands')->count());
    }

    public function test_postgresql_retains_profiles_and_commands_and_rejects_invalid_transitions(): void
    {
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($director);
        app(ClinicianCredentialService::class)->save($director, $doctor->id, $this->command());
        foreach ([
            'DELETE FROM clinician_professional_profiles',
            'UPDATE clinician_professional_profiles SET lock_version = lock_version + 2',
            'UPDATE clinician_professional_profiles SET user_id = '.$director->id.', lock_version = lock_version + 1',
            'UPDATE clinician_credential_commands SET payload_hash = repeat(\'0\',64)',
            'DELETE FROM clinician_credential_commands',
            'DELETE FROM mc_template_revisions',
            'TRUNCATE mc_template_revisions',
        ] as $sql) {
            DB::beginTransaction();
            try {
                DB::statement($sql);
                $this->fail('Protected mutation accepted: '.$sql);
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
        $this->assertSame(1, ClinicianProfessionalProfile::query()->sole()->lock_version);
        $this->assertSame(1, DB::table('clinician_credential_commands')->count());
        $this->assertSame(2, DB::table('mc_template_revisions')->count());
    }

    public function test_permission_migration_is_additive_and_replay_safe(): void
    {
        $ca = $this->actor('ca');
        $ca->givePermissionTo(ClinicianCredentialService::PERMISSION);
        $before = DB::table('model_has_permissions')->get()->toArray();
        $migration = require database_path('migrations/2026_10_19_001100_grant_clinician_credentials_permission.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertEquals($before, DB::table('model_has_permissions')->get()->toArray());
        $this->assertSame(1, Permission::query()->where('name', ClinicianCredentialService::PERMISSION)->count());
        $this->assertTrue(PermissionCatalogue::isAdministrativeAuthority(ClinicianCredentialService::PERMISSION));
    }
}
