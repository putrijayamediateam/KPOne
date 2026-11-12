<?php

namespace App\Console\Commands;

use App\Domain\HR\Credentials\Services\CredentialRetentionPurge;
use Illuminate\Console\Command;

/** PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use): deletes credential records older than 7 years. */
class PurgeHrCredentials extends Command
{
    protected $signature = 'hr-credentials:purge';

    protected $description = 'Delete HR credential records no longer in force, or of staff who left, older than 7 years.';

    public function handle(CredentialRetentionPurge $purge): int
    {
        $this->components->info('Deleted '.$purge->purgeExpired().' credential records.');

        return self::SUCCESS;
    }
}
