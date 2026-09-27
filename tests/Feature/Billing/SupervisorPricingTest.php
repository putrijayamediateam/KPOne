<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * PX-01 (owner decision, 2026-09-27): ca_supervisor manages price books, charge
 * definitions and published prices, as finance_officer does.
 */
class SupervisorPricingTest extends ClinicalTestCase
{
    private const REFERENCES = 'pricing.references.manage.organisation';

    private const PUBLISH = 'prices.publish.organisation';

    private const MIGRATION_PATH = 'database/migrations/2026_09_27_000100_grant_ca_supervisor_pricing_permissions_additively.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION_PATH);
    }

    /** @return array<string, int> */
    private function permissionCountsByRole(): array
    {
        return DB::table('roles')
            ->leftJoin('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->groupBy('roles.name')->orderBy('roles.name')
            ->selectRaw('roles.name as name, count(role_has_permissions.permission_id) as c')
            ->pluck('c', 'name')->map(fn ($c) => (int) $c)->all();
    }

    public function test_exactly_these_roles_hold_the_two_pricing_permissions(): void
    {
        $roles = PermissionCatalogue::roles();
        $holders = fn (string $permission): array => collect($roles)
            ->filter(fn (array $permissions) => in_array($permission, $permissions, true))->keys()->sort()->values()->all();

        $this->assertSame(['ca_supervisor', 'director', 'finance_officer'], $holders(self::REFERENCES));
        $this->assertSame(['ca_supervisor', 'finance_officer'], $holders(self::PUBLISH));
    }

    public function test_the_grant_does_not_touch_staff_administration(): void
    {
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(self::REFERENCES));
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(self::PUBLISH));
        $this->assertNotContains(self::REFERENCES, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
        $this->assertNotContains(self::PUBLISH, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);

        // ca_supervisor still carries no administrative authority, so who may administer it is unchanged.
        $supervisor = collect(PermissionCatalogue::roles()['ca_supervisor'])
            ->filter(fn (string $p) => PermissionCatalogue::isAdministrativeAuthority($p));
        $this->assertTrue($supervisor->isEmpty());

        $technical = $this->actor('technical_admin');
        $supervisorUser = $this->actor('ca_supervisor');
        foreach (['update', 'manageAccess', 'manageRoles', 'manageStatus'] as $ability) {
            $this->assertTrue($technical->can($ability, $supervisorUser), $ability);
            $this->assertFalse($supervisorUser->can($ability, $technical), $ability);
        }
        $this->assertFalse(app(StaffAuthorityService::class)->canAssignRoles($technical, ['ca_supervisor']));
    }

    public function test_a_supervisor_sees_the_pricing_menu(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);

        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('workspace.navigation.pricing', true));
    }

    public function test_a_supervisor_can_create_a_price_book_and_a_charge_and_publish_a_price(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);

        $this->get(route('pricing.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Pricing/Index')->where('canPublish', true));

        $this->post(route('pricing.price-books.store'), ['name' => 'Supervisor price book', 'currency' => 'MYR'])
            ->assertRedirect(route('pricing.index'));
        $book = PriceBook::query()->where('name', 'Supervisor price book')->sole();

        $this->post(route('pricing.charges.store'), ['type' => 'consultation', 'code' => 'SUP-CONSULT', 'display_name' => 'Supervisor consultation'])
            ->assertRedirect(route('pricing.index'));
        $charge = ChargeDefinition::query()->where('code', 'SUP-CONSULT')->sole();

        $this->post(route('pricing.charges.publish', $charge), [
            'price_book_public_id' => $book->public_id,
            'amount_sen' => 8000,
            'expected_version' => 0,
            'expected_branch_id' => $this->branch->id,
        ])->assertRedirect(route('pricing.index'));

        $this->assertDatabaseHas('price_entries', ['charge_definition_id' => $charge->id, 'unit_price_sen' => 8000, 'version' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'price_book.created', 'actor_user_id' => $supervisor->id]);
    }

    public function test_finance_officer_and_director_behave_exactly_as_before(): void
    {
        $finance = $this->actor('finance_officer');
        $this->assertTrue($finance->can(self::REFERENCES));
        $this->assertTrue($finance->can(self::PUBLISH));

        $director = $this->actor('director');
        $this->assertTrue($director->can(self::REFERENCES));
        $this->assertFalse($director->can(self::PUBLISH));

        foreach (['ca', 'resident_doctor', 'panel_officer', 'technical_admin', 'hr_manager', 'marketing', 'business_development'] as $role) {
            $user = $this->actor($role);
            $this->assertFalse($user->can(self::REFERENCES), $role);
            $this->assertFalse($user->can(self::PUBLISH), $role);
        }
    }

    public function test_migration_grants_an_existing_database_only_what_is_missing_and_never_removes(): void
    {
        $role = Role::query()->where('name', 'ca_supervisor')->firstOrFail();
        $role->revokePermissionTo([self::REFERENCES, self::PUBLISH]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->actor('ca_supervisor')->can(self::PUBLISH));

        $before = $this->permissionCountsByRole();
        $permissionsBefore = DB::table('permissions')->count();

        $this->migration()->up();

        $after = $this->permissionCountsByRole();
        foreach ($before as $name => $count) {
            $this->assertGreaterThanOrEqual($count, $after[$name], "{$name} lost a permission");
            if ($name !== 'ca_supervisor') {
                $this->assertSame($count, $after[$name], "{$name} changed");
            }
        }
        $this->assertSame($before['ca_supervisor'] + 2, $after['ca_supervisor']);
        $this->assertSame($permissionsBefore, DB::table('permissions')->count());
        $this->assertTrue($this->actor('ca_supervisor')->can(self::REFERENCES));
        $this->assertTrue($this->actor('ca_supervisor')->can(self::PUBLISH));

        // Idempotent: a second run, and an up -> down -> up, change nothing.
        $this->migration()->up();
        $this->migration()->down();
        $this->migration()->up();
        $this->assertSame($after, $this->permissionCountsByRole());
    }

    public function test_migration_creates_a_missing_permission_but_never_a_missing_role(): void
    {
        DB::table('permissions')->whereIn('name', [self::REFERENCES, self::PUBLISH])->delete();
        Role::query()->where('name', 'ca_supervisor')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::spy();

        $this->migration()->up();

        $this->assertSame(2, DB::table('permissions')->whereIn('name', [self::REFERENCES, self::PUBLISH])->count());
        $this->assertTrue(Role::query()->where('name', 'ca_supervisor')->doesntExist());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ($context['role'] ?? null) === 'ca_supervisor')->once();
    }
}
