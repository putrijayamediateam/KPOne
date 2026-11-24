<?php

namespace App\Console\Commands;

use App\Domain\HR\Attendance\Services\AttendanceRetentionPurge;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * HR-3a retention (owner, 2026-11-11; the 7 years confirmed by the owner on 16 Nov 2026): deletes attendance records older than 7 years.
 * HR-PHOTO-STORAGE: `--dry-run` only reports what the purge would delete today and what falls due over the next 12
 * months; it reads and never deletes, locks or audits anything.
 */
class PurgeAttendanceRecords extends Command
{
    protected $signature = 'hr-attendance:purge {--dry-run : Report what would be deleted, and when things fall due, without deleting anything}';

    protected $description = 'Delete HR attendance records (clock sessions and what hangs on them) older than 7 years.';

    public function handle(AttendanceRetentionPurge $purge): int
    {
        if ($this->option('dry-run')) {
            return $this->dryRun($purge);
        }
        $counts = $purge->purgeExpired();
        $this->components->info("Deleted {$counts['sessions']} clock sessions, {$counts['corrections']} corrections and {$counts['consents']} location consents.");

        return self::SUCCESS;
    }

    private function dryRun(AttendanceRetentionPurge $purge): int
    {
        $preview = $purge->preview();
        $due = $preview['due'];
        $this->components->info('Dry run: nothing is deleted. Attendance records are kept '.AttendanceRetentionPurge::RETENTION_YEARS.' years ('.AttendanceRetentionPurge::RETENTION_BASIS.').');
        $this->components->twoColumnDetail('Clock sessions due today', (string) $due['sessions']);
        $this->components->twoColumnDetail('Photos due today', $due['photos'].' ('.self::size($due['photo_bytes']).')');
        $this->components->twoColumnDetail('Corrections due today', (string) $due['corrections']);
        $this->components->twoColumnDetail('Location consents due today', (string) $due['consents']);
        $this->components->twoColumnDetail('Photo notice records due today', (string) $due['notices']);
        $next = $preview['next_due'] === null ? 'nothing is waiting' : CarbonImmutable::parse($preview['next_due'])->utc()->format('j M Y');
        $this->components->twoColumnDetail('Next falls due', $next);
        if ($preview['months'] === []) {
            $this->line('  Nothing falls due in the next 12 months.');
        } else {
            $this->line('  Falling due in the next 12 months (by age; a record with something newer hanging on it waits longer):');
            foreach ($preview['months'] as $m) {
                $month = CarbonImmutable::createFromFormat('!Y-m', $m['month'], 'UTC');
                $this->components->twoColumnDetail($month === null ? $m['month'] : $month->format('M Y'),
                    "{$m['sessions']} sessions, {$m['photos']} photos (".self::size($m['photo_bytes']).')');
            }
        }

        return self::SUCCESS;
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 1).' GB',
            $bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
