<?php

namespace Tests\Feature;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\DepartmentSpace\V2ShellNavigation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** UX-21: the Version 2 Clinic management hub. Same links and permissions as Classic; V2 sentence-case labels only in V2. */
class V2ClinicHubTest extends VisitTestCase
{
    private function turnOn(User $user): void
    {
        DB::table('user_space_preferences')->insert(['user_id' => $user->id, 'enabled' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<int, array{title: string, icon: string, links: list<array{title: string, description: string, href: string}>}>  $groups
     * @return list<string>
     */
    private function rows(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['links'] as $link) {
                $rows[] = $group['title'].'|'.$link['href'].'|'.$link['title'].'|'.$link['description'];
            }
        }

        return $rows;
    }

    /** @return array<int, array{title: string, icon: string, links: list<array{title: string, description: string, href: string}>}> */
    private function groupsOf(Assert $page): array
    {
        /** @var array<int, array{title: string, icon: string, links: list<array{title: string, description: string, href: string}>}> $groups */
        $groups = $page->toArray()['props']['groups'];

        return $groups;
    }

    public function test_classic_keeps_its_page_and_its_original_titles(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $hub = app(ClinicManagementNavigation::class)->groups($ca);

        $this->get('/clinic-management')->assertOk()->assertInertia(function (Assert $page) use ($hub): void {
            $page->component('ClinicManagement/Index');
            $this->assertSame($this->rows($hub), $this->rows($this->groupsOf($page)));
        });
        // A Classic title the V2 labels would change is still sent unchanged.
        $this->assertContains('QR Intake', array_column(array_merge(...array_column($hub, 'links')), 'title'));
    }

    public function test_version_2_gets_the_v2_page_with_the_same_links_in_sentence_case(): void
    {
        foreach (['ca', 'director', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->turnOn($actor);
            $hub = app(ClinicManagementNavigation::class)->groups($actor);
            foreach ($hub as &$group) {
                foreach ($group['links'] as &$link) {
                    $link['title'] = V2ShellNavigation::label($link['title']);
                }
                unset($link);
            }
            unset($group);

            $this->actingAs($actor)->get('/clinic-management')->assertOk()->assertInertia(function (Assert $page) use ($hub, $role): void {
                $page->component('ClinicManagement/V2/Index');
                $this->assertSame($this->rows($hub), $this->rows($this->groupsOf($page)), $role);
            });
        }

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->turnOn($ca);
        $this->get('/clinic-management')->assertInertia(function (Assert $page): void {
            $titles = array_column(array_merge(...array_column($this->groupsOf($page), 'links')), 'title');
            $this->assertContains('QR intake', $titles);
            $this->assertNotContains('QR Intake', $titles);
        });
    }

    public function test_version_2_grants_nothing_a_person_without_hub_links_still_gets_403(): void
    {
        $actor = $this->actor('ca');
        $actor->syncRoles([]);
        $actor->givePermissionTo('dashboard.view.own');
        $this->selectBranch($actor);
        $this->turnOn($actor);

        $this->get('/clinic-management')->assertForbidden();
    }
}
