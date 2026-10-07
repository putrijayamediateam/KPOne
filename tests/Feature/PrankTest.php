<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

// PRANK: presentation joke only. Delete with the prank branch.
class PrankTest extends VisitTestCase
{
    public function test_the_prank_pages_do_not_exist_unless_the_switch_is_on(): void
    {
        $this->selectBranch($this->actor('ca'));
        $id = (string) Str::uuid();

        $this->assertFalse(config('prank.enabled'));
        foreach (['prank.pharmamax', 'prank.error', 'prank.reveal'] as $name) {
            $this->get(route($name, $id))->assertNotFound();
        }
    }

    public function test_with_the_switch_on_each_page_renders_and_touches_no_data(): void
    {
        config(['prank.enabled' => true]);
        $this->selectBranch($this->actor('ca'));
        $id = (string) Str::uuid();
        $audits = AuditLog::query()->count();

        foreach ([
            'prank.pharmamax' => 'Prank/PharmaMax',
            'prank.error' => 'Prank/DelightfulError',
            'prank.reveal' => 'Prank/Reveal',
        ] as $name => $component) {
            $this->get(route($name, $id))->assertOk()
                ->assertInertia(fn ($page) => $page->component($component)->where('caseId', $id));
        }

        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_the_prank_pages_need_sign_in_and_dispensary_view_permission(): void
    {
        config(['prank.enabled' => true]);
        $id = (string) Str::uuid();

        $this->get(route('prank.pharmamax', $id))->assertRedirect(route('login'));
        $this->selectBranch($this->actor('marketing'));
        $this->get(route('prank.pharmamax', $id))->assertForbidden();
    }
}
