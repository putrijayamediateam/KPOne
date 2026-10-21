<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Documents\AttendanceService;
use App\Domain\Clinical\Documents\BranchDocumentNumberAllocator;
use App\Domain\Clinical\Documents\MedicalCertificateService;
use App\Domain\Clinical\Documents\ReferralService;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Identity\Services\ClinicianCredentialService;
use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class AttendanceTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    public function ready(bool $logo = false): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(ClinicianCredentialService::class)->save($director, $doctor->id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'professional_name' => 'Synthetic Referral Doctor', 'mmc_number' => (string) (100000 + $doctor->id), 'verified' => true]);
        app(BusinessDetailsService::class)->save($director, ['expected_branch_id' => $this->branch->id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Referral Clinic', 'address_line_1' => '42 Synthetic Street', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'country' => 'MY', 'email' => 'clinic@attendance.test'], $logo ? UploadedFile::fake()->image('synthetic.png', 100, 60) : null);
        $this->selectBranch($doctor);

        return [$doctor, $ca, $visit, $queue, $encounter];
    }

    public function command($doctor, $visit, array $overrides = []): array
    {
        $source = app(AttendanceService::class)->source($doctor, $visit);

        return [...['expected_branch_id' => $visit->branch_id, 'action' => 'issue', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'source_token' => $source['token'], 'language' => 'en', 'recipient' => 'Synthetic School', 'consent' => true], ...$overrides];
    }

    public function reviewed($doctor, $visit, array $command): array
    {
        $preview = app(AttendanceService::class)->preview($doctor, $visit, $command);

        return [...$command, 'review_token' => $preview['reviewToken']];
    }

    private function denied(callable $call, string $field): void
    {
        try {
            $call();
            $this->fail('Unsafe referral action accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function evidence(): array
    {
        $result = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
            if (str_starts_with($row->tablename, 'attendance_') || $row->tablename === 'audit_logs') {
                continue;
            }
            $columns = DB::getSchemaBuilder()->getColumnListing($row->tablename);
            $query = DB::table($row->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $result[$row->tablename] = $query->get()->toJson();
        }

        return $result;
    }

    public function test_exact_grants_every_catalogue_role_routes_and_fresh_revocation(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(AttendanceService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = $service->act($doctor, $visit, $command);
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            foreach (['issue.own', 'view.own', 'print.own', 'view.branch', 'print.branch'] as $ability) {
                $expected = str_ends_with($ability, '.own') ? $role === 'resident_doctor' : in_array($role, ['ca', 'ca_supervisor'], true);
                $this->assertSame($expected, in_array('attendance_documents.'.$ability, $permissions, true), $role.' '.$ability);
            }
            $actor = $role === 'resident_doctor' ? $doctor : $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('attendance.index', $visit))->assertStatus($role === 'resident_doctor' ? 200 : 403);
            $this->get(route('attendance.source', $visit))->assertStatus($role === 'resident_doctor' ? 200 : 403);
            $this->get(route('attendance.show', [$visit, $id]))->assertStatus(in_array($role, ['resident_doctor', 'ca', 'ca_supervisor'], true) ? 200 : 403);
            foreach (['issue', 'replace', 'void'] as $action) {
                if ($role !== 'resident_doctor') {
                    $this->postJson(route('attendance.store', $visit), [...$command, 'action' => $action])->assertForbidden();
                }
            }
            if (in_array($role, ['ca', 'ca_supervisor'], true)) {
                $copies = $service->currentCopies($actor, $visit);
                $this->assertSame([['id' => $id, 'number' => 'ATT/KP/CHR/'.now()->setTimezone($this->branch->timezone)->year.'/000001']], $copies);
                $this->get(route('visits.show', $visit))->assertOk()->assertInertia(fn ($page) => $page->has('attendanceCopies', 1));
            }
        }
        foreach (['resident_doctor' => ['issue.own', 'view.own', 'print.own'], 'ca' => ['view.branch', 'print.branch']] as $roleName => $abilities) {
            $actor = $roleName === 'resident_doctor' ? $doctor : $this->actor($roleName);
            $this->selectBranch($actor);
            $role = Role::findByName($roleName);
            foreach ($abilities as $ability) {
                $permission = $role->permissions()->where('name', 'attendance_documents.'.$ability)->sole();
                DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
                if ($ability === 'issue.own') {
                    $this->postJson(route('attendance.store', $visit), $command)->assertForbidden();
                } else {
                    $this->get(route('attendance.show', [$visit, $id]))->assertForbidden();
                }
            }
        }
        $grants = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('name', 'not like', 'attendance_documents.%')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_23_000200_grant_attendance_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($grants, DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('name', 'not like', 'attendance_documents.%')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->selectBranch($doctor);
        $this->get(route('attendance.show', [$visit, $id]))->assertOk();
    }

    public function test_issue_replace_void_multiple_chains_retry_and_all_source_rows_are_identical(): void
    {
        [$doctor, , $visit] = $this->ready();
        // Populate independent financial, claims, stock, dispensing and MC evidence before comparisons.
        [, $ca, $paidVisit, $invoice] = $this->finalizedFixture();
        $method = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYN-CASH', 'name' => 'Synthetic cash', 'is_active' => true]);
        $method->save();
        app(PaymentService::class)->add($ca, $paidVisit, $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => $method->code, 'idempotency_key' => (string) Str::uuid()]);
        $finance = $this->actor('finance_officer');
        $officer = $this->actor('panel_officer');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 2000]);
        $this->selectBranch($finance);
        $allocation = app(ResponsibilityService::class)->propose($finance, $paidVisit, $invoice->refresh(), 'panel', ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 2000, 'panel_id' => $panel->id, 'reason' => 'Synthetic private responsibility']);
        $this->selectBranch($officer);
        app(ResponsibilityService::class)->approve($officer, $paidVisit, $invoice->refresh(), 'panel', $allocation->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $allocation->lock_version]);
        app(PanelClaimService::class)->prepare($officer, $allocation->refresh(), ['expected_branch_id' => $this->branch->id]);
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->selectBranch($doctor);
        app(MedicalCertificateService::class)->act($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'action' => 'issue', 'language' => 'en', 'start_date' => now()->setTimezone($this->branch->timezone)->toDateString(), 'end_date' => now()->setTimezone($this->branch->timezone)->toDateString()]);
        $ref = app(ReferralService::class);
        $source = $ref->source($doctor, $visit);
        $input = ['expected_branch_id' => $this->branch->id, 'action' => 'issue', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'source_token' => $source['token'], 'language' => 'en', 'institution' => 'Synthetic Referral Hospital', 'reason' => 'Synthetic referral question', 'urgency' => 'routine', 'diagnoses' => [], 'medicines' => [], 'allergies' => [], 'include_allergies' => false, 'consent' => true];
        $ref->act($doctor, $visit, [...$input, 'review_token' => $ref->preview($doctor, $visit, $input)['reviewToken']]);
        $before = $this->evidence();
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $service = app(AttendanceService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->assertSame($before, $this->evidence());
        $id = $service->act($doctor, $visit, $command);
        $this->assertSame($before, $this->evidence());
        $this->assertSame($id, $service->act($doctor, $visit, $command));
        $this->assertSame($before, $this->evidence());
        $this->denied(fn () => $service->act($doctor, $visit, [...$command, 'recipient' => 'Different synthetic recipient']), 'idempotency_key');
        $original = DB::table('attendance_documents')->find($id);
        $replace = $this->reviewed($doctor, $visit, $this->command($doctor, $visit, ['action' => 'replace', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'details_corrected', 'language' => 'bm']));
        $second = $service->act($doctor, $visit, $replace);
        $this->assertSame($before, $this->evidence());
        $history = $service->history($doctor, $visit, 1);
        $this->assertStringEndsWith('+00:00', $history['documents'][0]['issuedAt']);
        $this->assertSame($before, $this->evidence());
        $doc = $history['documents'][0];
        $void = ['expected_branch_id' => $visit->branch_id, 'action' => 'void', 'document_id' => $second, 'lock_version' => 2, 'source_token' => $doc['voidToken'], 'idempotency_key' => (string) Str::uuid(), 'change_reason' => 'patient_request'];
        $service->act($doctor, $visit, $void);
        $this->assertSame($before, $this->evidence());
        $this->assertSame($second, $service->act($doctor, $visit, $void));
        $this->denied(fn () => $service->act($doctor, $visit, [...$void, 'idempotency_key' => (string) Str::uuid()]), 'lock_version');
        $third = $service->act($doctor, $visit, $this->reviewed($doctor, $visit, $this->command($doctor, $visit)));
        $this->assertSame($before, $this->evidence());
        $this->assertNotSame($original->chain_id, DB::table('attendance_documents')->find($third)->chain_id);
        $this->assertSame(3, DB::table('attendance_number_counters')->sole()->last_number);
        $this->assertSame($original->encrypted_snapshot, DB::table('attendance_documents')->find($id)->encrypted_snapshot);
        foreach ([$id => 'REPLACED', $second => 'VOID', $third => 'CURRENT'] as $documentId => $status) {
            $copy = $service->copy($doctor, $visit, $documentId);
            $this->assertSame($status, $copy['status']);
            $this->assertSame($before, $this->evidence());
        }
        $caReader = $ca;
        $this->selectBranch($caReader);
        $this->assertCount(1, $service->currentCopies($caReader, $visit));
        $this->assertSame($before, $this->evidence());
        $service->copy($caReader, $visit, $third);
        $this->assertSame($before, $this->evidence());
        $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
        foreach (DB::table('audit_logs')->where('event', 'like', 'attendance.%')->get() as $audit) {
            $metadata = json_decode($audit->metadata, true);
            $this->assertEmpty(array_diff(array_keys($metadata), ['document_id', 'chain_id', 'replaces_id', 'version', 'fields']));
            foreach (['Synthetic', 'Hospital', 'referral question', 'ATT/KP'] as $private) {
                $this->assertStringNotContainsString($private, $audit->metadata);
            }
        }
    }

    public function test_letterhead_required_fields_missing_credentials_and_configured_logo_fail_closed(): void
    {
        Storage::fake('local');
        [$doctor, , $visit] = $this->ready(true);
        $service = app(AttendanceService::class);
        $asset = BusinessDetailAsset::query()->sole();
        $bytes = Storage::disk('local')->get($asset->path);
        Storage::disk('local')->put($asset->path, 'corrupt');
        $this->denied(fn () => $service->source($doctor, $visit), 'letterhead');
        Storage::disk('local')->delete($asset->path);
        $this->denied(fn () => $service->source($doctor, $visit), 'letterhead');
        Storage::disk('local')->put($asset->path, $bytes);
        foreach (['clinic_name', 'address_line_1', 'postcode', 'city', 'state', 'country', 'email'] as $field) {
            $profile = BranchBusinessProfile::query()->sole();
            $old = $profile->$field;
            DB::table('branch_business_profiles')->where('id', $profile->id)->update([$field => '']);
            $this->denied(fn () => $service->source($doctor, $visit), 'letterhead');
            DB::table('branch_business_profiles')->where('id', $profile->id)->update([$field => $old]);
        }
        $credential = ClinicianProfessionalProfile::query()->sole();
        $credential->forceFill(['verified' => false, 'verified_at' => null, 'verified_by_user_id' => null, 'lock_version' => $credential->lock_version + 1])->save();
        $this->denied(fn () => $service->source($doctor, $visit), 'credentials');
        $this->assertSame(0, DB::table('attendance_documents')->count());
    }

    public function test_audit_failure_rolls_back_all_attendance_writes_and_refuses_source_preview_history_copy(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(AttendanceService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $before = $this->evidence();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnNull();
        foreach ([fn () => $service->act($doctor, $visit, $command), fn () => $service->source($doctor, $visit), fn () => $service->history($doctor, $visit, 1), fn () => $service->preview($doctor, $visit, $command)] as $call) {
            try {
                $call();
                $this->fail('Unaudited access accepted');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
        }
        foreach (['attendance_chains', 'attendance_documents', 'attendance_actions', 'attendance_number_counters'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame($before, $this->evidence());
        $this->app->forgetInstance(AuditRecorder::class);
        $id = $service->act($doctor, $visit, $command);
        $doc = $service->history($doctor, $visit, 1)['documents'][0];
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnNull();
        foreach ([fn () => $service->copy($doctor, $visit, $id), fn () => $service->act($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'action' => 'void', 'document_id' => $id, 'lock_version' => 1, 'source_token' => $doc['voidToken'], 'idempotency_key' => (string) Str::uuid(), 'change_reason' => 'entered_in_error'])] as $call) {
            try {
                $call();
                $this->fail('Unaudited action accepted');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
        }
        $this->assertSame(1, DB::table('attendance_actions')->count());
        $this->assertSame(1, DB::table('attendance_chains')->sole()->lock_version);
    }

    public function test_branch_organisation_assignment_isolation_and_local_new_year_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 15:50:00', 'UTC'));
        [$doctor, , $visit] = $this->ready();
        $this->travelTo(CarbonImmutable::parse('2026-12-31 16:10:00', 'UTC'));
        $service = app(AttendanceService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = $service->act($doctor, $visit, $command);
        $snapshot = $service->copy($doctor, $visit, $id)['snapshot'];
        $this->assertSame('ATT/KP/CHR/2027/000001', $snapshot['number']);
        $this->assertSame('01/01/2027', $snapshot['issueDate']);
        $this->assertSame('31/12/2026', $snapshot['examDate']);
        $this->assertSame('2026-12-31 16:10:00.000000+00:00', $snapshot['issuedAtUtc']);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $other);
        $this->selectBranch($doctor, $other);
        $this->get(route('attendance.show', [$visit, $id]))->assertNotFound();
        $this->postJson(route('attendance.store', $visit), $command)->assertNotFound();
        $foreign = Organisation::query()->create(['code' => 'REF_FOREIGN', 'name' => 'Synthetic Foreign Organisation']);
        $foreignBranch = (new Branch)->forceFill(['organisation_id' => $foreign->id, 'code' => 'REF_FOREIGN', 'name' => 'Synthetic Foreign Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreignBranch->save();
        $foreignDoctor = $this->doctor($foreignBranch);
        $this->selectBranch($foreignDoctor, $foreignBranch);
        $this->get(route('attendance.show', [$visit, $id]))->assertNotFound();
        $this->selectBranch($doctor);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $doctor->staffProfile->id)->update(['valid_until' => '2026-12-30']);
        $this->get(route('attendance.index', $visit))->assertNotFound();
    }

    public function test_fresh_organisation_deactivation_refuses_all_document_access_without_source_changes(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(AttendanceService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = $service->act($doctor, $visit, $command);
        DB::table('organisations')->where('id', $this->organisation->id)->update(['is_active' => false]);
        $before = $this->evidence();
        foreach ([fn () => $service->source($doctor, $visit), fn () => $service->history($doctor, $visit, 1), fn () => $service->copy($doctor, $visit, $id), fn () => $service->act($doctor, $visit, $command)] as $call) {
            try {
                $call();
                $this->fail('Inactive organisation accepted');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_serving_hold_checkout_reopen_and_after_checkout_original_issuer_corrections(): void
    {
        [$doctor, , $visit, $queue, $encounter] = $this->ready();
        $service = app(AttendanceService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = $service->act($doctor, $visit, $command);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $held = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->denied(fn () => $service->act($doctor, $visit, $held), 'attendance');
        app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        app(CompleteConsultationService::class)->complete($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->denied(fn () => $service->act($doctor, $visit, $command), 'attendance');
        $replacement = $this->reviewed($doctor, $visit, $this->command($doctor, $visit, ['action' => 'replace', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'details_corrected']));
        $service->act($doctor, $visit, $replacement);
        $checkout = ConsultationCheckout::query()->where('visit_id', $visit->id)->sole();
        app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => $checkout->lock_version]);
        $reopened = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->denied(fn () => $service->act($doctor, $visit, $reopened), 'attendance');
        $this->assertFalse($service->history($doctor, $visit, 1)['canFirstIssue']);
    }

    public function test_recipient_consent_bounds_stale_source_review_and_no_clinical_or_unsaved_content(): void
    {
        [$doctor, , $visit, , $encounter] = $this->ready();
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        $plan = TreatmentPlan::factory()->create(['clinical_encounter_id' => $encounter->id]);
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->organisation->id]);
        $order = TreatmentPlanMedicineOrder::factory()->create(['treatment_plan_id' => $plan->id, 'medicine_catalogue_item_id' => $medicine->id]);
        $allergy = PatientAllergyProfile::factory()->create(['organisation_id' => $this->organisation->id, 'patient_id' => $visit->patient_id, 'updated_by_user_id' => $doctor->id]);
        $record = PatientAllergyRecord::factory()->create(['patient_allergy_profile_id' => $allergy->id]);
        $service = app(AttendanceService::class);
        $input = $this->command($doctor, $visit);
        foreach ([['consent' => false, 'field' => 'consent'], ['recipient' => '0', 'consent' => false, 'field' => 'consent'], ['recipient' => ['not a string'], 'field' => 'recipient'], ['recipient' => str_repeat('x', 201), 'field' => 'recipient'], ['recipient' => "Hidden\u{202E}", 'field' => 'recipient'], ['recipient' => "line\nsecond", 'field' => 'recipient'], ['language' => 'xx', 'field' => 'language']] as $invalid) {
            $field = $invalid['field'];
            unset($invalid['field']);
            $this->denied(fn () => $service->preview($doctor, $visit, [...$input, ...$invalid]), $field);
        }
        $this->denied(fn () => $service->act($doctor, $visit, $input), 'review_token');
        $reviewed = $this->reviewed($doctor, $visit, $input);
        $this->denied(fn () => $service->act($doctor, $visit, [...$reviewed, 'recipient' => 'Changed school']), 'review_token');
        DB::table('clinical_encounters')->where('id', $encounter->id)->update(['lock_version' => DB::raw('lock_version + 1')]);
        $this->denied(fn () => $service->act($doctor, $visit, $reviewed), 'source_token');
        $input = $this->command($doctor, $visit);
        $source = $service->source($doctor, $visit);
        $this->assertSame(['name', 'number'], array_keys($source['patient']));
        foreach (['diagnoses', 'medicines', 'allergies', 'dateOfBirth', 'clinical_note'] as $key) {
            $this->assertArrayNotHasKey($key, $source);
        }
        $snapshot = $service->preview($doctor, $visit, [...$input, 'clinical_note' => 'UNSAVED CLINICAL VALUE', 'patient_name' => 'FORGED NAME', 'diagnoses' => [$encounter->diagnoses()->sole()->id]])['snapshot'];
        foreach (['UNSAVED CLINICAL VALUE', 'FORGED NAME', 'Synthetic clinical note', 'Synthetic diagnosis alpha', $order->medicine_name_snapshot, $record->allergen_text, 'DOB', 'Tarikh lahir'] as $secret) {
            $this->assertStringNotContainsString($secret, $snapshot['html']);
        }
        foreach (['diagnoses', 'medicines', 'allergies', 'selected', 'dateOfBirth', 'identification'] as $key) {
            $this->assertArrayNotHasKey($key, $snapshot);
        }
        $this->assertSame(['name', 'number'], array_keys($snapshot['patient']));
        $preview = $service->preview($doctor, $visit, $this->command($doctor, $visit, ['recipient' => '', 'consent' => false]));
        $this->assertFalse($preview['snapshot']['consent']);
        $this->assertSame('', $preview['snapshot']['recipient']);
    }

    public function test_snapshot_is_stable_immutable_draft_escaped_date_only_and_one_page_in_both_languages(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(AttendanceService::class);
        foreach (['en', 'bm'] as $language) {
            $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit, ['language' => $language, 'recipient' => '<script>Synthetic School & test</script>']));
            $id = $service->act($doctor, $visit, $command);
            $snapshot = $service->copy($doctor, $visit, $id)['snapshot'];
            $this->assertCount(1, $snapshot['pages']);
            $this->assertSame(1, substr_count($snapshot['html'], 'class="ref-sign"'));
            $this->assertStringContainsString('Page / Halaman 1 / 1', $snapshot['html']);
            $this->assertGreaterThan(strrpos($snapshot['html'], 'class="ref-lines"'), strpos($snapshot['html'], 'class="ref-sign"'));
            $this->assertStringContainsString('DRAFT WORDING', $snapshot['html']);
            $this->assertStringContainsString($language === 'en' ? 'does not certify illness, fitness or leave' : 'bukan sijil sakit', preg_replace('/\s+/u', ' ', strip_tags($snapshot['html'])));
            $this->assertStringNotContainsString('<script>', $snapshot['html']);
            $this->assertStringContainsString('&lt;script&gt;', $snapshot['html']);
            $this->assertStringNotContainsString($snapshot['issuedAtUtc'], $snapshot['html']);
            $this->assertFalse($snapshot['productionApproved']);
            $this->assertSame('pre-signature', $snapshot['evidence']);
            $this->assertTrue($snapshot['consent']);
        }
        $original = $service->copy($doctor, $visit, $id);
        DB::table('patients')->where('id', $visit->patient_id)->update(['full_name' => 'Changed synthetic patient', 'lock_version' => DB::raw('lock_version + 1')]);
        DB::table('clinical_encounters')->where('visit_id', $visit->id)->update(['clinical_note' => 'Changed synthetic source', 'lock_version' => DB::raw('lock_version + 1')]);
        $profile = BranchBusinessProfile::query()->sole();
        $profile->forceFill(['clinic_name' => 'Changed synthetic clinic', 'lock_version' => $profile->lock_version + 1])->save();
        $credential = ClinicianProfessionalProfile::query()->sole();
        $credential->forceFill(['professional_name' => 'Changed synthetic doctor', 'lock_version' => $credential->lock_version + 1])->save();
        $this->assertSame($original, $service->copy($doctor, $visit, $id));
        foreach (['attendance_documents', 'attendance_actions', 'attendance_template_revisions'] as $table) {
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Evidence mutation accepted');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('Attendance evidence is immutable', $e->getMessage());
                }
            }
        }
        $other = $this->doctor();
        $this->selectBranch($other);
        $this->get(route('attendance.show', [$visit, $id]))->assertNotFound();
        foreach (['replace', 'void'] as $action) {
            $this->postJson(route('attendance.store', $visit), [...$command, 'action' => $action, 'document_id' => $id, 'change_reason' => 'entered_in_error'])->assertNotFound();
        }
    }

    public function test_ca_current_entry_is_branch_scoped_audited_and_disappears_when_chain_is_voided(): void
    {
        [$doctor, $ca, $visit] = $this->ready();
        $service = app(AttendanceService::class);
        $this->selectBranch($ca);
        $this->assertSame([], $service->currentCopies($ca, $visit));
        $this->selectBranch($doctor);
        $id = $service->act($doctor, $visit, $this->reviewed($doctor, $visit, $this->command($doctor, $visit)));
        $this->selectBranch($ca);
        $this->assertCount(1, $service->currentCopies($ca, $visit));
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($ca->staffProfile, $other);
        $this->selectBranch($ca, $other);
        $this->get(route('attendance.show', [$visit, $id]))->assertNotFound();
        $this->selectBranch($ca);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnNull();
        foreach ([fn () => $service->currentCopies($ca, $visit), fn () => $service->copy($ca, $visit, $id)] as $call) {
            try {
                $call();
                $this->fail('Unaudited CA document access released');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
        }
        $this->app->forgetInstance(AuditRecorder::class);
        $this->selectBranch($doctor);
        $doc = $service->history($doctor, $visit, 1)['documents'][0];
        $service->act($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'action' => 'void', 'document_id' => $id, 'lock_version' => 1, 'source_token' => $doc['voidToken'], 'idempotency_key' => (string) Str::uuid(), 'change_reason' => 'entered_in_error']);
        $this->selectBranch($ca);
        $this->assertSame([], $service->currentCopies($ca, $visit));
        $this->get(route('attendance.show', [$visit, $id]))->assertOk()->assertSee('COPY — VOID')->assertSee('NOT VALID');
        $this->assertDatabaseHas('audit_logs', ['event' => 'attendance.current_copies_viewed', 'actor_user_id' => $ca->id]);
    }

    public function test_stale_patient_credentials_letterhead_sources_are_refused_and_no_counter_is_consumed(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(AttendanceService::class);
        foreach ([
            fn () => DB::table('patients')->where('id', $visit->patient_id)->update(['full_name' => 'Synthetic Updated Patient', 'lock_version' => DB::raw('lock_version + 1')]),
            fn () => DB::table('clinician_professional_profiles')->where('user_id', $doctor->id)->update(['professional_name' => 'Synthetic Updated Professional', 'lock_version' => DB::raw('lock_version + 1')]),
            fn () => DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->update(['clinic_name' => 'Synthetic Updated Clinic', 'lock_version' => DB::raw('lock_version + 1')]),
        ] as $change) {
            $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
            $change();
            $this->denied(fn () => $service->act($doctor, $visit, $command), 'source_token');
        }
        $this->assertDatabaseCount('attendance_number_counters', 0);
        $this->assertDatabaseCount('attendance_documents', 0);
    }

    public function test_counter_allowlist_and_separate_referral_counter_are_preserved(): void
    {
        [$doctor, , $visit] = $this->ready();
        $allocator = app(BranchDocumentNumberAllocator::class);
        $at = now()->utc()->toImmutable();
        $ref = DB::transaction(fn () => $allocator->allocate($this->branch, 'referral', 'REF', $at));
        $before = DB::table('referral_number_counters')->orderBy('id')->get()->toJson();
        $service = app(AttendanceService::class);
        $id = $service->act($doctor, $visit, $this->reviewed($doctor, $visit, $this->command($doctor, $visit)));
        $this->assertStringEndsWith('/000001', $service->copy($doctor, $visit, $id)['snapshot']['number']);
        $this->assertStringStartsWith('REF/KP/CHR/', $ref);
        $this->assertSame($before, DB::table('referral_number_counters')->orderBy('id')->get()->toJson());
        foreach ([['attendance', 'REF'], ['referral', 'ATT'], ['prescription', 'RX']] as [$type, $prefix]) {
            try {
                $allocator->allocate($this->branch, $type, $prefix, $at);
                $this->fail('Unsupported document allocation accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Unsupported document counter.', $e->getMessage());
            }
        }
        foreach (['attendance_chains' => 'lock_version', 'attendance_number_counters' => 'last_number'] as $table => $field) {
            foreach (["UPDATE {$table} SET {$field}=0", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Retained control mutation accepted');
                } catch (QueryException $e) {
                    $this->assertStringContainsString(str_starts_with($sql, 'UPDATE') ? 'Invalid attendance' : (str_starts_with($sql, 'DELETE') ? 'Attendance metadata is retained' : 'Attendance evidence is immutable'), $e->getMessage());
                }
            }
        }
    }
}
