<?php

namespace App\Console\Commands;

use App\Domain\Agents\Registry\AgentRegistrar;
use Illuminate\Console\Command;
use Throwable;

/**
 * AG-36: registers the contract's agents on a real server (the seeder refuses outside local and testing). Run once by the
 * person who looks after the server. Safe to repeat. See docs/agents/AGENT-REGISTRATION-DESIGN.md.
 */
final class KponeRegisterAgents extends Command
{
    protected $signature = 'kpone:register-agents
        {--dry-run : Show what would be created and change nothing}
        {--force : Needed to write in production}
        {--enable : Create new agents enabled (default: disabled; the Director enables each on the Agents page)}
        {--add-missing-tools : Add tools missing from existing agents (never removes a tool)}';

    protected $description = 'Register the contract agents (disabled unless --enable). Idempotent; never changes an existing agent.';

    public function handle(AgentRegistrar $registrar): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && app()->isProduction() && ! $this->option('force')) {
            $this->error('This writes to a production database. Run with --dry-run first, then add --force.');

            return self::FAILURE;
        }

        try {
            $report = $registrar->register((bool) $this->option('enable'), (bool) $this->option('add-missing-tools'), $dry);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $prefix = $dry ? 'Would ' : '';
        foreach ($report['created'] as $code) {
            $this->line($prefix.'create '.$code.($report['enabled'] ? ' (enabled)' : ' (disabled)'));
        }
        foreach ($report['tools_added'] as $code => $tools) {
            $this->line($prefix.'add tools to '.$code.': '.implode(', ', $tools));
        }
        foreach ($report['tools_missing'] as $code => $tools) {
            $this->warn($code.' lacks '.implode(', ', $tools).' (use --add-missing-tools to add)');
        }
        if ($report['created'] === [] && $report['tools_added'] === []) {
            $this->info('Nothing to do.');
        }

        return self::SUCCESS;
    }
}
