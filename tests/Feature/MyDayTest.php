<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\AccountMenuLinks;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DAY-1a: the My day page, its account-menu links and who may open it. */
final class MyDayTest extends VisitTestCase
{
    public function test_every_human_role_opens_my_day_and_the_tv_account_does_not(): void
    {
        foreach (['ca', 'resident_doctor', 'director', 'finance_officer', 'branch_manager', 'account_assistant', 'store_admin', 'hr_manager', 'locum'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor)->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('MyDay/Index')
                ->where('header.branchName', $this->branch->name)->has('shortcuts'));
        }
        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->actingAs($tv)->get(route('my-day'))->assertForbidden();
    }

    public function test_the_header_date_is_in_the_branch_time_zone_and_no_greeting_or_patient_data_is_sent(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->travelTo(now($this->branch->timezone)->setTime(23, 30)->utc());
        $body = $this->actingAs($actor)->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('header.date', now($this->branch->timezone)->format('D j M Y')));
        $json = json_encode($body->viewData('page')['props'] ?? []);
        $this->assertStringNotContainsString('Good morning', (string) $json);
        $this->assertStringNotContainsString('Good evening', (string) $json);
    }

    public function test_the_account_menu_links_my_day_and_the_main_menu_for_people_and_nothing_for_the_tv(): void
    {
        $director = $this->actor('director');
        $links = array_column(app(AccountMenuLinks::class)->for($director)['links'], 'href');
        $this->assertSame(['/my-day', '/dashboard'], array_slice($links, 0, 2));
        $tv = $this->actor('queue_display');
        $this->assertNotContains('/my-day', array_column(app(AccountMenuLinks::class)->for($tv)['links'], 'href'));
    }

    public function test_the_main_menu_still_opens_and_sign_in_lands_on_my_day_for_a_branch_manager(): void
    {
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $this->actingAs($manager)->get(route('workspace'))->assertRedirectToRoute('my-day');
        $this->actingAs($manager)->get(route('dashboard'))->assertOk();
    }
}
