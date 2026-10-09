<?php

namespace Tests\Feature\Visit;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\BillingTestCase;

/**
 * VH-01 (owner decision, 2026-10-08): every role that can see Registration and Consultation
 * can open the read-only history of a completed visit at its own branch.
 */
class VisitHistoryTest extends BillingTestCase
{
    private const PERMISSION = 'visits.history.view.branch';

    /** @return array{0: Visit, 1: User} */
    private function completedVisit(): array
    {
        [, $ca, $visit] = $this->finalizedFixture();
        // Synthetic initial state: the history page only reads a completed visit.
        $visit->forceFill(['status' => Visit::STATUS_COMPLETED, 'completed_at' => now()])->save();

        return [$visit->refresh(), $ca];
    }

    public function test_exactly_the_registration_and_consultation_roles_hold_the_permission(): void
    {
        $holders = collect(PermissionCatalogue::roles())
            ->filter(fn (array $permissions): bool => in_array(self::PERMISSION, $permissions, true))
            ->keys()->sort()->values()->all();

        $this->assertSame(['ca', 'ca_supervisor', 'director', 'resident_doctor'], $holders);
        $this->assertNotContains(self::PERMISSION, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
    }

    public function test_a_ca_sees_the_consultation_items_services_and_financial_summary(): void
    {
        [$visit, $ca] = $this->completedVisit();

        $this->actingAs($ca)->get(route('visits.history', $visit))
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Visits/History')
                ->where('history.visit.visitNumber', $visit->visit_number)
                ->where('history.patient.patientNumber', $visit->patient->patient_number)
                ->has('history.consultation')
                ->has('history.financial.lines', 1)
                ->where('history.financial.state.total', 4000)
                ->where('history.canSeeFinancial', true));
    }

    public function test_a_doctor_sees_the_clinical_history_but_no_financial_content(): void
    {
        [$visit] = $this->completedVisit();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor, $visit->branch);

        $this->actingAs($doctor)->get(route('visits.history', $visit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Visits/History')
                ->has('history.consultation')
                ->where('history.financial', null)
                ->where('history.canSeeFinancial', false)
                ->where('history.links.billing', null));
    }

    public function test_other_roles_are_forbidden_and_the_response_has_no_clinical_content(): void
    {
        [$visit] = $this->completedVisit();

        foreach (['technical_admin', 'marketing', 'business_development', 'hr_manager', 'panel_officer', 'finance_officer', 'queue_display'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $visit->branch);
            $this->actingAs($actor)->get(route('visits.history', $visit))->assertForbidden();
        }
    }

    public function test_only_a_completed_visit_at_the_active_branch_opens(): void
    {
        [$visit, $ca] = $this->completedVisit();

        $visit->forceFill(['status' => Visit::STATUS_REGISTERED, 'completed_at' => null])->save();
        $this->actingAs($ca)->get(route('visits.history', $visit))->assertNotFound();

        $visit->forceFill(['status' => Visit::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $otherBranch = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '<>', $visit->branch_id)->firstOrFail();
        $other = $this->actor('ca', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $this->actingAs($other)->get(route('visits.history', $visit))->assertNotFound();
    }

    public function test_a_completed_registration_row_carries_the_history_link_and_others_do_not(): void
    {
        [$visit, $ca] = $this->completedVisit();

        $row = fn () => collect($this->actingAs($ca)->postJson(route('registration.search'), ['board_status' => 'all'])
            ->assertOk()->json('data'))->firstWhere('visitNumber', $visit->visit_number);

        $this->assertSame(route('visits.history', $visit), $row()['historyUrl']);

        $visit->forceFill(['status' => Visit::STATUS_REGISTERED, 'completed_at' => null])->save();
        $this->assertNull($row()['historyUrl']);
    }

    public function test_migration_grants_the_four_roles_only_and_never_removes(): void
    {
        $roles = ['director', 'resident_doctor', 'ca', 'ca_supervisor'];
        foreach ($roles as $name) {
            Role::query()->where('name', $name)->firstOrFail()->revokePermissionTo(self::PERMISSION);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $count = fn (): array => DB::table('roles')
            ->leftJoin('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->groupBy('roles.name')->selectRaw('roles.name as name, count(role_has_permissions.permission_id) as c')
            ->pluck('c', 'name')->map(fn ($c) => (int) $c)->all();
        $before = $count();
        $migration = require base_path('database/migrations/2026_10_08_000200_grant_visit_history_view_additively.php');

        $migration->up();

        foreach ($count() as $name => $total) {
            $this->assertSame($before[$name] + (in_array($name, $roles, true) ? 1 : 0), $total, "{$name} changed unexpectedly");
        }
        $migration->up();
        $migration->down();
        $migration->up();
        $this->assertSame(array_map(fn ($c) => $c, $count()), $count());

        DB::table('permissions')->where('name', self::PERMISSION)->delete();
        Role::query()->whereIn('name', $roles)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::spy();
        $migration->up();
        $this->assertSame(1, DB::table('permissions')->where('name', self::PERMISSION)->count());
        $this->assertTrue(Role::query()->whereIn('name', $roles)->doesntExist());
        Log::shouldHaveReceived('warning')->times(4);
    }
}
