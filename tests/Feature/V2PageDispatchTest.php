<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\UiVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** Version 2 page dispatch: the same Inertia::render() call gets the V2 page only for a person in V2, for a registered page. */
class V2PageDispatchTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['v2-pages.pages' => ['Pricing/Index']]);
        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::get('/_v2-test/registered', fn () => Inertia::render('Pricing/Index', ['x' => 1]));
            Route::get('/_v2-test/other', fn () => Inertia::render('Pricing/PriceCheck', ['x' => 1]));
        });
    }

    private function switchOn($user): void
    {
        DB::table('user_space_preferences')->insert(['user_id' => $user->id, 'enabled' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_classic_person_gets_the_classic_page(): void
    {
        $this->selectBranch($this->actor('ca'));

        $this->get('/_v2-test/registered')->assertInertia(fn (Assert $p) => $p->component('Pricing/Index'));
    }

    public function test_a_person_in_v2_gets_the_v2_page_for_a_registered_page_with_the_same_props(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);
        $this->switchOn($user);

        $this->get('/_v2-test/registered')->assertInertia(fn (Assert $p) => $p->component('Pricing/V2/Index', false)->where('x', 1));
    }

    public function test_a_page_that_is_not_registered_is_never_replaced_and_its_response_is_identical(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);

        $classic = $this->get('/_v2-test/other')->viewData('page');
        $this->switchOn($user);
        config(['v2-pages.pages' => []]);
        $registryEmpty = $this->get('/_v2-test/other')->viewData('page');
        config(['v2-pages.pages' => ['Pricing/Index']]);
        $registered = $this->get('/_v2-test/other')->viewData('page');

        $this->assertSame('Pricing/PriceCheck', $registered['component']);
        $this->assertSame($registryEmpty['component'], $registered['component']);
        $this->assertSame($registryEmpty['props']['x'], $registered['props']['x']);
        $this->assertSame($classic['component'], $registered['component']);
    }

    public function test_the_waiting_room_tv_account_is_never_v2(): void
    {
        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->switchOn($tv);

        $resolver = app(UiVersionResolver::class);
        $this->assertFalse($resolver->isV2($tv, Request::create('/spaces/me')));
        $this->assertSame('Pricing/Index', $resolver->pageFor('Pricing/Index', $tv, Request::create('/spaces/me')));
    }

    public function test_a_space_page_is_v2_even_without_the_switch(): void
    {
        $user = $this->actor('ca');
        $resolver = app(UiVersionResolver::class);

        $this->assertTrue($resolver->isV2($user, Request::create('/spaces/me')));
        $this->assertFalse($resolver->isV2($user, Request::create('/registration')));
        $this->assertSame('Pricing/V2/Index', $resolver->pageFor('Pricing/Index', $user, Request::create('/spaces/me')));
    }

    public function test_the_preference_is_read_once_per_request(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);
        $this->switchOn($user);

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'user_space_preferences')) {
                $reads++;
            }
        });
        $this->get('/_v2-test/registered')->assertOk();

        $this->assertSame(1, $reads);
    }

    public function test_an_unregistered_page_costs_no_extra_read_for_the_dispatch(): void
    {
        $user = $this->actor('ca');
        $this->selectBranch($user);

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'user_space_preferences')) {
                $reads++;
            }
        });
        $this->get('/_v2-test/other')->assertOk();

        // The only read is the shared page props': the dispatch checks the registry first and never asks.
        $this->assertSame(1, $reads);
    }

    public function test_every_registered_page_has_its_v2_file_and_every_v2_file_is_registered(): void
    {
        // The real registry in config/v2-pages.php, not the one this test class overrides in setUp.
        $registered = collect((require base_path('config/v2-pages.php'))['pages'])->sort()->values()->all();

        $files = collect(File::allFiles(resource_path('js/pages')))
            ->map(fn ($file) => str_replace('\\', '/', $file->getRelativePathname()))
            ->filter(fn ($path) => preg_match('#(^|/)V2/[^/]+\.vue$#', $path) === 1)
            ->map(fn ($path) => preg_replace('#/V2/([^/]+)\.vue$#', '/$1', $path))
            ->sort()
            ->values()
            ->all();

        foreach ($registered as $page) {
            $at = strrpos($page, '/');
            $file = resource_path('js/pages/'.substr($page, 0, $at).'/V2'.substr($page, $at).'.vue');
            $this->assertFileExists($file, "{$page} is registered but has no V2 file");
        }

        $this->assertSame($registered, $files);
    }
}
