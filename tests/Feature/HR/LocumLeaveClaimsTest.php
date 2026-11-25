<?php

namespace Tests\Feature\HR;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22b2 (owner, via B.E.R.N.I.S.S, 25 Nov 2026, m1 option b "Sembunyikan cuti & tuntutan"): a locum doctor, like a plain
 * locum, never applies for leave or submits a staff claim. The rule lives in User::hasPermissionTo, so the pages, the
 * actions, the menus and the Me home all agree. A resident doctor who is not a locum is unchanged. Synthetic data only.
 */
class LocumLeaveClaimsTest extends VisitTestCase
{
    public function test_a_locum_doctor_holds_neither_permission_and_a_resident_doctor_keeps_both(): void
    {
        $locumDoctor = $this->locumDoctor();
        $resident = $this->actor('resident_doctor');

        foreach (StaffKind::WITHHELD_FROM_LOCUMS as $permission) {
            $this->assertFalse($locumDoctor->can($permission), $permission);
            $this->assertFalse($locumDoctor->hasPermissionTo($permission), $permission);
            $id = (int) DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');
            $this->assertFalse($locumDoctor->hasPermissionTo($id), "{$permission} by id");
            $this->assertFalse($locumDoctor->getAllPermissions()->contains('name', $permission), $permission);
            $this->assertTrue($resident->can($permission), $permission);
            $this->assertTrue($resident->getAllPermissions()->contains('name', $permission), $permission);
        }
        $this->assertTrue($locumDoctor->can('encounters.start.own'), 'the doctor role is otherwise unchanged');
        $this->assertTrue($locumDoctor->can('hr.roster.view.own'));
        $this->assertFalse($this->actor(PermissionCatalogue::LOCUM_ROLE)->can('hr.leave.request.own'), 'a plain locum, as before');
    }

    public function test_the_leave_and_claims_pages_and_actions_are_refused_for_a_locum_doctor(): void
    {
        $doctor = $this->locumDoctor();
        $this->selectBranch($doctor);
        $before = [DB::table('hr_leave_requests')->count(), DB::table('hr_staff_claims')->count()];

        $this->get(route('hr.leave.index'))->assertForbidden();
        $this->get(route('hr.claims.index'))->assertForbidden();
        $this->post(route('hr.leave.requests.store'), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('hr.leave.preview'), [])->assertForbidden();
        $this->post(route('hr.claims.send'), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame($before, [DB::table('hr_leave_requests')->count(), DB::table('hr_staff_claims')->count()]);

        foreach (['hr.roster.index', 'hr.records.index', 'hr.attendance.index', 'hr.credentials.index', 'hr.sops.index'] as $route) {
            $this->get(route($route))->assertOk(); // the rest of the own HR pages still open
        }
    }

    public function test_the_me_home_and_clinic_management_leave_out_leave_and_claims(): void
    {
        $doctor = $this->locumDoctor();
        $this->selectBranch($doctor);
        $this->get(route('my-day'))->assertOk()->assertInertia(function (Assert $page) {
            $tiles = $page->toArray()['props']['today']['tiles'];
            $this->assertNull($tiles['leave'] ?? null);
            $this->assertNull($tiles['claims'] ?? null);
        });
        $hrefs = collect(app(ClinicManagementNavigation::class)->groups($doctor->refresh()))->flatten()->filter(fn ($v): bool => is_string($v))->all();
        $this->assertNotContains(route('hr.leave.index', absolute: false), $hrefs);
        $this->assertNotContains(route('hr.claims.index', absolute: false), $hrefs);

        $resident = $this->actor('resident_doctor');
        $this->selectBranch($resident);
        $this->get(route('hr.leave.index'))->assertOk();
        $this->get(route('hr.claims.index'))->assertOk();
    }

    private function locumDoctor(): User
    {
        $doctor = $this->actor('resident_doctor');
        $doctor->assignRole(PermissionCatalogue::LOCUM_ROLE);

        return $doctor->refresh();
    }
}
