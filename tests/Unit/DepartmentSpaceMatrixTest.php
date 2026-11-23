<?php

namespace Tests\Unit;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\DepartmentSpace\DepartmentSpaceNavigation;
use PHPUnit\Framework\TestCase;

/** DS-1: the owner's role-to-space map ("agree map", 2026-11-11), pinned for every catalogue role. */
final class DepartmentSpaceMatrixTest extends TestCase
{
    private const MAP = [
        'director' => 'front_desk,pharmacy,billing,consultation,panel,stock,hr,management,me',
        'resident_doctor' => 'consultation,me',
        'ca' => 'front_desk,pharmacy,billing,me',
        'ca_supervisor' => 'front_desk,pharmacy,billing,stock,me',
        'panel_officer' => 'panel,me',
        'finance_officer' => 'billing,panel,stock,me',
        'administrator' => 'stock,management,me',
        'business_development' => 'me',
        'marketing' => 'me',
        'hr_manager' => 'hr,me',
        'technical_admin' => 'management,me',
        'queue_display' => '',
        'locum' => 'me',
        // ROLE-1 (owner 21 Nov 2026): the Account Assistant opens the Panel page, the Store Admin has the stock review right.
        'branch_manager' => 'billing,panel,me', // BM reports B (owner, 22 Nov 2026): Panel summary opens the Panel space, as for the Account Assistant
        'account_assistant' => 'panel,me',
        'store_admin' => 'stock,me',
        // MA-1a (owner, 23 Nov 2026, "MA: approve all recommended"): the Medical Assistant has the CA's functions, so the CA's spaces.
        'medical_assistant' => 'front_desk,pharmacy,billing,me',
    ];

    public function test_every_catalogue_role_gets_exactly_its_spaces(): void
    {
        $roles = PermissionCatalogue::roles();
        $this->assertEqualsCanonicalizing(array_keys(self::MAP), array_keys($roles), 'A new role needs an owner decision on its spaces.');
        foreach ($roles as $role => $perms) {
            $keys = implode(',', array_column(DepartmentSpaceNavigation::forPermissions($perms, $role === 'director', true, $role === 'queue_display'), 'key'));
            $this->assertSame(self::MAP[$role], $keys, $role);
        }
    }

    public function test_no_active_branch_hides_every_branch_space_except_the_directors(): void
    {
        foreach (PermissionCatalogue::roles() as $role => $perms) {
            $keys = array_column(DepartmentSpaceNavigation::forPermissions($perms, $role === 'director', false, $role === 'queue_display'), 'key');
            if ($role === 'director') {
                $this->assertCount(9, $keys);
            } elseif ($role !== 'queue_display') {
                $this->assertSame(['me'], $keys, $role);
            }
        }
    }

    public function test_every_permission_a_space_needs_exists_in_the_catalogue(): void
    {
        $all = array_unique(array_merge(...array_values(PermissionCatalogue::roles())));
        foreach (DepartmentSpaceNavigation::SPACES as $key => [, $needs]) {
            foreach ($needs as $p) {
                $this->assertContains($p, $all, "$key needs unknown permission $p");
            }
        }
    }
}
