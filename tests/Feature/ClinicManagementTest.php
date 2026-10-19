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
            '/medical-certificate-templates' => ['catalogue.settings.manage.organisation'],
            '/clinician-credentials' => ['clinician_credentials.manage.organisation'],
            '/clinician-credentials/me' => ['encounters.view.own'],
            '/registration' => ['visits.view.branch'],
            '/queue' => ['queue.view.own', 'queue.view.branch'],
            '/registration-review' => ['public_intakes.review.branch'],
            '/payment-reconciliations' => ['payments.reconcile.branch'],
            '/business-details' => ['catalogue.settings.manage.organisation'],
            '/public-checkin-links' => ['public_checkin_links.manage.organisation', 'public_checkin_links.manage.branch'],
            '/registration-settings' => ['catalogue.settings.manage.organisation'],
            '/consultation-settings' => ['catalogue.settings.manage.organisation'],
            '/checkout-settings' => ['panels.manage.organisation'],
            '/payment-methods' => ['payment_methods.manage.organisation'],
            '/billing-approval-limits' => ['billing.approval_limits.manage.organisation'],
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
            '/panel-responsibility' => ['panel.work.view.branch'],
            '/panel-invoices' => ['panel.invoices.export.branch'],
            '/staff' => ['staff.view.own', 'staff.view.branch', 'staff.view.organisation'],
            '/branches' => ['branches.view.branch', 'branches.view.organisation'],
            '/access-control' => ['access.view.organisation'],
            '/audit-logs' => ['audit.view.organisation'],
        ];
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $expected = collect($permissions)->filter(fn ($abilities) => $actor->hasAnyPermission($abilities))
                ->keys()->sort()->values()->all();
            if (! $actor->hasAnyPermission(['billing.view.branch', 'billing.summary.branch'])) {
                $expected = array_values(array_diff($expected, ['/financial-work', '/panel-responsibility']));
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
                foreach (['/patients', '/financial-work', '/panel-invoices', '/panel-claims', '/panel-responsibility', '/registration', '/queue', '/registration-review', '/payment-reconciliations'] as $denied) {
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

    public function test_ca_supervisor_sees_both_catalogues_pricing_and_queue_display_settings(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('groups', function ($groups): bool {
                $hrefs = collect($groups)->flatMap(fn ($group) => $group['links'])->pluck('href')->all();
                foreach (['/medicines', '/clinical-services', '/pricing', '/queue-display-settings'] as $expected) {
                    $this->assertContains($expected, $hrefs);
                }

                return true;
            }));
        foreach (['/medicines', '/clinical-services', '/pricing', '/queue-display-settings'] as $destination) {
            $this->get($destination)->assertOk();
        }
    }

    public function test_added_links_use_the_existing_permissions_and_card_locations(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('groups', function ($groups): bool {
                $cards = collect($groups)->keyBy('title');
                foreach (['In-clinic' => ['/public-checkin-links', '/billing-approval-limits'],
                    'Staff & branches' => ['/access-control'], 'Panel' => ['/panel-responsibility']] as $card => $links) {
                    foreach ($links as $link) {
                        $this->assertContains($link, collect($cards[$card]['links'])->pluck('href')->all());
                    }
                }

                return true;
            }));

        // Workspace permission alone is insufficient: the existing summary/view gate is also required.
        $actor->syncRoles([]);
        $actor->givePermissionTo('panel.work.view.branch');
        $this->get('/clinic-management')->assertForbidden();
        $this->get('/panel-responsibility')->assertForbidden();
        $actor->givePermissionTo('billing.summary.branch');
        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.title', 'Panel')->where('groups.0.links.0.href', '/panel-responsibility'));
        $this->get('/panel-responsibility')->assertOk();
    }

    public function test_business_details_hub_link_is_visible_and_reachable_for_only_the_agreed_catalogue_roles(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $allowed = in_array($role, ['director', 'administrator', 'finance_officer', 'panel_officer'], true);
            $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('workspace.navigation.businessDetails', $allowed)
                ->where('groups', function ($groups) use ($allowed): bool {
                    $links = collect($groups)->flatMap(fn ($group) => $group['links'])->where('href', '/business-details');
                    $this->assertCount($allowed ? 1 : 0, $links);
                    if ($allowed) {
                        $this->assertSame('Business details', $links->sole()['title']);
                        $this->assertContains('/business-details', collect(collect($groups)->keyBy('title')['In-clinic']['links'])->pluck('href')->all());
                    }

                    return true;
                }));
            $response = $this->get('/business-details');
            if ($allowed) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_business_details_hub_link_uses_the_existing_permission_rather_than_a_role_name(): void
    {
        $actor = $this->actor('ca');
        $actor->givePermissionTo('catalogue.settings.manage.organisation');
        $this->selectBranch($actor);
        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.links.0.href', '/business-details'));
        $this->get('/business-details')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
        $actor->revokePermissionTo('catalogue.settings.manage.organisation');
        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('groups', function ($groups): bool {
                $this->assertNotContains('/business-details', collect($groups)->flatMap(fn ($group) => $group['links'])->pluck('href')->all());

                return true;
            }));
        $this->get('/business-details')->assertForbidden();
    }

    public function test_daily_work_links_keep_the_existing_destination_access_for_every_role(): void
    {
        $routes = [
            '/registration' => ['visits.view.branch'],
            '/queue' => ['queue.view.own', 'queue.view.branch'],
            '/payment-reconciliations' => ['payments.reconcile.branch'],
            '/registration-review' => ['public_intakes.review.branch'],
        ];
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            foreach ($routes as $url => $permissions) {
                $response = $this->get($url);
                if ($actor->hasAnyPermission($permissions)) {
                    if ($url === '/registration-review') {
                        $response->assertRedirect(route('registration.index', ['tab' => 'qr-intake']));
                    } else {
                        $response->assertOk();
                    }
                } else {
                    $response->assertForbidden();
                }
            }
        }
    }
}
