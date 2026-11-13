<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Retention (owner, 2026-11-11): a location-check result (inside, outside or not available) is kept 90 days, then
 * deleted. The clock press itself stays with the attendance records; only the location result goes. PostgreSQL refuses
 * the delete unless this purge marks its transaction and the row is past the same 90 days.
 */
final class LocationResultPurge
{
    public const RETENTION_DAYS = 90;

    public function __construct(private AuditRecorder $audit) {}

    public function purgeExpired(): int
    {
        // PostgreSQL's own clock, the one its delete trigger checks, with a minute of margin, so the two never disagree
        // whatever the application server's clock says (Suresh, m1).
        $cutoff = "created_at < now() - interval '".self::RETENTION_DAYS." days' - interval '1 minute'";
        $total = 0;
        $organisations = DB::table('hr_clock_location_checks')->whereRaw($cutoff)->distinct()->pluck('organisation_id');
        foreach ($organisations as $organisationId) {
            $deleted = DB::transaction(function () use ($organisationId, $cutoff): int {
                DB::statement("SELECT set_config('kpone.hr_location_purge', 'on', true)");
                $count = DB::table('hr_clock_location_checks')->where('organisation_id', $organisationId)->whereRaw($cutoff)->delete();
                // Switched off again, so even inside an outer transaction nothing after the purge can delete.
                DB::statement("SELECT set_config('kpone.hr_location_purge', '', true)");
                if ($count > 0) {
                    $this->audit->record('hr.attendance.location_results_purged', null, ['count' => $count, 'retention_days' => self::RETENTION_DAYS],
                        null, null, (int) $organisationId);
                }

                return $count;
            }, 3);
            $total += $deleted;
        }

        return $total;
    }
}
