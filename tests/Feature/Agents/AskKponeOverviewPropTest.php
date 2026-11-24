<?php

namespace Tests\Feature\Agents;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestCase;

/** CHAT-2b: the overview page tells the Ask KPOne drawer which paths are on and allowed, so it never calls a switched-off one. */
class AskKponeOverviewPropTest extends BillingTestCase
{
    public function test_the_page_names_only_the_paths_that_are_on_and_allowed(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $expect = fn (bool $chat, bool $claude) => $this->get(route('insights.overview'))->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Insights/Overview')->where('askKpone.chat', $chat)->where('askKpone.claude', $claude),
        );

        $expect(false, false);

        config(['agents.chat_enabled' => true]);
        $expect(true, false);

        config(['agents.chat_enabled' => false, 'agents.ask_claude_enabled' => true]);
        $expect(false, false);

        config(['agents.connector_enabled' => true]);
        $expect(false, true);

        config(['agents.chat_enabled' => true]);
        $expect(true, true);
    }

    public function test_the_finance_officer_sees_the_overview_but_no_ask_path_even_with_everything_on(): void
    {
        config(['agents.chat_enabled' => true, 'agents.ask_claude_enabled' => true, 'agents.connector_enabled' => true]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);

        $this->get(route('insights.overview'))->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('askKpone.chat', false)->where('askKpone.claude', false),
        );
    }
}
