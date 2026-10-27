<?php

namespace Tests\Feature\Patient;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Patient\Services\PatientGuardianService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\GuardianFixture;

class GuardianWhatsAppIsolationTest extends QueueTestCase
{
    public function test_guardian_never_reaches_whatsapp_redirect_buttons_lists_or_redirect_audit(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $patient = app(PatientAdministrationService::class)->create($ca, ['full_name' => 'Synthetic guardian isolation child', 'date_of_birth' => '2015-03-03', 'sex' => 'unknown', 'mobile_phone' => '+12025550121', 'phone_country' => 'US', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-GUARD-WA']], 'guardian' => GuardianFixture::contact()]);
        $visit = $this->register($ca, $patient, ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $this->doctor()->id, 'visit_reason' => 'Synthetic isolation']);
        $this->send($ca, $visit);
        $this->selectBranch($ca);
        $url = route('patients.whatsapp', ['context' => 'patient', 'key' => $patient->patient_number]);
        $this->get($url)->assertRedirect('https://wa.me/12025550121');
        $audit = AuditLog::query()->where('event', 'patient.whatsapp_opened')->latest('id')->firstOrFail();
        $this->assertSame(['patient_id' => $patient->id], $audit->metadata);
        foreach (['Synthetic Guardian', '+12025550120', 'guardian@kpone.test'] as $value) {
            $this->assertStringNotContainsString($value, $audit->toJson());
        }
        foreach (['+12025550121', null] as $ownPhone) {
            DB::table('patients')->where('id', $patient->id)->update(['mobile_phone' => $ownPhone]);
            $detail = app(PatientDirectoryService::class)->detail($ca, $patient->fresh());
            $this->assertSame($ownPhone !== null, $detail['canWhatsapp']);
            $rows = [app(PatientDirectoryService::class)->search($ca, ['query' => $patient->patient_number, 'search_type' => 'patient_number'])['data'][0], app(VisitDirectoryService::class)->search($ca, [])['data'][0], app(QueueDirectoryService::class)->snapshot($ca)['waiting']['data'][0], app(QueueDirectoryService::class)->poll($ca)['waiting']['data'][0]];
            foreach ($rows as $row) {
                $this->assertSame($ownPhone !== null, $row['canWhatsapp']);
                $this->assertArrayNotHasKey('guardian', $row);
                foreach (['Synthetic Guardian', '+12025550120', 'guardian@kpone.test'] as $value) {
                    $this->assertStringNotContainsString($value, json_encode($row, JSON_THROW_ON_ERROR));
                }
            }
        }
        $before = AuditLog::query()->where('event', 'patient.whatsapp_opened')->count();
        $this->get($url)->assertStatus(422);
        $this->assertSame($before, AuditLog::query()->where('event', 'patient.whatsapp_opened')->count());
    }

    public function test_guardian_detail_is_released_only_to_existing_patient_contact_permission_holders(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $patient = app(PatientAdministrationService::class)->create($ca, ['full_name' => 'Synthetic contact privacy child', 'date_of_birth' => '2015-03-03', 'sex' => 'unknown', 'mobile_phone' => null, 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-GUARD-ROLES']], 'guardian' => GuardianFixture::contact()]);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->get(route('patients.show', $patient));
            if (in_array($role, ['director', 'resident_doctor', 'ca', 'ca_supervisor'], true)) {
                $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('patient.guardian.full_name', 'Synthetic Guardian'));
            } else {
                $response->assertForbidden()->assertDontSee('Synthetic Guardian')->assertDontSee('+12025550120')->assertDontSee('guardian@kpone.test');
                $this->assertStringNotContainsString('"guardian":', $response->getContent());
            }
        }
    }

    public function test_guardian_calendar_date_does_not_resolve_cache_or_change_branch_session(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $before = session()->all();
        $this->mock(BranchAccessService::class)->shouldNotReceive('activeBranch');
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(16, 1)->utc());
        $this->assertSame(now()->setTimezone($this->branch->timezone)->toDateString(), app(PatientGuardianService::class)->today($ca));
        $this->assertSame($before, session()->all());
    }
}
