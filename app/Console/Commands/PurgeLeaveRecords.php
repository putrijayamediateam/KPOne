<?php

namespace App\Console\Commands;

use App\Domain\HR\Leave\Services\LeaveRetentionPurge;
use Illuminate\Console\Command;

/** PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use): deletes leave years older than 7 years. */
class PurgeLeaveRecords extends Command
{
    protected $signature = 'hr-leave:purge';

    protected $description = 'Delete HR leave records (requests, ledger, entitlements and evidence) of leave years older than 7 years.';

    public function handle(LeaveRetentionPurge $purge): int
    {
        $c = $purge->purgeExpired();
        $this->components->info("Deleted {$c['requests']} leave requests, {$c['entries']} entries, {$c['ledger']} other ledger lines, {$c['entitlements']} entitlements and {$c['files']} evidence files.");

        return self::SUCCESS;
    }
}
