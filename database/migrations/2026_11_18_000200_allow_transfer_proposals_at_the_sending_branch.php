<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AG-31: a transfer suggestion is held by the sending branch while its run stays at the receiving branch
 * (docs/agents/CONTRACT.md section 15). The proposal guard trigger required the run's branch and the proposal's branch
 * to be the same; for transfer suggestions only it now needs both to be in the same organisation (ProposalService checks
 * that the run is at the receiver and the proposal at the sender, because the payload is encrypted).
 */
return new class extends Migration
{
    private const SAME_BRANCH = '(r.branch_id IS NULL OR r.branch_id = NEW.branch_id)';

    private const TRANSFER_OR_SAME_BRANCH = "(r.branch_id IS NULL OR r.branch_id = NEW.branch_id OR NEW.type = 'inventory.transfer_suggestion')";

    public function up(): void
    {
        $this->replace(self::SAME_BRANCH, self::TRANSFER_OR_SAME_BRANCH);
    }

    public function down(): void
    {
        $held = DB::table('ai_proposals as p')->join('ai_agent_runs as r', 'r.id', '=', 'p.ai_agent_run_id')
            ->where('p.type', 'inventory.transfer_suggestion')->whereNotNull('r.branch_id')->whereColumn('r.branch_id', '<>', 'p.branch_id')->exists();
        if ($held) {
            throw new RuntimeException('Transfer suggestions are held by a branch other than their run\'s; rolling this back would break them.');
        }
        $this->replace(self::TRANSFER_OR_SAME_BRANCH, self::SAME_BRANCH);
    }

    private function replace(string $from, string $to): void
    {
        $definition = (string) DB::selectOne("select pg_get_functiondef('kp_ai_proposal_guard()'::regprocedure) as d")->d;
        $normalised = str_replace(["\r"], '', $definition);
        $pattern = 'r.ai_agent_id = NEW.ai_agent_id AND '.$from;
        if (substr_count($normalised, $pattern) !== 1) {
            throw new RuntimeException('The proposal guard function does not look as expected; refusing to change it.');
        }
        // The function body is the live definition with one exact-match replacement, so it is not a literal string.
        // @phpstan-ignore argument.type
        DB::unprepared(str_replace($pattern, 'r.ai_agent_id = NEW.ai_agent_id AND '.$to, $normalised));
    }
};
