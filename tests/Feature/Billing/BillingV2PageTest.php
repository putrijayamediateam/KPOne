<?php

namespace Tests\Feature\Billing;

use App\Domain\DepartmentSpace\UiVersionResolver;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** BV2-1: Patient Billing has a Version 2 page; a person in Version 2 gets it with exactly the Classic props. */
class BillingV2PageTest extends BillingTestCase
{
    public function test_a_person_in_version_2_gets_the_v2_billing_page_with_the_same_props(): void
    {
        [, $ca, $visit] = $this->finalizedFixture(0);

        $classic = $this->get(route('billing.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Billing/Show'))
            ->viewData('page');

        DB::table('user_space_preferences')->insert(['user_id' => $ca->id, 'enabled' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // The look is read once per request cycle; the test app keeps it between requests.
        $this->app->forgetInstance(UiVersionResolver::class);
        $v2 = $this->get(route('billing.show', $visit))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('Billing/V2/Show')->where('billing.can.complete', true))
            ->viewData('page');

        $this->assertSame($classic['props']['billing'], $v2['props']['billing']);
    }
}
