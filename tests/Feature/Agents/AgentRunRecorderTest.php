<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Visit\VisitTestCase;

/** AG-1: AgentRunRecorder lifecycle, guards and the append-only database rules. */
class AgentRunRecorderTest extends VisitTestCase
{
    private AgentRunRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->recorder = app(AgentRunRecorder::class);
    }

    private function scheduled(string $agent = 'inventory_analyst', ?string $key = null): AiAgentRun
    {
        return $this->recorder->start($agent, 'daily_stock_review', $this->branch, idempotencyKey: $key);
    }

    public function test_a_scheduled_run_records_steps_messages_and_finishes_with_audit(): void
    {
        $run = $this->scheduled();
        $this->assertSame('working', $run->status);
        $this->assertNull($run->requested_by_user_id);
        $this->assertNotNull($run->started_at);

        $first = $this->recorder->step($run, 'data_read', 'Read stock review counts', 'inventory.stock_review');
        $second = $this->recorder->step($run, 'decision', 'Proposed 3 restock suggestions');
        $this->assertSame([1, 2], [$first->sequence, $second->sequence]);
        $this->assertSame('Proposed 3 restock suggestions', $run->refresh()->last_step_summary);

        $message = $this->recorder->message($run, 'director_assistant', '3 items below reorder level at Cheras');
        $this->assertSame('3 items below reorder level at Cheras', $message->refresh()->body);
        $stored = DB::table('ai_agent_messages')->where('id', $message->id)->value('body');
        $this->assertStringNotContainsString('reorder', $stored, 'the body is encrypted at rest');

        $this->recorder->complete($run);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->finished_at);

        $started = AuditLog::query()->where('event', 'ai_agent_run.started')->sole();
        $finished = AuditLog::query()->where('event', 'ai_agent_run.finished')->sole();
        $agentId = AiAgent::query()->where('code', 'inventory_analyst')->value('id');
        $this->assertSame(['run_id' => $run->id, 'agent_id' => $agentId, 'task_type' => 'daily_stock_review', 'branch_id' => $this->branch->id], $started->metadata);
        $this->assertSame('completed', $finished->metadata['status']);
        $this->assertSame($this->branch->id, $finished->branch_id);
        $this->assertSame($this->branch->id, $started->branch_id);

        foreach ([fn () => $this->recorder->step($run, 'note', 'Too late'), fn () => $this->recorder->message($run, 'director_assistant', 'Too late'), fn () => $this->recorder->fail($run, 'late.failure')] as $late) {
            try {
                $late();
                $this->fail('A finished run must not change.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_lifecycle_queued_to_approval_and_failure_codes(): void
    {
        $run = $this->recorder->start('report_assistant', 'daily_report', null, queued: true, organisationId: $this->organisation->id);
        $this->assertSame('queued', $run->status);
        $this->assertNull($run->started_at);
        $this->recorder->begin($run);
        $this->recorder->awaitApproval($run);
        $this->assertSame('awaiting_approval', $run->status);
        $this->recorder->begin($run);
        $this->recorder->fail($run, 'data_source.refused');
        $this->assertSame(['failed', 'data_source.refused'], [$run->status, $run->error_code]);

        $queued = $this->recorder->start('report_assistant', 'daily_report', null, queued: true, organisationId: $this->organisation->id);
        $this->recorder->cancel($queued);
        $this->assertSame('cancelled', $queued->status);
        $this->assertNull($queued->started_at);

        $this->expectException(ValidationException::class);
        $this->recorder->fail($this->scheduled(), 'Free text is not a code');
    }

    public function test_data_reads_need_the_allowlist_and_text_never_carries_personal_identifiers(): void
    {
        $run = $this->scheduled();
        try {
            $this->recorder->step($run, 'data_read', 'Read money', 'money.day_summary');
            $this->fail('The allowlist must refuse a tool the agent does not hold.');
        } catch (AuthorizationException) {
        }
        foreach ([
            fn () => $this->recorder->step($run, 'data_read', 'Missing tool'),
            fn () => $this->recorder->step($run, 'note', 'Note with tool', 'inventory.stock_review'),
            fn () => $this->recorder->step($run, 'thinking', 'Unknown kind'),
            fn () => $this->recorder->step($run, 'note', str_repeat('a', 201)),
            fn () => $this->recorder->step($run, 'note', 'Patient 900101-14-5678 low stock'),
            fn () => $this->recorder->step($run, 'note', 'Contact someone@example.test'),
            fn () => $this->recorder->step($run, 'note', 'Call 012-345 6789 now'),
            fn () => $this->recorder->step($run, 'note', 'Call +60123456789 now'),
            fn () => $this->recorder->step($run, 'note', 'Call +60 12-345 6789 now'),
            fn () => $this->recorder->step($run, 'note', 'Call 12-345 6789 now'),
            fn () => $this->recorder->step($run, 'note', 'IC 900101 14 5678'),
            fn () => $this->recorder->message($run, 'director_assistant', str_repeat('b', 2001)),
        ] as $refused) {
            try {
                $refused();
                $this->fail('This step must be refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->recorder->step($run, 'note', 'Checked 1,250 units on 2026-11-05 10:00');
        $this->assertSame(1, DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->count());
    }

    public function test_idempotency_key_returns_the_original_run_and_refuses_a_different_task(): void
    {
        $key = 'inventory_analyst:cheras:2026-11-05:0700';
        $first = $this->scheduled(key: $key);
        $retry = $this->scheduled(key: $key);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, AiAgentRun::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'ai_agent_run.started')->count());

        $this->expectException(ValidationException::class);
        $this->recorder->start('stocktake_officer', 'daily_stock_review', $this->branch, idempotencyKey: $key);
    }

    public function test_a_disabled_or_foreign_agent_cannot_start(): void
    {
        DB::table('ai_agents')->where('code', 'inventory_analyst')->update(['is_enabled' => false]);
        try {
            $this->scheduled();
            $this->fail('A disabled agent must not start.');
        } catch (ValidationException) {
        }
        $this->expectException(AuthorizationException::class);
        $this->recorder->start('unknown_agent', 'daily_stock_review', $this->branch);
    }

    public function test_a_requester_needs_the_stated_permission_and_an_assignment_at_the_branch(): void
    {
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $director = $this->actor('director');
        $run = $this->recorder->start('director_assistant', 'quick_answer', $this->branch, $director, AgentPermissions::ASSISTANT);
        $this->assertSame($director->id, $run->requested_by_user_id);
        $this->assertSame($director->id, AuditLog::query()->where('event', 'ai_agent_run.started')->sole()->actor_user_id);
        $this->recorder->complete($run);
        $this->assertSame($director->id, AuditLog::query()->where('event', 'ai_agent_run.finished')->sole()->actor_user_id);
        $this->recorder->start('director_assistant', 'quick_answer', null, $director, AgentPermissions::ASSISTANT);

        $supervisor = $this->actor('ca_supervisor');
        $refusals = [
            'no permission' => fn () => $this->recorder->start('director_assistant', 'quick_answer', $this->branch, $supervisor, AgentPermissions::ASSISTANT),
            'no stated permission' => fn () => $this->recorder->start('director_assistant', 'quick_answer', $this->branch, $director),
            'not assigned to the branch' => fn () => $this->recorder->start('inventory_analyst', 'quick_answer', $puchong, $supervisor, AgentPermissions::PROPOSALS_VIEW),
            'director not assigned to the branch' => fn () => $this->recorder->start('director_assistant', 'quick_answer', $puchong, $director, AgentPermissions::ASSISTANT),
            'branch permission without a branch' => fn () => $this->recorder->start('inventory_analyst', 'quick_answer', null, $supervisor, AgentPermissions::PROPOSALS_VIEW),
        ];
        DB::table('users')->where('id', $director->id)->update(['is_active' => false]);
        $refusals['inactive'] = fn () => $this->recorder->start('director_assistant', 'quick_answer', $this->branch, $director->refresh(), AgentPermissions::ASSISTANT);
        foreach ($refusals as $case => $refused) {
            try {
                $refused();
                $this->fail("This requester must be refused: {$case}.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, AiAgentRun::query()->count());
        $this->recorder->start('inventory_analyst', 'quick_answer', $this->branch, $supervisor, AgentPermissions::PROPOSALS_VIEW);
    }

    public function test_disallowed_transitions_and_steps_before_start_are_refused(): void
    {
        $queued = $this->recorder->start('report_assistant', 'daily_report', $this->branch, queued: true);
        $working = $this->scheduled();
        $cancelled = $this->recorder->cancel($this->recorder->start('report_assistant', 'daily_report', $this->branch, queued: true));
        foreach ([
            fn () => $this->recorder->awaitApproval($queued),
            fn () => $this->recorder->complete($queued),
            fn () => $this->recorder->step($queued, 'note', 'Not started'),
            fn () => $this->recorder->message($queued, 'director_assistant', 'Not started'),
            fn () => $this->recorder->begin($working),
            fn () => $this->recorder->complete($cancelled),
            fn () => $this->recorder->begin($cancelled),
        ] as $refused) {
            try {
                $refused();
                $this->fail('This transition must be refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['queued', 'working', 'cancelled'], [$queued->refresh()->status, $working->refresh()->status, $cancelled->refresh()->status]);
    }

    public function test_database_lifecycle_checks(): void
    {
        $agent = AiAgent::query()->where('code', 'inventory_analyst')->value('id');
        $base = ['organisation_id' => $this->organisation->id, 'ai_agent_id' => $agent, 'task_type' => 'daily_stock_review', 'created_at' => now(), 'updated_at' => now()];
        foreach ([
            ['status' => 'completed', 'started_at' => now(), 'finished_at' => null],
            ['status' => 'queued', 'started_at' => now()],
            ['status' => 'working', 'started_at' => null],
            ['status' => 'failed', 'started_at' => now(), 'finished_at' => now()],
            ['status' => 'completed', 'started_at' => now(), 'finished_at' => now()->subMinute()],
            ['status' => 'completed', 'started_at' => now(), 'finished_at' => now(), 'error_code' => 'not.failed'],
            ['status' => 'executed', 'started_at' => now(), 'finished_at' => now()],
            ['status' => 'working', 'started_at' => now(), 'task_type' => 'Free text'],
        ] as $row) {
            $this->assertRefused(fn () => DB::table('ai_agent_runs')->insert([...$base, ...$row]));
        }
        $this->assertSame(0, AiAgentRun::query()->count());
    }

    public function test_idempotency_refuses_the_same_key_for_another_branch_or_requester(): void
    {
        $key = 'quick-answer-0001';
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $director = $this->actor('director');
        $first = $this->recorder->start('director_assistant', 'quick_answer', $this->branch, $director, AgentPermissions::ASSISTANT, $key);
        $this->assertSame($first->id, $this->recorder->start('director_assistant', 'quick_answer', $this->branch, $director, AgentPermissions::ASSISTANT, $key)->id);
        foreach ([
            fn () => $this->recorder->start('director_assistant', 'quick_answer', $puchong, idempotencyKey: $key),
            fn () => $this->recorder->start('director_assistant', 'quick_answer', $this->branch, $this->actor('director'), AgentPermissions::ASSISTANT, $key),
            fn () => $this->recorder->start('director_assistant', 'other_task', $this->branch, $director, AgentPermissions::ASSISTANT, $key),
        ] as $refused) {
            try {
                $refused();
                $this->fail('A reused key must be refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, AiAgentRun::query()->count());
    }

    public function test_steps_and_messages_are_append_only_in_the_database(): void
    {
        $run = $this->scheduled();
        $step = $this->recorder->step($run, 'note', 'Started');
        $message = $this->recorder->message($run, 'director_assistant', 'Hello');

        foreach ([
            fn () => DB::table('ai_agent_run_steps')->where('id', $step->id)->update(['summary' => 'Changed']),
            fn () => DB::table('ai_agent_run_steps')->where('id', $step->id)->delete(),
            fn () => DB::statement('TRUNCATE ai_agent_run_steps'),
            fn () => DB::table('ai_agent_messages')->where('id', $message->id)->update(['to_agent_id' => $message->from_agent_id]),
            fn () => DB::table('ai_agent_messages')->where('id', $message->id)->delete(),
            fn () => DB::statement('TRUNCATE ai_agent_messages'),
            fn () => DB::table('ai_agent_runs')->where('id', $run->id)->delete(),
            fn () => DB::table('ai_agent_runs')->where('id', $run->id)->update(['task_type' => 'other_task']),
            fn () => DB::table('ai_agent_runs')->where('id', $run->id)->update(['status' => 'expired']),
            fn () => DB::table('ai_agent_runs')->where('id', $run->id)->update(['status' => 'failed', 'finished_at' => now()]),
        ] as $statement) {
            $this->assertRefused($statement);
        }

        foreach ([fn () => $step->forceFill(['summary' => 'Changed'])->save(), fn () => $message->delete()] as $modelWrite) {
            try {
                $modelWrite();
                $this->fail('Model writes must be refused.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $hana = $run->ai_agent_id;
        $arif = AiAgent::query()->where('code', 'director_assistant')->value('id');
        $this->assertRefused(fn () => DB::table('ai_agent_messages')->insert(['ai_agent_run_id' => $run->id, 'from_agent_id' => $arif, 'to_agent_id' => $hana, 'body' => 'x', 'created_at' => now()]));

        $this->recorder->complete($run);
        $this->assertRefused(fn () => DB::table('ai_agent_runs')->where('id', $run->id)->update(['last_step_summary' => 'Rewritten']));
        $this->assertRefused(fn () => DB::table('ai_agent_run_steps')->insert(['ai_agent_run_id' => $run->id, 'sequence' => 9, 'kind' => 'note', 'summary' => 'Late', 'created_at' => now()]));
        $this->assertRefused(fn () => DB::table('ai_agent_messages')->insert(['ai_agent_run_id' => $run->id, 'from_agent_id' => $hana, 'to_agent_id' => $arif, 'body' => 'x', 'created_at' => now()]));
    }

    public function test_database_refuses_cross_organisation_runs_and_messages(): void
    {
        $other = DB::table('organisations')->insertGetId(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic Other Clinic', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('ai_agents')->insertGetId(['organisation_id' => $other, 'code' => 'foreign_agent', 'display_name' => 'Foreign', 'job_title' => 'Foreign (AI agent)', 'kind' => 'runtime', 'is_enabled' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $run = $this->scheduled();
        $this->assertRefused(fn () => DB::table('ai_agent_runs')->insert(['organisation_id' => $this->organisation->id, 'ai_agent_id' => $foreign, 'task_type' => 'daily_stock_review', 'status' => 'working', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        $this->assertRefused(fn () => DB::table('ai_agent_messages')->insert(['ai_agent_run_id' => $run->id, 'from_agent_id' => $run->ai_agent_id, 'to_agent_id' => $foreign, 'body' => 'x', 'created_at' => now()]));
        $this->expectException(AuthorizationException::class);
        $this->recorder->message($run, 'foreign_agent', 'Hello');
    }

    private function assertRefused(callable $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The database accepted a statement it must refuse.');
    }
}
