<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
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

class ReferralTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    public function ready(bool $logo = false): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(ClinicianCredentialService::class)->save($director, $doctor->id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'professional_name' => 'Synthetic Referral Doctor', 'mmc_number' => (string) (100000 + $doctor->id), 'verified' => true]);
        app(BusinessDetailsService::class)->save($director, ['expected_branch_id' => $this->branch->id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Referral Clinic', 'address_line_1' => '42 Synthetic Street', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'country' => 'MY', 'email' => 'clinic@referral.test'], $logo ? UploadedFile::fake()->image('synthetic.png', 100, 60) : null);
        $this->selectBranch($doctor);

        return [$doctor, $ca, $visit, $queue, $encounter];
    }

    public function command($doctor, $visit, array $overrides = []): array
    {
        $source = app(ReferralService::class)->source($doctor, $visit);

        return [...['expected_branch_id' => $visit->branch_id, 'action' => 'issue', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'source_token' => $source['token'], 'language' => 'en', 'institution' => 'Synthetic Hospital', 'department' => '', 'clinician' => '', 'reason' => 'Synthetic referral question', 'urgency' => 'routine', 'history' => '', 'findings' => '', 'management' => '', 'diagnoses' => [], 'medicines' => [], 'allergies' => [], 'include_allergies' => false, 'consent' => true], ...$overrides];
    }

    public function reviewed($doctor, $visit, array $command): array
    {
        $preview = app(ReferralService::class)->preview($doctor, $visit, $command);

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
            if (str_starts_with($row->tablename, 'referral_') || $row->tablename === 'audit_logs') {
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

    public function test_all_catalogue_roles_and_routes_are_exactly_own_doctor_only_and_freshly_revocable(): void
    {
        [$doctor, , $visit] = $this->ready();
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = app(ReferralService::class)->act($doctor, $visit, $command);
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            foreach (['issue', 'view', 'print'] as $ability) {
                $this->assertSame($role === 'resident_doctor', in_array("referrals.{$ability}.own", $permissions, true));
            }
            $actor = $role === 'resident_doctor' ? $doctor : $this->actor($role);
            $this->selectBranch($actor);
            foreach (['referrals.index', 'referrals.source', 'referrals.show'] as $route) {
                $response = $this->get(route($route, $route === 'referrals.show' ? [$visit, $id] : $visit));
                $role === 'resident_doctor' ? $response->assertOk() : $response->assertForbidden();
            }
            if ($role !== 'resident_doctor') {
                $this->postJson(route('referrals.store', $visit), $command)->assertForbidden();
                $this->postJson(route('referrals.preview', $visit), $command)->assertForbidden();
            }
        }
        $otherDoctor = $this->doctor();
        $this->selectBranch($otherDoctor);
        $this->get(route('referrals.index', $visit))->assertNotFound();
        $this->postJson(route('referrals.store', $visit), [...$command, 'action' => 'replace', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'details_corrected'])->assertNotFound();
        $this->postJson(route('referrals.store', $visit), ['expected_branch_id' => $visit->branch_id, 'action' => 'void', 'document_id' => $id, 'lock_version' => 1, 'source_token' => str_repeat('a', 64), 'idempotency_key' => (string) Str::uuid(), 'change_reason' => 'entered_in_error'])->assertNotFound();
        $this->selectBranch($doctor);
        $role = Role::findByName('resident_doctor');
        foreach (['issue', 'print', 'view'] as $ability) {
            $permission = $role->permissions()->where('name', "referrals.{$ability}.own")->sole();
            DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
            if ($ability === 'issue') {
                $this->postJson(route('referrals.store', $visit), $command)->assertForbidden();
            } else {
                $this->get(route('referrals.show', [$visit, $id]))->assertForbidden();
            }
        }
        $migration = require database_path('migrations/2026_10_22_000200_grant_referral_permissions.php');
        $mc = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('name', 'like', 'medical_certificates.%')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->get(route('referrals.show', [$visit, $id]))->assertOk();
        $this->assertSame($mc, DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('name', 'like', 'medical_certificates.%')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
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
        $before = $this->evidence();
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $service = app(ReferralService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->assertSame($before, $this->evidence());
        $id = $service->act($doctor, $visit, $command);
        $this->assertSame($before, $this->evidence());
        $this->assertSame($id, $service->act($doctor, $visit, $command));
        $this->assertSame($before, $this->evidence());
        $this->denied(fn () => $service->act($doctor, $visit, [...$command, 'institution' => 'Different synthetic recipient']), 'idempotency_key');
        $original = DB::table('referral_documents')->find($id);
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
        $this->assertNotSame($original->chain_id, DB::table('referral_documents')->find($third)->chain_id);
        $this->assertSame(3, DB::table('referral_number_counters')->sole()->last_number);
        $this->assertSame($original->encrypted_snapshot, DB::table('referral_documents')->find($id)->encrypted_snapshot);
        foreach ([$id => 'REPLACED', $second => 'VOID', $third => 'CURRENT'] as $documentId => $status) {
            $copy = $service->copy($doctor, $visit, $documentId);
            $this->assertSame($status, $copy['status']);
            $this->assertSame($before, $this->evidence());
        }
        $this->assertSame($audits->toJson(), DB::table('audit_logs')->where('id', '<=', $audits->last()->id)->orderBy('id')->get()->toJson());
        foreach (DB::table('audit_logs')->where('event', 'like', 'referral.%')->get() as $audit) {
            $metadata = json_decode($audit->metadata, true);
            $this->assertEmpty(array_diff(array_keys($metadata), ['document_id', 'chain_id', 'replaces_id', 'version', 'fields']));
            foreach (['Synthetic', 'Hospital', 'referral question', 'REF/KP'] as $private) {
                $this->assertStringNotContainsString($private, $audit->metadata);
            }
        }
    }

    public function test_consent_recipient_bounds_selections_and_stale_saved_source_or_unreviewed_content_are_refused(): void
    {
        [$doctor, , $visit, , $encounter] = $this->ready();
        $service = app(ReferralService::class);
        $input = $this->command($doctor, $visit);
        foreach ([['consent' => false, 'field' => 'consent'], ['institution' => '', 'field' => 'institution'], ['reason' => str_repeat('x', 1001), 'field' => 'reason'], ['history' => str_repeat('x', 4001), 'field' => 'history'], ['language' => 'xx', 'field' => 'language'], ['urgency' => 'invented', 'field' => 'urgency'], ['diagnoses' => [99999], 'field' => 'diagnoses'], ['history' => "Hidden\u{202E}", 'field' => 'history']] as $invalid) {
            $field = $invalid['field'];
            unset($invalid['field']);
            $this->denied(fn () => $service->preview($doctor, $visit, [...$input, ...$invalid]), $field);
        }
        $this->denied(fn () => $service->act($doctor, $visit, $input), 'review_token');
        $reviewed = $this->reviewed($doctor, $visit, $input);
        $this->denied(fn () => $service->act($doctor, $visit, [...$reviewed, 'reason' => 'Edited after preview']), 'review_token');
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        $this->denied(fn () => $service->act($doctor, $visit, $reviewed), 'source_token');
        $this->assertSame(0, DB::table('referral_number_counters')->count());
        $input = $this->command($doctor, $visit);
        $preview = $service->preview($doctor, $visit, [...$input, 'clinical_note' => 'UNSAVED BROWSER TEXT', 'patient_name' => 'FORGED NAME']);
        $this->assertStringNotContainsString('UNSAVED BROWSER TEXT', $preview['snapshot']['html']);
        $this->assertStringNotContainsString('Synthetic clinical note', $preview['snapshot']['html']);
        $this->assertStringNotContainsString('Synthetic diagnosis alpha', $preview['snapshot']['html']);
        $this->assertSame([], $preview['snapshot']['selected']['diagnoses']);
    }

    public function test_selected_clinical_content_unknown_and_none_allergies_escaping_and_en_bm_long_pages(): void
    {
        [$doctor, , $visit, , $encounter] = $this->ready();
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        $plan = TreatmentPlan::factory()->create(['clinical_encounter_id' => $encounter->id]);
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->organisation->id]);
        $order = TreatmentPlanMedicineOrder::factory()->create(['treatment_plan_id' => $plan->id, 'medicine_catalogue_item_id' => $medicine->id]);
        $profile = PatientAllergyProfile::factory()->create(['organisation_id' => $this->organisation->id, 'patient_id' => $visit->patient_id, 'updated_by_user_id' => $doctor->id, 'status' => 'unknown']);
        $service = app(ReferralService::class);
        foreach (['en', 'bm'] as $language) {
            $input = $this->command($doctor, $visit, ['language' => $language, 'include_allergies' => true, 'history' => str_repeat('Synthetic history & <script>not executable</script> 雪. ', 60), 'diagnoses' => [$encounter->diagnoses()->sole()->id], 'medicines' => [$order->id]]);
            $preview = $service->preview($doctor, $visit, $input)['snapshot'];
            $this->assertGreaterThan(1, count($preview['pages']));
            $this->assertStringContainsString('DRAFT WORDING', $preview['html']);
            $this->assertStringNotContainsString('<script>', $preview['html']);
            $this->assertStringContainsString('&lt;script&gt;', $preview['html']);
            $this->assertStringContainsString($language === 'en' ? 'Allergy status unknown' : 'Status alahan tidak diketahui', $preview['html']);
            $this->assertStringNotContainsString('No known allergies recorded', $preview['html']);
            $this->assertStringContainsString($order->medicine_name_snapshot, $preview['html']);
            $this->assertStringContainsString('Synthetic diagnosis alpha', $preview['html']);
            $this->assertSame(count($preview['pages']), substr_count($preview['html'], 'COPY · Page / Halaman'));
            $this->assertSame(count($preview['pages']), substr_count($preview['html'], $visit->patient->patient_number));
        }
        $profile->forceFill(['status' => 'no_known_allergies', 'reviewed_at' => now(), 'reviewed_by_user_id' => $doctor->id, 'lock_version' => $profile->lock_version + 1])->save();
        $preview = $service->preview($doctor, $visit, $this->command($doctor, $visit, ['include_allergies' => true]))['snapshot'];
        $this->assertStringContainsString('No known allergies recorded', $preview['html']);
        $profile->forceFill(['status' => 'has_allergies', 'lock_version' => $profile->lock_version + 1])->save();
        $record = PatientAllergyRecord::factory()->create(['patient_allergy_profile_id' => $profile->id]);
        $preview = $service->preview($doctor, $visit, $this->command($doctor, $visit, ['include_allergies' => true, 'allergies' => [$record->id]]))['snapshot'];
        $this->assertStringContainsString($record->allergen_text, $preview['html']);
    }

    public function test_letterhead_required_fields_missing_credentials_and_configured_logo_fail_closed(): void
    {
        Storage::fake('local');
        [$doctor, , $visit] = $this->ready(true);
        $service = app(ReferralService::class);
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
        $this->assertSame(0, DB::table('referral_documents')->count());
    }

    public function test_serving_hold_checkout_reopen_and_after_checkout_original_issuer_corrections(): void
    {
        [$doctor, , $visit, $queue, $encounter] = $this->ready();
        $service = app(ReferralService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = $service->act($doctor, $visit, $command);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $held = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->denied(fn () => $service->act($doctor, $visit, $held), 'referral');
        app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        app(CompleteConsultationService::class)->complete($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->denied(fn () => $service->act($doctor, $visit, $command), 'referral');
        $replacement = $this->reviewed($doctor, $visit, $this->command($doctor, $visit, ['action' => 'replace', 'document_id' => $id, 'lock_version' => 1, 'change_reason' => 'details_corrected']));
        $service->act($doctor, $visit, $replacement);
        $checkout = ConsultationCheckout::query()->where('visit_id', $visit->id)->sole();
        app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => $checkout->lock_version]);
        $reopened = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $this->denied(fn () => $service->act($doctor, $visit, $reopened), 'referral');
        $this->assertFalse($service->history($doctor, $visit, 1)['canFirstIssue']);
    }

    public function test_snapshots_remain_exact_after_live_changes_and_postgresql_refuses_evidence_mutations(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(ReferralService::class);
        $id = $service->act($doctor, $visit, $this->reviewed($doctor, $visit, $this->command($doctor, $visit)));
        $original = $service->copy($doctor, $visit, $id);
        $visit->patient->forceFill(['full_name' => 'Changed synthetic name', 'lock_version' => $visit->patient->lock_version + 1])->save();
        DB::table('clinical_encounters')->where('visit_id', $visit->id)->update(['clinical_note' => 'Changed synthetic source', 'lock_version' => DB::raw('lock_version + 1')]);
        $profile = BranchBusinessProfile::query()->sole();
        $profile->forceFill(['clinic_name' => 'Changed synthetic clinic', 'lock_version' => $profile->lock_version + 1])->save();
        $credential = ClinicianProfessionalProfile::query()->sole();
        $credential->forceFill(['professional_name' => 'Changed synthetic doctor', 'lock_version' => $credential->lock_version + 1])->save();
        $this->assertSame($original, $service->copy($doctor, $visit, $id));
        $this->get(route('referrals.show', [$visit, $id]))->assertOk()->assertSee('COPY — CURRENT')->assertSee('DRAFT WORDING')->assertSee('Wet signature')->assertDontSee('Changed synthetic');
        foreach (['referral_documents', 'referral_actions', 'referral_template_revisions'] as $table) {
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Evidence mutation accepted');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('Referral evidence is immutable', $e->getMessage());
                }
            }
        }
        foreach (['referral_chains' => 'lock_version', 'referral_number_counters' => 'last_number'] as $table => $field) {
            try {
                DB::transaction(fn () => DB::table($table)->update([$field => 0]));
                $this->fail('Counter reused');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Invalid referral', $e->getMessage());
            }
        }
        $raw = DB::table('referral_documents')->find($id);
        $this->assertStringNotContainsString('Synthetic', $raw->encrypted_snapshot);
        $this->assertFalse($original['snapshot']['productionApproved']);
        $this->assertArrayNotHasKey('identification', $original['snapshot']['patient']);
        $this->assertSame('pre-signature', $original['snapshot']['evidence']);
    }

    public function test_audit_failure_rolls_back_all_referral_writes_and_refuses_source_preview_history_copy(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(ReferralService::class);
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
        foreach (['referral_chains', 'referral_documents', 'referral_actions', 'referral_number_counters'] as $table) {
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
        $this->assertSame(1, DB::table('referral_actions')->count());
        $this->assertSame(1, DB::table('referral_chains')->sole()->lock_version);
    }

    public function test_branch_organisation_assignment_isolation_and_local_new_year_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 15:50:00', 'UTC'));
        [$doctor, , $visit] = $this->ready();
        $this->travelTo(CarbonImmutable::parse('2026-12-31 16:10:00', 'UTC'));
        $service = app(ReferralService::class);
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = $service->act($doctor, $visit, $command);
        $snapshot = $service->copy($doctor, $visit, $id)['snapshot'];
        $this->assertSame('REF/KP/CHR/2027/000001', $snapshot['number']);
        $this->assertSame('01/01/2027', $snapshot['issueDate']);
        $this->assertSame('31/12/2026', $snapshot['examDate']);
        $this->assertSame('2026-12-31 16:10:00.000000+00:00', $snapshot['issuedAtUtc']);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $other);
        $this->selectBranch($doctor, $other);
        $this->get(route('referrals.show', [$visit, $id]))->assertNotFound();
        $this->postJson(route('referrals.store', $visit), $command)->assertNotFound();
        $foreign = Organisation::query()->create(['code' => 'REF_FOREIGN', 'name' => 'Synthetic Foreign Organisation']);
        $foreignBranch = (new Branch)->forceFill(['organisation_id' => $foreign->id, 'code' => 'REF_FOREIGN', 'name' => 'Synthetic Foreign Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreignBranch->save();
        $foreignDoctor = $this->doctor($foreignBranch);
        $this->selectBranch($foreignDoctor, $foreignBranch);
        $this->get(route('referrals.show', [$visit, $id]))->assertNotFound();
        $this->selectBranch($doctor);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $doctor->staffProfile->id)->update(['valid_until' => '2026-12-30']);
        $this->get(route('referrals.index', $visit))->assertNotFound();
    }

    public function test_patient_credential_letterhead_and_order_changes_invalidate_review_and_missing_dob_is_not_invented(): void
    {
        [$doctor, , $visit, , $encounter] = $this->ready();
        $service = app(ReferralService::class);
        $plan = TreatmentPlan::factory()->create(['clinical_encounter_id' => $encounter->id]);
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->organisation->id]);
        $order = TreatmentPlanMedicineOrder::factory()->create(['treatment_plan_id' => $plan->id, 'medicine_catalogue_item_id' => $medicine->id]);
        foreach ([
            fn () => DB::table('patients')->where('id', $visit->patient_id)->update(['date_of_birth' => null, 'lock_version' => DB::raw('lock_version + 1')]),
            fn () => DB::table('clinician_professional_profiles')->where('user_id', $doctor->id)->update(['professional_name' => 'Synthetic Updated Professional', 'lock_version' => DB::raw('lock_version + 1')]),
            fn () => DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->update(['clinic_name' => 'Synthetic Updated Clinic', 'lock_version' => DB::raw('lock_version + 1')]),
            fn () => DB::table('treatment_plan_medicine_orders')->where('id', $order->id)->update(['dosage' => 'Synthetic updated dosage']),
        ] as $change) {
            $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
            $change();
            $this->denied(fn () => $service->act($doctor, $visit, $command), 'source_token');
        }
        $command = $this->reviewed($doctor, $visit, $this->command($doctor, $visit));
        $id = $service->act($doctor, $visit, $command);
        $this->assertNull($service->copy($doctor, $visit, $id)['snapshot']['patient']['dateOfBirth']);
        $this->assertSame(1, DB::table('referral_number_counters')->sole()->last_number);
    }

    public function test_fresh_organisation_deactivation_refuses_all_document_access_without_source_changes(): void
    {
        [$doctor, , $visit] = $this->ready();
        $service = app(ReferralService::class);
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
}
