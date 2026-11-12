<?php

namespace App\Console\Commands;

use App\Domain\HR\Applications\Services\InterviewRecordsPurge;
use Illuminate\Console\Command;

/** PDPA 15 (owner, 2026-11-11): deletes unsuccessful candidates' interview records 6 months after the application closed. */
class PurgeInterviewRecords extends Command
{
    protected $signature = 'hr-interview-records:purge';

    protected $description = 'Delete interview time choices, bookings and document download records of applications closed unsuccessfully more than 6 months ago.';

    public function handle(InterviewRecordsPurge $purge): int
    {
        $c = $purge->purgeExpired();
        $this->components->info("Cleared the interview records of {$c['applications']} applications ({$c['picks']} time choices, {$c['interviews']} bookings, {$c['downloads']} downloads).");

        return self::SUCCESS;
    }
}
