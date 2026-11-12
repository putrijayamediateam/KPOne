<?php

namespace App\Console\Commands;

use App\Domain\HR\Claims\Services\ClaimRetentionPurge;
use Illuminate\Console\Command;

/** PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use): deletes closed staff claims older than 7 years. */
class PurgeStaffClaims extends Command
{
    protected $signature = 'hr-claims:purge';

    protected $description = 'Delete closed HR staff claims, with their receipts, actions and payments, older than 7 years.';

    public function handle(ClaimRetentionPurge $purge): int
    {
        $c = $purge->purgeExpired();
        $this->components->info("Deleted {$c['claims']} staff claims and {$c['receipts']} receipt files.");

        return self::SUCCESS;
    }
}
