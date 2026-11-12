<?php

namespace Tests\Feature;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\DepartmentSpace\V2ShellNavigation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** UX-17: the Version 2 sidebar shell. Sent only when "Try the new layout" is on, and only with pages the person can open. */
class V2ShellNavigationTest extends VisitTestCase
{
    /** @return array{groups: list<array{title: string, items: list<array{key: string, title: string, href: string, links: list<array{title: string, href: string}>}>}>, more: list<array{title: string, links: list<array{title: string, href: string}>}>, current: array{key: string, title: string, href: string}|null} */
    private function shellFor(User $user): array
    {
        return app(V2ShellNavigation::class)->for($user, 'registration/create');
    }

    /**
     * @param  array{more: list<array{title: string, links: list<array{title: string, href: string}>}>}  $shell
     * @return list<string>
     */
    private function moreHrefs(array $shell): array
    {
        $hrefs = [];
        foreach ($shell['more'] as $section) {
            foreach ($section['links'] as $link) {
                $hrefs[] = $link['href'];
            }
        }

        return $hrefs;
    }

    private function turnOn(User $user): void
    {
        DB::table('user_space_preferences')->insert(['user_id' => $user->id, 'enabled' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_classic_gets_no_shell_and_nothing_else_changes(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/registration/create')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('workspace.spaces.enabled', false)
            ->where('workspace.shell', null));
    }

    public function test_a_ca_in_version_2_gets_their_spaces_more_and_the_front_desk_crumb_on_quick_create(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->turnOn($ca);

        $this->get('/registration/create')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('workspace.shell.groups.0.title', 'Clinic')
            ->where('workspace.shell.groups.0.items.0.key', 'front_desk')
            ->where('workspace.shell.groups.0.items.0.href', '/spaces/front_desk')
            ->where('workspace.shell.current', ['key' => 'front_desk', 'title' => 'Front desk', 'href' => '/spaces/front_desk']));

        $shell = $this->shellFor($ca);
        $frontDesk = array_column($shell['groups'][0]['items'][0]['links'], 'href');
        // Front desk pages sit under Front desk, not under More (Daniel, 2026-11-12).
        $this->assertContains('/registration', $frontDesk);
        $this->assertContains('/registration-review', $frontDesk);
        $this->assertNotContains('/registration', $this->moreHrefs($shell));
        // A CA cannot enter Consultation, so the Consultation queue stays under More rather than vanishing.
        $this->assertContains('/queue', $this->moreHrefs($shell));
        // Nothing is lost or added: the sidebar holds exactly the person's Clinic management links.
        $hub = [];
        foreach (app(ClinicManagementNavigation::class)->groups($ca) as $group) {
            foreach ($group['links'] as $link) {
                $hub[] = $link['href'].'|'.$link['title'];
            }
        }
        $sidebar = [];
        foreach ($shell['groups'] as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['links'] as $link) {
                    $sidebar[] = $link['href'].'|'.$link['title'];
                }
            }
        }
        foreach ($shell['more'] as $section) {
            foreach ($section['links'] as $link) {
                $sidebar[] = $link['href'].'|'.$link['title'];
            }
        }
        sort($hub);
        sort($sidebar);
        $this->assertSame($hub, $sidebar);

        // A CA has no Stock or Management space, so neither appears anywhere in the sidebar groups.
        $this->get('/registration/create')->assertInertia(function (Assert $p): void {
            /** @var list<array{items: list<array{key: string}>}> $groups */
            $groups = $p->toArray()['props']['workspace']['shell']['groups'];
            $keys = array_merge(...array_map(fn (array $g) => array_column($g['items'], 'key'), $groups));
            $this->assertNotContains('stock', $keys);
            $this->assertNotContains('management', $keys);
            $this->assertNotContains('me', $keys);
            /** @var list<array{links: list<array{href: string}>}> $more */
            $more = $p->toArray()['props']['workspace']['shell']['more'];
            $hrefs = array_merge(...array_map(fn (array $m) => array_column($m['links'], 'href'), $more));
            // Pages a CA cannot open never appear in "More".
            $this->assertNotContains('/business-details', $hrefs);
            $this->assertNotContains('/access-control', $hrefs);
        });

        // The Business details page itself still refuses the CA; the sidebar grants nothing.
        $this->get('/business-details')->assertForbidden();
    }

    public function test_the_director_gets_all_three_groups_and_a_page_outside_any_space_has_no_crumb(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->turnOn($director);

        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('workspace.shell.groups.0.title', 'Clinic')
            ->where('workspace.shell.groups.1.title', 'Money')
            ->where('workspace.shell.groups.2.title', 'Operations')
            ->has('workspace.shell.groups.2.items', 3)
            ->where('workspace.shell.current', null));
    }

    public function test_a_crumb_names_only_a_space_the_person_may_enter(): void
    {
        // A Finance Officer may open Billing work but has no Front desk space, so a Registration path gives no crumb.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->turnOn($finance);

        $shell = app(V2ShellNavigation::class)->for($finance, 'registration/create');
        $this->assertNull($shell['current']);
        $this->assertSame('billing', app(V2ShellNavigation::class)->for($finance, 'billing/work')['current']['key'] ?? null);
    }
}
