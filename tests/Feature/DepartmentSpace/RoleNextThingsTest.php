<?php

namespace Tests\Feature\DepartmentSpace;

use App\Domain\Queue\Services\OtcQueueService;
use Tests\Feature\Visit\VisitTestCase;

/**
 * Clean Look homes: "Your next things", the live strip and the doctor's sales line come only from the boards' own services,
 * so each person sees what they could open on its page, as a queue number and a short name.
 */
class RoleNextThingsTest extends VisitTestCase
{
    private function wait(object $actor, string $name): void
    {
        $visit = $this->register($actor, $this->patient($actor, ['full_name' => $name]));
        app(OtcQueueService::class)->enter($actor, $visit, ['expected_branch_id' => $this->branch->id]);
    }

    public function test_a_ca_gets_waiting_things_with_short_names_and_a_live_strip(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->wait($ca, 'Ahmad Ismail bin Ali');
        $this->wait($ca, 'Tan Mei Ling');

        $response = $this->getJson(route('my-day.next'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $json = $response->json();
        $this->assertSame(2, $json['live']['waiting']);
        $this->assertSame(2, $json['thingsTotal']);
        $this->assertSame('call', $json['things'][0]['kind']);
        $this->assertStringStartsWith('/visits/', $json['things'][0]['href']);
        $this->assertSame(['kind', 'title', 'detail', 'minutes', 'tone', 'notReady', 'href'], array_keys($json['things'][0]));
        $this->assertStringNotContainsString('Ismail', json_encode($json));
        $this->assertNull($json['sales']);
    }

    public function test_the_list_is_capped_and_an_empty_day_is_empty(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->assertSame([], $this->getJson(route('my-day.next'))->json('things'));
        foreach (range(1, 10) as $i) {
            $this->wait($ca, "Patient Number{$i}");
        }
        $json = $this->getJson(route('my-day.next'))->json();
        $this->assertCount(8, $json['things']);
        $this->assertSame(10, $json['thingsTotal']);
    }

    public function test_a_person_without_board_access_gets_nothing_and_the_tv_account_is_refused(): void
    {
        $this->selectBranch($this->actor('marketing'));
        $json = $this->getJson(route('my-day.next'))->assertOk()->json();
        $this->assertNull($json['live']);
        $this->assertSame([], $json['things']);

        $this->selectBranch($this->actor('queue_display'));
        $this->getJson(route('my-day.next'))->assertForbidden();
    }

    public function test_my_day_carries_the_same_block_on_first_load(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn ($p) => $p->has('next.things')->has('next.thingsTotal')->where('next.sales', null));
    }
}
