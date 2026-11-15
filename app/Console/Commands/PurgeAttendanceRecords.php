<?php

namespace App\Console\Commands;

use App\Domain\HR\Attendance\Services\AttendanceRetentionPurge;
use Illuminate\Console\Command;

/** HR-3a retention (owner, 2026-11-11; the accountant confirms before real use): deletes attendance records older than 7 years. */
class PurgeAttendanceRecords extends Command
{
    protected $signature = 'hr-attendance:purge';

    protected $description = 'Delete HR attendance records (clock sessions and what hangs on them) older than 7 years.';

    public function handle(AttendanceRetentionPurge $purge): int
    {
        $counts = $purge->purgeExpired();
        $this->components->info("Deleted {$counts['sessions']} clock sessions, {$counts['corrections']} corrections and {$counts['consents']} location consents.");

        return self::SUCCESS;
    }
}
