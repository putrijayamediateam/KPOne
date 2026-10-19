<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Documents\MedicalCertificateDirectory;
use App\Domain\Clinical\Documents\MedicalCertificateService;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Identity\Services\ClinicianCredentialService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Billing\BillingTestCase;

class MedicalCertificateTest extends BillingTestCase
{
    private function verified(User $doctor): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(ClinicianCredentialService::class)->save($director, $doctor->id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'professional_name' => 'Synthetic MC Doctor', 'mmc_number' => (string) (100000 + $doctor->id), 'verified' => true]);
        $this->selectBranch($doctor);
    }

    private function command(array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'action' => 'issue', 'language' => 'en', 'start_date' => now()->setTimezone($this->branch->timezone)->toDateString(), 'end_date' => now()->setTimezone($this->branch->timezone)->toDateString(), ...$overrides];
    }

    private function act(User $doctor, Visit $visit, array $command): int
    {
        return app(MedicalCertificateService::class)->act($doctor, $visit, $command);
    }

    private function evidence(): array
    {
        $tables = ['visits', 'clinical_encounters', 'queue_entries'];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' AND (tablename LIKE '%invoice%' OR tablename LIKE '%payment%' OR tablename LIKE '%claim%' OR tablename LIKE '%responsib%' OR tablename LIKE '%receiv%' OR tablename LIKE '%adjustment%' OR tablename LIKE '%checkout%') ORDER BY tablename") as $row) {
            $tables[] = $row->tablename;
        }
        $result = [];
        foreach (array_unique($tables) as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_issue_replace_void_are_append_only_and_leave_every_source_and_financial_fact_identical(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $before = $this->evidence();
        $command = $this->command();
        $first = $this->act($doctor, $visit, $command);
        $this->assertSame($before, $this->evidence());
        $this->assertSame($first, $this->act($doctor, $visit, $command));
        $this->assertSame(1, DB::table('mc_number_counters')->sole()->last_number);
        $raw = DB::table('medical_certificates')->find($first);
        $this->assertMatchesRegularExpression('~^MC/KP/CHR/[0-9]{4}/000001$~', $raw->number);
        $this->assertStringNotContainsString('Synthetic MC Doctor', $raw->encrypted_snapshot);
        $snapshot = json_decode(Crypt::decryptString($raw->encrypted_snapshot), true);
        $this->assertSame('pre-signature', $snapshot['evidence']);
        $this->assertSame(1, $snapshot['templateRevision']);
        $this->assertFalse($snapshot['productionApproved']);
        $this->assertNull($snapshot['reason']);
        $this->assertStringNotContainsString('Synthetic diagnosis', $snapshot['html']);
        $this->assertStringContainsString('Wet signature', $snapshot['html']);
        $replacement = $this->act($doctor, $visit, $this->command(['action' => 'replace', 'document_id' => $first, 'lock_version' => 1, 'change_reason' => 'dates_corrected', 'language' => 'bm', 'end_date' => now()->addDay()->setTimezone($this->branch->timezone)->toDateString()]));
        $this->assertSame($before, $this->evidence());
        $this->assertSame($raw->encrypted_snapshot, DB::table('medical_certificates')->find($first)->encrypted_snapshot);
        $this->act($doctor, $visit, $this->command(['action' => 'void', 'document_id' => $replacement, 'lock_version' => 2, 'change_reason' => 'entered_in_error']));
        $this->assertSame($before, $this->evidence());
        $history = app(MedicalCertificateDirectory::class)->history($doctor, $visit);
        $this->assertSame(['void', 'replaced'], array_column($history['documents'], 'status'));
        $this->assertSame(3, $history['lockVersion']);
        $this->assertFalse($history['canFirstIssue']);
        $this->assertFalse($history['documents'][0]['canCorrect']);
        $this->assertSame($this->branch->timezone, $history['branchTimezone']);
        $this->assertStringEndsWith('+00:00', $history['documents'][0]['issuedAt']);
        $this->assertSame(2, DB::table('mc_number_counters')->sole()->last_number);
        foreach (AuditLog::query()->where('event', 'like', 'medical_certificate.%')->get() as $audit) {
            $this->assertEqualsCanonicalizing(['document_id', 'replaces_id', 'control_version', 'fields'], array_keys($audit->metadata));
            $json = json_encode($audit->metadata);
            $this->assertStringNotContainsString('Synthetic', $json);
            $this->assertStringNotContainsString($raw->number, $json);
        }
        $this->assertSame(1, $encounter->refresh()->lock_version);
    }

    public function test_exact_role_matrix_and_print_access_are_permission_based_and_branch_scoped(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $id = $this->act($doctor, $visit, $this->command());
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $role === 'resident_doctor' ? $doctor : $this->actor($role);
            $this->selectBranch($actor);
            $allowed = in_array($role, ['resident_doctor', 'ca', 'ca_supervisor'], true);
            $this->get("/visits/{$visit->visit_number}/medical-certificates")->assertStatus($allowed ? 200 : 403);
            $response = $this->get("/visits/{$visit->visit_number}/medical-certificates/{$id}?print=1");
            $response->assertStatus($allowed ? 200 : 403);
            $this->assertSame($role === 'resident_doctor', $actor->can('medical_certificates.issue.own'));
            if ($allowed) {
                $response->assertSee('DRAFT WORDING')->assertSee('COPY')->assertSee('Pre-signature');
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
        }
        $other = $this->actor('resident_doctor');
        $this->selectBranch($other);
        $this->get("/visits/{$visit->visit_number}/medical-certificates")->assertNotFound();
        $this->get("/visits/{$visit->visit_number}/medical-certificates/{$id}")->assertNotFound();
        // Direct permission remains authoritative; a role name never grants issuance by itself.
        $ca = $this->actor('ca');
        $ca->revokePermissionTo('medical_certificates.issue.own');
        $this->selectBranch($ca);
        $this->post("/visits/{$visit->visit_number}/medical-certificates", $this->command())->assertForbidden();
    }

    public function test_missing_credentials_hold_and_checkout_block_first_issue_without_allocating_a_number(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        try {
            $this->act($doctor, $visit, $this->command());
            $this->fail('Missing credentials accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('certificate', $e->errors());
        }
        $this->assertSame(0, DB::table('mc_controls')->count());
        $this->verified($doctor);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        try {
            $this->act($doctor, $visit, $this->command());
            $this->fail('Held issue accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('certificate', $e->errors());
        }
        $this->assertSame(0, DB::table('medical_certificates')->count());
        $this->assertSame(0, DB::table('mc_number_counters')->count());
    }

    public function test_payload_conflicts_stale_versions_duration_and_reason_consent_are_refused(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $command = $this->command();
        $id = $this->act($doctor, $visit, $command);
        foreach ([
            [...$command, 'language' => 'bm'],
            $this->command(['action' => 'replace', 'document_id' => $id, 'lock_version' => 0, 'change_reason' => 'details_corrected']),
            $this->command(['action' => 'replace', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'dates_corrected', 'end_date' => now()->addDays(30)->toDateString()]),
            $this->command(['reason' => 'Synthetic sensitive reason', 'reason_consent' => false]),
            $this->command(['reason' => '<script>alert(1)</script>', 'reason_consent' => true]),
        ] as $invalid) {
            try {
                $this->act($doctor, $visit, $invalid);
                $this->fail('Invalid MC accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(1, DB::table('medical_certificates')->count());
        $this->assertSame(1, DB::table('mc_number_counters')->sole()->last_number);
    }

    public function test_snapshot_is_independent_of_live_patient_credentials_letterhead_and_template_renderer(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $id = $this->act($doctor, $visit, $this->command(['reason' => 'Synthetic consented reason', 'reason_consent' => true]));
        $directory = app(MedicalCertificateDirectory::class);
        $original = $directory->snapshot($doctor, $visit, $id);
        $this->assertTrue($original['snapshot']['reasonConsent']);
        $visit->patient->forceFill(['full_name' => 'Synthetic Later Patient Name', 'lock_version' => $visit->patient->lock_version + 1])->save();
        $doctor->forceFill(['name' => 'Synthetic Later Account Name'])->save();
        $this->branch->forceFill(['name' => 'Synthetic Later Branch'])->save();
        $this->assertSame($original, $directory->snapshot($doctor, $visit, $id));
        $response = $this->get("/visits/{$visit->visit_number}/medical-certificates/{$id}?print=1");
        $response->assertOk()->assertSee('Synthetic consented reason')->assertDontSee('Synthetic Later Patient Name')->assertDontSee('Synthetic Later Account Name')->assertDontSee('Synthetic Later Branch');
        $this->assertStringContainsString('background:#fff!important', $response->getContent());
        $this->assertStringContainsString("script-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame(1, AuditLog::query()->where('event', 'medical_certificate.print_view_opened')->count());
        foreach (AuditLog::query()->where('event', 'like', 'medical_certificate.%')->get() as $audit) {
            $this->assertStringNotContainsString('Synthetic consented reason', json_encode($audit->metadata));
        }
    }

    public function test_postgresql_refuses_document_action_mutations_and_counter_reuse(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $this->act($doctor, $visit, $this->command());
        foreach (["UPDATE medical_certificates SET encrypted_snapshot='changed'", 'DELETE FROM medical_certificates', 'TRUNCATE medical_certificates CASCADE', "UPDATE mc_actions SET action='void'", 'DELETE FROM mc_actions', 'TRUNCATE mc_actions', 'UPDATE mc_number_counters SET last_number=1', 'DELETE FROM mc_controls'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Evidence mutation accepted: '.$sql);
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_audit_failure_rolls_back_snapshot_command_counter_and_control(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        try {
            $this->act($doctor, $visit, $this->command());
            $this->fail('Audit failure accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        foreach (['medical_certificates', 'mc_actions', 'mc_number_counters', 'mc_controls'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_first_issue_is_blocked_after_checkout_but_original_issuer_can_replace_and_void_after_payment(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $id = $this->act($doctor, $visit, $this->command());
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        // Real finalised, paid billing evidence on another visit also remains byte-for-byte intact.
        [, $payCa, $paidVisit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($payCa);
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        app(PaymentService::class)->add($payCa, $paidVisit, $invoice, ['expected_branch_id' => $paidVisit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($ca);
        $builder = app(BillingBuilderService::class);
        $mcInvoice = $builder->build($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $mcInvoice = $builder->finalize($ca, $visit, $mcInvoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $mcInvoice->lock_version]);
        app(PaymentService::class)->add($ca, $visit, $mcInvoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $mcInvoice->lock_version, 'amount_sen' => 4000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $before = $this->evidence();
        $this->selectBranch($doctor);
        $replacement = $this->act($doctor, $visit, $this->command(['action' => 'replace', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'details_corrected']));
        $this->assertSame($before, $this->evidence());
        $this->act($doctor, $visit, $this->command(['action' => 'void', 'document_id' => $replacement, 'lock_version' => 2, 'change_reason' => 'entered_in_error']));
        $this->assertSame($before, $this->evidence());
        $checkout = ConsultationCheckout::query()->where('visit_id', $paidVisit->id)->sole();
        $this->selectBranch($paidVisit->assignedDoctor);
        try {
            $this->act($paidVisit->assignedDoctor, $paidVisit, $this->command());
            $this->fail('First issue after checkout accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('certificate', $e->errors());
        }
    }

    public function test_branch_switch_denies_reads_and_fresh_revoked_permission_denies_writes(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $id = $this->act($doctor, $visit, $this->command());
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->firstOrFail();
        $ca = $this->actor('ca', $otherBranch);
        $this->selectBranch($ca, $otherBranch);
        $this->get("/visits/{$visit->visit_number}/medical-certificates")->assertNotFound();
        $this->get("/visits/{$visit->visit_number}/medical-certificates/{$id}?print=1")->assertNotFound();
        $this->selectBranch($doctor);
        $this->assertTrue($doctor->can('medical_certificates.issue.own'));
        $role = Role::query()->where('name', 'resident_doctor')->sole();
        $permission = Permission::query()->where('name', 'medical_certificates.issue.own')->sole();
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
        try {
            $this->act($doctor, $visit, $this->command(['action' => 'void', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'entered_in_error']));
            $this->fail('Stale grant accepted');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertSame(1, DB::table('mc_actions')->count());
        }
    }

    public function test_branch_local_year_numbering_and_bilingual_inclusive_days_are_deterministic(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 16:10:00', 'UTC'));
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $id = $this->act($doctor, $visit, $this->command(['language' => 'bm', 'end_date' => '2027-01-02']));
        $doc = DB::table('medical_certificates')->find($id);
        $this->assertSame('MC/KP/CHR/2027/000001', $doc->number);
        $snapshot = json_decode(Crypt::decryptString($doc->encrypted_snapshot), true);
        $this->assertSame('2', $snapshot['fields']['days_number']);
        $this->assertSame('dua', $snapshot['fields']['days_words']);
        $this->assertSame('01/01/2027', $snapshot['fields']['issue_date']);
        $this->assertSame('2026-12-31 16:10:00.000000+00:00', $snapshot['issuedAtUtc']);
        $history = app(MedicalCertificateDirectory::class)->history($doctor, $visit);
        $this->assertSame('Asia/Kuala_Lumpur', $history['branchTimezone']);
        $this->assertSame('2026-12-31T16:10:00+00:00', $history['documents'][0]['issuedAt']);
    }

    public function test_logo_bytes_are_retained_privately_even_after_current_logo_is_removed(): void
    {
        Storage::fake('local');
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(BusinessDetailsService::class)->save($director, [
            'expected_branch_id' => $this->branch->id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0,
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic MC Group', 'clinic_name' => 'Synthetic MC Clinic',
            'address_line_1' => '42 Synthetic Street', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'country' => 'MY', 'email' => 'clinic@mc.test',
        ], UploadedFile::fake()->image('synthetic-logo.png', 160, 90));
        $asset = BusinessDetailAsset::query()->sole();
        $this->selectBranch($doctor);
        $id = $this->act($doctor, $visit, $this->command());
        $before = app(MedicalCertificateDirectory::class)->snapshot($doctor, $visit, $id);
        $this->assertStringStartsWith('data:image/png;base64,', $before['snapshot']['letterhead']['logo']['url']);
        $this->assertStringNotContainsString($asset->path, json_encode($before));
        Storage::disk('local')->delete($asset->path);
        $this->assertSame($before, app(MedicalCertificateDirectory::class)->snapshot($doctor, $visit, $id));
        $this->assertStringNotContainsString('data:image/png', DB::table('medical_certificates')->find($id)->encrypted_snapshot);
    }

    public function test_first_issue_after_reopened_checkout_is_still_blocked_and_migration_grants_are_additive(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $checkout = app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => $checkout->lock_version]);
        try {
            $this->act($doctor, $visit, $this->command());
            $this->fail('First issue after reopened checkout accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('certificate', $e->errors());
        }
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_19_001300_grant_medical_certificate_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_fresh_branch_activation_overrides_a_cached_branch_inside_the_same_request(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->verified($doctor);
        $id = $this->act($doctor, $visit, $this->command());
        $this->assertTrue(app(BranchAccessService::class)->activeBranch($doctor)->is_active);
        DB::table('branches')->where('id', $this->branch->id)->update(['is_active' => false]);
        try {
            $this->act($doctor, $visit, $this->command(['action' => 'void', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'entered_in_error']));
            $this->fail('Cached active branch accepted');
        } catch (ModelNotFoundException|HttpException $e) {
            $this->assertSame(1, DB::table('mc_actions')->count());
        }
    }
}
