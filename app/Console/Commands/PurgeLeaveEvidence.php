<?php

namespace App\Console\Commands;

use App\Domain\HR\Leave\Services\LeaveEvidenceService;
use Illuminate\Console\Command;

/** HR-2b: deletes leave evidence files whose retention has passed (owner, 2026-11-06). The rows and history stay. */
class PurgeLeaveEvidence extends Command
{
    protected $signature = 'hr-leave-evidence:purge';

    protected $description = 'Delete leave evidence files past their leave type\'s retention, or 30 days after a rejection or cancellation.';

    public function handle(LeaveEvidenceService $evidence): int
    {
        $this->components->info('Deleted '.$evidence->purgeExpired().' leave evidence files.');

        return self::SUCCESS;
    }
}
