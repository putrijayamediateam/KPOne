<?php

namespace Tests\Feature\Catalogue;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * CAT-12 (B.E.R.N.I.S.S for the owner, 2026-11-06; wording and group from Staff Experience): a "Check price list"
 * card in Clinic management, straight after Pricing, for holders of the existing pricing permission.
 */
class PriceCheckCardTest extends VisitTestCase
{
    /** @return list<array{title: string, description: string, href: string}> */
    private function inventoryLinks(string $role): array
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $links = [];
        $this->get('/clinic-management')->assertOk()->assertInertia(function (Assert $page) use (&$links): void {
            $group = collect($page->toArray()['props']['groups'])->firstWhere('title', 'Inventory & services');
            $links = $group['links'] ?? [];
        });

        return $links;
    }

    public function test_the_card_sits_straight_after_pricing_with_the_agreed_wording(): void
    {
        foreach (['director', 'ca_supervisor', 'finance_officer', 'administrator'] as $role) {
            $links = $this->inventoryLinks($role);
            $hrefs = array_column($links, 'href');
            $at = array_search('/pricing/price-check', $hrefs, true);
            $this->assertIsInt($at, $role);
            $this->assertSame('/pricing', $hrefs[$at - 1], $role);
            $this->assertSame(['title' => 'Check price list', 'description' => 'See the price billing uses for every active item, with gaps flagged.', 'href' => '/pricing/price-check'], $links[$at]);
            $this->get('/pricing/price-check')->assertOk();
        }
    }

    public function test_roles_without_the_pricing_permission_do_not_see_it(): void
    {
        foreach (['ca', 'resident_doctor', 'panel_officer'] as $role) {
            $this->assertNotContains('/pricing/price-check', array_column($this->inventoryLinks($role), 'href'), $role);
        }
    }
}
