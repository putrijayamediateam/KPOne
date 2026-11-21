<?php

namespace Tests\Feature;

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
