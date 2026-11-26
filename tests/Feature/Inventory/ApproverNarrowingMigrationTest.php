<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** STK-50 and STK-51: the revoke migration takes away exactly two grants, and its down() gives them back. */
final class ApproverNarrowingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_07_003800_narrow_purchase_order_and_supplier_invoice_approvers.php');
    }

    private function holds(string $role, string $permission): bool
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::findByName($role)->fresh()->hasPermissionTo($permission);
    }

    public function test_up_revokes_only_the_two_grants_and_down_restores_them(): void
    {
        $po = 'inventory.purchase_orders.approve.organisation';
        $bill = 'inventory.supplier_invoices.approve.branch';
        $this->assertFalse($this->holds('finance_officer', $po));
        $this->assertFalse($this->holds('director', $bill));
        $this->assertTrue($this->holds('director', $po));
        $this->assertTrue($this->holds('finance_officer', $bill));
        $before = Role::query()->with('permissions')->get()->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])->all();

        $this->migration()->down();
        $this->assertTrue($this->holds('finance_officer', $po));
        $this->assertTrue($this->holds('director', $bill));

        $this->migration()->up();
        $this->migration()->up();
        $after = Role::query()->with('permissions')->get()->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])->all();
        $this->assertSame($before, $after);
    }
}
