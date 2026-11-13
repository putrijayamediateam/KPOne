<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Models\User;
use Database\Seeders\KPOneAgentSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-21: the inbox says why a person cannot decide a pending suggestion; it changes no permission and no write path. */
class CannotDecideReasonTest extends VisitTestCase
{
    private function proposalFor(?User $requester): AiProposal
    {
        $this->seed(KPOneAgentSeeder::class);
        $f = (new GovernedStockFixture($this->branch))->build('2.000');
        $run = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch, $requester, $requester ? 'ai_proposals.view.branch' : null);

        return app(ProposalService::class)->propose($run, 'inventory.restock_suggestion', $this->branch, [
            'sku_public_id' => $f['sku']->public_id, 'location_public_id' => $f['location']->public_id, 'current_quantity' => '2',
            'reorder_level' => '10', 'suggested_quantity' => '8', 'reason_code' => 'at_or_below_reorder_level',
        ], 'reason-'.uniqid());
    }

    public function test_the_person_who_started_the_run_is_told_so_without_a_name(): void
    {
        $director = $this->actor('director');
        $proposal = $this->proposalFor($director);
        $this->selectBranch($director);

        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.id', $proposal->id)->where('proposals.0.canDecide', false)
            ->where('proposals.0.cannotDecideReason', 'You started the run that made this suggestion, so someone else must decide.'));
    }

    public function test_someone_else_who_may_decide_gets_no_reason(): void
    {
        $this->proposalFor($this->actor('director'));
        $this->selectBranch($this->actor('ca_supervisor'));

        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.canDecide', true)->where('proposals.0.cannotDecideReason', null));
    }

    public function test_a_decided_proposal_has_no_reason(): void
    {
        $proposal = $this->proposalFor(null);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('agents.inbox.approve', $proposal), ['lock_version' => 0, 'reason' => 'Order placed'])->assertRedirect();

        $this->get(route('agents.inbox', ['tab' => 'approved']))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.canDecide', false)->where('proposals.0.cannotDecideReason', null));
    }

    public function test_the_reason_helper_names_each_cause_and_never_a_person(): void
    {
        $director = $this->actor('director');
        $proposal = $this->proposalFor($director)->load('run');
        $service = app(ProposalService::class);
        $supervisor = $this->actor('ca_supervisor');

        $this->assertSame('You started the run that made this suggestion, so someone else must decide.', $service->cannotDecideReason($director, $proposal, true));
        $this->assertSame('You are not assigned to this branch now, so someone else must decide.', $service->cannotDecideReason($supervisor, $proposal, false));
        $this->assertNull($service->cannotDecideReason($supervisor, $proposal, true));

        $ca = $this->actor('ca');
        $reason = $service->cannotDecideReason($ca, $proposal, true);
        $this->assertSame('Your role can see this suggestion but not decide it, so someone else must decide.', $reason);

        foreach ([$director, $supervisor, $ca] as $user) {
            $this->assertStringNotContainsString((string) $user->name, (string) $service->cannotDecideReason($ca, $proposal, true));
        }
        $this->assertSame($service->canDecide($supervisor, $proposal, true), $service->cannotDecideReason($supervisor, $proposal, true) === null);
    }
}
