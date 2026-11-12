<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\UiVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Visit\VisitTestCase;

/** The Version 2 preference is read once per request, however many places ask. */
class UiVersionResolverTest extends VisitTestCase
{
    public function test_a_full_page_render_reads_the_preference_exactly_once(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'user_space_preferences')) {
                $reads++;
            }
        });
        $this->get(route('queue.index'))->assertOk();

        $this->assertSame(1, $reads);
    }

    public function test_asking_again_in_the_same_request_does_not_read_again(): void
    {
        $user = $this->actor('ca');
        $resolver = app(UiVersionResolver::class);

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'user_space_preferences')) {
                $reads++;
            }
        });
        $resolver->state($user);
        $resolver->isV2($user, Request::create('/registration'));
        $resolver->isV2($user, Request::create('/spaces/me'));

        $this->assertSame(1, $reads);
    }

    public function test_the_tv_account_is_never_v2_and_a_space_page_is_v2_without_the_switch(): void
    {
        $tv = $this->actor('queue_display');
        $ca = $this->actor('ca');
        $resolver = app(UiVersionResolver::class);

        $this->assertFalse($resolver->isV2($tv, Request::create('/spaces/me')));
        $this->assertTrue($resolver->isV2($ca, Request::create('/spaces/me')));
        $this->assertFalse($resolver->isV2($ca, Request::create('/registration')));
    }
}
