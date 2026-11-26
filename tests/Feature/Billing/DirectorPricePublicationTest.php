<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Feature\Clinical\ClinicalTestCase;

final class DirectorPricePublicationTest extends ClinicalTestCase
{
    private const PUBLISH = 'prices.publish.organisation';

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_26_250100_grant_director_price_publication_additively.php');
    }

    public function test_migration_is_additive_repeatable_and_preserves_staff_authority(): void
    {
        $director = Role::findByName('director', 'web');
        $director->revokePermissionTo(self::PUBLISH);
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $otherBefore = DB::table('role_has_permissions')->where('role_id', '!=', $director->id)->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $authority = PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS;
        $migration = $this->migration();
        $migration->up();
        $after = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->assertNotSame($before, $after);
        $this->assertTrue($director->fresh()->hasPermissionTo(self::PUBLISH));
        $this->assertSame($otherBefore, DB::table('role_has_permissions')->where('role_id', '!=', $director->id)->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $migration->up();
        $migration->down();
        $this->assertSame($after, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertSame($authority, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(self::PUBLISH));
    }

    public function test_migration_does_not_create_a_missing_director_role(): void
    {
        Role::findByName('director', 'web')->delete();
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->migration()->up();
        $this->assertFalse(Role::query()->where('name', 'director')->where('guard_name', 'web')->exists());
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }
}
