<?php

namespace Tests\Feature\Queue;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Services\VisitWhatsAppEligibility;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class WhatsAppBoardTest extends QueueTestCase
{
    public function test_board_and_queue_payloads_only_expose_boolean_and_existing_visit_key(): void
    {
        $ca = $this->actor();
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $this->selectBranch($ca);
        foreach ([app(VisitDirectoryService::class)->search($ca, [])['data'][0], app(QueueDirectoryService::class)->snapshot($ca)['waiting']['data'][0], app(QueueDirectoryService::class)->poll($ca)['waiting']['data'][0]] as $row) {
            $this->assertTrue($row['canWhatsapp']);
            $this->assertSame($visit->visit_number, $row['visitNumber']);
            $this->assertStringNotContainsString('60123456789', json_encode($row, JSON_THROW_ON_ERROR));
            foreach (['phone', 'mobile_phone', 'mobilePhone', 'whatsappNumber', 'whatsappUrl'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
        }
        foreach ([null, 'not a phone'] as $phone) {
            DB::table('patients')->where('id', $visit->patient_id)->update(['mobile_phone' => $phone]);
            $this->assertFalse(app(VisitDirectoryService::class)->search($ca, [])['data'][0]['canWhatsapp']);
            $this->assertFalse(app(QueueDirectoryService::class)->snapshot($ca)['waiting']['data'][0]['canWhatsapp']);
        }
    }

    public function test_catalogue_roles_need_the_existing_picker_permission_and_scope(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient($ca));
        $visit->load('patient');
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->assertSame(in_array($role, ['director', 'resident_doctor', 'ca', 'ca_supervisor'], true), VisitWhatsAppEligibility::allows($actor, $visit, $this->branch), $role);
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $this->assertFalse(VisitWhatsAppEligibility::allows($ca, $visit, $other));
        $visit->status = Visit::STATUS_CANCELLED;
        $this->assertFalse(VisitWhatsAppEligibility::allows($ca, $visit, $this->branch));
    }

    public function test_board_only_authority_does_not_reveal_button_and_redirect_checks_current_visit(): void
    {
        $ca = $this->actor();
        $visit = $this->consultationVisit($ca, $this->doctor());
        $this->send($ca, $visit);
        $this->selectBranch($ca);
        $url = route('patients.whatsapp', ['context' => 'visit', 'key' => $visit->visit_number]);
        $this->get($url)->assertRedirect('https://wa.me/60123456789');
        $role = Role::findByName('ca');
        $permission = Permission::findByName('patients.view.organisation');
        $role->revokePermissionTo($permission);
        $ca->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse(app(VisitDirectoryService::class)->search($ca, [])['data'][0]['canWhatsapp']);
        $this->assertFalse(app(QueueDirectoryService::class)->snapshot($ca)['waiting']['data'][0]['canWhatsapp']);
        $this->get($url)->assertForbidden();
        $role->givePermissionTo($permission);
        $ca->unsetRelation('roles')->unsetRelation('permissions');
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $otherCa = $this->actor('ca', $other);
        $this->selectBranch($otherCa, $other);
        $this->get($url)->assertNotFound();
        $this->selectBranch($ca);
        app(VisitAdministrationService::class)->cancel($visit->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version, 'cancellation_reason' => 'Synthetic cancelled contact test'], $ca);
        $this->get($url)->assertNotFound();
        $this->assertFalse(app(VisitDirectoryService::class)->search($ca, ['board_status' => 'cancelled'])['data'][0]['canWhatsapp']);
    }

    public function test_phone_projection_uses_one_existing_eager_load_not_a_query_per_row(): void
    {
        $ca = $this->actor();
        $doctor = $this->doctor();
        for ($i = 0; $i < 6; $i++) {
            $this->send($ca, $this->consultationVisit($ca, $doctor));
        }
        $this->selectBranch($ca);
        foreach ([fn () => app(VisitDirectoryService::class)->search($ca, []), fn () => app(QueueDirectoryService::class)->snapshot($ca), fn () => app(QueueDirectoryService::class)->poll($ca)] as $read) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $read();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $phoneQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'mobile_phone')));
            $this->assertCount(1, $phoneQueries);
            $this->assertStringContainsString(' in (', $phoneQueries[0]['query']);
        }
    }
}
