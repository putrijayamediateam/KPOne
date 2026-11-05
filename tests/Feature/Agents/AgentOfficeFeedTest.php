<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Services\AgentRunRecorder;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Visit\VisitTestCase;

/** AG-1: GET /agents/office-feed (docs/agents/CONTRACT.md sections 3 and 11). */
class AgentOfficeFeedTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->selectBranch($this->actor('director'));
    }

    /** @return array<string, array<string, mixed>> */
    private function agents(): array
    {
        return collect($this->getJson(route('agents.office-feed'))->assertOk()->json('agents'))->keyBy('code')->all();
    }

    public function test_feed_has_exactly_the_contract_shape(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T02:00:00Z'));
        $response = $this->getJson(route('agents.office-feed'))->assertOk();
        $this->assertSame(['serverTime', 'pendingProposals', 'agents', 'messages'], array_keys($response->json()));
        $this->assertSame('2026-11-05T02:00:00Z', $response->json('serverTime'));
        $this->assertSame(0, $response->json('pendingProposals'));
        $this->assertSame([], $response->json('messages'));
        $hana = collect($response->json('agents'))->firstWhere('code', 'inventory_analyst');
        $this->assertSame([
            'code' => 'inventory_analyst', 'displayName' => 'Hana', 'jobTitle' => 'Inventory Analyst (AI agent)', 'department' => 'Clinic Operations',
            'kind' => 'runtime', 'avatarKey' => null, 'isEnabled' => true, 'light' => 'grey', 'currentTask' => null, 'lastStep' => null,
            'lastStepAt' => null, 'pendingProposals' => 0,
        ], $hana);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_lights_follow_the_contract(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-05T01:58:10Z'));
        $working = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $recorder->step($working, 'decision', 'Proposed 3 restock suggestions');
        $waiting = $recorder->start('stocktake_officer', 'stock_count_check', $this->branch);
        $recorder->awaitApproval($waiting);
        $recorder->fail($recorder->start('technical_admin_agent', 'system_health', null, organisationId: $this->organisation->id), 'health.check_failed');
        $recorder->complete($recorder->start('report_assistant', 'daily_report', null, organisationId: $this->organisation->id));

        $agents = $this->agents();
        $this->assertSame('green', $agents['inventory_analyst']['light']);
        $this->assertSame('Daily stock review, '.$this->branch->name, $agents['inventory_analyst']['currentTask']);
        $this->assertSame('Proposed 3 restock suggestions', $agents['inventory_analyst']['lastStep']);
        $this->assertSame('2026-11-05T01:58:10Z', $agents['inventory_analyst']['lastStepAt']);
        $this->assertSame('yellow', $agents['stocktake_officer']['light']);
        $this->assertSame('red', $agents['technical_admin_agent']['light']);
        $this->assertNull($agents['technical_admin_agent']['currentTask']);
        $this->assertSame('grey', $agents['report_assistant']['light']);
        $this->assertSame('grey', $agents['qa_reviewer']['light']);

        $this->travelTo(CarbonImmutable::parse('2026-11-06T01:58:11Z'));
        $this->assertSame('grey', $this->agents()['technical_admin_agent']['light'], 'red lasts 24 hours');

        $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
        $recorder->complete($recorder->start('technical_admin_agent', 'system_health', null, organisationId: $this->organisation->id));
        $this->assertSame('grey', $this->agents()['technical_admin_agent']['light'], 'only the last run counts');
    }

    public function test_messages_hold_the_20_newest_with_short_summaries(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $run = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        for ($i = 1; $i <= 22; $i++) {
            $recorder->message($run, 'director_assistant', "Update {$i} for the stock review");
        }
        $recorder->message($run, 'report_assistant', str_repeat('Long note ', 50));

        $messages = $this->getJson(route('agents.office-feed'))->json('messages');
        $this->assertCount(20, $messages);
        $this->assertSame(['from', 'to', 'summary', 'at'], array_keys($messages[0]));
        $this->assertSame('report_assistant', $messages[0]['to']);
        $this->assertSame(120, mb_strlen($messages[0]['summary']));
        $this->assertStringEndsWith('…', $messages[0]['summary']);
        $this->assertSame('Update 22 for the stock review', $messages[1]['summary']);
        $this->assertSame('Update 4 for the stock review', $messages[19]['summary']);
        $this->assertSame('inventory_analyst', $messages[1]['from']);
    }

    public function test_a_message_of_exactly_120_characters_is_kept_whole_and_an_unreadable_one_never_breaks_the_feed(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $run = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $exact = str_repeat('a', 120);
        $recorder->message($run, 'director_assistant', $exact);
        $recorder->message($run, 'director_assistant', 'Readable');
        DB::table('ai_agent_messages')->insert(['ai_agent_run_id' => $run->id, 'from_agent_id' => $run->ai_agent_id, 'to_agent_id' => $run->ai_agent_id, 'body' => 'not-a-valid-ciphertext', 'created_at' => now()]);

        $summaries = collect($this->getJson(route('agents.office-feed'))->assertOk()->json('messages'))->pluck('summary')->all();
        $this->assertSame(['Message unavailable', 'Readable', $exact], $summaries);
        $this->get(route('agents.index'))->assertOk();
    }

    public function test_feed_never_shows_another_organisations_agents_or_messages(): void
    {
        $other = DB::table('organisations')->insertGetId(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic Other Clinic', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('ai_agents')->insertGetId(['organisation_id' => $other, 'code' => 'foreign_agent', 'display_name' => 'Foreign', 'job_title' => 'Foreign (AI agent)', 'kind' => 'runtime', 'is_enabled' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $run = app(AgentRunRecorder::class)->start('foreign_agent', 'daily_check', null, organisationId: $other);
        app(AgentRunRecorder::class)->message($run, 'foreign_agent', 'Foreign message');

        $json = $this->getJson(route('agents.office-feed'))->assertOk()->json();
        $this->assertNotContains('foreign_agent', array_column($json['agents'], 'code'));
        $this->assertSame([], $json['messages']);
        $this->get(route('agents.index'))->assertInertia(fn ($page) => $page->has('agents', 6)->has('activity', 0));
        $this->assertSame($foreign, $run->ai_agent_id);
    }

    public function test_pending_proposals_are_counted_from_the_inbox(): void
    {
        // Owner-approved change (2026-11-05): AG-2 created the real ai_proposals table, so this test now
        // inserts real proposals instead of building a temporary table; the checks are unchanged.
        $run = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch);
        foreach (['pending', 'pending', 'approved'] as $i => $status) {
            DB::table('ai_proposals')->insert([
                'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'ai_agent_run_id' => $run->id, 'ai_agent_id' => $run->ai_agent_id,
                'type' => 'inventory.restock_suggestion', 'payload' => encrypt('{}', false), 'payload_hash' => str_repeat('a', 64),
                'status' => $status, 'decided_at' => $status === 'approved' ? now() : null, 'decided_by_user_id' => $status === 'approved' ? $this->actor('director')->id : null,
                'lock_version' => $status === 'approved' ? 1 : 0, 'idempotency_key' => 'feed-test-'.$i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(AgentRunRecorder::class)->complete($run);

        $response = $this->getJson(route('agents.office-feed'))->assertOk();
        $this->assertSame(2, $response->json('pendingProposals'));
        $agent = collect($response->json('agents'))->firstWhere('code', 'inventory_analyst');
        $this->assertSame(['yellow', 2], [$agent['light'], $agent['pendingProposals']]);
    }

    public function test_feed_carries_no_personal_data_keys(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $run = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $recorder->message($run, 'director_assistant', 'Stock checked');
        $keys = [];
        $json = $this->getJson(route('agents.office-feed'))->json();
        $walk = function (array $value) use (&$walk, &$keys): void {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $keys[] = strtolower($key);
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($json);
        foreach (['patient', 'nric', 'passport', 'phone', 'email', 'address', 'diagnosis', 'user', 'requested'] as $forbidden) {
            foreach ($keys as $key) {
                $this->assertStringNotContainsString($forbidden, $key);
            }
        }
    }
}
