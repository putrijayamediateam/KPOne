<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Retention (owner, 2026-11-11; the accountant confirms the 7 years before real use): attendance records are kept 7
 * years, then deleted. A clock session goes with its events, corrections, overtime requests and location result, and
 * only once every one of those is itself past the 7 years (a later correction or overtime ask keeps the session until it
 * is old enough too). Corrections with no session, and location-check consents, go on their own time. Settings versions
 * are kept. PostgreSQL refuses each delete unless this purge marks its transaction and the row is past the 7 years by
 * its own clock, which is also the clock used here (with a day of margin).
 */
final class AttendanceRetentionPurge
{
    public const RETENTION_YEARS = 7;

    private const CUTOFF = "now() - interval '7 years' - interval '1 day'";

    public function __construct(private AuditRecorder $audit) {}

    /** @return array{sessions: int, corrections: int, consents: int} */
    public function purgeExpired(): array
    {
        $total = ['sessions' => 0, 'corrections' => 0, 'consents' => 0];
        $cutoff = self::CUTOFF;
        $organisations = DB::table('hr_clock_sessions')->whereRaw("clock_in_at < {$cutoff}")->distinct()->pluck('organisation_id')
            ->merge(DB::table('hr_attendance_corrections')->whereNull('clock_session_id')->whereNull('created_session_id')->whereRaw("created_at < {$cutoff}")->distinct()->pluck('organisation_id'))
            ->merge(DB::table('hr_location_consents')->whereRaw("acknowledged_at < {$cutoff}")->distinct()->pluck('organisation_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values();
        foreach ($organisations as $organisationId) {
            $counts = DB::transaction(function () use ($organisationId, $cutoff): array {
                DB::statement("SELECT set_config('kpone.hr_attendance_purge', 'on', true)");
                DB::statement("SELECT set_config('kpone.hr_location_purge', 'on', true)");
                $sessions = DB::table('hr_clock_sessions as s')->where('s.organisation_id', $organisationId)->whereRaw("s.clock_in_at < {$cutoff}")
                    ->whereNotExists(fn ($q) => $q->from('hr_attendance_corrections as c')->where(fn ($q) => $q->whereColumn('c.clock_session_id', 's.id')
                        ->orWhereColumn('c.created_session_id', 's.id'))->whereRaw("c.created_at >= {$cutoff}"))
                    ->whereNotExists(fn ($q) => $q->from('hr_overtime_requests as o')->whereColumn('o.clock_session_id', 's.id')->whereRaw("o.created_at >= {$cutoff}"))
                    ->whereNotExists(fn ($q) => $q->from('hr_clock_events as e')->whereColumn('e.clock_session_id', 's.id')->whereRaw("e.created_at >= {$cutoff}"))
                    ->orderBy('s.id')->lockForUpdate()->pluck('s.id')->map(fn ($id): int => (int) $id)->all();
                $corrections = 0;
                foreach (array_chunk($sessions, 500) as $ids) {
                    DB::table('hr_clock_location_checks')->whereIn('clock_session_id', $ids)->delete();
                    DB::table('hr_overtime_requests')->whereIn('clock_session_id', $ids)->delete();
                    DB::table('hr_clock_events')->whereIn('clock_session_id', $ids)->delete();
                    $corrections += DB::table('hr_attendance_corrections')->where(fn ($q) => $q->whereIn('clock_session_id', $ids)->orWhereIn('created_session_id', $ids))->delete();
                    DB::table('hr_clock_sessions')->whereIn('id', $ids)->delete();
                }
                $corrections += DB::table('hr_attendance_corrections')->where('organisation_id', $organisationId)->whereNull('clock_session_id')
                    ->whereNull('created_session_id')->whereRaw("created_at < {$cutoff}")->delete();
                $consents = DB::table('hr_location_consents')->where('organisation_id', $organisationId)->whereRaw("acknowledged_at < {$cutoff}")->delete();
                // Switched off again, so even inside an outer transaction nothing after the purge can delete (Suresh).
                DB::statement("SELECT set_config('kpone.hr_attendance_purge', '', true), set_config('kpone.hr_location_purge', '', true)");
                $counts = ['sessions' => count($sessions), 'corrections' => $corrections, 'consents' => $consents];
                if (array_sum($counts) > 0) {
                    $this->audit->record('hr.attendance.retention_purged', null, [...$counts, 'retention_years' => self::RETENTION_YEARS], null, null, $organisationId);
                }

                return $counts;
            }, 3);
            foreach ($counts as $k => $v) {
                $total[$k] += $v;
            }
        }

        return $total;
    }
}
