<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-2: proposals are made by the right agent during a run, decided by an authorised other person, and change no stock. */
class ProposalServiceTest extends VisitTestCase
{
    private ProposalService $proposals;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->proposals = app(ProposalService::class);
        $this->f = (new GovernedStockFixture($this->branch))->build('3.000');
    }

    private function agentRun(string $agent = 'inventory_analyst', ?User $requester = null): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start($agent, 'daily_stock_review', $this->branch, $requester, $requester ? 'ai_proposals.view.branch' : null);
    }

    private function restock(array $overrides = []): array
    {
        return ['sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id, 'current_quantity' => '3.000',
            'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level', ...$overrides];
    }

    private function propose(?AiAgentRun $run = null, string $key = 'restock-cheras-0001', array $payload = []): AiProposal
    {
        return $this->proposals->propose($run ?? $this->agentRun(), 'inventory.restock_suggestion', $this->branch, $payload ?: $this->restock(), $key);
    }

    /** @return array<string, mixed> */
    private function stockSnapshot(): array
    {
        return collect(['inventory_stock_balances', 'stock_movements', 'inventory_purchase_orders', 'inventory_stock_requests', 'inventory_governed_cases', 'inventory_stock_reviews'])
            ->filter(fn (string $table) => DB::getSchemaBuilder()->hasTable($table))
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function refused(callable $action, string $exception, string $case): void
    {
        try {
            $action();
            $this->fail("Must be refused: {$case}.");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $case.': '.$e->getMessage());
        }
    }

    public function test_an_agent_proposes_once_per_key_with_an_encrypted_payload_audit_and_step(): void
    {
        $run = $this->agentRun();
        $proposal = $this->propose($run);

        $this->assertSame(['pending', 0], [$proposal->status, $proposal->lock_version]);
        $this->assertSame(collect($this->restock())->sortKeys()->all(), $proposal->refresh()->payload);
        $this->assertStringNotContainsString($this->f['sku']->public_id, (string) DB::table('ai_proposals')->value('payload'), 'the payload is encrypted at rest');
        $this->assertSame(hash('sha256', json_encode(collect($this->restock())->sortKeys()->all(), JSON_UNESCAPED_SLASHES)), $proposal->payload_hash);
        $this->assertSame(1, AuditLog::query()->where('event', 'ai_proposal.proposed')->count());
        $this->assertSame('Proposed: Restock suggestion', DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->value('summary'));

        $this->assertSame($proposal->id, $this->propose($run)->id, 'an identical retry returns the original');
        $this->assertSame(1, AiProposal::query()->count());
        $this->refused(fn () => $this->propose($run, payload: $this->restock(['suggested_quantity' => '8.000'])), ValidationException::class, 'same key, different proposal');
    }

    public function test_only_the_right_agent_during_a_run_at_its_branch_with_a_valid_payload_may_propose(): void
    {
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $finished = app(AgentRunRecorder::class)->complete($this->agentRun());
        $cases = [
            'wrong agent' => [fn () => $this->propose($this->agentRun('director_assistant'), 'k-wrong-agent'), AuthorizationException::class],
            'finished run' => [fn () => $this->propose($finished, 'k-finished-run'), ValidationException::class],
            'another branch' => [fn () => $this->proposals->propose($this->agentRun(), 'inventory.restock_suggestion', $puchong, $this->restock(), 'k-other-branch'), AuthorizationException::class],
            'extra field' => [fn () => $this->propose(null, 'k-extra-field', $this->restock(['patient' => 'x'])), ValidationException::class],
            'bad quantity' => [fn () => $this->propose(null, 'k-bad-quantity', $this->restock(['suggested_quantity' => '-1'])), ValidationException::class],
            'unknown sku' => [fn () => $this->propose(null, 'k-unknown-sku', $this->restock(['sku_public_id' => '00000000-0000-4000-8000-000000000000'])), ValidationException::class],
            'unknown type' => [fn () => $this->proposals->propose($this->agentRun(), 'inventory.purchase_order', $this->branch, $this->restock(), 'k-unknown-type'), InvalidArgumentException::class],
            'transfer to another branch' => [fn () => $this->proposals->propose($this->agentRun(), 'inventory.transfer_suggestion', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'from_branch_code' => 'CHERAS', 'to_branch_code' => 'PUCHONG', 'quantity' => '1', 'from_current_quantity' => '5', 'from_reorder_level' => '2', 'to_current_quantity' => '0', 'to_reorder_level' => '2'], 'k-transfer'), ValidationException::class],
        ];
        foreach ($cases as $case => [$action, $exception]) {
            $this->refused($action, $exception, $case);
        }
        $run = $this->agentRun('inventory_analyst');
        DB::table('ai_agents')->where('code', 'inventory_analyst')->update(['is_enabled' => false]);
        $this->refused(fn () => $this->propose($run, 'k-disabled'), AuthorizationException::class, 'disabled agent');
        $this->assertSame(0, AiProposal::query()->count());
    }

    public function test_each_type_validates_its_payload_and_a_transfer_comes_into_the_proposal_branch(): void
    {
        $run = $this->agentRun();
        $this->proposals->propose($run, 'inventory.expiry_warning', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'batch_public_id' => $this->f['batch']->public_id, 'location_public_id' => $this->f['location']->public_id, 'quantity' => '3.000', 'expiry_date' => '2027-01-01', 'days_left' => 57, 'suggested_action' => 'write_off_review'], 'expiry-0001');
        $this->proposals->propose($run, 'inventory.transfer_suggestion', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'from_branch_code' => 'PUCHONG', 'to_branch_code' => 'CHERAS', 'quantity' => '2.000', 'from_current_quantity' => '9.000', 'from_reorder_level' => '5.000', 'to_current_quantity' => '3.000', 'to_reorder_level' => '5.000'], 'transfer-0001');
        $this->proposals->propose($run, 'inventory.slow_moving', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id, 'quantity' => '3.000', 'last_dispensed_date' => null, 'days_without_dispensing' => 60, 'suggested_action' => 'review'], 'slow-0001');
        $this->assertSame(['inventory.expiry_warning', 'inventory.transfer_suggestion', 'inventory.slow_moving'], AiProposal::query()->orderBy('id')->pluck('type')->all());
        $this->refused(fn () => $this->proposals->propose($run, 'inventory.expiry_warning', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'batch_public_id' => $this->f['batch']->public_id, 'location_public_id' => $this->f['location']->public_id, 'quantity' => '3.000', 'expiry_date' => '2027-01-01', 'days_left' => 57, 'suggested_action' => 'destroy'], 'expiry-0002'), ValidationException::class, 'unknown action');
    }

    public function test_an_authorised_person_approves_once_and_nothing_in_stock_changes(): void
    {
        $proposal = $this->propose();
        $supervisor = $this->actor('ca_supervisor');
        $before = $this->stockSnapshot();

        $approved = $this->proposals->approve($supervisor, $proposal, ['lock_version' => 0]);

        $this->assertSame(['approved', 1, $supervisor->id, null], [$approved->status, $approved->lock_version, $approved->decided_by_user_id, $approved->decision_reason]);
        $this->assertSame($before, $this->stockSnapshot(), 'approval records the decision only');
        $audit = AuditLog::query()->where('event', 'ai_proposal.approved')->sole();
        $this->assertSame([$supervisor->id, $proposal->id, false], [$audit->actor_user_id, $audit->metadata['proposal_id'], $audit->metadata['has_reason']]);
        $this->assertSame($approved->id, $this->proposals->approve($supervisor, $proposal, ['lock_version' => 0])->id, 'the same decision retried');
        $this->assertSame(1, AuditLog::query()->where('event', 'ai_proposal.approved')->count(), 'a retried decision is not audited twice');
        $this->refused(fn () => $this->proposals->reject($this->actor('finance_officer'), $proposal, ['lock_version' => 1, 'reason' => 'Too late']), ValidationException::class, 'already decided');
    }

    public function test_reject_needs_a_reason_without_personal_details_and_the_current_version(): void
    {
        $proposal = $this->propose();
        $supervisor = $this->actor('ca_supervisor');
        foreach ([['lock_version' => 0], ['lock_version' => 0, 'reason' => 'Call 012-345 6789'], ['lock_version' => 3, 'reason' => 'Supplier out of stock']] as $input) {
            $this->refused(fn () => $this->proposals->reject($supervisor, $proposal, $input), ValidationException::class, json_encode($input));
        }
        $rejected = $this->proposals->reject($supervisor, $proposal, ['lock_version' => 0, 'reason' => '  Supplier   out of stock ']);
        $this->assertSame(['rejected', 'Supplier out of stock'], [$rejected->status, $rejected->decision_reason]);
        $this->assertTrue(AuditLog::query()->where('event', 'ai_proposal.rejected')->sole()->metadata['has_reason']);
        $this->assertArrayNotHasKey('reason', AuditLog::query()->where('event', 'ai_proposal.rejected')->sole()->metadata);
    }

    public function test_deciding_needs_the_type_permission_an_assignment_at_the_branch_and_not_your_own_request(): void
    {
        $director = $this->actor('director');
        $own = $this->propose($this->agentRun('inventory_analyst', $director), 'own-request-0001');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        foreach ([
            'asked for the run' => $director,
            'no permission' => $this->actor('ca'),
            'administrator' => $this->actor('administrator'),
            'assigned elsewhere' => $this->actor('ca_supervisor', $puchong),
        ] as $case => $actor) {
            $this->refused(fn () => $this->proposals->approve($actor, $own, ['lock_version' => 0]), AuthorizationException::class, $case);
        }
        $this->assertSame('pending', $own->refresh()->status);
        $this->assertSame('approved', $this->proposals->approve($this->actor('finance_officer'), $own, ['lock_version' => 0])->status);
    }

    public function test_the_proposing_agent_withdraws_a_pending_proposal(): void
    {
        $run = $this->agentRun();
        $proposal = $this->propose($run);
        $this->refused(fn () => $this->proposals->withdraw($this->agentRun('stocktake_officer'), $proposal), AuthorizationException::class, 'another agent');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $puchong);
        $this->refused(fn () => $this->proposals->withdraw($elsewhere, $proposal), AuthorizationException::class, 'a run at another branch');
        $withdrawn = $this->proposals->withdraw($run, $proposal);
        $this->assertSame(['withdrawn', null], [$withdrawn->status, $withdrawn->decided_by_user_id]);
        $this->assertNotNull($withdrawn->decided_at);
        $this->refused(fn () => $this->proposals->approve($this->actor('ca_supervisor'), $proposal, ['lock_version' => 1]), ValidationException::class, 'withdrawn');
    }

    public function test_the_database_keeps_proposals_and_decisions_unchanged(): void
    {
        $pending = $this->propose();
        $decided = $this->proposals->approve($this->actor('ca_supervisor'), $this->propose(null, 'restock-cheras-0002', $this->restock(['suggested_quantity' => '9.000'])), ['lock_version' => 0]);
        foreach ([
            fn () => DB::table('ai_proposals')->where('id', $decided->id)->update(['status' => 'rejected']),
            fn () => DB::table('ai_proposals')->where('id', $decided->id)->update(['decision_reason' => 'Rewritten']),
            fn () => DB::table('ai_proposals')->where('id', $pending->id)->update(['payload_hash' => str_repeat('b', 64)]),
            fn () => DB::table('ai_proposals')->where('id', $pending->id)->update(['status' => 'executed', 'decided_at' => now()]),
            fn () => DB::table('ai_proposals')->where('id', $pending->id)->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by_user_id' => $this->actor('director')->id]),
            fn () => DB::table('ai_proposals')->where('id', $pending->id)->delete(),
            fn () => DB::statement('TRUNCATE ai_proposals CASCADE'),
        ] as $statement) {
            try {
                DB::transaction($statement);
                $this->fail('The database accepted a statement it must refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['pending', 'approved'], AiProposal::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_quantities_and_numbers_are_normalised_so_a_differently_written_retry_is_the_same_proposal(): void
    {
        $run = $this->agentRun();
        $first = $this->propose($run, 'restock-normalise-1', $this->restock(['current_quantity' => '3', 'reorder_level' => '10', 'suggested_quantity' => '7']));
        $retry = $this->propose($run, 'restock-normalise-1', $this->restock(['current_quantity' => '3.000', 'reorder_level' => '10.0', 'suggested_quantity' => '07.00']));
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(['3.000', '10.000', '7.000'], [$first->refresh()->payload['current_quantity'], $first->payload['reorder_level'], $first->payload['suggested_quantity']]);
        $expiry = $this->proposals->propose($run, 'inventory.expiry_warning', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'batch_public_id' => $this->f['batch']->public_id, 'location_public_id' => $this->f['location']->public_id, 'quantity' => '0.5', 'expiry_date' => '2027-01-01', 'days_left' => '57', 'suggested_action' => 'use_first'], 'expiry-normalise-1');
        $this->assertSame(['0.500', 57], [$expiry->payload['quantity'], $expiry->payload['days_left']]);
    }

    public function test_the_database_refuses_proposals_outside_their_run_or_organisation_and_versions_must_advance(): void
    {
        $run = $this->agentRun();
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $row = ['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'ai_agent_run_id' => $run->id, 'ai_agent_id' => $run->ai_agent_id,
            'type' => 'inventory.restock_suggestion', 'payload' => 'x', 'payload_hash' => str_repeat('a', 64), 'status' => 'pending', 'lock_version' => 0,
            'idempotency_key' => 'db-scope-0001', 'created_at' => now(), 'updated_at' => now()];
        $other = DB::table('organisations')->insertGetId(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic Other Clinic', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $outsider = User::factory()->create(['organisation_id' => $other, 'email' => 'outsider@kpone.test']);
        foreach ([
            'branch other than the run branch' => [...$row, 'branch_id' => $puchong->id],
            'agent other than the run agent' => [...$row, 'ai_agent_id' => DB::table('ai_agents')->where('code', 'stocktake_officer')->value('id')],
            'another organisation' => [...$row, 'organisation_id' => $other],
            'decider from another organisation' => [...$row, 'status' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => $outsider->id, 'lock_version' => 1],
        ] as $case => $values) {
            try {
                DB::transaction(fn () => DB::table('ai_proposals')->insert($values));
                $this->fail("Must be refused: {$case}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $id = DB::table('ai_proposals')->insertGetId($row);
        try {
            DB::transaction(fn () => DB::table('ai_proposals')->where('id', $id)->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by_user_id' => $this->actor('director')->id, 'decision_reason' => 'Not needed']));
            $this->fail('A change that does not advance the version must be refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        DB::table('ai_proposals')->where('id', $id)->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by_user_id' => $this->actor('director')->id, 'decision_reason' => 'Not needed', 'lock_version' => 1]);
        $this->assertSame('rejected', DB::table('ai_proposals')->where('id', $id)->value('status'), 'a pending proposal may be decided once');
    }
}
