<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\DepartmentSpace\UiVersionResolver;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-2: the per-person "Try the new layout" switch. */
class SpacePreferenceTest extends VisitTestCase
{
    public function test_default_is_off_and_every_staff_account_but_the_tv_is_offered_the_switch(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p
            ->where('workspace.spaces', ['offered' => true, 'enabled' => false, 'lockVersion' => 0]));

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p
            ->where('workspace.spaces', ['offered' => true, 'enabled' => false, 'lockVersion' => 0]));
        $this->put('/spaces/preference', ['enabled' => true, 'lock_version' => 0])->assertRedirect(route('spaces.index'));
        $this->assertTrue((bool) DB::table('user_space_preferences')->where('user_id', $ca->id)->value('enabled'));

        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->put('/spaces/preference', ['enabled' => true, 'lock_version' => 0])->assertForbidden();
        $this->assertSame(0, DB::table('user_space_preferences')->where('user_id', $tv->id)->count());
    }

    public function test_switching_on_and_off_is_versioned_audited_and_rejects_a_stale_page(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $this->put('/spaces/preference', ['enabled' => true, 'lock_version' => 0])->assertRedirect(route('spaces.index'));
        $this->assertTrue((bool) DB::table('user_space_preferences')->where('user_id', $director->id)->value('enabled'));

        $this->put('/spaces/preference', ['enabled' => false, 'lock_version' => 0])->assertSessionHasErrors('enabled');
        $this->put('/spaces/preference', ['enabled' => false, 'lock_version' => 1])->assertRedirect();
        $row = DB::table('user_space_preferences')->where('user_id', $director->id)->first();
        $this->assertFalse((bool) $row->enabled);
        $this->assertSame(2, (int) $row->lock_version);
        $this->assertSame(2, AuditLog::query()->where('event', 'space_preference.changed')->count());
    }

    public function test_use_the_old_layout_works_from_a_space_page_even_when_the_switch_was_never_on(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $this->put('/spaces/preference', ['enabled' => false, 'lock_version' => 0])->assertRedirect('/workspace');
        $this->assertFalse((bool) DB::table('user_space_preferences')->where('user_id', $director->id)->value('enabled'));
    }

    public function test_new_look_is_a_separate_default_off_switch_that_needs_version_2_and_changes_nothing_else(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        // Off by default; the existing `spaces` shape is unchanged.
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p
            ->where('workspace.newLook', false)
            ->where('workspace.spaces', ['offered' => true, 'enabled' => false, 'lockVersion' => 0]));

        // Turning the new layout on does not turn the look on.
        $this->put('/spaces/preference', ['enabled' => true, 'lock_version' => 0])->assertRedirect(route('spaces.index'));
        $this->assertFalse((bool) DB::table('user_space_preferences')->where('user_id', $ca->id)->value('new_look'));

        // The look switch: same endpoint, versioned and audited, and it lands on Me home.
        $this->put('/spaces/preference', ['enabled' => true, 'new_look' => true, 'lock_version' => 1])
            ->assertRedirect(route('spaces.show', ['space' => 'me']));
        $this->assertTrue((bool) DB::table('user_space_preferences')->where('user_id', $ca->id)->value('new_look'));
        $this->app->forgetInstance(UiVersionResolver::class); // the resolver caches per request; a test keeps one app
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p->where('workspace.newLook', true));
        $this->assertTrue(AuditLog::query()->where('event', 'space_preference.changed')->latest('id')->first()->metadata['new_look']);

        // A stale page is refused.
        $this->put('/spaces/preference', ['enabled' => true, 'new_look' => false, 'lock_version' => 1])->assertSessionHasErrors('enabled');

        // Switching back to Classic keeps the saved choice but never shows the look outside Version 2.
        $this->put('/spaces/preference', ['enabled' => false, 'lock_version' => 2])->assertRedirect(route('workspace', absolute: false));
        $this->assertTrue((bool) DB::table('user_space_preferences')->where('user_id', $ca->id)->value('new_look'));
        $this->app->forgetInstance(UiVersionResolver::class);
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p->where('workspace.newLook', false));
    }

    public function test_the_tv_account_cannot_turn_the_look_on(): void
    {
        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->put('/spaces/preference', ['enabled' => true, 'new_look' => true, 'lock_version' => 0])->assertForbidden();
        $this->assertSame(0, DB::table('user_space_preferences')->where('user_id', $tv->id)->count());
    }
}
