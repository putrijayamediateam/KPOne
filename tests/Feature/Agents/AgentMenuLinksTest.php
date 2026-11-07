<?php

namespace Tests\Feature\Agents;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
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
            'director' => ['/agents', '/agents/office', '/agents/assistant', '/agents/daily-summary', '/agents/inbox'],
            'administrator' => ['/agents', '/agents/office', '/agents/daily-summary'],
            'technical_admin' => ['/agents', '/agents/office'],
            'ca_supervisor' => ['/agents/daily-summary', '/agents/inbox'],
            'finance_officer' => ['/agents/daily-summary', '/agents/inbox'],
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

        $this->assertSame(['All agents', 'Agent office', 'Quick answers', 'AI daily summary', 'Approval inbox'], $links->pluck('title')->values()->all());
        $this->assertStringContainsString('Approving changes nothing in stock.', $links['/agents/inbox']['description']);
    }

    public function test_the_inbox_row_hides_on_a_branch_the_viewer_is_not_assigned_to_and_the_inbox_agrees(): void
    {
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->where('is_active', true)->firstOrFail();

        // Quick answers and the AI daily summary are not tied to one branch (they read the branches the Director
        // works at), so they stay.
        $this->assertSame(['/agents', '/agents/office', '/agents/assistant', '/agents/daily-summary'], array_column($this->agentLinks('director', $other), 'href'));
        $this->get('/agents/inbox')->assertForbidden();
    }

    public function test_the_menu_flag_on_every_page_reads_no_staff_record_for_the_inbox_row(): void
    {
        // The shell asks only whether the hub has anything; the inbox row's assignment check (a staff record read)
        // is for the hub page itself, so ordinary pages such as the website releases read no staff record for it.
        $director = $this->actor('director');
        $this->selectBranch($director);
        $fresh = $director->fresh();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue(app(ClinicManagementNavigation::class)->hasDestinations($fresh));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertSame([], preg_grep('/\b(?:staff_profiles|staff_branch_assignments)\b/', $queries));
    }
}
