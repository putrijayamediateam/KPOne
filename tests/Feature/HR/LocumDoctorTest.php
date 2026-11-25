<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffKind;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\Identity\Services\StaffRoleService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22b (HR-PLAN 29.3; the owner's role decision under AGENTS.md, 24 Nov 2026): a locum doctor's account holds exactly
 * Locum and Resident Doctor. HR asks for it on the sign-in request; the account administrator creates and links it; the
 * locum marker stays readable; the doctor lands where residents land. Synthetic data only.
 */
class LocumDoctorTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_hr_asks_for_a_locum_doctor_and_only_an_account_with_both_roles_links(): void
    {
        $worker = $this->locumFile();
        $this->ask($worker, 'doctor@example.test', 'consultant')->assertSessionHasErrors('role');
        $this->ask($worker, 'Doctor@Example.test', 'locum_doctor')->assertSessionHasNoErrors();
        $request = DB::table('hr_locum_sign_ins')->sole();
        $this->assertSame('locum_doctor', $request->role_requested);
        $audit = DB::table('audit_logs')->where('event', 'hr.records.locum_sign_in_requested')->latest('id')->first();
        $this->assertStringContainsString('"role_requested":"locum_doctor"', (string) ($audit->metadata ?? ''));
        $this->assertStringNotContainsString('doctor@example.test', (string) ($audit->metadata ?? ''));
        $this->get(route('hr.records.show', $worker->public_id))->assertInertia(fn (Assert $page) => $page->where('file.signIn.state', 'open')
            ->where('file.signIn.role', 'locum_doctor')->where('file.signIn.roleLabel', 'Locum doctor'));

        // An account with the Locum role only and the right email is not the one asked for.
        $locumOnly = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $locumOnly->forceFill(['email' => 'doctor@example.test'])->save();
        $this->selectBranch($this->director);
        $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->where('locumSignIns.requests.0.role', 'locum_doctor')
            ->where('locumSignIns.requests.0.roleLabel', 'Locum doctor')->where('locumSignIns.requests.0.account', null)
            ->where('locumSignIns.requests.0.canCreate', true));
        // A Technical Admin sees the request but may not give the roles, so the page asks them to ask the Director (Daniel).
        $this->selectBranch($this->actor('technical_admin'));
        $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->where('locumSignIns.requests.0.canCreate', false));
        $this->selectBranch($this->director);
        $this->link($request->public_id, $locumOnly->id)->assertSessionHasErrors('user_id');

        // The Director gives the same account the Resident Doctor role the usual way; now it links.
        $this->put(route('staff.roles.update', $locumOnly), ['roles' => ['locum', 'resident_doctor']])->assertSessionHasNoErrors();
        $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->where('locumSignIns.requests.0.account.id', (string) $locumOnly->id));
        $this->link($request->public_id, $locumOnly->id)->assertSessionHasNoErrors();
        $this->assertSame('done', DB::table('hr_locum_sign_ins')->value('status'));

        $this->selectBranch($this->hr);
        $this->get(route('hr.records.show', $worker->public_id))->assertInertia(fn (Assert $page) => $page->where('file.signIn.state', 'linked')
            ->where('file.signIn.role', 'locum_doctor'));
        $doctor = $locumOnly->refresh();
        $this->assertTrue(StaffKind::isLocum($doctor));
        $this->assertTrue(StaffKind::isDoctor($doctor));
        $this->assertTrue($doctor->can('queue.view.own'));
    }

    public function test_a_plain_locum_request_still_links_only_the_locum_role(): void
    {
        $worker = $this->locumFile();
        $this->ask($worker, 'locum@example.test')->assertSessionHasNoErrors(); // no role sent: Locum, as before
        $request = DB::table('hr_locum_sign_ins')->sole();
        $this->assertSame('locum', $request->role_requested);
        $both = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $both->assignRole('resident_doctor');
        $both->forceFill(['email' => 'locum@example.test'])->save();
        $this->selectBranch($this->director);
        $this->link($request->public_id, $both->id)->assertSessionHasErrors('user_id');
    }

    public function test_role_rules_allow_locum_with_resident_doctor_only_and_a_linked_account_keeps_what_hr_asked_for(): void
    {
        $unlinked = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $this->selectBranch($this->director);
        $roles = fn (User $u, array $r) => $this->put(route('staff.roles.update', $u), ['roles' => $r]);

        $roles($unlinked, ['locum', 'ca'])->assertSessionHasErrors(['roles' => StaffRoleService::LOCUM_ONLY]);
        $roles($unlinked, ['locum', 'resident_doctor', 'ca'])->assertSessionHasErrors(['roles' => StaffRoleService::LOCUM_ONLY]);
        $roles($unlinked, ['resident_doctor', 'locum'])->assertSessionHasNoErrors();
        $this->assertSame(['locum', 'resident_doctor'], $unlinked->refresh()->getRoleNames()->sort()->values()->all());

        // Linked as a locum doctor: neither drops Resident Doctor nor gains anything.
        $worker = $this->locumFile();
        $this->ask($worker, 'linked@example.test', 'locum_doctor')->assertSessionHasNoErrors();
        $unlinked->forceFill(['email' => 'linked@example.test'])->save();
        $this->selectBranch($this->director);
        $this->link((string) DB::table('hr_locum_sign_ins')->value('public_id'), $unlinked->id)->assertSessionHasNoErrors();
        $roles($unlinked, ['locum'])->assertSessionHasErrors(['roles' => StaffRoleService::LOCUM_ONLY]);
        $roles($unlinked, ['resident_doctor'])->assertSessionHasErrors(['roles' => StaffRoleService::LOCUM_ONLY]);
        $this->assertSame(['locum', 'resident_doctor'], $unlinked->refresh()->getRoleNames()->sort()->values()->all());

        // Technical Admin may still not assign Resident Doctor (unchanged staff authority).
        $other = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $this->selectBranch($this->actor('technical_admin'));
        $roles($other, ['locum', 'resident_doctor'])->assertForbidden();
    }

    public function test_a_locum_doctor_lands_where_residents_land_and_a_locum_on_their_shifts(): void
    {
        $doctor = $this->actor('resident_doctor');
        $doctor->assignRole(PermissionCatalogue::LOCUM_ROLE);
        $this->selectBranch($doctor);
        $this->get(route('workspace'))->assertRedirect(route('queue.index'));

        $locum = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $this->selectBranch($locum);
        $this->get(route('workspace'))->assertRedirect(route('hr.roster.index'));
    }

    public function test_a_locum_doctor_with_no_staff_file_opens_their_own_hr_pages(): void
    {
        // Resident Doctor brings the staff self-service HR pages (Suresh, m1); a locum doctor has no staff file of their own,
        // so each own page must open, not fail.
        $doctor = $this->actor('resident_doctor');
        $doctor->assignRole(PermissionCatalogue::LOCUM_ROLE);
        $this->assertNull(Worker::query()->where('user_id', $doctor->id)->first());
        $this->selectBranch($doctor);
        foreach (['hr.roster.index', 'hr.records.index', 'hr.leave.index', 'hr.attendance.index', 'hr.claims.index', 'hr.credentials.index',
            'hr.sops.index', 'hr.memos.index', 'hr.incidents.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_postgresql_keeps_what_was_asked_and_the_exact_roles(): void
    {
        $worker = $this->locumFile();
        $this->ask($worker, 'doctor@example.test', 'locum_doctor')->assertSessionHasNoErrors();
        $request = DB::table('hr_locum_sign_ins')->sole();
        $account = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $done = fn () => DB::table('hr_locum_sign_ins')->where('id', $request->id)->update(['status' => 'done',
            'closed_by_user_id' => $this->director->id, 'closed_at' => now(), 'linked_user_id' => $account->id, 'lock_version' => 2]);

        $this->refused('23514', fn () => DB::table('hr_locum_sign_ins')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id,
            'worker_id' => $this->locumFile('Second Locum')->id, 'email_encrypted' => 'x', 'role_requested' => 'doctor', 'status' => 'open',
            'requested_by_user_id' => $this->hr->id, 'requested_at' => now(), 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]));
        $this->refused('P0001', fn () => DB::table('hr_locum_sign_ins')->where('id', $request->id)->update(['role_requested' => 'locum', 'status' => 'cancelled',
            'closed_by_user_id' => $this->hr->id, 'closed_at' => now(), 'lock_version' => 2]));
        $this->refused('23514', $done); // the Locum role only, but a locum doctor was asked for
        $account->assignRole('resident_doctor');
        $account->assignRole('ca');
        $this->refused('23514', $done); // one role too many
        $account->removeRole('ca');
        $done();
        $this->assertSame('done', DB::table('hr_locum_sign_ins')->where('id', $request->id)->value('status'));
    }

    // ---- fixture ----

    private function locumFile(string $name = 'Synthetic Locum Doctor'): Worker
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.records.locums.store'), ['display_name' => $name, 'phone' => '+60123456789', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return Worker::query()->where('kind', 'locum')->where('display_name', $name)->sole();
    }

    /** @return TestResponse<Response> */
    private function ask(Worker $worker, string $email, ?string $role = null): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.records.sign-in.store', $worker->public_id), array_filter(['email' => $email, 'role' => $role,
            'idempotency_key' => (string) Str::uuid()], fn ($v) => $v !== null));
    }

    /** @return TestResponse<Response> */
    private function link(string $requestId, int $userId): TestResponse
    {
        return $this->post(route('staff.locum-sign-ins.link', $requestId), ['user_id' => $userId, 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function refused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
