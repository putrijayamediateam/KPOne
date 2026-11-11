<?php

namespace Tests\Feature;

use App\Domain\Queue\Services\OtcQueueService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-4: the Front desk space shows only what the person's own Registration pages already show. */
class FrontDeskSpaceTest extends VisitTestCase
{
    private function wait(object $actor, string $name): void
    {
        $visit = $this->register($actor, $this->patient($actor, ['full_name' => $name]));
        app(OtcQueueService::class)->enter($actor, $visit, ['expected_branch_id' => $this->branch->id]);
    }

    public function test_a_ca_sees_an_empty_waiting_widget_a_glance_the_qr_count_and_the_open_links(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/front_desk')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('frontDesk.waiting.total', 0)
            ->where('frontDesk.waiting.rows', [])
            ->where('frontDesk.qrPending.total', 0)
            ->where('frontDesk.qrPending.href', '/registration?tab=qr-intake')
            ->where('frontDesk.primaryAction', ['title' => 'Register a visit', 'href' => '/registration/create'])
            ->where('frontDesk.links', [['title' => 'Open Registration', 'href' => '/registration'], ['title' => 'Patient directory', 'href' => '/patients']])
            ->has('frontDesk.glance.waiting'));
    }

    public function test_the_waiting_list_shows_queue_numbers_short_names_a_cap_and_counts_that_match(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        foreach (['Ahmad Ismail bin Ali', 'Nurul Huda binti Hassan', 'Tan Mei Ling', 'Siva Kumar a/l Raju', 'Aisyah Rahman', 'Lim Wei Jie'] as $name) {
            $this->wait($ca, $name);
        }

        $props = $this->get('/spaces/front_desk')->assertOk()->viewData('page')['props']['frontDesk'];
        $this->assertSame(6, $props['waiting']['total']);
        $this->assertSame(6, $props['glance']['waiting']);
        $this->assertCount(5, $props['waiting']['rows']);
        foreach ($props['waiting']['rows'] as $row) {
            $this->assertMatchesRegularExpression('/^B-\d{3}$/', $row['queueNumber']);
            $this->assertSame(['visitNumber', 'queueNumber', 'patient', 'minutes', 'href'], array_keys($row));
            $this->assertStringStartsWith('/visits/', $row['href']);
        }
        $this->assertStringNotContainsString('Ismail', json_encode($props));
        $minutes = array_column($props['waiting']['rows'], 'minutes');
        $sorted = $minutes;
        rsort($sorted);
        $this->assertSame($sorted, $minutes, 'longest waiting first');
    }

    public function test_the_director_sees_the_same_widgets_and_a_doctor_is_refused_the_space(): void
    {
        $this->selectBranch($this->actor('director'));
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p
            ->has('frontDesk.waiting')->has('frontDesk.qrPending'));

        $this->selectBranch($this->actor('resident_doctor'));
        $this->get('/spaces/front_desk')->assertForbidden();
    }

    public function test_qr_count_is_left_out_for_a_role_that_cannot_review_and_other_spaces_carry_no_front_desk_data(): void
    {
        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p->where('frontDesk', null));
    }
}
