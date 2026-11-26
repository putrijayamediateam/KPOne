<?php

namespace Tests\Feature\Access;

use App\Domain\Access\ShellMenu;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * Clean Look shell: the rail's areas and panels come from the permission-filtered Clinic management rows, so a link shows only for
 * someone who may open its page, and an area with no visible link is left out. Today is always first.
 */
class ShellMenuTest extends VisitTestCase
{
    /** @return array<string, list<string>> */
    private function hrefsByArea(object $actor): array
    {
        $out = [];
        foreach (app(ShellMenu::class)->for($actor) as $area) {
            $out[$area['key']] = array_merge(...array_map(fn (array $s): array => array_column($s['links'], 'href'), $area['sections']));
        }

        return $out;
    }

    public function test_today_is_first_and_a_ca_gets_front_desk_and_billing_links_only_for_what_they_may_open(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $areas = app(ShellMenu::class)->for($ca);

        $this->assertSame('today', $areas[0]['key']);
        $this->assertSame('/my-day', $areas[0]['href']);
        $byArea = $this->hrefsByArea($ca);
        $this->assertContains('/registration', $byArea['front_desk']);
        $this->assertContains('/registration?tab=dispensary', $byArea['dispensary']);
        $this->assertContains('/registration?tab=billing', $byArea['billing']);
        $this->assertArrayNotHasKey('hr_admin', $byArea);
        $this->assertNotContains('/hr/payroll', array_merge(...array_values($byArea)));
    }

    public function test_a_person_with_no_clinic_pages_gets_today_only_and_every_area_has_a_link(): void
    {
        $marketing = $this->actor('marketing');
        $this->selectBranch($marketing);
        foreach (app(ShellMenu::class)->for($marketing) as $area) {
            $this->assertNotEmpty($area['href']);
            $this->assertNotEmpty($area['sections']);
            foreach ($area['sections'] as $section) {
                $this->assertNotEmpty($section['links']);
            }
        }
        $this->assertNotContains('/registration', array_merge(...array_values($this->hrefsByArea($marketing))));
    }

    public function test_the_menu_is_shared_with_every_page_as_the_shell_menu_prop(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('workspace.shellMenu.0.key', 'today')->has('workspace.shellMenu.0.sections.0.links.0.href'));
    }
}
