<?php

namespace App\Console\Commands;

use App\Domain\Agents\DailySummary\DailyRun;
use Illuminate\Console\Command;

/**
 * AG-8: the daily summary runs (contract section 14). Scheduled every hour for each slot; a branch runs a slot
 * once its local clock has reached 07:00 or 14:00, once per local date, so a missed hour catches up and a repeat
 * does nothing. No message, email or notification is sent.
 */
final class AgentsDailyRun extends Command
{
    protected $signature = 'agents:daily-run
        {slot : 0700 (full run) or 1400 (update)}
        {--branch= : Only this branch code (Faris runs only without it)}';

    protected $description = 'The AI agents\' 07:00 and 14:00 runs for the daily summary (Lina, Hana, Rizal and Faris; AI agents).';

    public function handle(DailyRun $daily): int
    {
        $slot = (string) $this->argument('slot');
        if (! array_key_exists($slot, DailyRun::SLOT_HOURS)) {
            $this->error('Use 0700 or 1400.');

            return self::FAILURE;
        }
        $branch = $this->option('branch');
        $outcome = $daily->run($slot, is_string($branch) && $branch !== '' ? $branch : null);
        foreach ($outcome['stored'] as $line) {
            $this->line("Stored: {$line}");
        }
        foreach ($outcome['failed'] as $line) {
            $this->error("Failed: {$line}");
        }
        $this->info(sprintf('%d stored, %d skipped, %d failed.', count($outcome['stored']), count($outcome['skipped']), count($outcome['failed'])));

        return $outcome['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
