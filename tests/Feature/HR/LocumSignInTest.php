<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Roster\RosterPermissions;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-15a locum sign-ins (HR-PLAN 21.3; B.E.R.N.I.S.S for the owner, 2026-11-10). Synthetic data only.
 */
class LocumSignInTest extends VisitTestCase
{
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->actor('hr_manager');
    }

    public function test_the_locum_role_is_pinned_narrow_and_lands_on_its_own_shifts(): void
    {
        $expected = ['hr.roster.view.own', 'profile.view.own'];
        $catalogue = PermissionCatalogue::roles()[PermissionCatalogue::LOCUM_ROLE];
        sort($catalogue);
        $this->assertSame($expected, $catalogue);
        $seeded = Role::findByName(PermissionCatalogue::LOCUM_ROLE)->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame($expected, $seeded);
        $this->assertNotContains(PermissionCatalogue::LOCUM_ROLE, RosterPermissions::HUMAN_ROLES); // no leave, clock-in, records, incidents or payslips
        foreach ($expected as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        // Any account administrator may give or manage the role; it grants no authority over anyone.
        $this->assertTrue(app(StaffAuthorityService::class)->canAssignRoles($this->actor('technical_admin'), [PermissionCatalogue::LOCUM_ROLE]));
        $this->assertFalse($this->hr->can('access.manage.organisation')); // HR may not create accounts at all (StaffProvisioningService)

        $locum = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $this->selectBranch($locum);
        $this->get(route('workspace'))->assertRedirect(route('hr.roster.index'));
        $this->get(route('hr.roster.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Roster')
            ->where('roster.tab', 'my')->where('roster.planner', null)->has('roster.my'));
        $this->get(route('profile.edit'))->assertOk();
        foreach (['dashboard', 'registration.index', 'patients.index', 'staff.index', 'hr.records.index', 'hr.leave.index', 'hr.incidents.index',
            'hr.attendance.index', 'insights.today'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_hr_asks_the_administrator_links_and_the_file_shows_the_sign_in(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.records.locums.store'), ['display_name' => 'Synthetic Locum', 'phone' => '+60123456789', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $worker = Worker::query()->where('kind', 'locum')->sole();
        $before = [DB::table('users')->count(), DB::table('model_has_roles')->count(), DB::table('staff_branch_assignments')->count()];

        $this->get(route('hr.records.show', $worker->public_id))->assertInertia(fn (Assert $page) => $page->where('file.signIn.state', 'none'));
        $this->ask($worker, 'not an email')->assertSessionHasErrors('email');
        $this->ask($worker, 'Synthetic.Locum@Example.test')->assertSessionHasNoErrors();
        $this->ask($worker, 'other@example.test')->assertSessionHasErrors('email'); // one open request
        $this->get(route('hr.records.show', $worker->public_id))->assertInertia(fn (Assert $page) => $page->where('file.signIn.state', 'open')
            ->where('file.signIn.email', 'synthetic.locum@example.test'));
        $request = DB::table('hr_locum_sign_ins')->sole();

        // The HR Manager may not create staff accounts, so sees no list and cannot link.
        $this->post(route('staff.locum-sign-ins.link', $request->public_id), ['user_id' => $this->hr->id, 'expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        // The account administrator (a Director here) creates the account the usual way, then links it.
        $director = $this->actor('director');
        $staffAccount = $this->actor('ca');
        $otherLocum = $this->actor(PermissionCatalogue::LOCUM_ROLE); // a locum account with another email
        $twoRoles = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $twoRoles->assignRole('resident_doctor');
        $twoRoles->forceFill(['email' => 'synthetic.locum@example.test'])->save();
        $this->selectBranch($director);
        $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->has('locumSignIns.requests', 1)
            ->where('locumSignIns.requests.0.name', 'Synthetic Locum')->where('locumSignIns.requests.0.email', 'synthetic.locum@example.test')
            ->where('locumSignIns.requests.0.account', null)); // the only account with that email also holds another role
        $this->link($request->public_id, $staffAccount->id)->assertSessionHasErrors('user_id'); // not a locum account
        $this->link($request->public_id, $twoRoles->id)->assertSessionHasErrors('user_id'); // the Locum role only
        $this->link($request->public_id, $otherLocum->id)->assertSessionHasErrors('user_id'); // not the email HR asked for
        $twoRoles->removeRole('resident_doctor');
        $twoRoles->forceFill(['email' => 'retired.'.$twoRoles->id.'@example.test'])->save();
        $account = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $account->forceFill(['email' => 'Synthetic.Locum@example.test'])->save();
        $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->where('locumSignIns.requests.0.account.id', (string) $account->id));
        $this->link($request->public_id, $account->id)->assertRedirect(route('staff.index'))->assertSessionHasNoErrors();
        $this->assertSame(['done', $account->id, $director->id], [DB::table('hr_locum_sign_ins')->value('status'),
            (int) DB::table('hr_locum_sign_ins')->value('linked_user_id'), (int) DB::table('hr_locum_sign_ins')->value('closed_by_user_id')]);
        $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->where('locumSignIns.requests', []));

        // HR sees the link; a second sign-in is refused, and the locum account takes no staff file of its own.
        $this->selectBranch($this->hr);
        $this->get(route('hr.records.show', $worker->public_id))->assertInertia(fn (Assert $page) => $page->where('file.signIn.state', 'linked')
            ->where('file.signIn.accountName', $account->name));
        $this->ask($worker, 'again@example.test')->assertSessionHasErrors('email');
        $this->post(route('hr.records.files.store'), ['user_id' => $account->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('user_id');

        // HR and the link changed no account, role or branch assignment (only this test's own actors were made, one with a role
        // added and removed again).
        $this->assertSame([$before[0] + 5, $before[1] + 5, $before[2] + 5],
            [DB::table('users')->count(), DB::table('model_has_roles')->count(), DB::table('staff_branch_assignments')->count()]);
    }

    public function test_hr_can_cancel_and_ask_again(): void
    {
        $worker = $this->locumFile();
        $this->selectBranch($this->hr);
        $this->ask($worker, 'locum@example.test')->assertSessionHasNoErrors();
        $request = DB::table('hr_locum_sign_ins')->sole();
        $this->post(route('hr.records.sign-in.cancel', [$worker->public_id, $request->public_id]), ['expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', DB::table('hr_locum_sign_ins')->value('status'));
        $this->ask($worker, 'locum@example.test')->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('hr_locum_sign_ins')->count());
    }

    public function test_postgresql_keeps_the_rules(): void
    {
        $worker = $this->locumFile();
        $this->selectBranch($this->hr);
        $this->ask($worker, 'locum@example.test')->assertSessionHasNoErrors();
        $request = DB::table('hr_locum_sign_ins')->sole();
        $account = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $ca = $this->actor('ca');
        $director = $this->actor('director');
        $done = fn (int $by, int $user) => fn () => DB::table('hr_locum_sign_ins')->where('id', $request->id)->update(['status' => 'done',
            'closed_by_user_id' => $by, 'closed_at' => now(), 'linked_user_id' => $user, 'lock_version' => 2]);

        // Only for a locum file.
        $staffFile = Worker::query()->forceCreate(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'user_id' => $ca->id,
            'kind' => 'staff', 'created_by_user_id' => $this->hr->id]);
        $this->refused('P0001', fn () => DB::table('hr_locum_sign_ins')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id,
            'worker_id' => $staffFile->id, 'email_encrypted' => 'x', 'status' => 'open', 'requested_by_user_id' => $this->hr->id, 'requested_at' => now(),
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]));
        // Only someone who may create staff accounts links (the HR Manager may not), and only a locum account.
        $this->refused('23514', $done($this->hr->id, $account->id));
        $this->refused('23514', $done($director->id, $ca->id));
        $account->assignRole('resident_doctor');
        $this->refused('23514', $done($director->id, $account->id)); // the Locum role only
        $account->removeRole('resident_doctor');
        $done($director->id, $account->id)();
        // Closed once; never deleted; the account takes no staff file of its own.
        $this->refused('P0001', fn () => DB::table('hr_locum_sign_ins')->where('id', $request->id)->update(['status' => 'cancelled', 'linked_user_id' => null, 'lock_version' => 3]));
        $this->refused('P0001', fn () => DB::table('hr_locum_sign_ins')->delete());
        $this->refused('23514', fn () => Worker::query()->forceCreate(['public_id' => (string) Str::uuid(), 'organisation_id' => $account->organisation_id,
            'user_id' => $account->id, 'kind' => 'staff', 'created_by_user_id' => $this->hr->id]));
    }

    // ---- fixture ----

    private function locumFile(): Worker
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.records.locums.store'), ['display_name' => 'Synthetic Locum', 'phone' => '+60123456789', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return Worker::query()->where('kind', 'locum')->sole();
    }

    /** @return TestResponse<Response> */
    private function ask(Worker $worker, string $email): TestResponse
    {
        return $this->post(route('hr.records.sign-in.store', $worker->public_id), ['email' => $email, 'idempotency_key' => (string) Str::uuid()]);
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
