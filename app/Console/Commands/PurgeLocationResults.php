<?php

namespace App\Console\Commands;

use App\Domain\HR\Attendance\Services\LocationResultPurge;
use Illuminate\Console\Command;

/** HR-16a retention (owner, 2026-11-11): deletes location-check results older than 90 days. The clock presses stay. */
class PurgeLocationResults extends Command
{
    protected $signature = 'hr-location-checks:purge';

    protected $description = 'Delete HR clock-in location-check results older than 90 days.';

    public function handle(LocationResultPurge $purge): int
    {
        $this->components->info('Deleted '.$purge->purgeExpired().' location-check results.');

        return self::SUCCESS;
    }
}
