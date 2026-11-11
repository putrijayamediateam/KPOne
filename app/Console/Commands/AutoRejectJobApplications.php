<?php

namespace App\Console\Commands;

use App\Domain\HR\Applications\Services\HiringDeadlines;
use Illuminate\Console\Command;

/**
 * HR-17b: reminds the hiring mailbox on an application's third working day at New or Phone screening and closes it as
 * Auto-rejected from the fourth, telling the candidate. Each application's own branch-local date decides, and a
 * reminder or a closing happens once, so running it every hour is safe and catches up after a missed run.
 */
class AutoRejectJobApplications extends Command
{
    protected $signature = 'hr-applications:auto-reject';

    protected $description = 'Remind the hiring mailbox and close job applications left without an HR decision for 3 working days.';

    public function handle(HiringDeadlines $deadlines): int
    {
        [$reminded, $rejected] = $deadlines->run();
        $this->components->info("Sent {$reminded} reminders and closed {$rejected} applications as Auto-rejected.");

        return self::SUCCESS;
    }
}
