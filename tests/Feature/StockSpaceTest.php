<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-8: the Stock space shows only what the person's own Inventory pages already show. */
class StockSpaceTest extends VisitTestCase
{
    public function test_a_ca_supervisor_sees_empty_stock_widgets_the_primary_action_and_links(): void
    {
        $this->selectBranch($this->actor('ca_supervisor'));

        $this->get('/spaces/stock')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->has('stock.watch.lists', 3)
            ->where('stock.watch.lists.0.title', 'Low stock')
            ->where('stock.watch.lists.0.total', 0)
            ->where('stock.overdueReviews.total', 0)
            ->where('stock.reorder.total', 0)
            ->where('stock.primaryAction', ['title' => 'Open Stock review', 'href' => '/inventory/stock-review'])
            ->has('questions', 3));
    }

    public function test_the_administrator_gets_the_readiness_card_and_a_ca_is_refused_the_space(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $props = $this->get('/spaces/stock')->assertOk()->viewData('page')['props']['stock'];
        $this->assertSame(0, $props['readiness']['openings']);
        $this->assertSame('/inventory/readiness', $props['readiness']['href']);

        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/stock')->assertForbidden();
        $this->postJson('/spaces/stock/ask', ['question' => 'stock.held_batches'])->assertForbidden();
    }

    public function test_the_three_questions_answer_in_plain_sentences_and_link_to_their_pages(): void
    {
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->postJson('/spaces/stock/ask', ['question' => 'stock.held_batches'])
            ->assertOk()->assertJsonPath('sentence', 'No batch is on hold.')->assertJsonPath('href', '/inventory/batch-holds');
        $month = now()->format('F Y');
        $this->postJson('/spaces/stock/ask', ['question' => 'stock.written_off'])
            ->assertOk()->assertJsonPath('sentence', 'Nothing was written off in '.$month.'.');
        $this->postJson('/spaces/stock/ask', ['question' => 'stock.no_order'])
            ->assertOk()->assertJsonPath('sentence', 'Every low-stock item needing an order already has one on its way.');
    }
}
