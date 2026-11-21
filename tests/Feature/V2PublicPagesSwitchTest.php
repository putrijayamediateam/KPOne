<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\UiVersionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * The public (patient-facing) pages have one Version 2 switch for everybody, and it is off. While it is off, every request
 * to a public page gets the Classic page, signed in or not, and a signed-in person's own V2 switch changes nothing there.
 */
class V2PublicPagesSwitchTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No file is ever written under resources/js/pages (a parallel V2PageDispatchTest scans that folder): the
        // "has a V2 file" case uses the real PublicCheckIn/V2/Status page, the "no V2 file" case a listed page that has none.
        config([
            'v2-pages.public_pages' => ['PublicCheckIn/Status', 'PublicProbe/Missing'],
            'v2-pages.pages' => [],
        ]);
        Route::middleware(['web'])->group(function (): void {
            Route::get('/_v2-public/page', fn () => Inertia::render('PublicCheckIn/Status', ['x' => 1]));
            Route::get('/_v2-public/missing', fn () => Inertia::render('PublicProbe/Missing', ['x' => 1]));
        });
    }

    private function switchOn($user): void
    {
        DB::table('user_space_preferences')->insert(['user_id' => $user->id, 'enabled' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_switch_ships_off_and_lists_the_public_qr_pages_that_have_a_v2_page(): void
    {
        $config = require base_path('config/v2-pages.php');

        $this->assertFalse($config['public']);
        $this->assertSame(['PublicCheckIn/Status', 'PublicCheckIn/Show'], $config['public_pages']);
    }

    public function test_with_the_switch_off_a_patient_gets_classic_even_when_the_v2_file_exists(): void
    {
        config(['v2-pages.public' => false]);

        $this->get('/_v2-public/page')->assertInertia(fn (Assert $p) => $p->component('PublicCheckIn/Status', false)->where('x', 1));
    }

    public function test_with_the_switch_off_a_signed_in_v2_person_also_gets_classic(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);
        $this->switchOn($user);
        config(['v2-pages.public' => false]);

        $this->get('/_v2-public/page')->assertInertia(fn (Assert $p) => $p->component('PublicCheckIn/Status', false));
    }

    public function test_only_a_literal_true_turns_it_on(): void
    {

        foreach (['true', 1, 'yes', null] as $value) {
            config(['v2-pages.public' => $value]);
            $this->get('/_v2-public/page')->assertInertia(fn (Assert $p) => $p->component('PublicCheckIn/Status', false));
        }
    }

    public function test_with_the_switch_on_a_page_without_a_v2_file_stays_classic(): void
    {
        config(['v2-pages.public' => true]);

        $this->get('/_v2-public/missing')->assertInertia(fn (Assert $p) => $p->component('PublicProbe/Missing', false));
    }

    public function test_the_real_public_qr_pages_are_classic_with_the_switch_off(): void
    {
        $resolver = app(UiVersionResolver::class);
        config(['v2-pages.public' => false]);

        $this->assertSame('PublicCheckIn/Status', $resolver->pageFor('PublicCheckIn/Status'));
        $this->assertSame('PublicCheckIn/Show', $resolver->pageFor('PublicCheckIn/Show'));
    }
}
