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

    public function test_every_registered_page_has_its_v2_file_and_every_v2_file_is_registered(): void
    {
        // The real registry in config/v2-pages.php, not the one this test class overrides in setUp.
        $config = require base_path('config/v2-pages.php');
        // Staff pages follow each person's own switch (`pages`); public (patient-facing) pages have one switch for everybody
        // (`public_pages`). A page is in exactly one of the two lists.
        $this->assertSame([], array_values(array_intersect($config['pages'], $config['public_pages'])));
        $registered = collect([...$config['pages'], ...$config['public_pages']])->sort()->values()->all();

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
