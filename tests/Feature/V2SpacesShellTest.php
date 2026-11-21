<?php

namespace Tests\Feature;

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
}
