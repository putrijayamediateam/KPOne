<?php

namespace Tests\Feature\Insights;

use App\Domain\Visit\Models\Visit;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\BillingTestCase;

class TodayInsightsTest extends BillingTestCase
{
    public function test_all_staff_roles_can_view_the_organisation_insights_page(): void
    {
        foreach ([
            'director',
            'resident_doctor',
            'ca',
            'ca_supervisor',
            'panel_officer',
            'finance_officer',
            'business_development',
            'marketing',
            'hr_manager',
            'technical_admin',
        ] as $role) {
            $actor = $this->actor($role);
            $this->actingAs($actor);

            $this->get(route('insights.today'))->assertOk();
            $this->assertTrue($actor->can('insights.view.organisation'));
        }
    }

    public function test_permission_migration_grants_existing_roles_additively_and_is_repeatable(): void
    {
        $roleNames = [
            'director',
            'resident_doctor',
            'ca',
            'ca_supervisor',
            'panel_officer',
            'finance_officer',
            'business_development',
            'marketing',
            'hr_manager',
            'technical_admin',
        ];

        foreach ($roleNames as $roleName) {
            Role::query()->where('name', $roleName)->firstOrFail()
                ->revokePermissionTo('insights.view.organisation');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_10_01_000100_sync_insights_permission_additively.php');
        $migration->up();
        $migration->up();
        $migration->down();

        foreach ($roleNames as $roleName) {
            $this->assertTrue(
                Role::query()->where('name', $roleName)->firstOrFail()
                    ->hasPermissionTo('insights.view.organisation'),
            );
        }
    }

    public function test_today_report_uses_finalized_sales_and_returns_aggregate_rankings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuala_Lumpur'));
        [$doctor, $ca, , $invoice] = $this->finalizedFixture(12345);
        $actor = $this->actor('marketing');
        $this->actingAs($actor);

        $this->get(route('insights.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Insights/Today')
                ->where('branch', 'all')
                ->has('branches', 3)
                ->where('branches.0.name', 'Klinik Putrijaya Cheras')
                ->where('report.sales.totalSen', $invoice->total_sen)
                ->where('report.sales.newSen', $invoice->total_sen)
                ->where('report.sales.returningSen', 0)
                ->where('report.sales.patientCount', 1)
                ->where('report.sales.averagePerPatientSen', $invoice->total_sen)
                ->has('report.salesTrend', 24)
                ->where('report.rankings.services.0.name', 'Consultation')
                ->where('report.rankings.services.0.salesSen', $invoice->total_sen)
                ->where('report.rankings.providers.0.name', $doctor->name)
                ->where('report.rankings.packages', [])
                ->where('report.date', '1 Oct 2026'));
    }

    public function test_branch_selection_is_organisation_scoped_and_invalid_branches_are_not_found(): void
    {
        $actor = $this->actor('ca');
        $this->actingAs($actor);

        $this->get(route('insights.today', ['branch' => 'PUCHONG']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branch', 'PUCHONG')
                ->where('branches.1.name', 'Klinik Putrijaya Puchong')
                ->where('report.sales.totalSen', 0));

        $this->get(route('insights.today', ['branch' => 'OTHER_ORGANISATION']))->assertNotFound();
    }

    public function test_sales_are_returning_after_a_prior_completed_visit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuala_Lumpur'));
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(7300);
        $yesterday = CarbonImmutable::now('UTC')->setTimezone('Asia/Kuala_Lumpur')->subDay()->startOfDay()->addHours(10)->utc();

        Visit::factory()->create([
            'organisation_id' => $visit->organisation_id,
            'branch_id' => $visit->branch_id,
            'patient_id' => $visit->patient_id,
            'status' => Visit::STATUS_COMPLETED,
            'registered_at' => $yesterday->subMinutes(20),
            'registered_by_user_id' => $ca->id,
            'updated_by_user_id' => $ca->id,
            'completed_at' => $yesterday,
            'completed_by_user_id' => $doctor->id,
            'completion_evidence' => ['source' => 'synthetic-test'],
        ]);

        $actor = $this->actor('marketing');
        $this->actingAs($actor);

        $this->get(route('insights.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.sales.totalSen', $invoice->total_sen)
                ->where('report.sales.newSen', 0)
                ->where('report.sales.returningSen', $invoice->total_sen));
    }
}
