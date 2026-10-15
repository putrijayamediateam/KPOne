<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

class ClinicManagementTest extends VisitTestCase
{
    public function test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role(): void
    {
        $permissions = [
            '/consultation-settings' => ['catalogue.settings.manage.organisation'],
            '/checkout-settings' => ['panels.manage.organisation'],
            '/payment-methods' => ['payment_methods.manage.organisation'],
            '/medicines' => ['medicines.manage.organisation'],
            '/clinical-services' => ['clinical_services.manage.organisation'],
            '/pricing' => ['pricing.references.manage.organisation'],
            '/inventory-services-settings/medicine-details' => ['catalogue.settings.manage.organisation'],
            '/inventory-services-settings/suppliers' => ['catalogue.settings.manage.organisation'],
            '/inventory' => ['inventory.view.branch'],
            '/patients' => ['patients.search.organisation'],
            '/financial-work' => ['finance.work.view.branch'],
            '/queue-display-settings' => ['queue_display.manage.organisation', 'queue_display.manage.branch'],
            '/queue-display' => ['queue.display.branch', 'queue_display.manage.organisation', 'queue_display.manage.branch'],
            '/insights/today' => ['insights.view.organisation'],
            '/panel-claims' => ['panel.claims.manage.branch'],
            '/panel-invoices' => ['panel.invoices.export.branch'],
            '/staff' => ['staff.view.own', 'staff.view.branch', 'staff.view.organisation'],
            '/branches' => ['branches.view.branch', 'branches.view.organisation'],
            '/audit-logs' => ['audit.view.organisation'],
        ];
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $expected = collect($permissions)->filter(fn ($abilities) => $actor->hasAnyPermission($abilities))
                ->keys()->sort()->values()->all();
            if (! $actor->hasAnyPermission(['billing.view.branch', 'billing.summary.branch'])) {
                $expected = array_values(array_diff($expected, ['/financial-work']));
            }
            $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('ClinicManagement/Index')
                ->where('workspace.navigation.clinicManagement', true)
                ->where('groups', function ($groups) use ($expected): bool {
                    $actual = collect($groups)->flatMap(fn ($group) => $group['links'])->pluck('href')->unique()->sort()->values()->all();
                    $this->assertSame($expected, $actual);
                    foreach ($groups as $group) {
                        $this->assertNotEmpty($group['links']);
                        $this->assertSame(['title', 'icon', 'links'], array_keys($group));
                        foreach ($group['links'] as $link) {
                            $this->assertSame(['title', 'description', 'href'], array_keys($link));
                            $this->assertNotEmpty($link['description']);
                        }
                    }

                    return true;
                })
                ->missing('patients')->missing('visits')->missing('records')->missing('financial')->missing('letterhead'));
        }
    }

    public function test_administrative_roles_receive_no_patient_destinations_or_patient_queries(): void
    {
        $patient = $this->patient(overrides: ['full_name' => 'Synthetic Hub Privacy Sentinel']);
        foreach (['administrator', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get('/clinic-management')->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $response->assertDontSee($patient->full_name)->assertDontSee($patient->patient_number);
            $response->assertInertia(fn (Assert $page) => $page->where('groups', function ($groups): bool {
                $hrefs = collect($groups)->flatMap(fn ($group) => $group['links'])->pluck('href')->all();
                foreach (['/patients', '/financial-work', '/panel-invoices', '/panel-claims'] as $denied) {
                    $this->assertNotContains($denied, $hrefs);
                }

                return true;
            }));
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/from\s+["`]?patients["`]?\b/i', $query['query']);
            }
            $this->get('/patients')->assertForbidden();
        }
    }

    public function test_foreign_branch_context_cannot_be_used_by_the_hub_or_destination(): void
    {
        $actor = $this->actor('ca');
        $foreign = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $this->selectBranch($actor, $foreign);
        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('branchContext.active.id', $this->branch->id));
        $this->get('/queue-display-settings')->assertForbidden();
        $this->assertSame($this->branch->id, app(BranchAccessService::class)->activeBranch($actor)?->id);
    }

    public function test_hub_rejects_an_actor_with_no_destination_authority_and_requires_sign_in(): void
    {
        $this->get('/clinic-management')->assertRedirect('/login');
        $actor = $this->actor('ca');
        $actor->syncRoles([]);
        $actor->givePermissionTo('dashboard.view.own');
        $this->selectBranch($actor);
        $this->get('/clinic-management')->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('workspace.navigation.clinicManagement', false));
    }
}
