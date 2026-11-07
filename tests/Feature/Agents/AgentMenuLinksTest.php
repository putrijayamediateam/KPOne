<?php

namespace Tests\Feature\Agents;

use App\Domain\Organisation\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** AG-11: the AI agent pages appear in Clinic management only for people who may open them, and each link opens. */
class AgentMenuLinksTest extends VisitTestCase
{
    /** @return list<array{title: string, description: string, href: string}> */
    private function agentLinks(string $role, ?Branch $branch = null): array
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor, $branch);
        $links = [];
        $this->actingAs($actor)->get('/clinic-management')->assertOk()->assertInertia(function (Assert $page) use (&$links): void {
            $page->where('groups', function ($groups) use (&$links): bool {
                $group = collect($groups)->firstWhere('title', 'AI agents');
                $links = $group === null ? [] : array_values((array) $group['links']);
                if ($group !== null) {
                    $this->assertSame('agents', $group['icon']);
                }

                return true;
            });
        });

        return $links;
    }

    public function test_each_role_sees_exactly_the_agent_pages_it_may_open_and_every_link_opens(): void
    {
        $expected = [
            'director' => ['/agents', '/agents/office', '/agents/inbox'],
            'administrator' => ['/agents', '/agents/office'],
            'technical_admin' => ['/agents', '/agents/office'],
            'ca_supervisor' => ['/agents/inbox'],
            'finance_officer' => ['/agents/inbox'],
            'ca' => [],
            'resident_doctor' => [],
            'panel_officer' => [],
            'hr_manager' => [],
            'marketing' => [],
            'business_development' => [],
            'queue_display' => [],
        ];
        foreach ($expected as $role => $hrefs) {
            $links = $this->agentLinks($role);
            $this->assertSame($hrefs, array_column($links, 'href'), $role);
            foreach ($links as $link) {
                $this->get($link['href'])->assertOk();
            }
        }
    }

    public function test_the_rows_are_named_plainly_and_the_inbox_says_approving_changes_nothing(): void
    {
        $links = collect($this->agentLinks('director'))->keyBy('href');

        $this->assertSame(['All agents', 'Agent office', 'Approval inbox'], $links->pluck('title')->values()->all());
        $this->assertStringContainsString('Approving changes nothing in stock.', $links['/agents/inbox']['description']);
    }

    public function test_the_inbox_row_hides_on_a_branch_the_viewer_is_not_assigned_to_and_the_inbox_agrees(): void
    {
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->where('is_active', true)->firstOrFail();

        $this->assertSame(['/agents', '/agents/office'], array_column($this->agentLinks('director', $other), 'href'));
        $this->get('/agents/inbox')->assertForbidden();
    }
}
