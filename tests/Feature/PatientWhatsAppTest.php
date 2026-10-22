<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Patient\Services\WhatsAppNumber;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Billing\BillingTestCase;

class PatientWhatsAppTest extends BillingTestCase
{
    public function test_shared_php_js_vectors(): void
    {
        $vectors = json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp-numbers.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($vectors as [$input, $expected]) {
            $this->assertSame($expected, WhatsAppNumber::normalize($input), json_encode($input));
        }
    }

    public function test_every_catalogue_role_uses_existing_phone_authority_only(): void
    {
        $patient = $this->patient();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->get(route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]));
            if (in_array($role, ['director', 'resident_doctor', 'ca', 'ca_supervisor'], true)) {
                $response->assertStatus(302)->assertRedirect('https://wa.me/60123456789');
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                $response->assertHeader('Referrer-Policy', 'no-referrer');
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_redirect_reads_current_number_and_only_one_audit_changes_among_all_tables(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $this->register($ca, $patient);
        $directory = app(PatientDirectoryService::class)->search($ca, ['query' => $patient->patient_number, 'search_type' => 'patient_number']);
        $this->assertTrue($directory['data'][0]['canWhatsapp']);
        $this->assertStringNotContainsString($patient->mobile_phone, json_encode($directory, JSON_THROW_ON_ERROR));
        DB::table('patients')->where('id', $patient->id)->update(['mobile_phone' => '+60312345678']);
        $before = $this->allUnrelatedRows();
        $auditBefore = AuditLog::count();
        $this->get(route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]))
            ->assertRedirect('https://wa.me/60312345678');
        $this->assertSame($before, $this->allUnrelatedRows());
        $this->assertSame($auditBefore + 1, AuditLog::count());
        $audit = AuditLog::latest('id')->firstOrFail();
        $this->assertSame('patient.whatsapp_opened', $audit->event);
        $this->assertSame(['patient_id' => $patient->id], $audit->metadata);
        $this->assertSame($this->branch->id, $audit->branch_id);
        $this->assertSame($ca->id, $audit->actor_user_id);
        $this->assertStringNotContainsString('60312345678', $audit->toJson());
        $this->assertStringNotContainsString('60123456789', $audit->toJson());
        $this->get(route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number, 'text' => 'ignored', 'phone' => '+12025550123']))->assertRedirect('https://wa.me/60312345678');
    }

    public function test_invalid_or_missing_phone_is_not_eligible_and_cannot_redirect(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        foreach ([null, 'not a number', '012***6789'] as $phone) {
            DB::table('patients')->where('id', $patient->id)->update(['mobile_phone' => $phone]);
            $patient->refresh();
            $this->assertFalse(app(PatientDirectoryService::class)->detail($ca, $patient)['canWhatsapp']);
            $rows = app(PatientDirectoryService::class)->search($ca, ['query' => $patient->patient_number, 'search_type' => 'patient_number']);
            $this->assertFalse($rows['data'][0]['canWhatsapp']);
            $this->get(route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]))->assertUnprocessable();
        }
    }

    public function test_fresh_revocation_inactive_actor_and_foreign_organisation_are_refused(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $url = route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]);
        $ca->load('roles.permissions');
        $roleId = Role::findByName('ca')->id;
        $permissionId = Permission::findByName('patients.view.organisation')->id;
        DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $this->get($url)->assertForbidden();
        DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        $ca->syncRoles(['marketing']);
        $this->get($url)->assertForbidden();
        $ca->syncRoles(['ca']);
        $foreign = Organisation::query()->create(['code' => 'SYNTHETIC_FOREIGN', 'name' => 'Synthetic foreign organisation']);
        $foreignPatient = Patient::query()->forceCreate(['organisation_id' => $foreign->id, 'patient_number' => 'SYN-FOREIGN', 'full_name' => 'Synthetic foreign patient', 'search_name' => 'synthetic foreign patient', 'sex' => 'unknown', 'lock_version' => 1]);
        $this->get(route('patients.whatsapp', ['context' => 'patient', 'key' => $foreignPatient->patient_number]))->assertNotFound();
        $ca->forceFill(['is_active' => false])->save();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_audit_exception_or_missing_audit_record_blocks_redirect(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $before = $this->allUnrelatedRows();
        $url = route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andReturnNull();
        $this->get($url)->assertStatus(503)->assertHeaderMissing('Location');
        $this->assertSame($before, $this->allUnrelatedRows());
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('synthetic audit refusal'));
        $this->get($url)->assertStatus(500)->assertHeaderMissing('Location');
        $this->assertSame($before, $this->allUnrelatedRows());
    }

    public function test_per_actor_throttle_and_context_allowlist(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $url = route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]);
        for ($i = 0; $i < 20; $i++) {
            $this->get($url)->assertStatus(302);
        }
        $this->get($url)->assertTooManyRequests()->assertHeaderMissing('Location');
        $other = $this->actor();
        $this->selectBranch($other);
        $this->get($url)->assertStatus(302);
        $this->get('/patient-contact/arbitrary/'.$patient->patient_number.'/whatsapp')->assertNotFound();
    }

    public function test_expired_effective_branch_assignment_refuses_contact(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $ca->staffProfile->id)->update(['valid_from' => now()->subWeek()->toDateString(), 'valid_until' => now()->subDays(2)->toDateString()]);
        $this->get(route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]))->assertForbidden();
    }

    public function test_claim_context_preserves_identity_permission_and_branch_boundaries(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $officer = $this->actor('panel_officer');
        $finance = $this->actor('finance_officer');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 2000]);
        $this->selectBranch($finance);
        $allocation = app(ResponsibilityService::class)->propose($finance, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 2000, 'panel_id' => $panel->id, 'reason' => 'Synthetic responsibility']);
        $this->selectBranch($officer);
        app(ResponsibilityService::class)->approve($officer, $visit, $invoice->refresh(), 'panel', $allocation->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $allocation->lock_version]);
        $claim = app(PanelClaimService::class)->prepare($officer, $allocation->refresh(), ['expected_branch_id' => $this->branch->id]);
        $url = route('patients.whatsapp', ['context' => 'claim', 'key' => $claim->public_id]);
        $before = $this->allUnrelatedRows();
        $this->get($url)->assertRedirect('https://wa.me/60123456789');
        $this->assertSame($before, $this->allUnrelatedRows());
        $this->get(route('patients.whatsapp', ['context' => 'claim', 'key' => 'not-a-uuid']))->assertNotFound();
        $roleId = Role::findByName('panel_officer')->id;
        $permissionId = Permission::findByName('panel.claims.patient_identity.view.branch')->id;
        $officer->load('roles.permissions');
        DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $this->get($url)->assertForbidden();
        DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $role === 'panel_officer' ? $officer : $this->actor($role);
            $this->selectBranch($actor);
            $this->get($url)->assertStatus($role === 'panel_officer' ? 302 : 403);
        }
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        $otherOfficer = $this->actor('panel_officer', $otherBranch);
        $this->selectBranch($otherOfficer, $otherBranch);
        $this->get($url)->assertNotFound();
        $this->selectBranch($officer);
        $this->get(route('patients.whatsapp', ['context' => 'patient', 'key' => $visit->patient->patient_number]))->assertForbidden();
        $this->selectBranch($finance);
        $this->get($url)->assertForbidden();
        $this->selectBranch($officer);
        $officer->syncRoles(['marketing']);
        $this->get($url)->assertForbidden();
    }

    /** @return array<string, string> */
    private function allUnrelatedRows(): array
    {
        $result = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === 'audit_logs') {
                continue;
            }
            $columns = Schema::getColumnListing($name);
            $query = DB::table($name);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $result[$name] = $query->get()->toJson();
        }
        ksort($result);

        return $result;
    }
}
