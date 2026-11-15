<?php

namespace App\Console\Commands;

use App\Domain\HR\Applications\Services\JobApplicationService;
use Illuminate\Console\Command;

/** HR-13: clears closed job applications past the clinic's retention. The rows, steps and history stay. */
class PurgeJobApplications extends Command
{
    protected $signature = 'hr-applications:purge';

    protected $description = 'Delete the CV and personal details of closed job applications past the clinic-entered retention.';

    public function handle(JobApplicationService $applications): int
    {
        $this->components->info('Cleared '.$applications->purgeExpired().' job applications.');

        return self::SUCCESS;
    }
}
