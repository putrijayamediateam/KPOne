<?php

namespace App\Console\Commands;

use App\Domain\Agents\Inventory\InventoryAnalyst;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * AG-6: runs the inventory analyst (Hana) for one branch as a scheduled ("system") run with no person. Called by
 * AG-8's daily run; it is not scheduled on its own. One run per branch, local date and slot: running it again for
 * the same slot does nothing once it has completed; a failed attempt can be retried.
 */
final class AgentsInventoryReview extends Command
{
    protected $signature = 'agents:inventory-review
        {branch : The branch code, e.g. CHERAS}
        {--checks=restock,expiry,transfer,slow_moving : Which checks to make, comma separated}
        {--slot=0700 : The run slot: 0700 (full) or 1400 (update)}';

    private const SLOTS = ['0700', '1400'];

    private const ATTEMPTS = 3;

    protected $description = 'Hana (inventory analyst, AI agent): propose restock, expiry, transfer and slow-moving suggestions for one branch.';

    public function handle(AgentRunRecorder $recorder, InventoryAnalyst $analyst): int
    {
        $matches = Branch::query()->where('code', (string) $this->argument('branch'))->where('is_active', true)->limit(2)->get();
        $branch = $matches->count() === 1 ? $matches->first() : null;
        if (! $branch) {
            $this->error('Unknown, inactive or ambiguous branch.');

            return self::FAILURE;
        }
        $slot = (string) $this->option('slot');
        $checks = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('checks')))));
        if (! in_array($slot, self::SLOTS, true) || $checks === [] || array_diff($checks, InventoryAnalyst::ALL_CHECKS) !== []) {
            $this->error('Use --slot 0700 or 1400 and --checks from: '.implode(', ', InventoryAnalyst::ALL_CHECKS).'.');

            return self::FAILURE;
        }

        $date = CarbonImmutable::now($branch->timezone)->toDateString();
        // One run per branch, date and slot. A failed attempt may be retried (up to three attempts): proposals keep
        // their own keys, so a retry never repeats a suggestion the failed attempt already made.
        $run = null;
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $key = InventoryAnalyst::AGENT.":{$branch->id}:{$date}:{$slot}".($attempt > 1 ? ":r{$attempt}" : '');
            $run = $recorder->start(InventoryAnalyst::AGENT, InventoryAnalyst::TASK, $branch, idempotencyKey: $key);
            if (! $run->isFinished()) {
                break;
            }
            if ($run->status === 'completed') {
                $this->info("Hana already ran for {$branch->code} on {$date} ({$slot}).");

                return self::SUCCESS;
            }
            $run = null;
        }
        if ($run === null) {
            $this->error('Hana failed '.self::ATTEMPTS." times for {$branch->code} on {$date} ({$slot}); not trying again.");

            return self::FAILURE;
        }

        try {
            $review = $analyst->review($run, $branch, $checks);
        } catch (Throwable $exception) {
            $recorder->fail($run, 'inventory_review.failed');
            $this->error('The inventory review failed; the run is marked failed.');

            throw $exception;
        }
        $recorder->complete($run);
        $this->info($review->summary());

        return self::SUCCESS;
    }
}
