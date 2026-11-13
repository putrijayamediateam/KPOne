<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\UiVersionResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** UX-24: the V2 sidebar goes with every page that renders in V2 (the switch, or a /spaces page), never a Classic page. */
class V2SpacesShellTest extends VisitTestCase
{
    private function turnOn(User $user): void
    {
        DB::table('user_space_preferences')->insert(['user_id' => $user->id, 'enabled' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_classic_page_never_gets_the_shell(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('ClinicManagement/Index')
            ->where('workspace.spaces.enabled', false)
            ->where('workspace.shell', null));
    }

    public function test_a_space_page_gets_the_shell_with_the_switch_off_and_on(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        foreach (['management', 'me'] as $space) {
            $this->get("/spaces/{$space}")->assertOk()->assertInertia(fn (Assert $p) => $p
                ->component('Spaces/Show')
                ->where('space.key', $space)
                ->where('workspace.spaces.enabled', false)
                ->where('workspace.shell.groups.2.title', 'Operations')
                ->where('workspace.shell.current', null));
        }

        $this->turnOn($director);
        // The resolver keeps each person's switch state for one request; a test reuses the app, so start fresh.
        $this->app->forgetInstance(UiVersionResolver::class);
        $this->get('/staff')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('workspace.shell.groups.0.title', 'Clinic'));
    }

    public function test_the_shell_grants_nothing_a_space_the_person_cannot_enter_is_still_refused(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/management')->assertForbidden();
        $this->get('/spaces/me')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('workspace.shell.groups.0.items.0.key', 'front_desk'));
    }
}
