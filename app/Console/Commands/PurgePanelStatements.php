<?php

namespace App\Console\Commands;

use App\Domain\Visit\Billing\Services\PanelStatementService;
use Illuminate\Console\Command;

/** PNL-7: deletes uploaded Panel statement files 30 days after upload (owner decision 2026-11-05). */
class PurgePanelStatements extends Command
{
    protected $signature = 'panel-statements:purge';

    protected $description = 'Delete uploaded Panel statement files whose 30 days have passed.';

    public function handle(PanelStatementService $statements): int
    {
        $this->components->info('Deleted '.$statements->purgeExpired().' Panel statement files.');

        return self::SUCCESS;
    }
}
