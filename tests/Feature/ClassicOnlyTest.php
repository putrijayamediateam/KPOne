<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\AccountMenuLinks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * CO-1 (owner decision 19 Nov 2026): Classic only. Whatever a person once saved for "Try the new layout", every page is
 * the Classic page, the department spaces render in the Classic layout with no V2 shell, the switch endpoint is gone,
 * and the saved rows are kept untouched (the owner decides later whether to drop them).
 */
class ClassicOnlyTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 07:00', $this->branch->timezone));

        Route::middleware(['web', 'auth'])->get('/_classic-only/registered', fn () => Inertia::render('Pricing/Index', ['x' => 1]));
    }

    /** A person who had switched on V2 and the New look before CO-1. */
    private function savedV2($user): void
    {
        DB::table('user_space_preferences')->insert(['user_id' => $user->id, 'enabled' => true, 'new_look' => true, 'lock_version' => 3, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_person_who_saved_version_2_gets_the_classic_page_and_no_v2_props(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);
        $this->savedV2($user);

        $this->get('/_classic-only/registered')->assertInertia(fn (Assert $p) => $p
            ->component('Pricing/Index')
            ->where('x', 1)
            ->missing('workspace.spaces')
            ->missing('workspace.newLook')
            ->missing('workspace.shell'));

        $this->assertStringNotContainsString('data-ui=', $this->get('/_classic-only/registered')->getContent());
        $this->assertStringNotContainsString('data-look="new"', $this->get('/_classic-only/registered')->getContent());
        // The saved choice is kept, not changed or deleted.
        $this->assertSame(1, DB::table('user_space_preferences')->where('user_id', $user->id)->where('enabled', true)->where('lock_version', 3)->count());
    }

    public function test_a_space_page_is_classic_with_or_without_a_saved_switch(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);

        foreach ([false, true] as $saved) {
            if ($saved) {
                $this->savedV2($user);
            }
            foreach (['/spaces/me', '/spaces/front_desk'] as $url) {
                $response = $this->get($url)->assertOk();
                $response->assertInertia(fn (Assert $p) => $p
                    ->component('Spaces/Show')
                    ->missing('workspace.shell')
                    ->missing('workspace.spaces'));
                $this->assertStringNotContainsString('data-ui=', $response->getContent());
            }
        }
    }

    public function test_the_switch_endpoint_is_gone_and_changes_nothing(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);

        $this->put('/spaces/preference', ['enabled' => true, 'lock_version' => 0])->assertStatus(405);
        $this->assertSame(0, DB::table('user_space_preferences')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'space_preference.changed')->count());
    }

    public function test_the_spaces_row_shows_for_everyone_who_can_enter_a_department_space(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p->where('workspace.accountMenu.spaces', true));
        $this->get('/spaces')->assertRedirect();

        // Marketing has only Me: no Spaces row, and /spaces still lands on Me.
        $marketing = $this->actor('marketing');
        $this->selectBranch($marketing);
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p->where('workspace.accountMenu.spaces', false));
        $this->get('/spaces')->assertRedirect('/spaces/me');

        // The waiting-room TV gets no row and no space.
        $tv = $this->actor('queue_display');
        $this->assertFalse(app(AccountMenuLinks::class)->for($tv)['spaces']);
        $this->actingAs($tv)->get('/spaces/front_desk')->assertForbidden();
    }

    public function test_classic_only_grants_nothing_a_space_the_person_cannot_enter_is_still_refused(): void
    {
        $user = $this->actor('marketing');
        $this->selectBranch($user);
        $this->savedV2($user);

        $this->get('/spaces/pharmacy')->assertForbidden();
    }
}
