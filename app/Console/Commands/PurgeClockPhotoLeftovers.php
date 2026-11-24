<?php

namespace App\Console\Commands;

use App\Domain\HR\Attendance\Photos\ClockPhotoBackstop;
use Illuminate\Console\Command;

/** HR-PHOTO: deletes run-out capture tokens and photo files that no attendance record points at. Photos themselves go with `hr-attendance:purge`. */
class PurgeClockPhotoLeftovers extends Command
{
    protected $signature = 'hr-clock-photos:purge';

    protected $description = 'Delete run-out HR clock capture tokens and clock photo files no record points at.';

    public function handle(ClockPhotoBackstop $backstop): int
    {
        $counts = $backstop->run();
        $this->components->info("Deleted {$counts['tokens']} capture tokens and {$counts['files']} leftover photo files.");

        return self::SUCCESS;
    }
}
