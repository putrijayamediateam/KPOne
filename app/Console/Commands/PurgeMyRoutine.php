<?php

namespace App\Console\Commands;

use App\Domain\MyDay\MyRoutineService;
use Illuminate\Console\Command;

/** DAY-1b: removes routine ticks older than 31 days, items hidden for more than 7 days, and the lists of accounts deactivated for 30 days. */
class PurgeMyRoutine extends Command
{
    protected $signature = 'my-routine:purge';

    protected $description = 'Remove old routine ticks, long-hidden routine items and the lists of long-deactivated accounts.';

    public function handle(MyRoutineService $routine): int
    {
        $this->components->info('Removed '.$routine->purge().' routine rows.');

        return self::SUCCESS;
    }
}
