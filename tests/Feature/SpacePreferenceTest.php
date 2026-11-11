<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-2: the per-person "Try the new layout" switch. */
class SpacePreferenceTest extends VisitTestCase
{
    public function test_default_is_off_and_only_the_director_is_offered_the_switch(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p
            ->where('workspace.spaces', ['offered' => true, 'enabled' => false, 'lockVersion' => 0]));

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p
            ->where('workspace.spaces.offered', false));
        $this->put('/spaces/preference', ['enabled' => true, 'lock_version' => 0])->assertForbidden();
        $this->assertSame(0, DB::table('user_space_preferences')->count());
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
}
